<?php

declare(strict_types=1);

/*
 * Mediaplayer, Stufe 3: Lautstärke (volume in Prozent), lauter und leiser (volume_change in Punkten), Stumm
 * (mute) und „weiter“ ohne Raum (command=play allein setzt fort, was in der letzten Stunde per Werkzeug
 * pausiert wurde), wie HassSetVolume, HassSetVolumeRelative, HassMediaPlayerMute/Unmute und HassMediaUnpause
 * bei Home Assistant.
 * Mitschnitt nuc_2026-10-11_lautstaerke.json: Sonos Wintergarten über das Sonos-Modul (Lautstärke 0 bis 100)
 * und über das HomeAssistant-Modul (Lautstärke 0 bis 1), Sonos Bad ohne Stummvariable.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-11_lautstaerke.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function rufe(array $werte, array $argumente, ?array $uebersetzungen = null): array
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
$gesendet = static function (int $variable): array {
    global $anlage;
    return array_values(array_map(static fn (array $q): mixed => $q[1], array_filter($anlage->requests, static fn (array $q): bool => $q[0] === $variable)));
};
$wintergarten = ['room' => 'Wintergarten', 'name' => 'Sonos'];
$ha = ['name' => 'Sonos über HA'];

/* --- Angebot -------------------------------------------------------------------------------------- */

