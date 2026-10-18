<?php

declare(strict_types=1);

/*
 * Verlauf der Leistung (Backlog B84, Wunsch Micha87 im Forum t/144611 #45 vom 17.10.2026: „min und max Werte der Leistung“
 * für Oktober; ChatGPT bekam nur kWh). type=power ist kein Gerätetyp: mit Zeitraum kommt der Verlauf der Leistung wie beim
 * Messwert, mit Minimum und Maximum samt Zeitpunkt aus MinTime/MaxTime der Aggregate (Doku AC_GetAggregatedValues), ohne
 * Zeitraum die Leistung jetzt. Fürs ganze Haus die Hausleistung im Stundenmittel aus den Zählern der Bilanz und die
 * Netzleistung je Richtung.
 *
 * Mitschnitt nuc_2026-10-18_leistung.json, alle Werte und die Anordnung echt (siehe _quelle). Die Orakel rechnet der Test
 * selbst aus der Fixture: das Monatsaggregat gegen die Tagesaggregate, die das Modul liest, die Rohwerte der Stunde mit dem
 * Monatsmaximum der Netzleistung gegen das Maximum, und die Hausleistung je Stunde aus den Zählern der Bilanz.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-18_leistung.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$v = $mitschnitt['variablen'];
$monat = (int)strtotime(date('Y-m-01', $mitschnitt['jetzt']));

function index(?array $geraete = null): DeviceIndex
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($geraete ?? $mitschnitt['geraete'], $anlage);
    $index->addSources($mitschnitt['quellen']);
    return $index;
}

function frage(array $argumente, ?array $geraete = null, bool $diagramm = false): array
{
    try {
        $index = index($geraete);
        return $diagramm ? $index->chart($argumente) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

function zahl(float $wert): string
{
    return rtrim(rtrim(number_format($wert, 1, '.', ''), '0'), '.');
}

/** Minimum und Maximum samt Zeitpunkt aus den Aggregaten einer Stufe von $von bis vor $bis. */
function spitzen(string $id, string $stufe, int $von, int $bis = PHP_INT_MAX): array
{
    global $v;
    $zeilen = array_values(array_filter($v[$id]['aggregate'][$stufe], static fn (array $a): bool => $a['zeit'] >= $von && $a['zeit'] < $bis));
    $tief = $hoch = $zeilen[0];
    foreach ($zeilen as $a) {
        $tief = $a['min'] < $tief['min'] ? $a : $tief;
        $hoch = $a['max'] > $hoch['max'] ? $a : $hoch;
    }
    return ['min' => $tief['min'], 'minzeit' => $tief['minzeit'], 'max' => $hoch['max'], 'maxzeit' => $hoch['maxzeit'], 'zeilen' => count($zeilen)];
}

$zeilen = static fn (array $r): array => explode("\n", $r['text']);

/* --- ein Gerät über den Monat --------------------------------------------------------------------- */

$tag = spitzen('32163', '1', $monat);
$monatsAggregat = $v['32163']['aggregate']['3'][count($v['32163']['aggregate']['3']) - 1];
pruefe($monatsAggregat['max'] === $tag['max'] && $monatsAggregat['maxzeit'] === $tag['maxzeit'] && $monatsAggregat['min'] === $tag['min'], 'Orakel: das Monatsaggregat hat dasselbe Minimum und Maximum wie die Tage');

$r = frage(['name' => 'Steckdose Fernseher', 'type' => 'power', 'period' => 'this_month']);
$z = $zeilen($r);
pruefe(!$r['isError'] && ($z[0] ?? '') === 'Power of 1 device(s) (type=power, name=Steckdose Fernseher, period=this_month), per day: minimum / average / maximum. Minimum and maximum are instantaneous values.', 'Kopfzeile: ' . $r['text']);
$erwartet = sprintf('Wohnzimmer | Steckdose Fernseher | socket | minimum %s W (%s), maximum %s W (%s)', zahl($tag['min']), date('Y-m-d H:i', $tag['minzeit']), zahl($tag['max']), date('Y-m-d H:i', $tag['maxzeit']));
pruefe(($z[1] ?? '') === $erwartet, 'Minimum und Maximum mit Zeitpunkt aus MinTime/MaxTime: ' . ($z[1] ?? '') . ' statt ' . $erwartet);
pruefe(count(array_filter($z, static fn (string $l): bool => preg_match('/^\d{4}-\d{2}-\d{2} \S+ \/ \S+ \/ \S+$/', $l) === 1)) === $tag['zeilen'], 'ein Gerät: Tabelle mit einer Zeile je Tag (' . $tag['zeilen'] . ')');

