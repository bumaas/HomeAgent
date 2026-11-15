<?php

declare(strict_types=1);

/*
 * Messwerte eines Heizungsreglers über einen Zeitraum (Heizungsanalyse 15.11.2026): Ein als Zähler archivierter Messwert
 * (Kompressorstarts, Kompressor-Laufzeit) meldete „kein Verlauf archiviert“, obwohl das Archiv den Zuwachs kennt. Ein
 * Status, dessen Darstellung jeden Wert über Wertebereiche als Text zeigt (0 bis 8 „Standby“, 8 bis 11 „Heizbetrieb“),
 * kam als Zahlenverlauf („Minimum 0, Maximum 128“) statt als Zeitleiste.
 *
 * Mitschnitt nuc_2026-11-15_heizungsregler.json (nur gelesen, 15.11.2026 10:35). ECHT sind die Zeilen der Freigabeliste,
 * alle Werte, die Rohwerte von Status, Merker und Betriebsart, die Zähleraggregate und die Texte der Statuswerte (von
 * GetValueFormattedEx an der Anlage). Die Erwartungen für den 14.11. sind von Hand aus den Rohwerten gerechnet:
 * Heizbetrieb 00:00:00-00:09:24, 00:19:24-06:34:13, 06:43:32-13:00:20, 14:22:41-16:33:31, 17:02:53-22:09:57,
 * 22:20:12-24:00 = 21:38:43; Standby 10:00 + 9:19 + 29:22 + 10:15 = 58:56; Warmwasserbereitung 13:00:20-14:22:41 = 1:22:21.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-15_heizungsregler.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function frage(array $argumente, array $werte, array $liste, ?array $uebersetzungen = null, string $werkzeug = 'get'): array
{
    global $mitschnitt;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($liste, $anlage);
        return $werkzeug === 'chart' ? $index->chart($argumente) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

$zeilen = static fn (array $r): array => explode("\n", $r['text']);
$hat = static fn (array $r, string $z): bool => in_array($z, explode("\n", $r['text']), true);
$kurz = static fn (array $r): string => mb_substr($r['text'], 0, 600);
$echt = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];

/* --- echt: Status aus Wertebereichen als Zeitleiste ---------------------------------------------- */

$r = frage(['name' => 'Status Wärmepumpe', 'period' => 'yesterday'], $echt, $liste);
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'Timeline of 1 device(s) (name=Status Wärmepumpe, period=yesterday).', 'Status mit Texten aus Wertebereichen bekommt eine Zeitleiste: ' . $kurz($r));
pruefe($hat($r, 'Keller | Status Wärmepumpe | measurement | Heizbetrieb 21 h 39 min, Warmwasserbereitung 1 h 22 min, Standby 59 min'), 'Summen je Zustand mit dem Text der Darstellung: ' . $kurz($r));
pruefe($hat($r, 'Heizbetrieb 00:00-00:09, Standby 00:09-00:19, Heizbetrieb 00:19-06:34, Standby 06:34-06:43, Heizbetrieb 06:43-13:00, Warmwasserbereitung 13:00-14:22, Heizbetrieb 14:22-16:33, Standby 16:33-17:02, Heizbetrieb 17:02-22:09, Standby 22:09-22:20, Heizbetrieb 22:20-24:00'), 'elf Abschnitte mit Zeiten: ' . $kurz($r));
pruefe(!str_contains($r['text'], 'minimum'), 'kein Zahlenverlauf: ' . $kurz($r));
// Der Zustand jetzt bleibt, wie er war
$r = frage(['name' => 'Status Wärmepumpe'], $echt, $liste);
pruefe($hat($r, 'Keller | Status Wärmepumpe | measurement | Heizbetrieb'), 'Zustand jetzt unverändert: ' . $kurz($r));
// Im Diagramm ein Band mit den drei Zuständen
$r = frage(['name' => 'Status Wärmepumpe', 'period' => 'yesterday'], $echt, $liste, null, 'chart');
$band = $r['structuredContent']['bands'][0] ?? [];
pruefe(!$r['isError'] && ($r['structuredContent']['kind'] ?? '') === 'timeline' && count($band['sections'] ?? []) === 11
    && array_column($band['states'] ?? [], 'label') === ['Heizbetrieb', 'Standby', 'Warmwasserbereitung'], 'Band mit elf Abschnitten: ' . json_encode($band['states'] ?? null, JSON_UNESCAPED_UNICODE) . ' ' . $kurz($r));

