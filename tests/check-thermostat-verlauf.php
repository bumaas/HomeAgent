<?php

declare(strict_types=1);

/*
 * Verlauf eines Thermostats mit Ist-Temperatur, Sollwert und Ventil, und die Meldung, welche Variable einem Fenster mit
 * Antrieb für die Zeitleiste fehlt (Heizungsanalyse 15.11.2026). Vorher gab der Verlauf von type=thermostat nur die
 * Ist-Temperatur zurück, ohne sie so zu nennen; Sollwert und Ventil waren archiviert, aber nicht abrufbar („wann ging
 * das Ventil im Musikzimmer zu“). Bei den Fenstern stand nur „5 Gerät(e) ohne archivierten Verlauf“, ohne die Variable.
 *
 * Mitschnitt nuc_2026-11-15_thermostat_verlauf.json (nur gelesen, 15.11.2026 10:05). ECHT sind: die Zeilen der
 * Freigabeliste, alle Werte, Bereiche und Einträge, die Stundenaggregate der Ist-Temperaturen und Ventile ab 14.11.,
 * die Rohwerte der Sollwerte (alle drei im Zeitraum unverändert) und der Position des Flurfensters, und dass die
 * Positionen der Fenster im Bad und im Schlafzimmer nicht archiviert sind. ANGENOMMEN ist, was unten so heißt.
 * Die Erwartungen sind von Hand aus dem Mitschnitt gerechnet: 14.11. 00:00 bis 15.11. 10:05 sind 34 h 5 min.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-15_thermostat_verlauf.json');
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
$stelle = static fn (array $r, string $anfang): int => (int)(array_key_first(array_filter(explode("\n", $r['text']), static fn (string $z): bool => str_starts_with($z, $anfang))) ?? -1);
$stunden = static fn (array $r): int => count(preg_grep('/^2026-11-1\d \d\d:00 /', explode("\n", $r['text'])));
$echt = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$ab = ['from' => '2026-11-14', 'to' => '2026-11-15'];

/* --- echt: Musikzimmer, ein Ventil, Sollwert seit 08.11. auf dem Sonderwert „On“ ----------------- */

$r = frage(['room' => 'Musikzimmer', 'type' => 'thermostat'] + $ab, $echt, $liste);
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'History of 2 measured value(s) (room=Musikzimmer, type=thermostat, from=2026-11-14, to=2026-11-15), per hour: minimum / average / maximum.', 'Kopfzeile zählt Ist-Temperatur und Ventil: ' . ($zeilen($r)[0] ?? ''));
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | actual temperature: minimum 19.2 °C (2026-11-15 06:50), maximum 20.3 °C (2026-11-14 00:03)'), 'die Ist-Temperatur heißt so: ' . $r['text']);
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | valve: minimum 0 % (2026-11-14 11:04), maximum 98 % (2026-11-14 00:00)'), 'Ventil mit dem Zeitpunkt, zu dem es zuging: ' . $r['text']);
pruefe($hat($r, '2026-11-14 11:00 0 / 93.1 / 98') && $hat($r, '2026-11-15 08:00 0 / 94.5 / 98') && $stunden($r) === 70, 'bei einem Thermostat auch das Ventil je Stunde, 2 mal 35 Stunden: ' . $stunden($r));
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | setpoint: “On” (100 °C) 34 h 5 min, “On” (100 °C) since 08.11. 17:33'), 'Sollwert als Zeitleiste, Sonderwert mit dem Text seines Eintrags, mit „seit“: ' . $r['text']);
pruefe($stelle($r, 'Musikzimmer | Heizung | thermostat | actual') === 1 && $stelle($r, 'Musikzimmer | Heizung | thermostat | setpoint') > 1
    && $stelle($r, 'Musikzimmer | Heizung | thermostat | valve') > $stelle($r, 'Musikzimmer | Heizung | thermostat | setpoint'), 'Reihenfolge Ist, Soll, Ventil: ' . $r['text']);
