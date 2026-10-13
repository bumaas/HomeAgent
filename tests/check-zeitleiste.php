<?php

declare(strict_types=1);

/*
 * Zeitleiste für Zustände (Backlog B69, Burkhard 13.10.2026): Ein Zeitraum in get_state liefert für Geräte mit
 * an/aus, offen/zu oder einer Auswahl (Batteriemodus), wann und wie lange jeder Zustand galt, aus den Rohwerten des
 * Archivs. Vorher ergab der Verlauf eines Zustands Unsinn („05:00 1 / 1.8 / 2“), und ein Gerät mit Zähler bekam
 * auf „wann war das Licht an?“ nur den Verbrauch. Ein Gerät mit Zähler bekommt beides in einer Zeile.
 * Mitschnitt nuc_2026-10-13_zeitleiste.json (nur gelesen): Rohwerte vom 12.10.2026 samt letztem Wert davor.
 * Die Erwartungen sind von Hand aus den Rohwerten gerechnet.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-13_zeitleiste.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function frage(array $argumente, ?array $uebersetzungen = null, string $werkzeug = 'get'): array
{
    global $anlage, $liste, $werte, $mitschnitt;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($liste, $anlage);
        return $werkzeug === 'chart' ? $index->chart($argumente) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeilen = static fn (array $r): array => explode("\n", $r['text']);
$hat = static fn (array $r, string $z): bool => in_array($z, explode("\n", $r['text']), true);

/* --- Auswahl: Batteriemodus ---------------------------------------------------------------------- */

// 23:15 Halten (vom Vortag), 05:45 Normal, 09:30 Ladesperre, 10:45 Halten, 11:00 Normal, 15:30 Halten, 18:30 Normal, 23:45 Halten
$r = frage(['name' => 'Batteriemodus', 'period' => 'yesterday']);
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'Timeline of 1 device(s) (name=Batteriemodus, period=yesterday).', 'Kopfzeile der Zeitleiste: ' . $r['text']);
pruefe($hat($r, 'Dachboden | Batteriemodus | measurement | Normal 13 h 30 min, Halten 9 h 15 min, Ladesperre 1 h 15 min'), 'Summen je Eintrag, die längste zuerst: ' . $r['text']);
pruefe($hat($r, 'Halten 00:00-05:45, Normal 05:45-09:30, Ladesperre 09:30-10:45, Halten 10:45-11:00, Normal 11:00-15:30, Halten 15:30-18:30, Normal 18:30-23:45, Halten 23:45-24:00'), 'Abschnitte mit Anfangswert vom Vortag bis Mitternacht: ' . $r['text']);
pruefe(!str_contains($r['text'], '1.8') && $anlage->historyRequests === [], 'kein Mittel aus Stundenaggregaten: ' . json_encode($anlage->historyRequests));
$r = frage(['name' => 'Batteriemodus', 'period' => 'yesterday'], $de);
pruefe(($zeilen($r)[0] ?? '') === 'Zeitleiste von 1 Gerät(en) (name=Batteriemodus, period=yesterday).', 'auf Deutsch: ' . $r['text']);

/* --- an/aus und offen/zu ------------------------------------------------------------------------- */

