<?php

declare(strict_types=1);

/*
 * Haken „Umgekehrt“ bei Meldern mit einer Ganzzahl (Burkhard 14.10.2026, Anlass Forum: Fenstergriffe über Matter).
 * Fenster, Tür, Präsenz und Wasser gelten bei einer Ganzzahl als aktiv, sobald der Wert über 0 liegt. Der Türstatus
 * von Matter zählt aber 0 Geöffnet, 1 Geschlossen, 2 bis 5 Sonderfälle; die Claude-App meldete deshalb geschlossene
 * Fenster als „offen (Geschlossen)“. Der Haken wirkte bisher nur auf Wahrheitswerte. Mit Haken gilt jetzt 0 als
 * aktiv, und die Zeile nennt den Eintrag in Klammern, wenn er mehr sagt als offen oder geschlossen.
 *
 * Echt: die Einträge und die falsche Antwort aus dem Forumsbeitrag (forum_2026-10-14_tuerstatus.json) sowie die
 * Haustür vom nuc mit Rohwerten (nuc_2026-10-13_zeitleiste.json, 0 Geschlossen, 1 Geöffnet). Angenommen: IDs,
 * Namen und die Darstellung der Griffe, Einzelheiten in der Fixture.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$griffe = fixture('forum_2026-10-14_tuerstatus.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** get_state mit beiden Griffen auf $wert, wahlweise mit Haken. */
function griffe(array $griffe, int $wert, bool $umgekehrt, array $argumente = [], ?array $sprache = null): string
{
    $variablen = $griffe['variablen'];
    foreach ($variablen as $id => $v) {
        $variablen[$id]['wert'] = $wert;
    }
    $liste = array_map(static fn (array $g): array => array_merge($g, ['Invert' => $umgekehrt]), $griffe['geraete']);
    try {
        return (new DeviceIndex($liste, new FakeBackend($variablen, $sprache)))->getState($argumente + ['room' => 'Wohnzimmer'])['text'];
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage();
    }
}

$zeile = static fn (string $text, string $name): string => (string)(array_values(array_filter(explode("\n", $text), static fn (string $z): bool => str_contains($z, '| ' . $name . ' |')))[0] ?? '');

/* --- Ohne Haken bleibt es bei „über 0 = offen“ (so stand es vor dem Fix) --------------------------------------- */

$text = griffe($griffe, 1, false, [], $de);
pruefe($zeile($text, 'Terrassentür') === 'Wohnzimmer | Terrassentür | window | offen (Geschlossen)', 'ohne Haken: 1 zählt als offen, wie im Forum gemeldet: ' . $text);

/* --- Mit Haken: 0 ist offen, alles andere zu, der Eintrag steht in Klammern ------------------------------------- */

$erwartet = [
    0 => 'offen',
    1 => 'geschlossen',
    2 => 'geschlossen (Blockiert)',
    3 => 'geschlossen (Gewaltsam geöffnet)',
    4 => 'geschlossen (Unbekannter Fehler)',
    5 => 'geschlossen (Angelehnt)',
];
foreach ($erwartet as $wert => $zustand) {
    $text = griffe($griffe, $wert, true, [], $de);
    pruefe($zeile($text, 'Terrassentür') === 'Wohnzimmer | Terrassentür | window | ' . $zustand, "mit Haken, Wert $wert: $zustand: " . $text);
}
$text = griffe($griffe, 1, true);
pruefe($zeile($text, 'Terrassentür') === 'Wohnzimmer | Terrassentür | window | closed', 'englisch: „Geschlossen“ ist kein Zusatz zu closed: ' . $text);
$text = griffe($griffe, 0, true);
pruefe($zeile($text, 'Terrassentür') === 'Wohnzimmer | Terrassentür | window | open', 'englisch: „Geöffnet“ ist kein Zusatz zu open: ' . $text);

/* --- Gezählt wird mit dem Haken: „Welche Fenster sind offen?“ ------------------------------------------------- */

$text = griffe($griffe, 1, true, ['active_only' => true], $de);
pruefe($zeile($text, 'Terrassentür') === '' && $zeile($text, 'Fenster Wohnzimmer') === '', 'mit Haken und Wert 1 ist kein Fenster offen: ' . $text);
$text = griffe($griffe, 0, true, ['active_only' => true], $de);
pruefe($zeile($text, 'Terrassentür') !== '' && $zeile($text, 'Fenster Wohnzimmer') !== '', 'mit Haken und Wert 0 sind beide offen: ' . $text);

/* --- Zeitleiste: die Haustür vom nuc mit Haken (0 Geschlossen gilt dann als offen) ------------------------------ */

$mitschnitt = fixture('nuc_2026-10-13_zeitleiste.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$haustuer = array_values(array_filter($mitschnitt['geraete'], static fn (array $g): bool => $g['Name'] === 'Haustür'));
$zeitleiste = static function (bool $umgekehrt) use ($mitschnitt, $haustuer): string {
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        return (new DeviceIndex([array_merge($haustuer[0], ['Invert' => $umgekehrt])], $anlage))->getState(['name' => 'Haustür', 'period' => 'yesterday'])['text'];
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage();
    }
};
$text = $zeitleiste(false);
pruefe(in_array('Flur | Haustür | door | open 6 min, closed 23 h 54 min', explode("\n", $text), true), 'Zeitleiste ohne Haken wie bisher: ' . $text);
$text = $zeitleiste(true);
pruefe(in_array('Flur | Haustür | door | open 23 h 54 min, closed 6 min', explode("\n", $text), true), 'Zeitleiste mit Haken: Zeiten getauscht: ' . $text);

/* --- Der Haken dreht keine Ganzzahl eines schaltbaren Geräts (Dimmer ohne Schalter) ----------------------------- */

$dimmer = new DeviceIndex([['Room' => 'Wohnzimmer', 'Name' => 'Dimmer', 'Aliases' => '', 'Type' => 'light', 'Invert' => true, 'LevelVariableID' => 10003]],
    new FakeBackend(['10003' => ['name' => 'Stufe', 'wert' => 0, 'aktion' => true, 'bereich' => ['min' => 0, 'max' => 100]]]));
$text = $dimmer->getState(['room' => 'Wohnzimmer'])['text'];
pruefe(str_contains($text, '| Dimmer | light | off'), 'Dimmer auf 0 bleibt aus, auch mit Haken: ' . $text);

ende('Ganzzahl bei Meldern mit Haken Umgekehrt');