/* --- echt: als Zähler archivierte Messwerte nennen den Zuwachs ------------------------------------ */

// Kompressor Starts: am 14.11. ein Start in der Stunde ab 17:00; Kompressor Stunden: jede Stunde 1, zusammen 24
$r = frage(['name' => 'Kompressorstarts', 'period' => 'yesterday'], $echt, $liste);
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'Increase of 1 counted value(s) (name=Kompressorstarts, period=yesterday), per hour.', 'Kopfzeile ohne Minimum und Maximum: ' . $kurz($r));
pruefe($hat($r, 'Keller | Kompressorstarts | measurement | increase 1') && $hat($r, '2026-11-14 17:00 1') && count($zeilen($r)) === 3, 'ein Start, nur die Stunde mit Zuwachs steht da: ' . $kurz($r));
$r = frage(['name' => 'Kompressor-Laufzeit', 'period' => 'yesterday'], $echt, $liste);
pruefe($hat($r, 'Keller | Kompressor-Laufzeit | measurement | increase 24 h') && $hat($r, '2026-11-14 00:00 1') && $hat($r, '2026-11-14 23:00 1') && count($zeilen($r)) === 26, 'Laufzeit mit Einheit, 24 Stunden: ' . $kurz($r));
$r = frage(['name' => 'Kompressor-Laufzeit', 'period' => 'yesterday'], $echt, $liste, $de);
pruefe(($zeilen($r)[0] ?? '') === 'Zuwachs von 1 gezählten Wert(en) (name=Kompressor-Laufzeit, period=yesterday), je Stunde.' && $hat($r, 'Keller | Kompressor-Laufzeit | measurement | Zuwachs 24 h'), 'auf Deutsch: ' . $kurz($r));
// Zwei Tage, je Tag: 14.11. 24 h, 15.11. bis 10:35 10 h
$r = frage(['name' => 'Kompressor-Laufzeit', 'period' => 'this_week'], $echt, $liste);
pruefe(str_contains($zeilen($r)[0] ?? '', 'per day') && $hat($r, '2026-11-14 24') && $hat($r, '2026-11-15 10'), 'je Tag: ' . $kurz($r));
// Im Diagramm eine Reihe mit dem Zuwachs je Stunde
$r = frage(['name' => 'Kompressor-Laufzeit', 'period' => 'yesterday'], $echt, $liste, null, 'chart');
$reihe = $r['structuredContent']['series'][0] ?? [];
pruefe(!$r['isError'] && ($reihe['name'] ?? '') === 'Kompressor-Laufzeit' && ($reihe['unit'] ?? '') === 'h' && count($reihe['points'] ?? []) === 24 && ($reihe['points'][0][2] ?? null) === 1.0, 'Reihe im Diagramm: ' . json_encode(array_diff_key($reihe, ['points' => 1]), JSON_UNESCAPED_UNICODE) . ' ' . $kurz($r));

/* --- ANGENOMMEN: Zähler ohne Archiv (am nuc sind beide archiviert) -------------------------------- */

$ohne = $echt;
$ohne[42061]['archiviert'] = false;
$r = frage(['name' => 'Kompressorstarts', 'period' => 'yesterday'], $ohne, $liste);
pruefe($hat($r, 'Keller | Kompressorstarts | measurement | no history archived'), 'ohne Archiv bleibt die alte Auskunft: ' . $kurz($r));

/* --- echt, schon vorher richtig: Merker und Betriebsart als Zeitleiste ---------------------------- */

// Eigenverbrauchsoptimierung am 14.11.: 13:05:02 an, 16:05:02 aus
$r = frage(['name' => 'Eigenverbrauchsoptimierung', 'period' => 'yesterday'], $echt, $liste);
pruefe($hat($r, 'Keller | Eigenverbrauchsoptimierung | measurement | Aus 21 h, An 3 h') && $hat($r, 'Aus 00:00-13:05, An 13:05-16:05, Aus 16:05-24:00'), 'Merker als Zeitleiste: ' . $kurz($r));
// Betriebsart am 14.11.: 16:27:25 off, 17:02:33 day
$r = frage(['name' => 'Betriebsart Heizung', 'period' => 'yesterday'], $echt, $liste);
pruefe($hat($r, 'day 00:00-16:27, off 16:27-17:02, day 17:02-24:00'), 'Betriebsart als Zeitleiste: ' . $kurz($r));

ende('Heizungsregler: Status aus Wertebereichen und Zähler als Messwert');