$r = frage(['room' => 'Musikzimmer', 'type' => 'thermostat'] + $ab, $echt, $liste, $de);
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | Ist-Temperatur: Minimum 19.2 °C (2026-11-15 06:50), Maximum 20.3 °C (2026-11-14 00:03)')
    && $hat($r, 'Musikzimmer | Heizung | thermostat | Sollwert: „On“ (100 °C) 34 h 5 min, „On“ (100 °C) seit 08.11. 17:33')
    && $hat($r, 'Musikzimmer | Heizung | thermostat | Ventil: Minimum 0 % (2026-11-14 11:04), Maximum 98 % (2026-11-14 00:00)'), 'auf Deutsch: ' . $r['text']);
// Nach dem Namen gefragt, ohne Typ
$r = frage(['room' => 'Musikzimmer', 'name' => 'Heizung'] + $ab, $echt, $liste);
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | valve: minimum 0 % (2026-11-14 11:04), maximum 98 % (2026-11-14 00:00)'), 'auch mit Namen statt Typ: ' . $r['text']);

/* --- echt: Wohnzimmer mit zwei Ventilen, Wintergarten mit gewöhnlichem Sollwert ------------------ */

$r = frage(['room' => 'Wohnzimmer', 'type' => 'thermostat'] + $ab, $echt, $liste);
pruefe(str_starts_with($zeilen($r)[0] ?? '', 'History of 3 measured value(s) ('), 'zwei Ventile zählen einzeln: ' . ($zeilen($r)[0] ?? ''));
pruefe($hat($r, 'Wohnzimmer | Heizung | thermostat | valve 1: minimum 0 % (2026-11-14 11:02), maximum 98 % (2026-11-14 00:00)')
    && $hat($r, 'Wohnzimmer | Heizung | thermostat | valve 2: minimum 98 % (2026-11-14 00:00), maximum 98 % (2026-11-14 00:00)'), 'jedes Ventil mit seiner Nummer: ' . $r['text']);
$r = frage(['room' => 'Wintergarten', 'type' => 'thermostat'] + $ab, $echt, $liste);
pruefe($hat($r, 'Wintergarten | Heizung | thermostat | setpoint: 20.5 °C 34 h 5 min, 20.5 °C since 13.11. 09:54'), 'gewöhnlicher Sollwert mit Einheit: ' . $r['text']);

/* --- echt: alle drei Thermostate, Stundenwerte nur für die Ist-Temperatur ------------------------ */

$r = frage(['type' => 'thermostat'] + $ab, $echt, $liste);
pruefe(!$r['isError'] && str_starts_with($zeilen($r)[0] ?? '', 'History of 7 measured value(s) (') && $stunden($r) === 105, 'drei Thermostate: 3 Temperaturen und 4 Ventile, Tabelle nur für die Temperatur: ' . ($zeilen($r)[0] ?? '') . ' / ' . $stunden($r));
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | valve: minimum 0 % (2026-11-14 11:04), maximum 98 % (2026-11-14 00:00)'), 'die Ventilzeile bleibt: ' . $r['text']);

/* --- type=temperature meint das Raumklima: keine Zeilen für Sollwert und Ventil ------------------- */

$r = frage(['room' => 'Wintergarten', 'type' => 'temperature'] + $ab, $echt, $liste);
pruefe(!$r['isError'] && !str_contains($r['text'], 'setpoint') && !str_contains($r['text'], 'valve') && $stunden($r) === 35, 'bei type=temperature nur die Temperatur: ' . $r['text']);

/* --- ANGENOMMEN: Archiv aus für Sollwert und Ventil (am nuc sind beide archiviert) ---------------- */

$ohne = $echt;
$ohne[48076]['geloggt'] = $ohne[48076]['archiviert'] = false;
$ohne[27238]['geloggt'] = $ohne[27238]['archiviert'] = false;
$r = frage(['room' => 'Musikzimmer', 'type' => 'thermostat'] + $ab, $ohne, $liste);
pruefe(!$r['isError'] && $hat($r, 'Musikzimmer | Heizung | thermostat | setpoint: no history archived') && $hat($r, 'Musikzimmer | Heizung | thermostat | valve: no history archived'), 'nicht archiviert steht in der Antwort: ' . $r['text']);

