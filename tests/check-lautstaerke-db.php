<?php

declare(strict_types=1);

/*
 * Lautstärke in dB (Backlog B58, Einrichtungs-Blindtest 12.10.2026): Der Agent ließ die Lautstärke des Denon weg,
 * weil er nicht wusste, wie Prozent auf -80 bis 18 dB abgebildet werden. Sie zählt in Prozent des Bereichs wie
 * jede andere. Gesendet wird im Raster der Variable: Das Denon-Modul lehnt -50.6 ab und nimmt -50.5 an.
 * Mitschnitt testbox_2026-10-12_denon_lautstaerke.json (Denon-Receiver über DenonAVRTelnet).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('testbox_2026-10-12_denon_lautstaerke.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function stelle(array $werte, array $argumente, ?array $uebersetzungen = null): array
{
    global $anlage, $liste;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    try {
        return (new DeviceIndex($liste, $anlage))->setState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$gesendet = static function (): array {
    global $anlage;
    return array_values(array_map(static fn (array $q): mixed => $q[1], array_filter($anlage->requests, static fn (array $q): bool => $q[0] === 44763)));
};
$denon = ['name' => 'Denon'];

/* --- Abfrage: Prozent und dB -------------------------------------------------------------------- */

$index = new DeviceIndex($liste, new FakeBackend($werte));
$text = $index->getState([])['text'];
pruefe(str_contains($text, 'Wohnzimmer | Denon | media | on (volume 64 % = -17.5 dB)'), '-17.5 dB sind 64 % des Bereichs, die dB stehen dabei: ' . $text);
$text = (new DeviceIndex($liste, new FakeBackend($werte, $de)))->getState([])['text'];
pruefe(str_contains($text, 'Wohnzimmer | Denon | media | an (Lautstärke 64 % = -17.5 dB)'), 'deutsch: ' . $text);

/* --- Stellen im Raster der Variable -------------------------------------------------------------- */

$r = stelle($werte, $denon + ['volume' => 30]);
pruefe($gesendet() === [-50.5], '30 % sind -50.6 dB, gesendet wird -50.5 (Raster 0.5; -50.6 lehnt das Denon-Modul ab): ' . json_encode($anlage->requests));
pruefe(str_starts_with($zeile($r, 0), '1 of 1 devices are now at volume 30 %') && $zeile($r, 1) === 'Wohnzimmer | Denon | media | on (volume 30 % = -50.5 dB)', 'nach 400 ms bestätigt: ' . $r['text']);
$r = stelle($werte, $denon + ['volume_change' => 10]);
pruefe($gesendet() === [-7.5], '64 % plus 10 Punkte sind -7.48 dB, gesendet wird -7.5: ' . json_encode($anlage->requests));
$r = stelle($werte, $denon + ['volume' => 64]);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Wohnzimmer | Denon | media | already set to volume 64 %', 'schon 64 %, nichts gesendet: ' . $r['text']);
$r = stelle($werte, $denon + ['volume' => 0]);
pruefe($gesendet() === [-80.0], '0 % ist das untere Ende: ' . json_encode($anlage->requests));
$r = stelle($werte, $denon + ['volume' => 100]);
pruefe($gesendet() === [18.0], '100 % ist das obere Ende: ' . json_encode($anlage->requests));

// Ohne Raster (Bereich ohne Schrittweite) bleibt es beim gerechneten Wert
$ohneRaster = $werte;
unset($ohneRaster['44763']['bereich']['schritt'], $ohneRaster['44763']['raster']);
$r = stelle($ohneRaster, $denon + ['volume' => 30]);
pruefe($gesendet() === [-50.6], 'ohne Schrittweite wird nicht gerundet: ' . json_encode($anlage->requests));

ende('Lautstärke in dB');
