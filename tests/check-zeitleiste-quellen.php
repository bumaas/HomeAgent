<?php

declare(strict_types=1);

/*
 * Vier Befunde des Code-Reviews vom 15.11.2026 über die Builds 302 bis 304, alle an der Zeitleiste:
 *   5  Der Text eines Statuswerts wurde je Rohwert neu beim Backend geholt (an der Anlage zwei Kernelaufrufe je Wert).
 *   6  Das Band des Sollwerts zählte gegen die Grenze der Bänder im Diagramm; ein Raum mit zehn Zeitleisten und einem
 *      Thermostat verlor dadurch alle Bänder, die er vor build 303 hatte.
 *   7  Ein Fenster mit nicht archiviertem Kontakt und einer Position ohne lesbaren Bereich nannte die Position als
 *      „nicht archiviert“, obwohl ihr Archiv nichts ändern würde.
 *   8  Status aus Wertebereichen und Sollwert bekamen ihre Farbe im Band nach der Reihenfolge des Auftretens.
 *
 * Mitschnitte nuc_2026-11-15_heizungsregler.json und nuc_2026-11-15_thermostat_verlauf.json (beide nur gelesen). ECHT sind
 * die Zeilen der Freigabeliste, alle Werte, die Rohwerte, die Texte der Statuswerte und die Wertebereiche der Darstellung
 * („intervalle“, in der Reihenfolge der Darstellung: Standby, Heizbetrieb, Fehler, Kühlbetrieb, Warmwasserbereitung).
 * ANGENOMMEN ist, was unten so heißt.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$regler = fixture('nuc_2026-11-15_heizungsregler.json');
$thermostat = fixture('nuc_2026-11-15_thermostat_verlauf.json');
date_default_timezone_set($regler['zeitzone']);
$anlage = null;

function frage(array $argumente, array $mitschnitt, ?array $werte = null, ?array $liste = null, string $werkzeug = 'get'): array
{
    global $anlage;
    $anlage = new FakeBackend($werte ?? $mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($liste ?? $mitschnitt['geraete'], $anlage);
        return $werkzeug === 'chart' ? $index->chart($argumente) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

$kurz = static fn (array $r): string => mb_substr($r['text'], 0, 500);
$farben = static fn (array $band): array => array_column($band['states'] ?? [], 'color', 'label');

/* --- Befund 5, echt: Statustexte je Wert nur einmal holen ----------------------------------------- */

// Am 14.11. hat der Status 17 Rohwerte mit drei verschiedenen Werten (0, 8, 128). Dazu kommt eine Frage nach dem Wert
// von jetzt, an der das Modul den Status erkennt.
$r = frage(['name' => 'Status Wärmepumpe', 'period' => 'yesterday'], $regler);
pruefe(!$r['isError'] && $anlage->statusTextCalls <= 4, 'höchstens ein Aufruf je verschiedenem Wert und einer zum Erkennen, nicht je Rohwert: ' . $anlage->statusTextCalls . ' Aufrufe');
pruefe(str_contains($r['text'], 'Heizbetrieb 21 h 39 min, Warmwasserbereitung 1 h 22 min, Standby 59 min'), 'die Zeitleiste bleibt dieselbe: ' . $kurz($r));

/* --- Befund 8, echt: Farbe eines Status nach der Reihenfolge der Wertebereiche -------------------- */

$r = frage(['name' => 'Status Wärmepumpe', 'period' => 'yesterday'], $regler, null, null, 'chart');
$band = $r['structuredContent']['bands'][0] ?? [];
pruefe($farben($band) === ['Standby' => 0, 'Heizbetrieb' => 1, 'Warmwasserbereitung' => 4], 'Farbe nach der Stelle des Wertebereichs in der Darstellung: ' . json_encode($farben($band), JSON_UNESCAPED_UNICODE));
// Heute (15.11. bis 10:35) kommen andere Zustände zuerst oder gar nicht vor: Die Farbe je Zustand bleibt
$r = frage(['name' => 'Status Wärmepumpe', 'period' => 'today'], $regler, null, null, 'chart');
$heute = $farben($r['structuredContent']['bands'][0] ?? []);
pruefe($heute !== [] && array_intersect_key(['Standby' => 0, 'Heizbetrieb' => 1, 'Warmwasserbereitung' => 4], $heute) === $heute, 'an einem anderen Tag dieselbe Farbe je Zustand: ' . json_encode($heute, JSON_UNESCAPED_UNICODE));

/* --- Befund 8, ANGENOMMEN: Sollwert im Zeitraum geändert (echt ist er unverändert) ---------------- */