/* --- ANGENOMMEN: Sollwert im Zeitraum geändert (echt ist er unverändert) -------------------------- */

// Wintergarten (echter Bereich 5 bis 30, 4,5 trägt den Text „Off“): 06:00 auf 21,5, 22:00 auf „Off“
$geaendert = $echt;
$geaendert[11093]['rohwerte'][] = [mktime(6, 0, 0, 11, 14, 2026), 21.5];
$geaendert[11093]['rohwerte'][] = [mktime(22, 0, 0, 11, 14, 2026), 4.5];
$r = frage(['room' => 'Wintergarten', 'type' => 'thermostat'] + $ab, $geaendert, $liste);
pruefe($hat($r, 'Wintergarten | Heizung | thermostat | setpoint: 21.5 °C 16 h, “Off” (4.5 °C) 12 h 5 min, 20.5 °C 6 h'), 'Summen je Sollwert, der längste zuerst: ' . $r['text']);
pruefe($hat($r, '20.5 °C 14.11. 00:00-14.11. 06:00, 21.5 °C 14.11. 06:00-14.11. 22:00, “Off” (4.5 °C) since 14.11. 22:00'), 'wann der Sollwert geändert wurde: ' . $r['text']);

/* --- ANGENOMMEN: Ventil mit Bereich 0 bis 1 (Homematic IP LEVEL), Werte des echten Ventils durch 100 */

$hmip = $echt;
$hmip[27238]['bereich'] = ['min' => 0, 'max' => 1];
$hmip[27238]['einheit'] = '';
foreach ($hmip[27238]['aggregate'] as &$stufe) {
    foreach ($stufe as &$a) {
        foreach (['min', 'mittel', 'max'] as $feld) {
            $a[$feld] /= 100;
        }
    }
}
unset($stufe, $a);
$r = frage(['room' => 'Musikzimmer', 'type' => 'thermostat'] + $ab, $hmip, $liste);
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | valve: minimum 0 % (2026-11-14 11:04), maximum 98 % (2026-11-14 00:00)') && $hat($r, '2026-11-14 11:00 0 / 93.1 / 98'), 'Ventil in Prozent seines Bereichs, wie im Zustand: ' . $r['text']);

/* --- Diagramm: Temperatur und Ventil als Linien, Sollwert als Band -------------------------------- */

$r = frage(['room' => 'Wohnzimmer', 'type' => 'thermostat'] + $ab, $echt, $liste, null, 'chart');
$reihen = array_map(static fn (array $s): array => [$s['name'], $s['unit']], $r['structuredContent']['series'] ?? []);
pruefe(!$r['isError'] && $reihen === [['Heizung (actual temperature)', '°C'], ['Heizung (valve 1)', '%'], ['Heizung (valve 2)', '%']], 'Reihen mit Namen des Teils: ' . json_encode($reihen, JSON_UNESCAPED_UNICODE) . ' ' . $r['text']);
$band = $r['structuredContent']['bands'][0] ?? [];
pruefe(($band['name'] ?? '') === 'Heizung (setpoint)' && array_column($band['states'] ?? [], 'label') === ['“On” (100 °C)'] && count($band['sections'] ?? []) === 1, 'Sollwert als Band: ' . json_encode($band, JSON_UNESCAPED_UNICODE));
// Die Grenze von fünf Reihen zählt die Messwerte, nicht ihre Ventile: drei Thermostate geben sieben Reihen
$r = frage(['type' => 'thermostat'] + $ab, $echt, $liste, null, 'chart');
pruefe(!$r['isError'] && count($r['structuredContent']['series'] ?? []) === 7, 'Ventile zählen nicht gegen die Grenze der Reihen: ' . $r['text']);

/* --- echt: Fenster mit Antrieb, zwei Positionen nicht archiviert ---------------------------------- */

