<?php

declare(strict_types=1);

/*
 * Drei Befunde des Nachreviews vom 15.11.2026 über die Builds 305 und 306, alle an der Zeitleiste:
 *   1  Ein Status aus Wertebereichen, von denen einer nur einen Wert umfasst, hat für das Backend auch einen Eintrag
 *      (options()). Die Farbe im Band kam dann aus den Einträgen statt aus den Wertebereichen, die übrigen Zustände
 *      bekamen sie wieder nach der Reihenfolge des Auftretens.
 *   3  Ein Diagramm ohne Linien mit mehr als zehn Zeitleisten wurde ganz abgelehnt, auch wenn das Band eines Sollwerts
 *      dabei war, das laut Regel bleibt.
 *   4  Stehen nur Sollwerte und daneben Zeitleisten anderer Geräte da, zählte die erste Zeile nur die Sollwerte.
 *
 * Mitschnitte nuc_2026-11-15_heizungsregler.json und nuc_2026-11-15_thermostat_verlauf.json (beide nur gelesen). ECHT sind
 * die Zeilen der Freigabeliste, alle Werte, die Rohwerte, die Texte der Statuswerte und die Wertebereiche der Darstellung.
 * ANGENOMMEN ist, was unten so heißt.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$regler = fixture('nuc_2026-11-15_heizungsregler.json');
$thermostat = fixture('nuc_2026-11-15_thermostat_verlauf.json');
date_default_timezone_set($regler['zeitzone']);

function frage(array $argumente, array $mitschnitt, ?array $werte = null, ?array $liste = null, string $werkzeug = 'get'): array
{
    $anlage = new FakeBackend($werte ?? $mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($liste ?? $mitschnitt['geraete'], $anlage);
        return $werkzeug === 'chart' ? $index->chart($argumente) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

$kurz = static fn (array $r): string => mb_substr($r['text'], 0, 400);
$zeilen = static fn (array $r): array => explode("\n", $r['text']);
$farben = static fn (array $band): array => array_column($band['states'] ?? [], 'color', 'label');

/* --- Befund 1, ANGENOMMEN: ein Wertebereich mit nur einem Wert ------------------------------------ */

// Echt hat der Status der Wärmepumpe #17704 fünf Bereiche (Standby 0 bis 8, Heizbetrieb, Fehler, Kühlbetrieb,
// Warmwasserbereitung). Angenommen ist, dass „Standby“ nur den Wert 0 umfasst: Dann liefert das Backend der Anlage ihn
// auch als Eintrag (intervalEntries), hier als „optionen“ nachgetragen. Die Farben bleiben die der Wertebereiche.
$einzelwert = $regler['variablen'];
$einzelwert[17704]['optionen'] = [[0.0, 'Standby']];
$r = frage(['name' => 'Status Wärmepumpe', 'period' => 'yesterday'], $regler, $einzelwert, null, 'chart');
$band = $r['structuredContent']['bands'][0] ?? [];
pruefe($farben($band) === ['Standby' => 0, 'Heizbetrieb' => 1, 'Warmwasserbereitung' => 4], 'Status mit einem Einzelwert-Bereich: Farbe weiter nach der Stelle des Wertebereichs: ' . json_encode($farben($band), JSON_UNESCAPED_UNICODE));
$r = frage(['name' => 'Status Wärmepumpe', 'period' => 'yesterday'], $regler, $einzelwert);
pruefe(str_contains($r['text'], 'Heizbetrieb 21 h 39 min, Warmwasserbereitung 1 h 22 min, Standby 59 min'), 'der Text der Zeitleiste bleibt derselbe: ' . $kurz($r));

/* --- Befunde 3 und 4, ANGENOMMEN: Thermostat nur mit Sollwert neben Fenstern mit Zeitleiste ------- */

// Echt sind das Flurfenster mit archivierter Position und der Thermostat im Musikzimmer. Angenommen ist, dass dem
// Thermostat Ist-Temperatur und Ventil fehlen (Pflicht ist nur der Sollwert) und dass Fenster wie das im Flur im
// Musikzimmer stehen: Kopien der Flur-Zeile mit eigenem Namen.
$ab = ['from' => '2026-11-14', 'to' => '2026-11-15'];
$fenster = array_values(array_filter($thermostat['geraete'], static fn (array $z): bool => $z['Room'] === 'Flur'))[0];
$heizung = array_map(static function (array $z): array {
    unset($z['StateVariableID'], $z['ValveVariableID']);
    return $z;
}, array_values(array_filter($thermostat['geraete'], static fn (array $z): bool => $z['Room'] === 'Musikzimmer' && $z['Type'] === 'thermostat')));
$mit = static fn (int $anzahl): array => array_merge($heizung, array_map(static fn (int $n): array => ['Room' => 'Musikzimmer', 'Name' => 'Fenster ' . $n, 'Aliases' => ''] + $fenster, range(1, $anzahl)));
$filter = 'room=Musikzimmer, from=2026-11-14, to=2026-11-15';

