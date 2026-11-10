<?php

declare(strict_types=1);

/*
 * Zeitleiste für fahrende Geräte (Rollläden, Fenster mit Antrieb; Claude-App 10.11.2026: „Wann wurden die Rollläden
 * geschlossen“). Vorher lief type=blind mit Zeitraum in die Verbrauchsabfrage und endete mit „Keines der passenden
 * Geräte misst Energie“; das Modell riet daraus „wahrscheinlich nicht archiviert“. Jetzt wird die archivierte Position
 * zu Abschnitten offen, teilweise offen und geschlossen (beim Kippfenster gekippt), und ohne Archiv sagt die Absage das.
 *
 * Mitschnitt nuc_2026-11-10_fahrzeitleiste.json (nur gelesen). ECHT sind: die Rohwerte des Flurfensters vom 09.11.2026
 * (viermal gekippt, Wert davor vom 08.11. 23:21), Werte und Bereiche aller Geräte, und dass keine Rollladenposition der
 * Anlage archiviert ist. ANGENOMMEN sind die Rohwerte der Rollläden weiter unten (an keiner Anlage ist eine
 * Rollladenposition archiviert): Zeitpunkte aus dem echten Flurfenster, Werte passend zum echten Bereich des Geräts.
 * Die Erwartungen sind von Hand aus den Rohwerten gerechnet.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-10_fahrzeitleiste.json');
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
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeilen = static fn (array $r): array => explode("\n", $r['text']);
$hat = static fn (array $r, string $z): bool => in_array($z, explode("\n", $r['text']), true);
$echt = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];

/* --- echt: Flurfenster mit Antrieb (Kippfenster) ------------------------------------------------- */

// 08.11. 23:21 zu; gekippt 08:39:46-09:09:45, 15:21:44-15:57:45, 17:15:45-17:48:45, 19:06:45-19:39:45 = 132 min
$r = frage(['room' => 'Flur', 'period' => 'yesterday'], $echt, $liste);
pruefe(!$r['isError'] && ($zeilen($r)[0] ?? '') === 'Timeline of 1 device(s) (room=Flur, period=yesterday).', 'Fenster mit Antrieb bekommt eine Zeitleiste: ' . $r['text']);
pruefe($hat($r, 'Flur | Fenster | window | closed 21 h 48 min, tilted 2 h 12 min'), 'Summen aus der Position, die längste zuerst: ' . $r['text']);
pruefe($hat($r, 'closed 00:00-08:39, tilted 08:39-09:09, closed 09:09-15:21, tilted 15:21-15:57, closed 15:57-17:15, tilted 17:15-17:48, closed 17:48-19:06, tilted 19:06-19:39, closed 19:39-24:00'), 'jeder Abschnitt mit Zustand, damit auch „wann geschlossen“ dasteht: ' . $r['text']);
$r = frage(['room' => 'Flur', 'period' => 'yesterday'], $echt, $liste, $de);
pruefe($hat($r, 'Flur | Fenster | window | geschlossen 21 h 48 min, gekippt 2 h 12 min'), 'auf Deutsch: ' . $r['text']);
// Heute (09:45) keine Änderung: Der eine Abschnitt nennt, seit wann er gilt, aus dem Zeitpunkt des Werts davor
$r = frage(['room' => 'Flur', 'period' => 'today'], $echt, $liste);
pruefe($hat($r, 'Flur | Fenster | window | closed 9 h 45 min, closed since 09.11. 19:39') && count($zeilen($r)) === 2, 'ohne Änderung heute: seit wann geschlossen: ' . $r['text']);
$r = frage(['room' => 'Flur', 'period' => 'today'], $echt, $liste, $de);
pruefe($hat($r, 'Flur | Fenster | window | geschlossen 9 h 45 min, geschlossen seit 09.11. 19:39'), 'auf Deutsch: ' . $r['text']);
// Gestern (abgeschlossener Zeitraum) gibt es kein „seit“
$r = frage(['room' => 'Flur', 'period' => 'yesterday'], $echt, $liste);
pruefe(!str_contains($r['text'], 'since'), 'abgeschlossener Zeitraum ohne „seit“: ' . $r['text']);