// Wintergarten wie in check-thermostat-verlauf.php: 06:00 auf 21,5, 22:00 auf „Off“ (4,5). Die Farbe folgt dem Wert,
// der kälteste zuerst, nicht der Reihenfolge, in der die Werte am Tag vorkommen.
$ab = ['from' => '2026-11-14', 'to' => '2026-11-15'];
$geaendert = $thermostat['variablen'];
$geaendert[11093]['rohwerte'][] = [mktime(6, 0, 0, 11, 14, 2026), 21.5];
$geaendert[11093]['rohwerte'][] = [mktime(22, 0, 0, 11, 14, 2026), 4.5];
$r = frage(['room' => 'Wintergarten', 'type' => 'thermostat'] + $ab, $thermostat, $geaendert, null, 'chart');
$band = $r['structuredContent']['bands'][0] ?? [];
pruefe($farben($band) === ['“Off” (4.5 °C)' => 0, '20.5 °C' => 1, '21.5 °C' => 2], 'Sollwerte in der Reihenfolge ihres Werts: ' . json_encode($farben($band), JSON_UNESCAPED_UNICODE));
// Beginnt der Zeitraum mit dem wärmeren Wert (13.11. 20:00 auf 21,5, 14.11. 06:00 zurück auf 20,5), bleiben die Farben
$anders = $thermostat['variablen'];
$anders[11093]['rohwerte'][] = [mktime(20, 0, 0, 11, 13, 2026), 21.5];
$anders[11093]['rohwerte'][] = [mktime(6, 0, 0, 11, 14, 2026), 20.5];
$r = frage(['room' => 'Wintergarten', 'type' => 'thermostat'] + $ab, $thermostat, $anders, null, 'chart');
$band = $r['structuredContent']['bands'][0] ?? [];
pruefe($farben($band) === ['20.5 °C' => 0, '21.5 °C' => 1], 'unabhängig davon, womit der Zeitraum beginnt: ' . json_encode($farben($band), JSON_UNESCAPED_UNICODE));

/* --- Befund 6, ANGENOMMEN: zehn Fenster mit Zeitleiste im Raum eines Thermostats ------------------ */

// Echt sind das Flurfenster mit archivierter Position und der Thermostat im Wintergarten. Angenommen ist, dass zehn
// (bzw. elf) solche Fenster im Wintergarten stehen: Kopien der Flur-Zeile mit eigenem Namen.
$fenster = array_values(array_filter($thermostat['geraete'], static fn (array $z): bool => $z['Room'] === 'Flur'))[0];
$heizung = array_values(array_filter($thermostat['geraete'], static fn (array $z): bool => $z['Room'] === 'Wintergarten'));
$mit = static fn (int $anzahl): array => array_merge($heizung, array_map(static fn (int $n): array => ['Room' => 'Wintergarten', 'Name' => 'Fenster ' . $n, 'Aliases' => ''] + $fenster, range(1, $anzahl)));
$r = frage(['room' => 'Wintergarten'] + $ab, $thermostat, null, $mit(10), 'chart');
$baender = $r['structuredContent']['bands'] ?? [];
pruefe(!$r['isError'] && count($baender) === 11 && !str_contains($r['text'], 'not in the chart'), 'zehn Zeitleisten und das Band des Sollwerts bleiben im Diagramm: ' . count($baender) . ' Bänder, ' . $kurz($r));
pruefe(in_array('Heizung (setpoint)', array_column($baender, 'name'), true), 'das Band des Sollwerts ist dabei: ' . json_encode(array_column($baender, 'name'), JSON_UNESCAPED_UNICODE));
// Mit elf Fenstern fallen wie vor build 303 deren Zeitleisten weg, das Band des Sollwerts gehört zum Thermostat und bleibt
$r = frage(['room' => 'Wintergarten'] + $ab, $thermostat, null, $mit(11), 'chart');
$baender = $r['structuredContent']['bands'] ?? [];
pruefe(!$r['isError'] && array_column($baender, 'name') === ['Heizung (setpoint)'] && str_contains($r['text'], '11 timeline(s) are not in the chart, at most 10 fit.'), 'elf Zeitleisten fallen weg und werden gezählt, der Sollwert bleibt: ' . json_encode(array_column($baender, 'name'), JSON_UNESCAPED_UNICODE) . ' ' . mb_substr($r['text'], -160));

/* --- Befund 7, ANGENOMMEN: Fenster mit Kontakt ohne Archiv und Position ohne lesbaren Bereich ----- */

// Badfenster mit dem Fahrstatus #46693 als Zustand (echte Variable, nicht archiviert). Angenommen ist, dass die Position
// #24234 archiviert ist und ihr Bereich unbekannt (echt: nicht archiviert, Bereich 0 bis 1). Ihr Archiv hilft dann
// nicht, genannt wird nur der Kontakt.
$beides = array_map(static fn (array $z): array => $z['Room'] === 'Bad' ? $z + ['StateVariableID' => 46693] : $z, $thermostat['geraete']);
$unlesbar = $thermostat['variablen'];
$unlesbar[24234]['geloggt'] = $unlesbar[24234]['archiviert'] = true;
$unlesbar[24234]['bereich'] = null;
$r = frage(['room' => 'Bad'] + $ab, $thermostat, $unlesbar, $beides);
pruefe($r['isError'] && str_contains($r['text'], 'Not archived: Fenster: state #46693.') && !str_contains($r['text'], '#24234'), 'nur der Kontakt fehlt im Archiv, die Position wird nicht genannt: ' . $kurz($r));
// Gegenprobe, wie bisher: Ist die Position lesbar und nicht archiviert, stehen beide da
$r = frage(['room' => 'Bad'] + $ab, $thermostat, null, $beides);
pruefe($r['isError'] && str_contains($r['text'], 'Not archived: Fenster: state #46693, position #24234.'), 'lesbare Position ohne Archiv wird weiter genannt: ' . $kurz($r));

ende('Zeitleiste: Statustexte, Farben, Bänder des Sollwerts, Fenster ohne lesbare Position');