// Gestern stündlich
$gestern = (int)strtotime('yesterday', $mitschnitt['jetzt']);
$stunde = spitzen('32163', '0', $gestern, (int)strtotime('today', $mitschnitt['jetzt']));
$r = frage(['name' => 'Steckdose Fernseher', 'type' => 'power', 'period' => 'yesterday']);
pruefe(str_contains($r['text'], sprintf('maximum %s W (%s)', zahl($stunde['max']), date('Y-m-d H:i', $stunde['maxzeit']))) && str_contains($r['text'], 'per hour'), 'gestern je Stunde: ' . strtok($r['text'], "\n"));

/* --- mehrere Geräte, Einheiten, Lücken ------------------------------------------------------------ */

// Im Keller sieben Leistungen (Entfeuchter und Luftentfeuchter teilen sich #40189): mehr als die fünf eines Messwertverlaufs,
// aber ohne Tabelle, nur die Zeilen je Gerät
$r = frage(['room' => 'Keller', 'type' => 'power', 'period' => 'this_month']);
$z = $zeilen($r);
pruefe(!$r['isError'] && str_starts_with($z[0] ?? '', 'Power of 7 device(s)'), 'Keller: sieben Leistungen, die geteilte einmal: ' . ($z[0] ?? $r['text']));
pruefe(count(array_filter($z, static fn (string $l): bool => str_starts_with($l, 'Keller | '))) === 7 && !array_any($z, static fn (string $l): bool => preg_match('/^\d{4}-/', $l) === 1), 'mehrere Geräte: je eine Zeile, keine Tabelle');
$wp = spitzen('10025', '1', $monat);
pruefe(in_array(sprintf('Keller | Wärmepumpe | appliance | minimum %s kW (%s), maximum %s kW (%s)', zahl($wp['min']), date('Y-m-d H:i', $wp['minzeit']), zahl($wp['max']), date('Y-m-d H:i', $wp['maxzeit'])), $z, true), 'die Wärmepumpe in kW: ' . implode(' ‖ ', array_filter($z, static fn (string $l): bool => str_contains($l, 'Wärmepumpe'))));

$r = frage(['room' => 'Flur', 'type' => 'power', 'period' => 'this_month']);
pruefe(in_array('Flur | Deckenlicht | light | no history archived', $zeilen($r), true), 'nicht archivierte Leistung: ' . $r['text']);

// Ein Gerät ohne Leistung
$ohne = [array_diff_key($mitschnitt['geraete'][0], ['PowerVariableID' => 0]) + ['StateVariableID' => 0]];
$r = frage(['room' => 'Flur', 'type' => 'power', 'period' => 'this_month'], $ohne);
pruefe($r['isError'] && str_contains($r['text'], 'measures power'), 'ohne Leistung: Absage: ' . $r['text']);

/* --- ohne Zeitraum: Leistung jetzt ---------------------------------------------------------------- */

$jetzt = frage(['name' => 'Steckdose Fernseher', 'period' => 'now']);
$r = frage(['name' => 'Steckdose Fernseher', 'type' => 'power']);
pruefe(!$r['isError'] && array_slice($zeilen($r), 1) === array_slice($zeilen($jetzt), 1), 'type=power ohne Zeitraum ist die Leistung jetzt: ' . $r['text']);
$r = frage(['name' => 'Steckdose Fernseher', 'type' => 'power', 'period' => 'now']);
pruefe(!$r['isError'] && array_slice($zeilen($r), 1) === array_slice($zeilen($jetzt), 1), 'ebenso mit period=now');

/* --- ganzes Haus ---------------------------------------------------------------------------------- */