/* --- echt: Rollläden ohne Archiv, die Absage sagt es --------------------------------------------- */

$r = frage(['type' => 'blind', 'period' => 'today'], $echt, $liste);
pruefe($r['isError'] && str_starts_with($r['text'], 'The position of the matching devices is not archived in Symcon, so there is no history (') && str_ends_with($r['text'], 'Only the current state is known.'), 'Rollläden ohne Archiv: eigene Absage: ' . $r['text']);
pruefe(!str_contains($r['text'], 'energy') && str_contains($r['text'], 'type=blind') && str_contains($r['text'], 'period=today'), 'kein Wort von Energie, Filter genannt: ' . $r['text']);
$r = frage(['room' => 'Gast', 'period' => 'yesterday'], $echt, $liste, $de);
pruefe($r['isError'] && str_starts_with($r['text'], 'Die Position der passenden Geräte ist in Symcon nicht archiviert, deshalb gibt es keinen Verlauf ('), 'Absage auf Deutsch: ' . $r['text']);

/* --- angenommen: Rollläden mit archivierter Position --------------------------------------------- */

// Rollladen Gast (echter Bereich 0 = zu, 1 = offen): offen, 17:15:45 auf 0,4, 17:48:45 zu
[$davor, $halb1715, $zu1748] = [$echt[20009]['rohwerte'][0][0], $echt[20009]['rohwerte'][5][0], $echt[20009]['rohwerte'][6][0]];
pruefe(date('d.m. H:i:s', $davor) === '08.11. 23:21:45' && date('d.m. H:i:s', $halb1715) === '09.11. 17:15:45' && date('d.m. H:i:s', $zu1748) === '09.11. 17:48:45', 'Zeitpunkte aus dem echten Flurfenster: ' . date('d.m. H:i:s', $halb1715));
$mit = $echt;
$mit[59648]['geloggt'] = true;
$mit[59648]['rohwerte'] = [[$davor, 1], [$halb1715, 0.4], [$zu1748, 0]];
$mit[59648]['wert'] = 0;
// Rollladen Bad oben (Velux, echter Bereich 0 = offen, 51200 = zu, echter Wert 13 = offen): 17:48:45 zu
$mit[47677]['geloggt'] = true;
$mit[47677]['rohwerte'] = [[$davor, 13], [$zu1748, 51200]];
$mit[47677]['wert'] = 51200;

$r = frage(['type' => 'blind', 'period' => 'yesterday'], $mit, $liste);
pruefe(!$r['isError'] && str_starts_with($r['text'], 'Timeline of 2 device(s) ('), 'zwei Rollläden mit Zeitleiste: ' . $r['text']);
pruefe($hat($r, 'Gast | Rollladen | blind | open 17 h 16 min, closed 6 h 11 min, partly open 33 min'), 'Rollladen: offen, teilweise offen, geschlossen: ' . $r['text']);
pruefe($hat($r, 'open 00:00-17:15, partly open 17:15-17:48, closed 17:48-24:00'), 'Rollladen: Abschnitte: ' . $r['text']);
pruefe($hat($r, 'Bad oben | Rollladen | blind | open 17 h 49 min, closed 6 h 11 min') && $hat($r, 'open 00:00-17:48, closed 17:48-24:00'), 'kleinster Wert offen (Velux), 13 von 51200 zählt als offen: ' . $r['text']);
$r = frage(['type' => 'blind', 'period' => 'yesterday'], $mit, $liste, $de);
pruefe($hat($r, 'Gast | Rollladen | blind | offen 17 h 16 min, geschlossen 6 h 11 min, teilweise offen 33 min'), 'auf Deutsch: ' . $r['text']);
// Die Frage aus dem Chat, am Morgen danach: seit wann geschlossen
$r = frage(['type' => 'blind', 'period' => 'today'], $mit, $liste);
pruefe($hat($r, 'Gast | Rollladen | blind | closed 9 h 45 min, closed since 09.11. 17:48') && $hat($r, 'Bad oben | Rollladen | blind | closed 9 h 45 min, closed since 09.11. 17:48'), '„Wann wurden die Rollläden geschlossen“: ' . $r['text']);
// Nur einer archiviert: der andere wird ausgelassen und gezählt, keine Absage
$einer = $mit;
$einer[47677] = $echt[47677];
$r = frage(['type' => 'blind', 'period' => 'yesterday'], $einer, $liste);
pruefe(!$r['isError'] && str_starts_with($r['text'], 'Timeline of 1 device(s) (') && str_contains($r['text'], '1 device(s) without archived history were left out.'), 'einer ohne Archiv: ausgelassen und gezählt: ' . $r['text']);

