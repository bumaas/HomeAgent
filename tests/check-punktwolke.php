<?php

declare(strict_types=1);

/*
 * Punktwolke in show_chart (Backlog B70, Burkhard 13.10.2026): „versus“ nennt das Gerät der x-Achse, die übrigen Filter
 * das der y-Achse (ohne Filter der Hausverbrauch der Bilanz). Je Abschnitt (Stunde bis zwei Tage, Tag bis 400 Tage,
 * sonst Monat) ein Punkt aus Mittel des Messwerts bzw. der Leistung oder Verbrauch des Zählers, gepaart nach Zeitpunkt.
 * Das Modell bekommt den Zusammenhang in Worten: linear oder mit Knick (Heizgrenze), wenn der Knick mindestens
 * 5 Prozentpunkte mehr erklärt. An den echten Werten erklärte linear 63 %, mit Knick 84 % (je Tag, Wärmepumpe gegen
 * Außentemperatur). Mitschnitt nuc_2026-10-13_punktwolke.json (nur gelesen). Die Erwartungen rechnet dieser Test
 * selbst aus den Rohdaten der Fixture nach.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-13_punktwolke.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function wolke(array $argumente, ?array $uebersetzungen = null, ?array $bilanz = null): array
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage, null, '', $bilanz ?? $mitschnitt['bilanz']);
    try {
        return $index->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

/* --- Nachrechnen aus den Rohdaten ------------------------------------------------------------- */

$aussen = 53076;
$waermepumpe = 10025;
$reihe = static function (int $id, string $stufe, int $von, int $bis) use ($mitschnitt): array {
    $r = [];
    foreach ($mitschnitt['variablen'][(string)$id]['verlauf'][$stufe] as [$t, , $avg]) {
        if ($t >= $von && $t <= $bis) {
            $r[$t] = (float)$avg;
        }
    }
    return $r;
};
$paare = static function (array $x, array $y): array {
    $p = [];
    foreach ($y as $t => $wert) {
        if (isset($x[$t])) {
            $p[] = [$x[$t], $wert, $t];
        }
    }
    return $p;
};
$bestimmt = static function (array $p, callable $f): float {
    $my = array_sum(array_column($p, 1)) / count($p);
    $sse = $sst = 0.0;
    foreach ($p as [$x, $y]) {
        $sse += ($y - $f($x)) ** 2;
        $sst += ($y - $my) ** 2;
    }
    return 1 - $sse / $sst;
};
$gerade = static function (array $p): array {
    $mx = array_sum(array_column($p, 0)) / count($p);
    $my = array_sum(array_column($p, 1)) / count($p);
    $sxy = $sxx = 0.0;
    foreach ($p as [$x, $y]) {
        $sxy += ($x - $mx) * ($y - $my);
        $sxx += ($x - $mx) ** 2;
    }
    return [$my - $sxy / $sxx * $mx, $sxy / $sxx];
};

$von = strtotime('2026-09-01 00:00');
$bis = strtotime('2026-10-13 00:00') - 1;
$p = $paare($reihe($aussen, '1', $von, $bis), $reihe($waermepumpe, '1', $von, $bis));
[$la, $lb] = $gerade($p);
$r2linear = $bestimmt($p, static fn (float $x): float => $la + $lb * $x);
// Knick unterhalb der Grenze c: y = a + b · max(0, c − x), c im Raster eines Hundertstels der Spanne zwischen dem 10. und
// 90. Perzentil der x-Werte
$xs = array_column($p, 0);
sort($xs);
$schritt = ($xs[count($xs) - 1] - $xs[0]) / 100;
$knick = null;
for ($c = $xs[(int)floor(count($xs) * 0.1)]; $c <= $xs[(int)floor(count($xs) * 0.9)] + 1e-9; $c += $schritt) {
    [$a, $b] = $gerade(array_map(static fn (array $q): array => [max(0.0, $c - $q[0]), $q[1]], $p));
    $r2 = $bestimmt($p, static fn (float $x): float => $a + $b * max(0.0, $c - $x));
    if ($knick === null || $r2 > $knick[3]) {
        $knick = [$a, $b, $c, $r2];
    }
}

