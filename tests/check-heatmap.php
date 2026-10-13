<?php

declare(strict_types=1);

/*
 * Heatmap in show_chart (Backlog B71, Burkhard 13.10.2026): heatmap=true zeigt einen Wert je Stunde als Raster, bis
 * 62 Tage eine Zeile je Tag, darüber sieben Zeilen Montag bis Sonntag mit dem Mittel je Wochentag und Stunde. Ein Gerät
 * wie bei der Punktwolke (Messwert, Zähler, Leistung), ohne Filter der Hausverbrauch der Bilanz. Das Modell bekommt die
 * stärkste und die schwächste Stunde im Mittel und Werktag gegen Wochenende. Mitschnitt nuc_2026-10-13_heatmap.json
 * (nur gelesen): Bilanzzähler stündlich im September 2026, Außentemperatur stündlich ab 01.08.2026. Die Erwartungen
 * rechnet dieser Test selbst aus den Rohdaten nach.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-13_heatmap.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function karte(array $argumente, ?array $uebersetzungen = null): array
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage, null, '', $mitschnitt['bilanz']);
    try {
        return $index->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}
$zahl = static fn (float $v): string => abs($v) >= 1 ? rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') : (string)round($v, 2);
$stunde = static fn (int $h): string => sprintf('%02d:00–%02d:00', $h, ($h + 1) % 24);

/* --- Nachrechnen: Hausverbrauch je Stunde im September ---------------------------------------- */

$vorzeichen = ['import' => 1, 'solar' => 1, 'discharge' => 1, 'export' => -1, 'charge' => -1];
$haus = [];
foreach ($vorzeichen as $teil => $v) {
    $var = $mitschnitt['variablen'][(string)$mitschnitt['bilanz'][$teil]];
    $faktor = strtolower($var['einheit']) === 'wh' ? 0.001 : 1.0;
    foreach ($var['aggregate']['0'] as $a) {
        $haus[$a['zeit']] = ($haus[$a['zeit']] ?? 0.0) + $v * $a['summe'] * $faktor;
    }
}
$haus = array_map(static fn (float $x): float => max(0.0, $x), $haus);
ksort($haus);
$profil = array_fill(0, 24, []);
$tage = [];
foreach ($haus as $t => $wert) {
    $profil[(int)date('G', $t)][] = $wert;
    $tage[date('Y-m-d', $t)] = ($tage[date('Y-m-d', $t)] ?? 0.0) + $wert;
}
$mittel = array_map(static fn (array $w): float => array_sum($w) / count($w), $profil);
$hoch = array_keys($mittel, max($mittel))[0];
$tief = array_keys($mittel, min($mittel))[0];
$werktag = $wochenende = [];
foreach ($tage as $tag => $summe) {
    if ((int)date('N', strtotime($tag)) >= 6) {
        $wochenende[] = $summe;
    } else {
        $werktag[] = $summe;
    }
}

/* --- Hausverbrauch im September: eine Zeile je Tag -------------------------------------------- */