// Mehr als fünf Geräte: nur Summen, das „seit“ des letzten Abschnitts bleibt (heute 08:00 wieder geöffnet, angenommen)
$viele = $liste;
$auf = $mit;
$auf[59648]['rohwerte'][] = [strtotime('2026-11-10 08:00:00'), 1];
foreach (['Heike', 'Küche', 'Musikzimmer', 'Schlafen', 'Wohnzimmer'] as $raum) {
    $viele[] = ['Room' => $raum] + $liste[1];
}
$r = frage(['type' => 'blind', 'period' => 'today'], $auf, $viele);
pruefe(str_starts_with($r['text'], 'Timeline of 7 device(s) (') && count($zeilen($r)) === 8, 'sieben Rollläden: je eine Zeile: ' . $r['text']);
pruefe($hat($r, 'Küche | Rollladen | blind | closed 8 h (1 times), open 1 h 45 min (1 times), open since 08:00'), 'viele Geräte: Summen und seit wann der jetzige Zustand gilt: ' . $r['text']);
pruefe($hat($r, 'Bad oben | Rollladen | blind | closed 9 h 45 min, closed since 09.11. 17:48'), 'viele Geräte, ohne Änderung heute: ' . $r['text']);

/* --- Band in show_chart und Schema --------------------------------------------------------------- */

$r = frage(['room' => 'Flur', 'period' => 'yesterday'], $echt, $liste, null, 'chart');
$band = $r['structuredContent']['bands'][0] ?? [];
pruefe(!$r['isError'] && ($r['structuredContent']['kind'] ?? '') === 'timeline' && count($band['sections'] ?? []) === 9, 'Flurfenster als Band mit neun Abschnitten: ' . json_encode($band, JSON_UNESCAPED_UNICODE) . ' ' . $r['text']);
pruefe(($band['states'] ?? []) === [['label' => 'tilted', 'active' => null], ['label' => 'closed', 'active' => null]], 'Zustände in fester Reihenfolge (offen, teilweise, zu): ' . json_encode($band['states'] ?? null, JSON_UNESCAPED_UNICODE));
$r = frage(['type' => 'blind', 'period' => 'yesterday'], $mit, $liste, null, 'chart');
pruefe((($r['structuredContent']['bands'][0]['states'] ?? []) === [['label' => 'open', 'active' => null], ['label' => 'partly open', 'active' => null], ['label' => 'closed', 'active' => null]]), 'Rollladen: drei Zustände in fester Reihenfolge: ' . json_encode($r['structuredContent']['bands'][0]['states'] ?? null, JSON_UNESCAPED_UNICODE));
// Die Werkzeugliste nennt die Zeitleiste auch, wenn nur Rollläden freigegeben sind
pruefe(DeviceIndex::hasTimeline($liste[1]) && DeviceIndex::hasTimeline($liste[0]), 'hasTimeline für Rollladen und Fenster mit Antrieb');

ende('Zeitleiste fahrender Geräte');