/* --- Wärmepumpe gegen Außentemperatur, je Tag ------------------------------------------------- */

$r = wolke(['name' => 'Wärmepumpe', 'versus' => 'Außentemperatur', 'from' => '2026-09-01', 'to' => '2026-10-12']);
$daten = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($daten['kind'] ?? '') === 'scatter' && ($daten['per'] ?? '') === 'day', 'Punktwolke je Tag: ' . substr($r['text'], 0, 300));
pruefe(($daten['range'] ?? null) === [$von, $bis + 1], 'Punktwolke mit Zeitraum für die Titelzeile: ' . json_encode($daten['range'] ?? null));
pruefe(count($daten['points'] ?? []) === count($p) && count($p) === 42, '42 Tage mit beiden Werten: ' . count($daten['points'] ?? []));
pruefe(abs(($daten['points'][0][0] ?? 0) - $p[0][0]) < 1e-6 && abs(($daten['points'][0][1] ?? 0) - $p[0][1]) < 1e-6 && ($daten['points'][0][2] ?? 0) === $p[0][2], 'erster Punkt: x Außentemperatur, y Wärmepumpe, Zeitpunkt: ' . json_encode($daten['points'][0] ?? null));
pruefe(($daten['x']['unit'] ?? '') === '°C' && ($daten['y']['unit'] ?? '') === 'kW' && ($daten['x']['label'] ?? '') === 'Garten · Außentemperatur' && ($daten['y']['label'] ?? '') === 'Keller · Wärmepumpe', 'Achsen mit Name und Einheit: ' . json_encode([$daten['x'] ?? null, $daten['y'] ?? null], JSON_UNESCAPED_UNICODE));
$modell = $daten['model'] ?? [];
pruefe($knick[3] >= $r2linear + 0.05 && ($modell['type'] ?? '') === 'knee_below', 'der Knick erklärt mehr als die Gerade (' . round($r2linear, 2) . ' gegen ' . round($knick[3], 2) . '), also Knick: ' . json_encode($modell));
pruefe(abs(($modell['c'] ?? 0) - $knick[2]) < 1e-6 && abs(($modell['a'] ?? 0) - $knick[0]) < 1e-6 && abs(($modell['b'] ?? 0) - $knick[1]) < 1e-6 && abs(($modell['r2'] ?? 0) - $knick[3]) < 1e-6, 'Kennzahlen wie nachgerechnet: ' . json_encode($modell) . ' / ' . json_encode($knick));
$zeilen = explode("\n", $r['text']);
pruefe(str_starts_with($zeilen[0], 'Scatter chart of Keller · Wärmepumpe (kW) against Garten · Außentemperatur (°C) (name=Wärmepumpe, versus=Außentemperatur, from=2026-09-01, to=2026-10-12), per day: 42 points.'), 'Kopfzeile: ' . $zeilen[0]);
$zahl = static fn (float $v): string => abs($v) >= 1 ? rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') : (string)round($v, 2);
pruefe(($zeilen[1] ?? '') === sprintf('Below about %s °C, Keller · Wärmepumpe rises by about %s kW per °C lower; above it stays at about %s kW (explains %d %%).', $zahl($knick[2]), $zahl($knick[1]), $zahl($knick[0]), (int)round($knick[3] * 100)), 'Zusammenhang in Worten: ' . ($zeilen[1] ?? ''));
pruefe(str_ends_with($r['text'], 'The chart is shown in the chat.'), 'Schlusszeile: ' . end($zeilen));
// Klassenmittel: Klassenbreite die nächste runde Zahl (1, 2, 5 …) über einem Zwölftel der Spanne
$klassen = $daten['classes'] ?? [];
$breite = ($klassen[0][1] ?? 0) - ($klassen[0][0] ?? 0);
pruefe($breite === 2.0 && array_sum(array_column($klassen, 3)) === 42, 'Klassen je 2 °C, zusammen alle Punkte: ' . json_encode($klassen));
$auf = $daten['labels'] ?? [];
pruefe(isset($auf['per'], $auf['classes'], $auf['model']), 'Beschriftungen für die Seite: ' . json_encode($auf, JSON_UNESCAPED_UNICODE));