// Haustür (Ganzzahl 0/1 mit Einträgen, Typ door): acht Öffnungen; unter einer Minute steht nur der Beginn
$r = frage(['name' => 'Haustür', 'period' => 'yesterday']);
pruefe($hat($r, 'Flur | Haustür | door | open 6 min, closed 23 h 54 min'), 'Tür: Summe offen zuerst: ' . $r['text']);
pruefe($hat($r, 'open 09:49-09:52, 12:08-12:09, 12:27, 13:40-13:41, 15:34, 15:47, 19:45, 22:39'), 'Tür: nur die offenen Abschnitte: ' . $r['text']);
$r = frage(['name' => 'Haustür', 'period' => 'yesterday'], $de);
pruefe($hat($r, 'Flur | Haustür | door | offen 6 min, geschlossen 23 h 54 min'), 'Tür auf Deutsch: ' . $r['text']);
// Fenster Wintergarten: seit dem 04.10. zu, gestern kein Eintrag; der Anfangswert kommt von davor
$r = frage(['room' => 'Wintergarten', 'name' => 'Fenster', 'period' => 'yesterday']);
pruefe($hat($r, 'Wintergarten | Fenster | window | closed 24 h') && count($zeilen($r)) === 2, 'ohne Änderung: nur die Summe aus dem Wert davor: ' . $r['text']);
// Nischenlicht: zwölf Abschnitte an, gerade noch einzeln
$r = frage(['name' => 'Nischenlicht', 'period' => 'yesterday']);
pruefe($hat($r, 'Flur | Nischenlicht | light | on 1 h 9 min, off 22 h 51 min'), 'Licht: Summen: ' . $r['text']);
pruefe($hat($r, 'on 07:29-07:35, 07:53-07:59, 08:03-08:09, 08:55-09:01, 18:28-18:34, 18:37-18:43, 18:56-19:02, 19:14-19:20, 19:22-19:28, 19:29-19:35, 19:42-19:45, 23:03-23:09'), 'Licht: zwölf Abschnitte: ' . $r['text']);
// Bewegungsmelder: 397 Rohwerte, gleiche Sekunde zusammengefasst; zu viele Abschnitte, also nur Summen mit Anzahl
$r = frage(['room' => 'Küche', 'name' => 'Bewegungsmelder', 'period' => 'yesterday']);
pruefe($hat($r, 'Küche | Bewegungsmelder | presence | detected 3 h 24 min (171 times), not detected 20 h 36 min (172 times)') && count($zeilen($r)) === 2, 'viele Abschnitte: nur Summen und Anzahl: ' . $r['text']);
$r = frage(['room' => 'Küche', 'name' => 'Bewegungsmelder', 'period' => 'yesterday'], $de);
pruefe($hat($r, 'Küche | Bewegungsmelder | presence | erkannt 3 h 24 min (171-mal), nicht erkannt 20 h 36 min (172-mal)'), 'auf Deutsch: ' . $r['text']);
// Nicht archiviert und kein Zähler: Absage, die beides nennt („misst keine Energie“ allein wäre bei „wann war es an?“ falsch)
$r = frage(['room' => 'Bad', 'name' => 'Deckenlicht', 'period' => 'yesterday']);
pruefe($r['isError'] && $r['text'] === 'None of the matching devices measures energy or has an archived state (room=Bad, name=Deckenlicht, period=yesterday).', 'ohne Archiv: gesagt, nicht geraten: ' . $r['text']);

/* --- Zähler und Zustand in einer Zeile ----------------------------------------------------------- */

// Kühlschrank: 288.6 Wh, Kompressor 17-mal an
$r = frage(['room' => 'Keller', 'name' => 'Kühlschrank', 'period' => 'yesterday']);
pruefe($hat($r, 'Keller | Kühlschrank | switch | 0.3 kWh, on 6 h 9 min (17 times), off 17 h 51 min (18 times)'), 'Verbrauch und Zeiten in einer Zeile: ' . $r['text']);
// Küche: Spülmaschine mit Zähler (den ganzen Tag an), Bewegungsmelder ohne Zähler bekommt seine Zeitleiste statt „ausgelassen“
$r = frage(['room' => 'Küche', 'period' => 'yesterday']);
pruefe($hat($r, 'Küche | Spülmaschine | switch | 0 kWh, on 24 h'), 'ganzer Tag an: ' . $r['text']);
pruefe(str_contains($r['text'], 'Küche | Bewegungsmelder | presence | detected 3 h 24 min') && !str_contains($r['text'], 'without energy meter were left out'), 'ein Gerät ohne Zähler bekommt seine Zeiten: ' . $r['text']);

/* --- gemischt: Messwert und Auswahl ------------------------------------------------------------- */

$r = frage(['room' => 'Dachboden', 'period' => 'yesterday']);
pruefe(str_starts_with($r['text'], 'History of 1 measured value(s) (room=Dachboden, period=yesterday)') && $hat($r, 'Timeline of 1 device(s) (room=Dachboden, period=yesterday).'), 'Ladezustand als Verlauf, Batteriemodus als Zeitleiste: ' . $r['text']);
pruefe(!str_contains($r['text'], 'were left out'), 'nichts ausgelassen: ' . $r['text']);
pruefe($anlage->historyRequests === [[13407, 0]], 'Verlauf nur für den Ladezustand: ' . json_encode($anlage->historyRequests));

/* --- ganzes Haus: keine Zeitleiste --------------------------------------------------------------- */