// Orakel Hausleistung: je Stunde Netzbezug + PV + Batterie entladen − Einspeisung − Batterie geladen in kWh, nicht unter 0
$stunden = [];
foreach ($mitschnitt['quellen'] as $q) {
    foreach (['EnergyIn' => 1, 'EnergyOut' => -1] as $feld => $vorzeichen) {
        $id = (string)($q[$feld] ?? 0);
        if ((int)$id <= 1) {
            continue;
        }
        $faktor = strtolower($v[$id]['einheit']) === 'wh' ? 0.001 : 1.0;
        // EnergyIn zählt ins Haus (Netzbezug, PV, Batterie entladen), EnergyOut hinaus (Einspeisung, Batterie geladen)
        foreach ($v[$id]['aggregate']['0'] as $a) {
            $beginn = (int)strtotime(date('Y-m-d H:00', $a['zeit']));
            $stunden[$beginn] = ($stunden[$beginn] ?? 0.0) + $vorzeichen * $a['summe'] * $faktor;
        }
    }
}
// Die laufende Stunde ist erst angebrochen, ihr Mittel wäre zu niedrig (eine falsche Grundlast)
$stunden = array_filter(array_map(static fn (float $w): float => max(0.0, $w), $stunden), static fn (int $beginn): bool => $beginn + 3600 <= $mitschnitt['jetzt'], ARRAY_FILTER_USE_KEY);
ksort($stunden);
$hausMax = max($stunden);
$hausMin = min($stunden);
$r = frage(['type' => 'power', 'period' => 'this_month']);
$z = $zeilen($r);
pruefe(!$r['isError'] && ($z[0] ?? '') === 'House power (type=power, period=this_month), per day: minimum / average / maximum.', 'Haus: Kopfzeile: ' . ($z[0] ?? $r['text']));
$haus = sprintf('- | house power (hourly mean) | house | minimum %s kW (%s), maximum %s kW (%s)', zahl($hausMin), date('Y-m-d H:i', (int)array_search($hausMin, $stunden, true)), zahl($hausMax), date('Y-m-d H:i', (int)array_search($hausMax, $stunden, true)));
pruefe(($z[1] ?? '') === $haus, 'Hausleistung im Stundenmittel: ' . ($z[1] ?? '') . ' statt ' . $haus);

// Orakel Netzleistung: das Maximum der Rohwerte in der Stunde des Monatsmaximums
$roh = $v['24942']['rohwerte'];
$rohMax = max(array_column($roh, 1));
$rohZeit = 0;
foreach ($roh as [$zeit, $wert]) {
    if ($wert === $rohMax) {
        $rohZeit = $zeit;
        break;
    }
}
$netz = spitzen('24942', '1', $monat);
pruefe($netz['max'] === round($rohMax, 3) && $netz['maxzeit'] === $rohZeit, 'Orakel: das Maximum der Tage ist das der Rohwerte (' . $rohMax . ' W um ' . date('H:i:s', $rohZeit) . ')');
pruefe(str_contains($r['text'], sprintf('- | grid import (instantaneous) | grid | minimum %s kW (%s), maximum %s kW (%s)', zahl($netz['min'] / 1000), date('Y-m-d H:i', $netz['minzeit']), zahl($rohMax / 1000), date('Y-m-d H:i', $rohZeit))), 'Netzbezug in kW mit dem Zeitpunkt der Rohwerte: ' . $r['text']);
$ein = spitzen('35568', '1', $monat);
pruefe(str_contains($r['text'], sprintf('- | grid export (instantaneous) | grid | minimum %s kW (%s), maximum %s kW (%s)', zahl($ein['min'] / 1000), date('Y-m-d H:i', $ein['minzeit']), zahl($ein['max'] / 1000), date('Y-m-d H:i', $ein['maxzeit']))), 'Einspeisung in kW');
pruefe(count($z) === 4, 'Haus: drei Zeilen ohne Tabelle: ' . count($z));

/* --- Diagramm ------------------------------------------------------------------------------------- */