// Flur: 13.11. 19:00:45 zu, 14.11. 07:00:46 gekippt, 07:27:45 zu = 27 min gekippt, 33 h 38 min geschlossen
$r = frage(['type' => 'window'] + $ab, $echt, $liste);
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'Timeline of 1 device(s) (type=window, from=2026-11-14, to=2026-11-15).' && $hat($r, 'Flur | Fenster | window | closed 33 h 38 min, tilted 27 min'), 'das archivierte Fenster hat seine Zeitleiste: ' . $r['text']);
$letzte = $zeilen($r)[count($zeilen($r)) - 1];
pruefe($letzte === '2 device(s) without archived history were left out. Not archived in Symcon: Fenster (Bad): position #24234, Westfenster (Schlafen): position #22996.', 'die Auslassung nennt Gerät und Variable: ' . $letzte);
$r = frage(['type' => 'window'] + $ab, $echt, $liste, $de);
$letzte = $zeilen($r)[count($zeilen($r)) - 1];
pruefe($letzte === '2 Gerät(e) ohne archivierten Verlauf wurden ausgelassen. In Symcon nicht archiviert: Fenster (Bad): Position #24234, Westfenster (Schlafen): Position #22996.', 'auf Deutsch: ' . $letzte);
$r = frage(['room' => 'Bad'] + $ab, $echt, $liste);
pruefe($r['isError'] && $r['text'] === 'The position of the matching devices is not archived in Symcon, so there is no history (room=Bad, from=2026-11-14, to=2026-11-15). Not archived: Fenster: position #24234. Only the current state is known.', 'die Absage nennt die Variable: ' . $r['text']);
$r = frage(['room' => 'Bad'] + $ab, $echt, $liste, $de);
pruefe($r['isError'] && str_contains($r['text'], 'Nicht archiviert: Fenster: Position #24234.'), 'auf Deutsch: ' . $r['text']);

/* --- ANGENOMMEN: Fenster mit Kontakt und Position (am nuc hat kein Fenster mit Antrieb einen Kontakt in der Liste) --- */

// Flurfenster mit einem nicht archivierten Zustand (echte Variable #20675, ohne Archiv) und der archivierten Position:
// die Zeitleiste kommt aus der Position
$beides = array_map(static fn (array $z): array => $z['Room'] === 'Flur' ? $z + ['StateVariableID' => 20675] : $z, $liste);
$r = frage(['room' => 'Flur'] + $ab, $echt, $beides);
pruefe(!$r['isError'] && $hat($r, 'Flur | Fenster | window | closed 33 h 38 min, tilted 27 min'), 'Zustand ohne Archiv, Position mit: Zeitleiste aus der Position: ' . $r['text']);
// Beides ohne Archiv (Bad mit dem Fahrstatus #46693 als Zustand): die Absage nennt beide Variablen
$beides = array_map(static fn (array $z): array => $z['Room'] === 'Bad' ? $z + ['StateVariableID' => 46693] : $z, $liste);
$r = frage(['room' => 'Bad'] + $ab, $echt, $beides);
pruefe($r['isError'] && str_contains($r['text'], 'Not archived: Fenster: state #46693, position #24234.'), 'weder Zustand noch Position archiviert: beide genannt: ' . $r['text']);
// Mit dem archivierten Kontakt in der Zeile (echte Variable #17096 „Status“ am Westfenster) kommt die Zeitleiste von dort
$kontakt = array_map(static fn (array $z): array => $z['Room'] === 'Schlafen' ? $z + ['StateVariableID' => 17096] : $z, $liste);
$r = frage(['room' => 'Schlafen'] + $ab, $echt, $kontakt);
pruefe(!$r['isError'] && str_starts_with($zeilen($r)[0] ?? '', 'Timeline of 1 device(s) (') && str_starts_with($zeilen($r)[1] ?? '', 'Schlafen | Westfenster | window | open '), 'archivierter Kontakt geht vor: ' . $r['text']);

ende('Verlauf des Thermostats und fehlendes Archiv der Fenster');