// Befund 4: Die erste Zeile zählt die Zeitleisten der Geräte und nennt den Sollwert dazu
$r = frage(['room' => 'Musikzimmer'] + $ab, $thermostat, null, $mit(2));
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'Timeline of 2 device(s) (' . $filter . '). 1 setpoint(s) as timeline.', 'erste Zeile zählt die Geräte und nennt den Sollwert: ' . ($zeilen($r)[0] ?? ''));
pruefe(count(preg_grep('/^Timeline of /', $zeilen($r))) === 1, 'nur eine Kopfzeile: ' . implode(' / ', preg_grep('/^Timeline of /', $zeilen($r))));
pruefe(count(preg_grep('/\| Fenster \d \| window \|/', $zeilen($r))) === 2 && count(preg_grep('/\| Heizung \| thermostat \| setpoint: /', $zeilen($r))) === 1, 'beide Fenster und der Sollwert stehen da: ' . $kurz($r));
$r = frage(['room' => 'Musikzimmer'] + $ab, $thermostat, null, $mit(2), 'chart');
$inhalt = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($inhalt['kind'] ?? '') === 'timeline' && ($inhalt['title'] ?? '') === 'Timeline of 2 device(s) (' . $filter . '). 1 setpoint(s) as timeline.' && count($inhalt['bands'] ?? []) === 3,
    'im Diagramm derselbe Titel über drei Bändern: ' . json_encode(['kind' => $inhalt['kind'] ?? null, 'title' => $inhalt['title'] ?? null, 'bands' => count($inhalt['bands'] ?? [])], JSON_UNESCAPED_UNICODE) . ' ' . $kurz($r));

// Befund 3: Elf Fenster sind zu viele Zeitleisten. Das Band des Sollwerts bleibt, die elf werden gezählt
$r = frage(['room' => 'Musikzimmer'] + $ab, $thermostat, null, $mit(11), 'chart');
$baender = $r['structuredContent']['bands'] ?? [];
pruefe(!$r['isError'] && array_column($baender, 'name') === ['Heizung (setpoint)'] && str_contains($r['text'], '11 timeline(s) are not in the chart, at most 10 fit.'),
    'ohne Linien: elf Zeitleisten fallen weg, der Sollwert bleibt: ' . json_encode(array_column($baender, 'name'), JSON_UNESCAPED_UNICODE) . ' ' . mb_substr($r['text'], -200));
// Gegenprobe, wie bisher: Ohne Thermostat bleibt es bei der Absage
$r = frage(['room' => 'Musikzimmer'] + $ab, $thermostat, null, array_slice($mit(11), count($heizung)), 'chart');
pruefe($r['isError'] && str_contains($r['text'], 'Too many devices (11) for a timeline chart.'), 'nur elf Zeitleisten: weiter die Absage: ' . $kurz($r));

/* --- Nachreview der Builds 307 und 308 (15.11.2026) ------------------------------------------------ */

// Befund 5: Fallen die elf Zeitleisten weg, zählt der Titel des Diagramms sie nicht mehr; er nennt, was das Diagramm zeigt
$r = frage(['room' => 'Musikzimmer'] + $ab, $thermostat, null, $mit(11), 'chart');
pruefe(($r['structuredContent']['title'] ?? '') === 'Timeline of 1 setpoint(s) (' . $filter . ').', 'Titel über dem einen Band des Sollwerts: ' . ($r['structuredContent']['title'] ?? ''));