$r = frage(['period' => 'yesterday']);
pruefe(str_contains($r['text'], 'Keller | Kühlschrank | switch | 0.3 kWh') && !str_contains($r['text'], 'on 6 h'), 'fürs ganze Haus nur der Verbrauch: ' . $r['text']);

/* --- Bänder in show_chart ------------------------------------------------------------------------ */

// Nur Zustände: kind timeline; je Gerät Zustände (aktiv ja/nein) und Abschnitte [Zustand, Beginn, Ende] in Sekunden
$gestern = strtotime('2026-10-12 00:00');
$r = frage(['name' => 'Batteriemodus', 'period' => 'yesterday'], null, 'chart');
$daten = $r['structuredContent'] ?? [];
$band = $daten['bands'][0] ?? [];
pruefe(!$r['isError'] && ($daten['kind'] ?? '') === 'timeline' && count($daten['bands'] ?? []) === 1 && ($daten['series'] ?? []) === [], 'Batteriemodus als Zeitleiste: ' . json_encode(array_diff_key($daten, ['bands' => 0]), JSON_UNESCAPED_UNICODE));
pruefe(($band['room'] ?? '') === 'Dachboden' && ($band['name'] ?? '') === 'Batteriemodus' && ($band['from'] ?? 0) === $gestern && ($band['to'] ?? 0) === $gestern + 86400, 'Band mit Raum, Name und Zeitraum: ' . json_encode(array_diff_key($band, ['sections' => 0]), JSON_UNESCAPED_UNICODE));
pruefe(($band['states'] ?? []) === [['label' => 'Normal', 'active' => null], ['label' => 'Halten', 'active' => null], ['label' => 'Ladesperre', 'active' => null]], 'Zustände in der Reihenfolge der Einträge, damit die Farben jeden Tag gleich sind: ' . json_encode($band['states'] ?? [], JSON_UNESCAPED_UNICODE));
pruefe(count($band['sections'] ?? []) === 8 && ($band['sections'][0] ?? []) === [1, $gestern, $gestern + 5 * 3600 + 45 * 60 + 1] && (end($band['sections']) ?: [])[2] === $gestern + 86400, 'acht Abschnitte, der erste „Halten“ bis 05:45:01, der letzte bis Mitternacht: ' . json_encode($band['sections'] ?? []));
pruefe(str_contains($r['text'], 'Timeline of 1 device(s)') && str_contains($r['text'], 'The chart is shown in the chat.'), 'Text fürs Modell: Zeitleiste und Hinweis: ' . $r['text']);
// an/aus: der aktive Zustand zuerst und als aktiv markiert
$r = frage(['name' => 'Haustür', 'period' => 'yesterday'], $de, 'chart');
pruefe((($r['structuredContent']['bands'][0]['states'] ?? []) === [['label' => 'offen', 'active' => true], ['label' => 'geschlossen', 'active' => false]]) && count($r['structuredContent']['bands'][0]['sections'] ?? []) === 17, 'Tür: offen aktiv, 17 Abschnitte: ' . json_encode($r['structuredContent']['bands'][0] ?? null, JSON_UNESCAPED_UNICODE));
// alle Abschnitte gehen an die Seite, auch wenn der Text nur Summen nennt (Bewegungsmelder)
$r = frage(['room' => 'Küche', 'name' => 'Bewegungsmelder', 'period' => 'yesterday'], null, 'chart');
pruefe(count($r['structuredContent']['bands'][0]['sections'] ?? []) === 343, 'Bewegungsmelder: alle 343 Abschnitte im Band: ' . count($r['structuredContent']['bands'][0]['sections'] ?? []));
// gemischt: Verlauf des Ladezustands und Band des Batteriemodus in einem Diagramm
$r = frage(['room' => 'Dachboden', 'period' => 'yesterday'], null, 'chart');
pruefe(($r['structuredContent']['kind'] ?? '') === 'history' && count($r['structuredContent']['series'] ?? []) === 1 && count($r['structuredContent']['bands'] ?? []) === 1, 'Dachboden: eine Linie, ein Band: ' . json_encode(array_map('count', array_filter($r['structuredContent'] ?? [], 'is_array'))));
// nichts archiviert: Absage wie bisher
$r = frage(['room' => 'Bad', 'name' => 'Deckenlicht', 'period' => 'yesterday'], null, 'chart');
pruefe($r['isError'], 'ohne Archiv kein Diagramm: ' . $r['text']);

ende('Zeitleiste');
