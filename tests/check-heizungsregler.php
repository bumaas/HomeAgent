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
    && array_column($band['states'] ?? [], 'label') === ['Standby', 'Heizbetrieb', 'Warmwasserbereitung'], 'Band mit elf Abschnitten, Zustände in der Reihenfolge der Wertebereiche: ' . json_encode($band['states'] ?? null, JSON_UNESCAPED_UNICODE) . ' ' . $kurz($r));

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

/* --- echt: alle Werte der Anlage über ihren gemeinsamen Alias, mit Zeitraum ----------------------- */

// Mitschnitt nuc_2026-11-15_heizkreis.json (nur gelesen, 15.11.2026): die 17 Zeilen mit dem Alias „Heizkreis“, alle im Raum
// Keller, 14 davon Messwerte. Die Absage riet „Make one call per room“, obwohl alle im selben Raum liegen; der Weg führte
// wieder in dieselbe Absage. Sie nennt jetzt die Namen, wie bei genanntem Raum.
$mitschnitt = fixture('nuc_2026-11-15_heizkreis.json');
$kreis = $mitschnitt['variablen'];
$zeilenKreis = $mitschnitt['geraete'];
$namen = 'Vorlauf, Rücklauf, Vorlauf-Soll, Heizkurve, Heizgrenze, Modulation, Kompressorstarts, Tag-Soll, Nacht-Soll, Raum-Sollwert, Grundwert Wetterausgleich, Kompressor-Laufzeit, Status Heizkreis, Zeitprogramm Heizung';
$r = frage(['name' => 'Heizkreis', 'period' => 'yesterday'], $kreis, $zeilenKreis);
pruefe($r['isError'] && $r['text'] === 'Too many measured values (14) for a history (name=Heizkreis, period=yesterday), at most 5 per call. Ask for each device on its own with room=Keller and name: ' . $namen . '. 3 further device(s) have a timeline, ask for them the same way: Betriebsart Heizung, Status Wärmepumpe, Eigenverbrauchsoptimierung.',
    'gemeinsamer Alias, alle in einem Raum: Absage nennt Raum und Namen, auch die der Zeitleisten: ' . $r['text']);
pruefe(!str_contains($r['text'], 'per room'), 'kein Rat „je Raum“, wenn alle im selben Raum liegen: ' . $kurz($r));
$r = frage(['name' => 'Heizkreis', 'period' => 'yesterday'], $kreis, $zeilenKreis, $de);
pruefe(str_contains($r['text'], 'höchstens 5 je Aufruf. Frag jedes Gerät einzeln mit room=Keller und name ab: Vorlauf, Rücklauf, '), 'auf Deutsch: ' . $kurz($r));
pruefe(str_ends_with($r['text'], ' 3 weitere(s) Gerät(e) haben eine Zeitleiste, frag sie genauso ab: Betriebsart Heizung, Status Wärmepumpe, Eigenverbrauchsoptimierung.'), 'Zeitleisten auf Deutsch: ' . $r['text']);
// Auch dieser Weg führt zum Ziel
$r = frage(['name' => 'Betriebsart Heizung', 'period' => 'yesterday'], $kreis, $zeilenKreis);
pruefe(!$r['isError'] && $hat($r, 'day 00:00-16:27, off 16:27-17:02, day 17:02-24:00'), 'ein Name aus dem zweiten Satz liefert seine Zeitleiste: ' . $kurz($r));
// Der genannte Weg führt zum Ziel: „Vorlauf“ trifft genau die eine Zeile, nicht auch „Vorlauf-Soll“
$r = frage(['name' => 'Vorlauf', 'period' => 'yesterday'], $kreis, $zeilenKreis);
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'History of 1 measured value(s) (name=Vorlauf, period=yesterday), per hour: minimum / average / maximum.'
    && $hat($r, 'Keller | Vorlauf | measurement | minimum 19.7 °C (2026-11-14 06:40), maximum 73.4 °C (2026-11-14 14:22)'), 'ein Name aus der Absage liefert seinen Verlauf: ' . $kurz($r));
// ANGENOMMEN (am nuc liegen alle im Keller): eine Zeile in einem anderen Raum, dann bleibt „je Raum“ ein Weg
$verteilt = $zeilenKreis;
$verteilt[0]['Room'] = 'Garten';
$r = frage(['name' => 'Heizkreis', 'period' => 'yesterday'], $kreis, $verteilt);
pruefe($r['isError'] && str_ends_with($r['text'], 'room takes a single room, not a list. Make one call per room or give one device in name.'), 'aus zwei Räumen bleibt der Rat „je Raum“: ' . $kurz($r));

/* --- Code-Review der Builds 310 bis 312 (15.11.2026), Befunde 2 bis 4 ----------------------------- */