$r = frage(['name' => 'Steckdose Fernseher', 'type' => 'power', 'period' => 'this_month'], null, true);
$reihe = $r['structuredContent']['series'][0] ?? [];
pruefe(!$r['isError'] && ($r['structuredContent']['kind'] ?? '') === 'history' && ($reihe['unit'] ?? '') === 'W' && count($reihe['points'] ?? []) === $tag['zeilen'], 'show_chart mit type=power: Verlauf in W, ein Punkt je Tag: ' . json_encode(array_diff_key($r['structuredContent'] ?? [], ['series' => 0, 'labels' => 0]), JSON_UNESCAPED_UNICODE));
$r = frage(['type' => 'power', 'period' => 'this_month'], null, true);
pruefe(!$r['isError'] && array_column($r['structuredContent']['series'] ?? [], 'unit') === ['kW', 'kW', 'kW'], 'Haus im Diagramm: drei Reihen in kW: ' . json_encode(array_column($r['structuredContent']['series'] ?? [], 'name'), JSON_UNESCAPED_UNICODE));

/* --- Grundlast mit unstimmigen Zählern ------------------------------------------------------------ */

// Betreute Anlage (betreut_2026-10-18_hausleistung.json): An Vormittagen, an denen die Batterie lädt, zählt der Ladezähler
// mehr, als PV und Netz liefern, der Hausverbrauch der Bilanz wird negativ. Solche Stunden zählen nicht als Grundlast
// (Burkhard 18.10.2026: Möglichkeit 1), die Zeile sagt, wie viele es waren. Vorher stand dort „Minimum 0 kW“.
$betreut = fixture('betreut_2026-10-18_hausleistung.json');
$anlage = new FakeBackend($betreut['variablen']);
$anlage->jetzt = $betreut['jetzt'];
$idx = new DeviceIndex([], $anlage);
$idx->addSources($betreut['quellen']);
$roh = [];
foreach ($betreut['quellen'] as $q) {
    foreach (['EnergyIn' => 1, 'EnergyOut' => -1] as $feld => $vorzeichen) {
        $id = (string)($q[$feld] ?? 0);
        if ((int)$id <= 1) {
            continue;
        }
        foreach ($betreut['variablen'][$id]['aggregate']['0'] as $a) {
            $roh[$a['zeit']] = ($roh[$a['zeit']] ?? 0.0) + $vorzeichen * $a['summe'];
        }
    }
}
$roh = array_filter($roh, static fn (int $beginn): bool => $beginn + 3600 <= $betreut['jetzt'], ARRAY_FILTER_USE_KEY);
ksort($roh);
$unstimmig = count(array_filter($roh, static fn (float $w): bool => $w < 0));
$gueltig = array_filter($roh, static fn (float $w): bool => $w >= 0);
pruefe($unstimmig === 15, 'Orakel: 15 Stunden mit negativem Hausverbrauch: ' . $unstimmig);
$r = $idx->getState(['type' => 'power', 'period' => 'this_month']);
$z = $zeilen($r);
$haus = sprintf('- | house power (hourly mean) | house | minimum %s kW (%s), maximum %s kW (%s)', zahl(min($gueltig)), date('Y-m-d H:i', (int)array_search(min($gueltig), $gueltig, true)), zahl(max($gueltig)), date('Y-m-d H:i', (int)array_search(max($gueltig), $gueltig, true)));
pruefe(($z[1] ?? '') === $haus, 'Grundlast ohne die unstimmigen Stunden: ' . ($z[1] ?? '') . ' statt ' . $haus);
// Die Zeile nennt die Tage mit den meisten solchen Stunden (höchstens fünf, bei Gleichstand der frühere), damit der Anwender
// dort nachsehen kann, welcher Zähler springt (Micha87 #47: „42 Stunden ausgelassen“ ohne Hinweis, wo).
$tage = [];
foreach (array_filter($roh, static fn (float $w): bool => $w < 0) as $beginn => $w) {
    $tage[date('Y-m-d', $beginn)] = ($tage[date('Y-m-d', $beginn)] ?? 0) + 1;
}
uksort($tage, static fn (string $a, string $b): int => [$tage[$b], $a] <=> [$tage[$a], $b]);
$genannt = array_map(static fn (string $tag, int $n): string => sprintf('%s (%d h)', $tag, $n), array_keys(array_slice($tage, 0, 5, true)), array_slice($tage, 0, 5, true));
$soll = '15 hour(s) with inconsistent meters (house consumption below 0) left out: ' . implode(', ', $genannt) . (count($tage) > 5 ? sprintf(' and %d more day(s)', count($tage) - 5) : '') . '.';
pruefe(count($tage) > 5, 'Orakel: die unstimmigen Stunden liegen an mehr als fünf Tagen: ' . json_encode($tage));
pruefe(($z[2] ?? '') === $soll, 'die Zeile darunter nennt sie mit den Tagen: ' . ($z[2] ?? $r['text']) . ' statt ' . $soll);
pruefe(str_starts_with($z[3] ?? '', '- | grid import (export negative, instantaneous) | grid | minimum -'), 'ein Netzsensor mit Vorzeichen: eine Zeile, die Einspeisung negativ, als Momentanwert: ' . ($z[3] ?? ''));
// Am nuc gibt es keine solche Stunde, also keine solche Zeile
pruefe(!str_contains(frage(['type' => 'power', 'period' => 'this_month'])['text'], 'inconsistent'), 'ohne unstimmige Stunden kein Hinweis');

