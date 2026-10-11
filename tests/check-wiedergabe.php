<?php

declare(strict_types=1);

/*
 * Mediaplayer, Stufe 2: Wiedergabe steuern mit set_state command=play, pause, stop, next, previous (wie die
 * Intents HassMediaUnpause, HassMediaPause, HassMediaNext, HassMediaPrevious von Home Assistant). Bestätigt
 * sind Play, Pause und Stop am Wiedergabewert oder am Statustext des HomeAssistant-Moduls (playing, paused,
 * idle …), Weiter und Zurück an einer Änderung von Wiedergabewert, Titel oder Interpret.
 * Mitschnitt nuc_2026-10-10_wiedergabe.json: Sonos Wintergarten über das Sonos-Modul und über das
 * HomeAssistant-Modul, mit Burkhards OK hörbar gesteuert; die Reaktionen je Befehl stehen in „reaktion“.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-10_wiedergabe.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function rufe(array $werte, string $werkzeug, array $argumente, ?array $uebersetzungen = null): array
{
    global $anlage, $liste;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    try {
        $idx = new DeviceIndex($liste, $anlage);
        return $werkzeug === 'get' ? $idx->getState($argumente) : $idx->setState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$gesendet = static function (int $variable): array {
    global $anlage;
    return array_values(array_map(static fn (array $q): mixed => $q[1], array_filter($anlage->requests, static fn (array $q): bool => $q[0] === $variable)));
};
// Beide spielen: Sonos-Modul Status 2, HA-Statustext „playing“.
$spielen = $werte;
$spielen['27341']['wert'] = 2;
$spielen['45169']['wert'] = 'playing';

/* --- Angebot -------------------------------------------------------------------------------------- */

