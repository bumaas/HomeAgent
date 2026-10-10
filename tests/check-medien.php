<?php

declare(strict_types=1);

/*
 * Mediaplayer, Stufe 1: nur abfragen (Plan 10.10.2026, Umfang wie die Medien-Intents von Home Assistant).
 * Typ „media“ mit den Fähigkeiten Ein/Aus („state“), Wiedergabe, Lautstärke, Stumm, Titel und Interpret.
 * Die Werte der Wiedergabe hängen am Profil: ~Playback… zählt 0 Zurück, 1 Stop, 2 Play, 3 Pause, 4 Weiter,
 * der eingebaute Media Player (~PlayerStatus) 0 Prev, 1 Play, 2 Pause, 3 Stop, 4 Weiter (beides am nuc gelesen).
 * Mitschnitt nuc_2026-10-10_medien.json; zu der Zeit spielte kein Player, „spielt“ ist angenommen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-10_medien.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function frage(array $werte, array $argumente, ?array $uebersetzungen = null): array
{
    global $liste;
    try {
        return (new DeviceIndex($liste, new FakeBackend($werte, $uebersetzungen)))->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeilen = static fn (array $r): array => explode("\n", $r['text']);

/* --- Typ und Fähigkeiten -------------------------------------------------------------------------- */

pruefe(array_key_exists('media', DeviceIndex::TYPES), 'Typ media');
$rollen = ['playback' => 'PlaybackVariableID', 'volume' => 'VolumeVariableID', 'mute' => 'MuteVariableID', 'title' => 'TitleVariableID', 'artist' => 'ArtistVariableID'];
pruefe(array_intersect_assoc($rollen, DeviceIndex::ROLES) === $rollen, 'Fähigkeiten mit ihren Schlüsseln: ' . json_encode(DeviceIndex::ROLES));
pruefe((DeviceIndex::TYPE_ROLES['media'] ?? null) === ['roles' => ['state', 'playback', 'volume', 'mute', 'title', 'artist'], 'required' => ['state', 'playback', 'volume']], 'Mediaplayer: Ein/Aus, Wiedergabe oder Lautstärke ist Pflicht');
pruefe(DeviceIndex::rolesOf($liste[0]) === ['playback' => 11085, 'volume' => 31588, 'mute' => 21842, 'title' => 53862, 'artist' => 12831], 'Zeile liest alle Fähigkeiten: ' . json_encode(DeviceIndex::rolesOf($liste[0])));
pruefe(DeviceIndex::abilitiesOf($liste[1]) === ['stop', 'play', 'pause', 'next', 'previous'], 'seit Stufe 2 versteht ein Mediaplayer die Wiedergabebefehle: ' . json_encode(DeviceIndex::abilitiesOf($liste[1])));

/* --- Zustand -------------------------------------------------------------------------------------- */

$r = frage($werte, ['type' => 'media']);
$z = $zeilen($r);
pruefe(!$r['isError'] && $z[0] === '6 device(s) found (type=media).', 'alle sechs Player: ' . $r['text']);
// HomeAssistant-Modul: Wiedergabe ist nur Befehl (-1), also kein Wort zum Zustand, aber Titel und Interpret.
pruefe(in_array('Wintergarten | Sonos | media | "Can\'t Get Enough" by Bad Company (volume 20 %)', $z, true), 'Sonos über HA ohne Wiedergabezustand, Lautstärke 0 bis 1 in Prozent: ' . $r['text']);
pruefe(in_array('Musikzimmer | Echo | media | paused (volume 37 %)', $z, true), 'Echo pausiert, leerer Titel fällt weg: ' . $r['text']);
pruefe(in_array('Wohnzimmer | Sonos | media | paused (volume 100 %)', $z, true), 'Sonos-Modul pausiert: ' . $r['text']);
pruefe(in_array('- | Media Player | media | stopped (volume 22 %)', $z, true), '~PlayerStatus zählt anders, 3 ist Stop: ' . $r['text']);
pruefe(in_array('Wohnzimmer | Fernseher | media | Standby (volume 65 %)', $z, true), 'Ein/Aus als Ganzzahl bleibt der Anzeigetext: ' . $r['text']);
pruefe(in_array('Wohnzimmer | Receiver | media | on', $z, true), 'Receiver nur mit Ein/Aus: ' . $r['text']);

// Angenommen: derselbe Echo spielt, mit Titel, Interpret und stumm.
$spielt = $werte;
$spielt['28976']['wert'] = 2;
$spielt['52178']['wert'] = 'Can\'t Get Enough';
$spielt['50164']['wert'] = 'Bad Company';
$spielt['30516']['wert'] = true;
$r = frage($spielt, ['name' => 'Echo']);
pruefe(($zeilen($r)[1] ?? '') === 'Musikzimmer | Echo | media | playing "Can\'t Get Enough" by Bad Company (volume 37 %, muted)', 'spielt mit Titel, Interpret und stumm: ' . $r['text']);
$spielt['50164']['wert'] = '';
$r = frage($spielt, ['name' => 'Echo']);
pruefe(($zeilen($r)[1] ?? '') === 'Musikzimmer | Echo | media | playing "Can\'t Get Enough" (volume 37 %, muted)', 'ohne Interpret nur der Titel: ' . $r['text']);
$r = frage($spielt, ['name' => 'Echo'], $de);
pruefe(($zeilen($r)[1] ?? '') === 'Musikzimmer | Echo | media | spielt „Can\'t Get Enough“ (Lautstärke 37 %, stumm)', 'deutsch: ' . $r['text']);
$r = frage($werte, ['room' => 'Wintergarten', 'type' => 'media'], $de);
pruefe(($zeilen($r)[1] ?? '') === 'Wintergarten | Sonos | media | „Can\'t Get Enough“ von Bad Company (Lautstärke 20 %)', 'deutsch ohne Zustand: ' . $r['text']);

$aus = $werte;
$aus['35575']['wert'] = false;
$r = frage($aus, ['name' => 'Receiver'], $de);
pruefe(($zeilen($r)[1] ?? '') === 'Wohnzimmer | Receiver | media | aus', 'Receiver aus: ' . $r['text']);

/* --- nur aktive ----------------------------------------------------------------------------------- */

// Aktiv heißt: spielt, oder eingeschaltet ohne bekannten Wiedergabezustand. Pausiert und gestoppt sind
// nicht aktiv; ohne Wiedergabezustand und ohne Wahrheitswert für Ein/Aus wird nicht gezählt.
$r = frage($werte, ['type' => 'media', 'active_only' => true]);
$z = $zeilen($r);
pruefe($z[0] === '1 of 4 checked devices active (type=media).' && ($z[1] ?? '') === 'Wohnzimmer | Receiver | media | on', 'nur der Receiver ist aktiv: ' . $r['text']);
pruefe(end($z) === '2 device(s) without on/off were not counted (media).', 'Sonos über HA und Fernseher nicht gezählt: ' . $r['text']);
$r = frage($spielt, ['type' => 'media', 'active_only' => true]);
pruefe(str_starts_with($r['text'], '2 of 4 checked devices active'), 'ein spielender Player ist aktiv: ' . $r['text']);

/* --- Übersetzungen -------------------------------------------------------------------------------- */

foreach (['playing', 'paused', 'stopped', 'muted', 'volume %s', '"%s"', '"%s" by %s'] as $text) {
    pruefe(isset($de[$text]), 'deutsche Übersetzung für „' . $text . '“');
}

ende('Mediaplayer');