/* --- Grenzen vor den Archivabfragen (Review 18.10.2026, H1 und M6) ----------------------------- */

// H1: Die Hausleistung hält jede Stunde aller Bilanzzähler; sechs Jahre mit fünf Zählern brauchten 86 MB (SymBox 64 MB).
$anlage = new FakeBackend($mitschnitt['variablen']);
$anlage->jetzt = $mitschnitt['jetzt'];
$haus = new DeviceIndex($mitschnitt['geraete'], $anlage);
$haus->addSources($mitschnitt['quellen']);
$r = $haus->getState(['type' => 'power', 'from' => '2020-01-01', 'to' => '2026-10-17']);
pruefe($r['isError'] && str_contains($r['text'], '400 days'), 'Hausleistung über sechs Jahre: Absage mit der Grenze von 400 Tagen: ' . $r['text']);
pruefe($anlage->seriesRequests === [] && $anlage->historyRequests === [], 'die Absage kommt vor jeder Archivabfrage: ' . count($anlage->seriesRequests) . ' Zählerreihen, ' . count($anlage->historyRequests) . ' Verläufe');
$r = frage(['type' => 'power', 'from' => '2025-10-18', 'to' => '2026-10-17']);
pruefe(!$r['isError'] && str_contains($r['text'], 'house power (hourly mean)'), 'ein Jahr geht weiter: ' . strtok($r['text'], "\n"));
// Geräte ohne Haus: die Aggregate der Stufe halten wenige Zeilen, dort gilt keine Grenze
$r = frage(['type' => 'power', 'room' => 'Keller', 'from' => '2020-01-01', 'to' => '2026-10-17']);
pruefe(!$r['isError'], 'Geräte im Keller über sechs Jahre gehen: ' . strtok($r['text'], "\n"));

// M6: Die Gerätegrenze greift vor den Archivabfragen.
$vorlage = $mitschnitt['geraete'][1];
$viele = [];
$werte = $mitschnitt['variablen'];
for ($n = 0; $n < 21; $n++) {
    $viele[] = ['Name' => 'Steckdose ' . $n, 'PowerVariableID' => 69000 + $n, 'EnergyVariableID' => 0] + $vorlage;
    $werte[(string)(69000 + $n)] = $werte['40189'];
}
$anlage = new FakeBackend($werte);
$anlage->jetzt = $mitschnitt['jetzt'];
$r = (new DeviceIndex($viele, $anlage))->getState(['type' => 'power', 'room' => 'Keller', 'period' => 'this_month']);
pruefe($r['isError'] && str_contains($r['text'], 'Too many devices (21)'), '21 Leistungen: Absage: ' . $r['text']);
pruefe($anlage->historyRequests === [], 'die Absage kommt vor den Archivabfragen: ' . count($anlage->historyRequests) . ' Verläufe');
ende('Verlauf der Leistung');
