<?php

declare(strict_types=1);

/*
 * Mediaplayer ein- und ausschalten (set_state on/off, wie HassTurnOn/HassTurnOff bei einem media_player).
 * Mitschnitt nuc_2026-10-11_receiver.json: Denon-Receiver über das Modul DenonAVRTelnet, mit Burkhards OK an und
 * wieder aus. Gegenprobe mit dem Statustext des HomeAssistant-Moduls aus nuc_2026-10-10_wiedergabe.json: ein Text
 * ohne Aktion lässt sich nicht schalten.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-11_receiver.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$wiedergabe = fixture('nuc_2026-10-10_wiedergabe.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function rufe(array $liste, array $werte, string $werkzeug, array $argumente, ?array $uebersetzungen = null): array
{
    global $anlage;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    try {
        $idx = new DeviceIndex($liste, $anlage);
        return $werkzeug === 'get' ? $idx->getState($argumente) : $idx->setState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$receiver = ['name' => 'Receiver'];

pruefe(DeviceIndex::abilitiesOf($liste[0]) === ['on', 'off'], 'nur Ein/Aus: on und off: ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));
pruefe(DeviceIndex::abilitiesOf($wiedergabe['geraete'][1]) === ['on', 'off', 'stop', 'play', 'pause', 'next', 'previous', 'volume', 'volume_change', 'mute'], 'mit Ein/Aus und Wiedergabe: ' . json_encode(DeviceIndex::abilitiesOf($wiedergabe['geraete'][1])));
// Ohne Ein/Aus pausieren off und setzen on fort (B86, check-musik-aus.php).
pruefe(DeviceIndex::abilitiesOf($wiedergabe['geraete'][0]) === ['on', 'off', 'stop', 'play', 'pause', 'next', 'previous', 'volume', 'volume_change', 'mute'], 'ohne Ein/Aus mit Wiedergabe: on und off über die Wiedergabe: ' . json_encode(DeviceIndex::abilitiesOf($wiedergabe['geraete'][0])));

$r = rufe($liste, $werte, 'get', $receiver);
pruefe($zeile($r, 1) === 'Wohnzimmer | Receiver | media | off', 'aus: ' . $r['text']);
$r = rufe($liste, $werte, 'set', $receiver + ['command' => 'on'], $de);
pruefe($anlage->requests === [[35575, true]] && $zeile($r, 0) === '1 von 1 Geräten sind jetzt an (name=Receiver).' && $zeile($r, 1) === 'Wohnzimmer | Receiver | media | an', 'eingeschaltet, nach 0,6 s bestätigt: ' . $r['text']);
pruefe($anlage->slept <= 700, 'die Antwort wartet nur bis zur Bestätigung: ' . $anlage->slept . ' ms');
$an = $werte;
$an['35575']['wert'] = true;
unset($an['35575']['formatiert']);
$r = rufe($liste, $an, 'set', $receiver + ['command' => 'off']);
pruefe($anlage->requests === [[35575, false]] && $zeile($r, 0) === '1 of 1 devices are now off (name=Receiver).', 'ausgeschaltet, nach 0,2 s bestätigt: ' . $r['text']);
$r = rufe($liste, $werte, 'set', $receiver + ['command' => 'off']);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Wohnzimmer | Receiver | media | already off', 'schon aus, nichts gesendet: ' . $r['text']);

// Der Statustext des HomeAssistant-Moduls ist ein Text ohne Aktion: nie geschaltet, off geht über die Wiedergabe (B86).
$r = rufe($wiedergabe['geraete'], $wiedergabe['variablen'], 'set', ['name' => 'Sonos über HA', 'command' => 'off']);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Wintergarten | Sonos über HA | media | already paused', 'Statustext wird nicht geschaltet, der pausierte Player bleibt: ' . $r['text']);

ende('Mediaplayer ein/aus');
