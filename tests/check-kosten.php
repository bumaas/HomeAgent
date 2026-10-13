<?php

declare(strict_types=1);

/*
 * Kosten in Euro (Backlog B72, Burkhard 13.10.2026): Mit einem Strompreis in der Energiebilanz (Variable in ct/kWh oder
 * €/kWh, archiviert) und der Einspeisevergütung (ct/kWh) rechnet das Modul je Stunde Netzbezug × Stundenmittel des Preises,
 * Einspeisung × Vergütung und den Hausverbrauch × Preis („ohne PV und Batterie“). get_state nennt die Kosten beim
 * Hausverbrauch, show_chart zeigt sie mit costs=true als Stapel und als Heatmap. Mitschnitt nuc_2026-10-13_kosten.json
 * (nur gelesen, September 2026): Der Tibber-Preis ist brutto, belegt an der Rechnung (37,23 € für 120,99 kWh); aus dem
 * Archiv kommen 38,21 € für 122,67 kWh (Zähler des SMA Home Manager, Stundenmittel). Die Erwartungen rechnet dieser Test
 * selbst aus den Rohdaten nach.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-13_kosten.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function index(?array $uebersetzungen = null, bool $mitPreis = true): DeviceIndex
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage, null, '', $mitschnitt['bilanz']);
    if ($mitPreis && method_exists($index, 'setTariff')) {
        $index->setTariff($mitschnitt['preis'], $mitschnitt['verguetung']);
    }
    return $index;
}
function frage(string $werkzeug, array $argumente, ?array $uebersetzungen = null, bool $mitPreis = true): array
{
    try {
        $index = index($uebersetzungen, $mitPreis);
        return $werkzeug === 'chart' ? $index->chart($argumente) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}
$euro = static fn (float $v): string => number_format($v, 2, '.', '');
$cent = static fn (float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

/* --- Nachrechnen ------------------------------------------------------------------------------- */

$stunde = static function (string $teil) use ($mitschnitt): array {
    $var = $mitschnitt['variablen'][(string)$mitschnitt['bilanz'][$teil]];
    $faktor = strtolower($var['einheit']) === 'wh' ? 0.001 : 1.0;
    $r = [];
    foreach ($var['aggregate']['0'] as $a) {
        $r[$a['zeit']] = $a['summe'] * $faktor;
    }
    return $r;
};
$preis = [];
foreach ($mitschnitt['variablen'][(string)$mitschnitt['preis']]['verlauf']['0'] as [$t, , $avg]) {
    $preis[$t] = $avg / 100;
}
$bezug = $stunde('import');
$einspeisung = $stunde('export');
$teile = ['import' => $bezug, 'solar' => $stunde('solar'), 'discharge' => $stunde('discharge'), 'export' => $einspeisung, 'charge' => $stunde('charge')];
$kosten = $menge = $ohne = 0.0;
$jeTag = [];
$hm = [];
foreach ($bezug as $t => $kwh) {
    if (!isset($preis[$t])) {
        continue;
    }
    $kosten += $kwh * $preis[$t];
    $menge += $kwh;
    $haus = max(0.0, $teile['import'][$t] + $teile['solar'][$t] + $teile['discharge'][$t] - $teile['export'][$t] - $teile['charge'][$t]);
    $ohne += $haus * $preis[$t];
    $jeTag[date('Y-m-d', $t)] = ($jeTag[date('Y-m-d', $t)] ?? 0.0) + $kwh * $preis[$t];
    $hm[$t] = $kwh * $preis[$t];
}
$verguetung = array_sum($einspeisung) * 0.0918;
$mittel = array_sum($preis) / count($preis);

/* --- get_state: Kosten beim Hausverbrauch ------------------------------------------------------ */