$r = karte(['heatmap' => true, 'period' => 'last_month']);
$daten = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($daten['kind'] ?? '') === 'heatmap' && ($daten['mode'] ?? '') === 'days', 'Heatmap je Tag: ' . substr($r['text'], 0, 300));
pruefe(count($daten['rows'] ?? []) === 30 && ($daten['rows'][0] ?? 0) === strtotime('2026-09-01 00:00') && count($daten['cells'][0] ?? []) === 24, '30 Zeilen ab 01.09., je 24 Stunden: ' . count($daten['rows'] ?? []));
$t = strtotime('2026-09-01 07:00');
pruefe(abs(($daten['cells'][0][7] ?? -1) - $haus[$t]) < 1e-6 && abs(($daten['cells'][29][23] ?? -1) - $haus[strtotime('2026-09-30 23:00')]) < 1e-6, 'Kästchen 01.09. 7 Uhr und 30.09. 23 Uhr wie nachgerechnet: ' . json_encode([$daten['cells'][0][7] ?? null, $haus[$t]]));
pruefe(($daten['unit'] ?? '') === 'kWh' && ($daten['scale'] ?? '') === 'sequential' && abs(($daten['max'] ?? 0) - max($haus)) < 1e-6 && abs(($daten['min'] ?? -1) - min($haus)) < 1e-6, 'Einheit, Farbskala, Minimum und Maximum: ' . json_encode(array_intersect_key($daten, ['unit' => 0, 'scale' => 0, 'min' => 0, 'max' => 0])));
// Farbskala bis zum 95. Perzentil: einzelne Spitzen bis 9,5 kWh ließen sonst fast alle Kästchen weiß (Bild am nuc)
$sortiert = array_values($haus);
sort($sortiert);
pruefe(abs(($daten['cap'] ?? -1) - $sortiert[(int)floor(0.95 * (count($sortiert) - 1))]) < 1e-6 && ($daten['cap'] ?? 99) < ($daten['max'] ?? 0), 'Farbskala endet beim 95. Perzentil: ' . json_encode([$daten['cap'] ?? null, $daten['max'] ?? null]));
pruefe(($daten['range'] ?? null) === [strtotime('2026-09-01 00:00'), strtotime('2026-10-01 00:00')], 'Zeitraum für die Titelzeile: ' . json_encode($daten['range'] ?? null));
$zeilen = explode("\n", $r['text']);
pruefe(($zeilen[0] ?? '') === 'Heatmap of house consumption (kWh) per hour (period=last_month): 30 days.', 'Kopfzeile: ' . ($zeilen[0] ?? ''));
pruefe(($zeilen[1] ?? '') === sprintf('On average highest at %s (%s kWh), lowest at %s (%s kWh).', $stunde($hoch), $zahl($mittel[$hoch]), $stunde($tief), $zahl($mittel[$tief])), 'stärkste und schwächste Stunde: ' . ($zeilen[1] ?? ''));
pruefe(($zeilen[2] ?? '') === sprintf('Per day on weekdays %s kWh, at weekends %s kWh.', $zahl(array_sum($werktag) / count($werktag)), $zahl(array_sum($wochenende) / count($wochenende))), 'Werktag gegen Wochenende je Tag: ' . ($zeilen[2] ?? ''));
pruefe(str_ends_with($r['text'], 'The chart is shown in the chat.'), 'Schlusszeile');
$r = karte(['heatmap' => true, 'period' => 'last_month'], $de);
pruefe(str_starts_with($r['text'], 'Heatmap Hausverbrauch (kWh) je Stunde'), 'auf Deutsch: ' . strtok($r['text'], "\n"));

/* --- Außentemperatur über 73 Tage: Mittel je Wochentag und Stunde ------------------------------ */

$von = strtotime('2026-08-01 00:00');
$bis = strtotime('2026-10-13 00:00');
$zellen = [];
foreach ($mitschnitt['variablen']['53076']['verlauf']['0'] as [$zeit, , $avg]) {
    if ($zeit >= $von && $zeit < $bis) {
        $zellen[(int)date('N', $zeit)][(int)date('G', $zeit)][] = (float)$avg;
    }
}
$r = karte(['room' => 'Garten', 'type' => 'temperature', 'heatmap' => true, 'from' => '2026-08-01', 'to' => '2026-10-12']);
$daten = $r['structuredContent'] ?? [];
$montag14 = array_sum($zellen[1][14]) / count($zellen[1][14]);
pruefe(!$r['isError'] && ($daten['mode'] ?? '') === 'weekdays' && ($daten['rows'] ?? []) === [1, 2, 3, 4, 5, 6, 7], 'über 62 Tage: sieben Zeilen Montag bis Sonntag: ' . json_encode($daten['rows'] ?? $r['text']));
pruefe(abs(($daten['cells'][0][14] ?? -99) - $montag14) < 1e-6 && ($daten['scale'] ?? '') === 'diverging' && ($daten['unit'] ?? '') === '°C', 'Montag 14 Uhr: Mittel aller Montage, Temperatur in Blau bis Rot: ' . json_encode([$daten['cells'][0][14] ?? null, $montag14]));
pruefe(str_starts_with($r['text'], 'Heatmap of Garten · Außentemperatur (°C) per weekday and hour (room=Garten, type=temperature, from=2026-08-01, to=2026-10-12): average over 73 days.'), 'Kopfzeile Wochenprofil: ' . strtok($r['text'], "\n"));
pruefe(str_contains($r['text'], 'On weekdays ') && !str_contains($r['text'], 'Per day'), 'Messwert: Mittel statt Summe je Tag: ' . $r['text']);

/* --- Absagen ---------------------------------------------------------------------------------- */

$r = karte(['name' => 'Nischenlicht', 'heatmap' => true, 'period' => 'last_month']);
pruefe($r['isError'] && str_contains($r['text'], 'has no values for a heatmap'), 'ein Licht nur mit Zustand: Absage: ' . $r['text']);
$r = karte(['heatmap' => true, 'period' => 'last_month', 'compare' => 'previous']);
pruefe($r['isError'] && str_contains($r['text'], 'one room and no comparison'), 'Heatmap mit Vergleich: Absage: ' . $r['text']);

ende('Heatmap');