// Befund 4, ANGENOMMEN: Eine Auswahl, deren wirksame Darstellung noch Wertebereiche mit Text aus einer früheren Variante
// trägt (Symcon lässt Parameter anderer Varianten stehen). Echt ist der Batteriemodus #54678 mit seinen fünf Einträgen und
// seinem Archiv; angenommen sind die zwei Wertebereiche „Niedrig“ und „Hoch“. Die Farben bleiben die der Einträge, die
// Texte der Wertebereiche kommen im Band nicht vor.
$auswahl = fixture('nuc_2026-10-13_zeitleiste.json');
$r = frage(['name' => 'Batteriemodus', 'period' => 'yesterday'], $auswahl, null, null, 'chart');
$echt = $farben($r['structuredContent']['bands'][0] ?? []);
pruefe($echt === ['Normal' => 0, 'Halten' => 1, 'Ladesperre' => 3], 'Auswahl am Mitschnitt: Farbe nach der Stelle des Eintrags: ' . json_encode($echt, JSON_UNESCAPED_UNICODE));
$alteBereiche = $auswahl['variablen'];
$alteBereiche[54678]['intervalle'] = [[0, 3, true, 'Niedrig'], [3, 10, true, 'Hoch']];
$alteBereiche[54678]['statustexte'] = [];
$r = frage(['name' => 'Batteriemodus', 'period' => 'yesterday'], $auswahl, $alteBereiche, null, 'chart');
pruefe($farben($r['structuredContent']['bands'][0] ?? []) === $echt, 'Auswahl mit übrig gebliebenen Wertebereichen: dieselben Farben: ' . json_encode($farben($r['structuredContent']['bands'][0] ?? []), JSON_UNESCAPED_UNICODE));

/* --- Code-Review der Builds 310 bis 312 (15.11.2026) ---------------------------------------------- */

// Befund 1, ANGENOMMEN (Spiegelbild des Falls darüber): ein echter Status, dessen Darstellung noch Einträge einer
// früheren Variante trägt. Echt ist der Status der Wärmepumpe #17704 mit Rohwerten und Texten; angenommen sind die zwei
// Einträge „Alt A“ und „Alt B“. Seit build 311 kamen die Farben dann nach dem Auftreten (Heizbetrieb 2, Standby 3), also
// je Tag anders. Die feste Liste sind die Einträge und dahinter die Texte der Wertebereiche: Jeder Zustand hat seine
// Stelle, um die zwei Einträge versetzt.
$alteEintraege = $regler['variablen'];
$alteEintraege[17704]['optionen'] = [[200, 'Alt A'], [201, 'Alt B']];
$r = frage(['name' => 'Status Wärmepumpe', 'period' => 'yesterday'], $regler, $alteEintraege, null, 'chart');
pruefe($farben($r['structuredContent']['bands'][0] ?? []) === ['Standby' => 2, 'Heizbetrieb' => 3, 'Warmwasserbereitung' => 6],
    'Status mit übrig gebliebenen Einträgen: Farbe nach der Stelle des Wertebereichs, nicht nach dem Auftreten: ' . json_encode($farben($r['structuredContent']['bands'][0] ?? []), JSON_UNESCAPED_UNICODE));
// Ein Zeitraum, der mit einem anderen Zustand beginnt, ändert die Farben nicht (ab 13:00 Warmwasserbereitung zuerst)
$r = frage(['name' => 'Status Wärmepumpe', 'from' => '2026-11-14T13:00', 'to' => '2026-11-14T18:00'], $regler, $alteEintraege, null, 'chart');
$spaeter = $farben($r['structuredContent']['bands'][0] ?? []);
pruefe(($spaeter['Warmwasserbereitung'] ?? null) === 6 && ($spaeter['Heizbetrieb'] ?? null) === 3 && ($spaeter['Standby'] ?? null) === 2,
    'dieselben Farben, wenn der Zeitraum mit einem anderen Zustand beginnt: ' . json_encode($spaeter, JSON_UNESCAPED_UNICODE));

// Befund 5, ANGENOMMEN: zwei Räume mit Komma, je ein Thermostat nur mit Sollwert und sechs Fenster (Kopien wie oben, die
// des zweiten Raums stehen im Flur). Die Zeitleisten der Fenster fallen weg, der Titel zählt zwei Sollwerte und nannte
// nur den ersten Raum.
$flur = array_map(static fn (array $z): array => ['Room' => 'Flur', 'Name' => $z['Name'] . ' Flur'] + $z, $mit(6));
$r = frage(['room' => 'Musikzimmer, Flur'] + $ab, $thermostat, null, array_merge($mit(6), $flur), 'chart');
pruefe(($r['structuredContent']['title'] ?? '') === 'Timeline of 2 setpoint(s) (room=Musikzimmer, Flur, from=2026-11-14, to=2026-11-15).', 'Titel über Sollwerten aus zwei Räumen nennt beide: ' . ($r['structuredContent']['title'] ?? $r['text']));

ende('Nachreview Zeitleiste: Statusfarbe mit Einzelwert, Sollwertband ohne Linien, erste Zeile neben Zeitleisten');