pruefe(abs($kosten - 38.21) < 0.01 && abs($menge - 122.67) < 0.01, 'Nachrechnung wie am nuc: 38,21 € für 122,67 kWh: ' . round($kosten, 2) . ' / ' . round($menge, 2));
$r = frage('get', ['period' => 'last_month']);
$erwartet = sprintf('Costs %s € for %s kWh grid import (paid on average %s ct/kWh, average price %s ct/kWh). Feed-in %s €. Without solar and battery it would have been %s €.',
    $euro($kosten), $cent($menge), $cent($kosten / $menge * 100), $cent($mittel * 100), $euro($verguetung), $euro($ohne));
pruefe(in_array($erwartet, explode("\n", $r['text']), true), 'Kostenzeile: ' . $r['text'] . ' // erwartet: ' . $erwartet);
$r = frage('get', ['period' => 'last_month'], $de);
pruefe(str_contains($r['text'], 'Kosten ' . $euro($kosten) . ' € für '), 'auf Deutsch: ' . $r['text']);
$r = frage('get', ['period' => 'last_month'], null, false);
pruefe(!str_contains($r['text'], 'Costs '), 'ohne Strompreis keine Kostenzeile');
$r = frage('get', ['name' => 'Wärmepumpe', 'period' => 'last_month']);
pruefe(!str_contains($r['text'], 'Costs '), 'Kosten nur fürs ganze Haus');

/* --- show_chart costs=true: Stapel in Euro ----------------------------------------------------- */

$r = frage('chart', ['costs' => true, 'period' => 'last_month']);
$daten = $r['structuredContent'] ?? [];
$reihe = static function (array $d, string $key): array {
    foreach ($d['series'] ?? [] as $s) {
        if ($s['key'] === $key) {
            return array_combine($d['times'], $s['values']);
        }
    }
    return [];
};
$netz = $reihe($daten, 'grid');
$ein = $reihe($daten, 'export');
pruefe(!$r['isError'] && ($daten['kind'] ?? '') === 'consumption' && ($daten['unit'] ?? '') === '€' && ($daten['per'] ?? '') === 'day' && count($daten['times'] ?? []) === 30, 'Stapel in Euro je Tag: ' . substr($r['text'], 0, 200));
pruefe(abs(($netz[strtotime('2026-09-01 00:00')] ?? -1) - round($jeTag['2026-09-01'], 3)) < 0.0015 && abs(array_sum($netz) - $kosten) < 0.05, 'Bezug je Tag wie nachgerechnet, zusammen ' . round(array_sum($netz), 2) . ' €');
pruefe(abs(array_sum($ein) + $verguetung) < 0.05 && max($ein) <= 0, 'Vergütung unter der Nulllinie, zusammen ' . round(array_sum($ein), 2) . ' €');
pruefe(($daten['range'] ?? null) === [strtotime('2026-09-01 00:00'), strtotime('2026-10-01 00:00')], 'mit Zeitraum für die Titelzeile');

/* --- show_chart costs=true heatmap=true: Kosten je Stunde -------------------------------------- */

$r = frage('chart', ['costs' => true, 'heatmap' => true, 'period' => 'last_month']);
$daten = $r['structuredContent'] ?? [];
$t = strtotime('2026-09-01 07:00');
pruefe(!$r['isError'] && ($daten['kind'] ?? '') === 'heatmap' && ($daten['unit'] ?? '') === '€' && abs(($daten['cells'][0][7] ?? -1) - $hm[$t]) < 1e-6, 'Heatmap der Kosten je Stunde: ' . json_encode([$daten['cells'][0][7] ?? null, $hm[$t]]));

/* --- Absagen ---------------------------------------------------------------------------------- */

$r = frage('chart', ['costs' => true, 'period' => 'last_month'], null, false);
pruefe($r['isError'] && str_contains($r['text'], 'electricity price'), 'ohne Strompreis: Absage: ' . $r['text']);
$r = frage('chart', ['costs' => true, 'name' => 'Wärmepumpe', 'period' => 'last_month']);
pruefe($r['isError'] && str_contains($r['text'], 'whole house'), 'Kosten mit Gerät: Absage: ' . $r['text']);

ende('Kosten');