pruefe(DeviceIndex::abilitiesOf($liste[0]) === ['on', 'off', 'stop', 'play', 'pause', 'next', 'previous', 'volume', 'volume_change', 'mute'], 'mit Lautstärke und Stumm: ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));
pruefe(DeviceIndex::abilitiesOf($liste[2]) === ['on', 'off', 'stop', 'play', 'pause', 'next', 'previous', 'volume', 'volume_change'], 'Bad ohne Stumm: ' . json_encode(DeviceIndex::abilitiesOf($liste[2])));
$nurLaut = ['Room' => 'Bad', 'Name' => 'Box', 'Type' => 'media', 'Invert' => false, 'VolumeVariableID' => 47436];
pruefe(DeviceIndex::abilitiesOf($nurLaut) === ['volume', 'volume_change'], 'nur Lautstärke: ' . json_encode(DeviceIndex::abilitiesOf($nurLaut)));
pruefe(array_slice(DeviceIndex::offeredAbilities($liste), -3) === ['volume', 'volume_change', 'mute'], 'das Schema bietet die Felder an: ' . json_encode(DeviceIndex::offeredAbilities($liste)));

/* --- Lautstärke ----------------------------------------------------------------------------------- */

$r = rufe($werte, $wintergarten + ['volume' => 30]);
pruefe(!$r['isError'] && $gesendet(27915) === [30], 'Sonos-Modul bekommt 30: ' . json_encode($anlage->requests));
pruefe(str_starts_with($zeile($r, 0), '1 of 1 devices are now at volume 30 %') && $zeile($r, 1) === 'Wintergarten | Sonos | media | stopped (volume 30 %)', 'bestätigt: ' . $r['text']);
$r = rufe($werte, $ha + ['volume' => 30], $de);
pruefe($gesendet(31588) === [0.3], 'HomeAssistant-Modul bekommt 0.3 (Bereich 0 bis 1): ' . json_encode($anlage->requests));
pruefe($zeile($r, 0) === '1 von 1 Geräten sind jetzt bei Lautstärke 30 % (name=Sonos über HA).' && $zeile($r, 1) === 'Wintergarten | Sonos über HA | media | pausiert „Can\'t Get Enough“ von Bad Company (Lautstärke 30 %)', 'nach 400 ms bestätigt: ' . $r['text']);
$r = rufe($werte, $ha + ['volume' => 20]);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Wintergarten | Sonos über HA | media | already set to volume 20 %', 'schon 20 %, nichts gesendet: ' . $r['text']);
$r = rufe($werte, $ha + ['volume' => 33.3]);
pruefe($gesendet(31588) === [0.33], 'auf ganze Prozent gerundet: ' . json_encode($anlage->requests));

/* --- Lauter und leiser ---------------------------------------------------------------------------- */

$r = rufe($werte, ['room' => 'Wintergarten', 'type' => 'media', 'volume_change' => 10]);
pruefe($gesendet(27915) === [30] && $gesendet(31588) === [0.3], 'beide um 10 Punkte lauter: ' . json_encode($anlage->requests));
pruefe($zeile($r, 0) === '2 of 2 devices are now louder (room=Wintergarten, type=media).', 'Kopfzeile: ' . $r['text']);
$r = rufe($werte, $wintergarten + ['volume_change' => -30], $de);
pruefe($gesendet(27915) === [0] && $zeile($r, 0) === '1 von 1 Geräten sind jetzt leiser (room=Wintergarten, name=Sonos).' && $zeile($r, 1) === 'Wintergarten | Sonos | media | angehalten (Lautstärke 0 %)', 'nicht unter 0: ' . $r['text']);
$laut = $werte;
$laut['27915']['wert'] = 100;
$r = rufe($laut, $wintergarten + ['volume_change' => 10]);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Wintergarten | Sonos | media | already set to volume 100 %', 'schon ganz laut: ' . $r['text']);

/* --- Stumm ---------------------------------------------------------------------------------------- */

$r = rufe($werte, $wintergarten + ['mute' => true]);
pruefe($gesendet(28490) === [true] && $zeile($r, 0) === '1 of 1 devices are now muted (room=Wintergarten, name=Sonos).' && $zeile($r, 1) === 'Wintergarten | Sonos | media | stopped (volume 20 %, muted)', 'stumm geschaltet: ' . $r['text']);
$r = rufe($werte, $ha + ['mute' => false], $de);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Wintergarten | Sonos über HA | media | war schon nicht stumm', 'war nicht stumm, nichts gesendet: ' . $r['text']);
$stumm = $werte;
$stumm['21842']['wert'] = true;
$r = rufe($stumm, $ha + ['mute' => false], $de);
pruefe($gesendet(21842) === [false] && $zeile($r, 0) === '1 von 1 Geräten sind jetzt nicht mehr stumm (name=Sonos über HA).', 'Ton wieder an: ' . $r['text']);
$r = rufe($werte, ['room' => 'Bad', 'mute' => true]);
pruefe($r['isError'] && $anlage->requests === [] && str_contains($r['text'], 'understands "mute"'), 'Bad hat keine Stummvariable: ' . $r['text']);

/* --- Absagen -------------------------------------------------------------------------------------- */

foreach ([
    [['volume' => 150], 'Parameter "volume" must be a number from 0 to 100.'],
    [['volume_change' => 0], 'Parameter "volume_change" must be a number from -100 to 100 other than 0.'],
    [['mute' => 'ja'], 'Parameter "mute" must be true or false.'],
    [['volume' => 30, 'volume_change' => 10], 'Nothing was changed. Give volume or volume_change alone or with command play, pause or stop, and mute alone.'],
    [['volume' => 30, 'command' => 'on'], 'Nothing was changed. Give volume or volume_change alone or with command play, pause or stop, and mute alone.'],
    [['volume' => 30, 'command' => 'next'], 'Nothing was changed. Give volume or volume_change alone or with command play, pause or stop, and mute alone.'],
    [['mute' => true, 'command' => 'play'], 'Nothing was changed. Give volume or volume_change alone or with command play, pause or stop, and mute alone.'],
    [['volume' => 30, 'percent' => 50], 'Nothing was changed. Give volume or volume_change alone or with command play, pause or stop, and mute alone.'],
] as [$argumente, $erwartet]) {
    $r = rufe($werte, $wintergarten + $argumente);
    pruefe($r['isError'] && $anlage->requests === [] && $r['text'] === $erwartet, json_encode($argumente) . ': ' . $r['text']);
}

/* --- Lautstärke zusammen mit Play, Pause oder Stopp -------------------------------------------- */

// Leitbild „schnell vor günstig“ (Burkhard 12.10.2026): Im Bedienungs-Blindtest schickte der Agent pause und
// volume zusammen, die Absage kostete eine Runde im Sprachmodell. Gesendet wird zuerst die Lautstärke, damit
// „leise an“ nicht kurz laut beginnt, dann der Befehl.
$r = rufe($werte, $wintergarten + ['volume' => 15, 'command' => 'play']);
pruefe(!$r['isError'] && $anlage->requests === [[27915, 15], [27341, 2]], 'erst Lautstärke, dann play: ' . json_encode($anlage->requests) . ' ' . $r['text']);
pruefe($zeile($r, 0) === '1 of 1 devices are now playing back at volume 15 % (room=Wintergarten, name=Sonos).', 'Kopfzeile nennt beides: ' . $r['text']);
pruefe(str_starts_with($zeile($r, 1), 'Wintergarten | Sonos | media | playing') && str_contains($zeile($r, 1), '(volume 15 %)'), 'Gerätezeile mit Wiedergabe und Lautstärke: ' . $zeile($r, 1));
$r = rufe($werte, $wintergarten + ['volume' => 15, 'command' => 'play'], $de);
pruefe($zeile($r, 0) === '1 von 1 Geräten sind jetzt in Wiedergabe bei Lautstärke 15 % (room=Wintergarten, name=Sonos).', 'deutsch: ' . $r['text']);
$r = rufe($werte, $wintergarten + ['command' => 'pause', 'volume' => 20]);
pruefe($anlage->requests === [[27341, 3]] && $zeile($r, 0) === '1 of 1 devices are now paused at volume 20 % (room=Wintergarten, name=Sonos).', 'Lautstärke stimmt schon, nur pause geht hinaus: ' . json_encode($anlage->requests) . ' ' . $r['text']);
$r = rufe($werte, $wintergarten + ['command' => 'stop', 'volume_change' => -10]);
pruefe($anlage->requests === [[27915, 10]] && $zeile($r, 0) === '1 of 1 devices are now stopped, quieter (room=Wintergarten, name=Sonos).', 'schon gestoppt, nur leiser: ' . json_encode($anlage->requests) . ' ' . $r['text']);
$r = rufe($werte, ['room' => 'Wintergarten', 'type' => 'media', 'volume' => 25, 'command' => 'play']);
pruefe($gesendet(27915) === [25] && $gesendet(31588) === [0.25] && str_starts_with($zeile($r, 0), '2 of 2 devices are now playing back at volume 25 %'), 'zwei Player, beide zuerst leiser: ' . json_encode($anlage->requests) . ' ' . $r['text']);
$keinLaut = $werte;
$keinLaut['27915']['aktion'] = false;
$r = rufe($keinLaut, $wintergarten + ['volume' => 15, 'command' => 'play']);
pruefe($zeile($r, 0) === '0 of 1 devices are now playing back at volume 15 % (room=Wintergarten, name=Sonos).' && str_contains($zeile($r, 1), 'cannot be set (the volume has no action)'), 'Lautstärke nicht stellbar: der Player zählt nicht als erreicht, die Zeile sagt warum: ' . $r['text']);

/* --- Weiter ohne Raum ----------------------------------------------------------------------------- */

$anlage = new FakeBackend($werte);
$idx = new DeviceIndex($liste, $anlage);
$r = $idx->setState(['room' => 'Bad', 'command' => 'pause']);
pruefe($anlage->requests === [[39166, 3]] && str_starts_with($r['text'], '1 of 1 devices are now paused'), 'Bad pausiert: ' . $r['text']);
$anlage->requests = [];
$anlage->sleep(30 * 60 * 1000);
$r = $idx->setState(['command' => 'play']);
pruefe($anlage->requests === [[39166, 2]] && $zeile($r, 0) === '1 of 1 devices are now playing back (paused in the last hour).', 'play allein setzt den zuletzt pausierten fort: ' . $r['text']);
$anlage->requests = [];
$r = $idx->setState(['type' => 'media', 'command' => 'play']);
pruefe($anlage->requests === [] && str_contains($r['text'], 'already playing'), 'auch mit type=media allein, jetzt spielt er schon: ' . $r['text']);
$idx->setState(['room' => 'Bad', 'command' => 'pause']);
$anlage->requests = [];
$anlage->sleep(61 * 60 * 1000);
$r = $idx->setState(['command' => 'play']);
pruefe($r['isError'] && $anlage->requests === [] && str_contains($r['text'], 'give at least a room'), 'nach über einer Stunde gilt der Merker nicht mehr: ' . $r['text']);
$anlage->requests = [];
$r = $idx->setState(['command' => 'pause']);
pruefe($r['isError'] && $anlage->requests === [], 'pause allein bleibt eine Absage: ' . $r['text']);

/* --- Übersetzungen -------------------------------------------------------------------------------- */

foreach (['at volume %s', 'louder', 'quieter', 'unmuted', 'not muted', 'paused in the last hour', 'cannot be set (unknown volume range)', 'cannot be set (the volume has no action)', 'cannot be set (the mute switch has no action)', 'cannot be set (mute is not a switch)', 'Parameter "volume" must be a number from 0 to 100.', 'Parameter "volume_change" must be a number from -100 to 100 other than 0.', 'Parameter "mute" must be true or false.', 'Nothing was changed. Give volume or volume_change alone or with command play, pause or stop, and mute alone.', 'playing back', 'paused', 'stopped'] as $text) {
    pruefe(isset($de[$text]), 'deutsche Übersetzung für „' . $text . '“');
}

ende('Lautstärke');
