<?php

declare(strict_types=1);

/*
 * Werkzeug show_chart (Backlog B63, Burkhard 12.10.2026): der Verlauf von Messwerten als Diagramm in der Claude-App
 * (MCP Apps, Erweiterung io.modelcontextprotocol/ui). Das Modell bekommt nur die Kurzfassung (Kopfzeile, je Messwert
 * Minimum und Maximum), die Stundenwerte gehen als structuredContent an die Seite des Diagramms. Das spart Tokens.
 * Mitschnitt nuc_2026-10-10_verlauf.json.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-10_verlauf.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function diagramm(array $argumente, ?array $uebersetzungen = null): array
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage);
    if (!method_exists($index, 'chart')) {
        return ['text' => 'FEHLT', 'isError' => true];
    }
    return $index->chart($argumente);
}

/* --- Eine Woche Temperatur im Gästezimmer ------------------------------------------------------- */

$r = diagramm(['room' => 'Gast', 'type' => 'temperature', 'period' => 'last_week']);
$zeilen = explode("\n", $r['text']);
pruefe(!$r['isError'] && str_starts_with($zeilen[0], 'History of 1 measured value(s) (room=Gast, type=temperature, period=last_week)'), 'Kopfzeile wie beim Verlauf: ' . $zeilen[0]);
pruefe(str_starts_with($zeilen[1] ?? '', 'Gast | Temperatur | temperature | minimum '), 'Gerätezeile mit Minimum und Maximum: ' . ($zeilen[1] ?? ''));
pruefe(!array_any($zeilen, static fn (string $z): bool => preg_match('/^\d{4}-\d\d-\d\d /', $z) === 1), 'keine Tabellenzeilen für das Modell: ' . $r['text']);
pruefe(end($zeilen) === 'The chart is shown in the chat.', 'Schlusszeile sagt, dass das Diagramm angezeigt wird: ' . end($zeilen));
$daten = $r['structuredContent'] ?? [];
pruefe(($daten['kind'] ?? null) === 'history' && ($daten['per'] ?? null) === 'day', 'Art und Stufe: ' . json_encode(array_intersect_key($daten, ['kind' => 0, 'per' => 0])));
$reihe = $daten['series'][0] ?? [];
pruefe(($reihe['room'] ?? null) === 'Gast' && ($reihe['name'] ?? null) === 'Temperatur' && ($reihe['unit'] ?? null) === '°C', 'Reihe mit Raum, Name und Einheit: ' . json_encode(array_diff_key($reihe, ['points' => 0])));
$tage = array_values(array_filter($mitschnitt['variablen']['41351']['verlauf']['1'], static fn (array $t): bool => date('Y-m-d', $t[0]) >= '2026-09-28' && date('Y-m-d', $t[0]) <= '2026-10-04'));
usort($tage, static fn (array $x, array $y): int => $x[0] <=> $y[0]); // die Fixture steht neueste zuerst, das Diagramm aufsteigend
pruefe(count($reihe['points'] ?? []) === 7 && ($reihe['points'][0] ?? null) === [$tage[0][0], (float)$tage[0][1], (float)$tage[0][2], (float)$tage[0][3]], 'sieben Tage, je [Zeit, Minimum, Mittel, Maximum] wie im Archiv: ' . json_encode($reihe['points'][0] ?? null));
pruefe(($daten['labels']['minimum'] ?? null) === 'minimum' && ($daten['labels']['maximum'] ?? null) === 'maximum' && ($daten['labels']['average'] ?? null) === 'average', 'Beschriftungen für die Seite: ' . json_encode($daten['labels'] ?? null));
pruefe(($daten['title'] ?? null) === $zeilen[0], 'Titel ist die Kopfzeile');
pruefe(($daten['labels']['band'] ?? null) === 'band from minimum to maximum', 'Beschriftung für den Schalter des Bands (Burkhard 12.10.2026: das Band ist nicht immer gewollt): ' . json_encode($daten['labels'] ?? null));

