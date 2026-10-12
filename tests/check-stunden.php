<?php

declare(strict_types=1);

/*
 * Freie Zeiträume mit Uhrzeit (Burkhard 12.10.2026: „Zeiträume lassen sich nur tageweise wählen, nicht stundengenau.“
 * lässt sich das erweitern?): „from“ und „to“ nehmen neben JJJJ-MM-TT auch JJJJ-MM-TT HH:MM. Gerechnet wird mit
 * ganzen Stunden aus den Stundenaggregaten: „from“ rundet ab, „to“ rundet auf und ist das Ende (13:00 bis 15:00 sind
 * zwei Stunden). Ohne „to“ gilt bis jetzt. Mit Uhrzeit höchstens 48 Stunden, sonst würde der Verlauf zu lang.
 * Ohne Uhrzeit bleibt alles wie bisher (check-zeitraum.php).
 * Mitschnitte nuc_2026-10-12_verbrauch.json (Haus, Stundenwerte ab 11.10.2026) und nuc_2026-10-10_verlauf.json
 * (Temperatur im Gästezimmer, Stundenwerte ab 09.10.2026). Die Hausverbräuche sind aus denselben Stundenaggregaten von
 * Hand gerechnet: 11.10. 13 Uhr 3,10 und 14 Uhr 2,21 kWh, 12.10. ab 12 Uhr 4,59 + 10,26 + 11,41 + 1,63 + 0,63 + 0,17.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$haus = fixture('nuc_2026-10-12_verbrauch.json');
$verlauf = fixture('nuc_2026-10-10_verlauf.json');
date_default_timezone_set($haus['zeitzone']);

function anlage(array $mitschnitt, bool $diagramm, array $argumente): array
{
    $backend = new FakeBackend($mitschnitt['variablen']);
    $backend->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $backend, null, '', $mitschnitt['bilanz'] ?? []);
    try {
        return $diagramm ? $index->chart($argumente) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$kopf = static fn (array $r): string => explode("\n", $r['text'])[0];

/* --- Verbrauch stundengenau ------------------------------------------------------------------- */

$r = anlage($haus, false, ['from' => '2026-10-11 13:00', 'to' => '2026-10-11 15:00']);
pruefe(!$r['isError'] && str_starts_with($kopf($r), 'House consumption 5.3 kWh (all devices, from=2026-10-11 13:00, to=2026-10-11 15:00)'), 'zwei Stunden Hausverbrauch: ' . $kopf($r));
$r = anlage($haus, false, ['from' => '2026-10-11T13:00', 'to' => '2026-10-11T15:00']);
pruefe(!$r['isError'] && str_starts_with($kopf($r), 'House consumption 5.3 kWh'), 'auch mit T zwischen Datum und Uhrzeit: ' . $kopf($r));
$r = anlage($haus, false, ['from' => '2026-10-11 13:20', 'to' => '2026-10-11 14:40']);
pruefe(!$r['isError'] && str_contains($kopf($r), 'from=2026-10-11 13:00, to=2026-10-11 15:00') && str_starts_with($kopf($r), 'House consumption 5.3 kWh'), 'Minuten auf ganze Stunden, der Kopf nennt die gerechneten: ' . $kopf($r));
$r = anlage($haus, false, ['from' => '2026-10-12 12:00']);
pruefe(!$r['isError'] && str_starts_with($kopf($r), 'House consumption 28.7 kWh (all devices, from=2026-10-12 12:00)'), 'ohne „to“ bis jetzt: ' . $kopf($r));
$r = anlage($haus, false, ['from' => '2026-10-11 22:00', 'to' => '2026-10-12']);
pruefe(!$r['isError'] && str_contains($kopf($r), 'from=2026-10-11 22:00, to=2026-10-13 00:00'), '„to“ ohne Uhrzeit: bis zum Ende dieses Tages: ' . $kopf($r));
$r = anlage($haus, false, ['name' => 'Waschmaschine', 'from' => '2026-10-11 13:00', 'to' => '2026-10-11 15:00']);
pruefe(!$r['isError'] && str_contains($kopf($r), 'from=2026-10-11 13:00, to=2026-10-11 15:00'), 'auch für ein Gerät: ' . $kopf($r));

/* --- Absagen ---------------------------------------------------------------------------------- */

$r = anlage($haus, false, ['from' => '2026-10-11 15:00', 'to' => '2026-10-11 15:00']);
pruefe($r['isError'] && $r['text'] === 'Parameter "to" must not be before "from".', 'Ende gleich Anfang: ' . $r['text']);
$r = anlage($haus, false, ['from' => '2026-10-09 12:00', 'to' => '2026-10-12 12:00']);
pruefe($r['isError'] && str_contains($r['text'], 'at most 48 hours'), 'mit Uhrzeit höchstens 48 Stunden: ' . $r['text']);
$r = anlage($haus, false, ['from' => '2026-10-11 25:00']);
pruefe($r['isError'] && str_starts_with($r['text'], 'Parameter "from" must be a date like 2026-01-31'), 'keine gültige Uhrzeit: ' . $r['text']);
$r = anlage($haus, false, ['from' => '2026-10-11', 'to' => '2026-10-11 15']);
pruefe($r['isError'] && str_starts_with($r['text'], 'Parameter "to" must be a date like 2026-01-31'), 'Uhrzeit ohne Minuten: ' . $r['text']);

/* --- Diagramm und Verlauf je Stunde ------------------------------------------------------------- */

$r = anlage($haus, true, ['from' => '2026-10-11 13:00', 'to' => '2026-10-11 15:00']);
pruefe(($r['structuredContent']['per'] ?? null) === 'hour' && ($r['structuredContent']['times'] ?? null) === [mktime(13, 0, 0, 10, 11, 2026), mktime(14, 0, 0, 10, 11, 2026)], 'Hausstapel mit zwei Stundenbalken: ' . json_encode($r['structuredContent']['times'] ?? null));
$r = anlage($verlauf, true, ['room' => 'Gast', 'type' => 'temperature', 'from' => '2026-10-10 06:00', 'to' => '2026-10-10 09:00']);
$punkte = $r['structuredContent']['series'][0]['points'] ?? [];
pruefe(($r['structuredContent']['per'] ?? null) === 'hour' && array_column($punkte, 0) === [mktime(6, 0, 0, 10, 10, 2026), mktime(7, 0, 0, 10, 10, 2026), mktime(8, 0, 0, 10, 10, 2026)], 'Temperatur von 6 bis 9 Uhr, drei Stundenwerte: ' . json_encode(array_column($punkte, 0)));
$r = anlage($verlauf, false, ['room' => 'Gast', 'type' => 'temperature', 'from' => '2026-10-09 22:00', 'to' => '2026-10-10 06:00']);
pruefe(!$r['isError'] && str_contains($kopf($r), 'per hour') && substr_count($r['text'], "\n2026-10-") === 8, 'Verlauf über Nacht in der Tabelle, acht Stunden: ' . $r['text']);

ende('Zeiträume mit Uhrzeit');