// Befund 2: Der Raum stand nicht im Filter, die Namen der Absage sind hausweit aber nicht eindeutig. Die Absage nennt
// deshalb den Raum als Parameter mit (oben geprüft); der Folgeaufruf mit beiden trifft die eine Zeile. ANGENOMMEN ist
// ein zweites Gerät „Vorlauf“ im Garten (Kopie der Zeile ohne den Alias): Nur mit name kämen beide.
$doppelt = array_merge($zeilenKreis, [['Room' => 'Garten', 'Aliases' => '', 'StateVariableID' => 69990] + $zeilenKreis[0]]);
$kreisDoppelt = $kreis + [69990 => $kreis[12155]];
$r = frage(['name' => 'Heizkreis', 'period' => 'yesterday'], $kreisDoppelt, $doppelt);
pruefe($r['isError'] && str_contains($r['text'], 'Ask for each device on its own with room=Keller and name: Vorlauf, Rücklauf, '), 'Absage mit Raum, auch wenn es „Vorlauf“ zweimal gibt: ' . $kurz($r));
$r = frage(['room' => 'Keller', 'name' => 'Vorlauf', 'period' => 'yesterday'], $kreisDoppelt, $doppelt);
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'History of 1 measured value(s) (room=Keller, name=Vorlauf, period=yesterday), per hour: minimum / average / maximum.', 'Folgeaufruf mit Raum und Name trifft genau die eine Zeile: ' . $kurz($r));
// Mit genanntem Raum bleibt der Satz ohne Raum: Er steht schon im Filter
$r = frage(['room' => 'Keller', 'period' => 'yesterday'], $kreis, $zeilenKreis);
pruefe($r['isError'] && str_contains($r['text'], 'Ask for each device on its own with name: Vorlauf, Rücklauf, '), 'mit room im Filter nennt die Absage nur die Namen: ' . $kurz($r));

// Befund 3: Über show_chart blieb der Rat „Give a room or a name“, und room=Keller führte in dieselbe Absage. Auch dort
// ist name der einzige Weg, also nennt die Absage die Namen.
$r = frage(['name' => 'Heizkreis', 'period' => 'yesterday'], $kreis, $zeilenKreis, null, 'chart');
pruefe($r['isError'] && str_contains($r['text'], 'Ask for each device on its own with room=Keller and name: ' . $namen . '.'), 'show_chart über den Alias: Absage nennt Raum und Namen: ' . $kurz($r));
$r = frage(['room' => 'Keller', 'period' => 'yesterday'], $kreis, $zeilenKreis, null, 'chart');
pruefe($r['isError'] && str_contains($r['text'], 'Ask for each device on its own with name: ' . $namen . '.') && !str_contains($r['text'], 'Give a room'), 'show_chart mit Raum: Namen statt „Give a room or a name“: ' . $kurz($r));
// Der genannte Weg führt auch im Diagramm zum Ziel
$r = frage(['room' => 'Keller', 'name' => 'Vorlauf', 'period' => 'yesterday'], $kreis, $zeilenKreis, null, 'chart');
pruefe(!$r['isError'] && count($r['structuredContent']['series'] ?? []) === 1, 'show_chart mit einem Namen aus der Absage zeichnet eine Reihe: ' . $kurz($r));
// ANGENOMMEN (wie oben): aus zwei Räumen bleibt im Diagramm der alte Satz, dort nimmt room eine Kommaliste
$r = frage(['name' => 'Heizkreis', 'period' => 'yesterday'], $kreis, $verteilt, null, 'chart');
pruefe($r['isError'] && str_ends_with($r['text'], 'Give a room or a name, at most 5 at a time.'), 'show_chart aus zwei Räumen: der alte Satz bleibt: ' . $kurz($r));

// Befund 4, ANGENOMMEN: ein Thermostat nur mit Sollwert (Pflicht ist nur der Sollwert) mit demselben Alias. Sein Sollwert
// #69991 ist eine Kopie des echten Tag-Solls #11080. Er ist kein Messwert und hat keine Zeitleiste aus dem Zustand, die
// Absage verwarf ihn stillschweigend; sein Sollwert käme als Zeitleiste, also steht er im zweiten Satz.
$mitRegler = array_merge($zeilenKreis, [['Room' => 'Keller', 'Name' => 'Regler Keller', 'Aliases' => 'Heizkreis', 'Type' => 'thermostat', 'ReadOnly' => true, 'SetpointVariableID' => 69991]]);
$kreisRegler = $kreis + [69991 => $kreis[11080]];
$r = frage(['name' => 'Heizkreis', 'period' => 'yesterday'], $kreisRegler, $mitRegler);
pruefe($r['isError'] && str_contains($r['text'], 'Too many measured values (14) ')
    && str_ends_with($r['text'], ' 4 further device(s) have a timeline, ask for them the same way: Betriebsart Heizung, Status Wärmepumpe, Eigenverbrauchsoptimierung, Regler Keller.'),
    'Thermostat nur mit Sollwert steht bei den Zeitleisten der Absage: ' . $r['text']);

ende('Heizungsregler: Status aus Wertebereichen und Zähler als Messwert');