$r = diagramm(['room' => 'Gast', 'type' => 'temperature', 'period' => 'last_week'], $de);
pruefe(($r['structuredContent']['labels']['minimum'] ?? null) === 'Minimum' && str_ends_with($r['text'], 'Das Diagramm steht im Chat.'), 'deutsch: ' . json_encode($r['structuredContent']['labels'] ?? null) . ' / ' . $r['text']);

/* --- Mehrere Messwerte, Standardzeitraum, Absagen ------------------------------------------------- */

$r = diagramm(['room' => 'Gast', 'period' => 'last_week']);
pruefe(count($r['structuredContent']['series'] ?? []) === 2, 'Temperatur und Luftfeuchte im Gästezimmer: ' . count($r['structuredContent']['series'] ?? []));
$r = diagramm(['name' => 'Außentemperatur']);
pruefe(!$r['isError'] && str_contains(explode("\n", $r['text'])[0], 'period=today') && ($r['structuredContent']['per'] ?? null) === 'hour', 'ohne Zeitraum: heute, stündlich: ' . explode("\n", $r['text'])[0]);
$r = diagramm(['room' => 'Gast', 'period' => 'now']);
pruefe($r['isError'] && !isset($r['structuredContent']), 'period=now ergibt kein Diagramm: ' . $r['text']);
$r = diagramm(['room' => 'Gast', 'type' => 'window', 'period' => 'last_week']);
pruefe($r['isError'] && !isset($r['structuredContent']), 'ohne Messwert kein Diagramm: ' . $r['text']);

/* --- Mehrere Räume in einem Diagramm (Claude-App 12.10.2026: Vergleich Gästezimmer und Simone über get_state) --- */

$r = diagramm(['room' => 'Gast, Wintergarten', 'type' => 'temperature', 'period' => 'last_week']);
$raeume = array_column($r['structuredContent']['series'] ?? [], 'room');
pruefe(!$r['isError'] && $raeume === ['Gast', 'Wintergarten'], 'zwei Räume in einem Diagramm (der Heizkörper im Wintergarten hat keinen archivierten Verlauf, wie am nuc): ' . json_encode($raeume));
pruefe(str_contains($r['text'], 'room=Gast,') && str_contains($r['text'], 'room=Wintergarten,') && substr_count($r['text'], 'The chart is shown in the chat.') === 1, 'je Raum Kopfzeile und Gerätezeilen, die Schlusszeile einmal: ' . $r['text']);
pruefe(($r['structuredContent']['per'] ?? null) === 'day', 'gemeinsame Stufe');
$r = diagramm(['room' => 'Gast, Badezimmer', 'type' => 'temperature', 'period' => 'last_week']);
pruefe(!$r['isError'] && array_column($r['structuredContent']['series'] ?? [], 'room') === ['Gast'] && str_contains($r['text'], 'No released device matches room=Badezimmer'), 'ein unbekannter Raum: die anderen werden gezeichnet, der Text nennt ihn: ' . $r['text']);
$r = diagramm(['room' => 'Badezimmer, Dachboden', 'period' => 'last_week']);
pruefe($r['isError'] && !isset($r['structuredContent']), 'kein Raum bekannt: Fehler ohne Diagramm: ' . $r['text']);
$r = diagramm(['room' => 'Gast, Wintergarten, Keller, Wohnzimmer, Garten', 'period' => 'last_week']);
pruefe($r['isError'] && str_contains($r['text'], 'at most 5'), 'mehr als fünf Messwerte über alle Räume: Absage: ' . $r['text']);

/* --- get_state bleibt, wie es ist --------------------------------------------------------------- */

$anlage = new FakeBackend($mitschnitt['variablen']);
$anlage->jetzt = $mitschnitt['jetzt'];
$r = (new DeviceIndex($mitschnitt['geraete'], $anlage))->getState(['room' => 'Gast', 'type' => 'temperature', 'period' => 'last_week']);
pruefe(count(explode("\n", $r['text'])) === 9 && !isset($r['structuredContent']), 'get_state liefert weiter die Tabelle und keine Daten fürs Diagramm');

ende('Diagramm');