pruefe(array_slice(DeviceIndex::COMMANDS, -4) === ['play', 'pause', 'next', 'previous'], 'neue Befehle hinten in der Liste: ' . json_encode(DeviceIndex::COMMANDS));
pruefe(in_array('media', DeviceIndex::CONTROLLABLE, true), 'ein Mediaplayer ist steuerbar');
pruefe(DeviceIndex::abilitiesOf($liste[0]) === ['stop', 'play', 'pause', 'next', 'previous', 'volume', 'volume_change', 'mute'], 'mit Wiedergabe: ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));
pruefe(DeviceIndex::abilitiesOf(['ReadOnly' => true] + $liste[0]) === [], 'nur abfragen versteht nichts');
pruefe(DeviceIndex::offeredAbilities($liste) === ['on', 'off', 'stop', 'play', 'pause', 'next', 'previous', 'volume', 'volume_change', 'mute'], 'das Schema bietet die Befehle an, on/off wegen des Statustexts als Ein/Aus: ' . json_encode(DeviceIndex::offeredAbilities($liste)));

/* --- Statustext des HomeAssistant-Moduls ---------------------------------------------------------- */

$r = rufe($werte, 'get', ['room' => 'Wintergarten']);
pruefe($zeile($r, 2) === 'Wintergarten | Sonos über HA | media | paused "Can\'t Get Enough" by Bad Company (volume 20 %)', 'der Statustext „paused“ gibt den Zustand: ' . $r['text']);
pruefe($zeile($r, 1) === 'Wintergarten | Sonos | media | paused (volume 20 %)', 'Sonos-Modul pausiert: ' . $r['text']);
$r = rufe($spielen, 'get', ['room' => 'Wintergarten', 'active_only' => true]);
pruefe(str_starts_with($r['text'], '2 of 2 checked devices active'), 'beide spielen und zählen als aktiv: ' . $r['text']);
$aus = $werte;
$aus['45169']['wert'] = 'off';
$r = rufe($aus, 'get', ['name' => 'Sonos über HA']);
pruefe($zeile($r, 1) === 'Wintergarten | Sonos über HA | media | off', 'Statustext „off“ heißt aus: ' . $r['text']);
$aus['45169']['wert'] = 'idle';
$r = rufe($aus, 'get', ['name' => 'Sonos über HA'], $de);
pruefe($zeile($r, 1) === 'Wintergarten | Sonos über HA | media | bereit (Lautstärke 20 %)', 'Statustext „idle“ heißt bereit, ohne Titel: ' . $r['text']);

/* --- Play und Pause ------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Sonos', 'command' => 'play']);
pruefe(!$r['isError'] && $gesendet(27341) === [2], 'Play als 2 an das Sonos-Modul: ' . json_encode($anlage->requests));
pruefe($zeile($r, 0) === '1 of 1 devices are now playing back (name=Sonos).' && $zeile($r, 1) === 'Wintergarten | Sonos | media | playing (volume 20 %)', 'nach 100 ms bestätigt: ' . $r['text']);
pruefe($anlage->slept <= 200, 'die Antwort wartet nur bis zur Bestätigung: ' . $anlage->slept . ' ms');
$r = rufe($werte, 'set', ['name' => 'Sonos', 'command' => 'pause']);
pruefe($gesendet(27341) === [] && $zeile($r, 1) === 'Wintergarten | Sonos | media | already paused', 'schon pausiert, nichts gesendet: ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Sonos über HA', 'command' => 'play'], $de);
pruefe($gesendet(11085) === [2] && $zeile($r, 0) === '1 von 1 Geräten sind jetzt in Wiedergabe (name=Sonos über HA).', 'über HA bestätigt der Statustext nach 900 ms: ' . $r['text']);
pruefe($zeile($r, 1) === 'Wintergarten | Sonos über HA | media | spielt „Can\'t Get Enough“ von Bad Company (Lautstärke 20 %)', 'die Zeile nennt Titel und Interpret: ' . $r['text']);
// Gemessen: Nach Pause über HA stand in 3 s kein „paused“ im Statustext.
$r = rufe($spielen, 'set', ['name' => 'Sonos über HA', 'command' => 'pause']);
pruefe($gesendet(11085) === [3] && $zeile($r, 0) === '0 of 1 devices are now paused, 1 not confirmed yet (name=Sonos über HA).', 'Pause über HA bleibt unbestätigt: ' . $r['text']);
// Gruppe: Das Sonos-Modul spielt, der Player über HA ist pausiert.
$gemischt = $werte;
$gemischt['27341']['wert'] = 2;
$r = rufe($gemischt, 'set', ['room' => 'Wintergarten', 'command' => 'pause']);
pruefe($gesendet(27341) === [3] && $gesendet(11085) === [] && $zeile($r, 0) === '2 of 2 devices are now paused (room=Wintergarten).', 'in der Gruppe pausiert nur, wer spielt: ' . $r['text']);

/* --- Weiter und Zurück ---------------------------------------------------------------------------- */

$r = rufe($spielen, 'set', ['name' => 'Sonos', 'command' => 'next']);
pruefe($gesendet(27341) === [4] && $zeile($r, 0) === '1 of 1 devices are now at the next track (name=Sonos).', 'Weiter: der Status springt auf -1, das bestätigt: ' . $r['text']);
$r = rufe($spielen, 'set', ['name' => 'Sonos über HA', 'command' => 'next']);
pruefe($gesendet(11085) === [4] && $zeile($r, 1) === 'Wintergarten | Sonos über HA | media | playing "Rock Steady" by Bad Company (volume 20 %)', 'Weiter über HA: neuer Titel nach 1,6 s: ' . $r['text']);
$r = rufe($spielen, 'set', ['name' => 'Sonos', 'command' => 'previous']);
pruefe($gesendet(27341) === [0] && $zeile($r, 1) === 'Wintergarten | Sonos | media | playing "Can\'t Get Enough" by Bad Company (volume 20 %)', 'Zurück: der Titel kommt nach 1,6 s: ' . $r['text']);
$weiter = $spielen;
$weiter['53862']['wert'] = 'Rock Steady';
$r = rufe($weiter, 'set', ['name' => 'Sonos über HA', 'command' => 'previous']);
pruefe($zeile($r, 0) === '0 of 1 devices are now at the previous track, 1 not confirmed yet (name=Sonos über HA).', 'Zurück über HA: der Titel kam erst nach 5,5 s: ' . $r['text']);

/* --- Absagen -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Sonos', 'command' => 'on']);
pruefe($r['isError'] && $anlage->requests === [] && str_contains($r['text'], 'cannot do "on"'), 'ein- und ausschalten kommt erst mit Stufe 3: ' . $r['text']);
$fremd = $werte;
$fremd['27341']['bereich']['profil'] = 'SONOS.Status';
$r = rufe($fremd, 'set', ['name' => 'Sonos', 'command' => 'play']);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Wintergarten | Sonos | media | cannot be controlled (unknown playback profile SONOS.Status)', 'unbekanntes Profil: nichts gesendet: ' . $r['text']);

/* --- Übersetzungen -------------------------------------------------------------------------------- */

foreach (['idle', 'standby', 'buffering', 'at the next track', 'at the previous track', 'already paused', 'already playing', 'already stopped', 'playing back', 'cannot be controlled (unknown playback profile %s)', 'cannot be controlled (playback has no action)', 'does not support "%s"'] as $text) {
    pruefe(isset($de[$text]), 'deutsche Übersetzung für „' . $text . '“');
}

ende('Wiedergabe');