$r = wolke(['name' => 'Wärmepumpe', 'versus' => 'Außentemperatur', 'from' => '2026-09-01', 'to' => '2026-10-12'], $de);
pruefe(str_starts_with(explode("\n", $r['text'])[1] ?? '', 'Unter etwa '), 'auf Deutsch: ' . $r['text']);

/* --- Stufe, Zähler, Haus ---------------------------------------------------------------------- */

$r = wolke(['name' => 'Wärmepumpe', 'versus' => 'Außentemperatur', 'period' => 'yesterday']);
pruefe(!$r['isError'] && ($r['structuredContent']['per'] ?? '') === 'hour' && count($r['structuredContent']['points'] ?? []) === 24, 'gestern je Stunde, 24 Punkte: ' . count($r['structuredContent']['points'] ?? []));
// Zähler: Verbrauch der Kühltruhe in kWh je Tag (der Zähler läuft in Wh)
$r = wolke(['name' => 'Kühltruhe', 'versus' => 'Außentemperatur', 'period' => 'last_month']);
$kwh = [];
foreach ($mitschnitt['variablen']['17393']['aggregate']['1'] as $a) {
    if ($a['zeit'] >= $von && $a['zeit'] < strtotime('2026-10-01 00:00')) {
        $kwh[$a['zeit']] = $a['summe'] / 1000;
    }
}
$erster = $r['structuredContent']['points'][0] ?? [0, 0, 0];
pruefe(!$r['isError'] && ($r['structuredContent']['y']['unit'] ?? '') === 'kWh' && count($r['structuredContent']['points'] ?? []) === 30 && abs($erster[1] - $kwh[$erster[2]]) < 1e-9, 'Kühltruhe: Verbrauch je Tag in kWh: ' . json_encode($erster) . ' / ' . substr($r['text'], 0, 200));
// Haus: ohne Filter der Hausverbrauch der Bilanz
$r = wolke(['versus' => 'Außentemperatur', 'period' => 'last_month']);
pruefe(!$r['isError'] && ($r['structuredContent']['y']['unit'] ?? '') === 'kWh' && count($r['structuredContent']['points'] ?? []) === 30 && str_contains($r['text'], 'Scatter chart of house consumption (kWh) against'), 'Hausverbrauch gegen Außentemperatur: ' . substr($r['text'], 0, 200));

/* --- Absagen ---------------------------------------------------------------------------------- */

$r = wolke(['name' => 'Wärmepumpe', 'versus' => 'Temperatur', 'period' => 'last_month']);
pruefe($r['isError'] && str_contains($r['text'], 'exactly one device') && str_contains($r['text'], 'Flur · Temperatur') && str_contains($r['text'], 'Keller · Temperatur'), 'mehrdeutiges versus: Absage mit Kandidaten: ' . $r['text']);
$r = wolke(['name' => 'Nischenlicht', 'versus' => 'Außentemperatur', 'period' => 'last_month']);
pruefe($r['isError'] && str_contains($r['text'], 'has no values for a scatter chart'), 'ein Licht nur mit Zustand taugt nicht als Achse: ' . $r['text']);
$r = wolke(['name' => 'Wärmepumpe', 'versus' => 'Kühlschrank', 'period' => 'last_month']);
pruefe($r['isError'] && str_contains($r['text'], 'Kühlschrank'), 'unbekanntes versus: ' . $r['text']);
$r = wolke(['versus' => 'Außentemperatur', 'period' => 'last_month'], null, []);
pruefe($r['isError'] && str_contains($r['text'], 'energy balance'), 'ohne Filter und ohne Bilanz: Absage: ' . $r['text']);

ende('Punktwolke');
