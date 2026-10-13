<?php

declare(strict_types=1);

/*
 * Teil der Bilanz im Diagramm (Backlog B74, Burkhard 13.10.2026): show_chart mit part=grid|feed_in|pv|battery_charge|
 * battery_discharge zeichnet nur diesen Zähler der Energiebilanz, als Balken, Summenkurve (mit Vergleich und Hochrechnung)
 * oder Heatmap; mit costs=true Netzbezug in Euro bzw. die Vergütung der Einspeisung. Anlass: Die Claude-App fragte am
 * 13.10.2026 nach der Einspeisevergütung als Summenkurve gegen das Vorjahr, versuchte name=Einspeisung (kein Gerät) und
 * holte sich danach 22 Monate einzeln über get_state, ohne Diagramm. Mitschnitte nuc_2026-10-12_verbrauch.json (Oktober,
 * jetzt 12.10.2026 17:19) und nuc_2026-10-13_kosten.json (September mit Preis). Die Erwartungen rechnet dieser Test selbst
 * aus den Aggregaten nach.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$verbrauch = fixture('nuc_2026-10-12_verbrauch.json');
$kosten = fixture('nuc_2026-10-13_kosten.json');
date_default_timezone_set($verbrauch['zeitzone']);

function teil(array $mitschnitt, array $argumente, bool $preis = false, bool $bilanz = true): array
{
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage, null, '', $bilanz ? $mitschnitt['bilanz'] : []);
    if ($preis) {
        $index->setTariff($mitschnitt['preis'], $mitschnitt['verguetung']);
    }
    try {
        return $index->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

/** Werte eines Bilanzzählers je Abschnitt in kWh aus den Aggregaten der Stufe. */
$reihe = static function (array $mitschnitt, string $teil, int $stufe, int $von, int $bis): array {
    $var = $mitschnitt['variablen'][(string)$mitschnitt['bilanz'][$teil]];
    $faktor = strtolower($var['einheit']) === 'wh' ? 0.001 : 1.0;
    $r = [];
    foreach ($var['aggregate'][(string)$stufe] as $a) {
        if ($a['zeit'] >= $von && $a['zeit'] < $bis) {
            $r[$a['zeit']] = $a['summe'] * $faktor;
        }
    }
    ksort($r);
    return $r;
};
$okt = strtotime('2026-10-01 00:00');
$nov = strtotime('2026-11-01 00:00');
$sep = strtotime('2026-09-01 00:00');
$tage = static fn (array $d): array => array_values(array_filter($d['series'][0]['values'] ?? [], static fn ($v): bool => $v !== null));

/* --- Jeder Teil allein als Balken je Tag (Oktober bis heute) ---------------------------------- */

foreach (['grid' => 'import', 'feed_in' => 'export', 'pv' => 'solar', 'battery_charge' => 'charge', 'battery_discharge' => 'discharge'] as $part => $zaehler) {
    $soll = $reihe($verbrauch, $zaehler, 1, $okt, $nov);
    $r = teil($verbrauch, ['part' => $part, 'period' => 'this_month']);
    $d = $r['structuredContent'] ?? [];
    $ist = array_slice($d['series'][0]['values'] ?? [], 0, count($soll));
    $passt = count($ist) === count($soll) && max(array_map(static fn ($a, $b): float => abs((float)$a - $b), $ist, array_values($soll)) ?: [1]) < 0.001;
    pruefe(!$r['isError'] && ($d['kind'] ?? '') === 'consumption' && ($d['unit'] ?? '') === 'kWh' && count($d['series'] ?? []) === 1 && ($d['series'][0]['key'] ?? '') === $part && $passt,
        "part=$part: eine Reihe mit dem Zähler je Tag: " . substr($r['text'], 0, 160) . ' ' . json_encode(array_slice($ist, 0, 3)));
    pruefe(count($d['times'] ?? []) === 31 && !isset($d['rings']), "part=$part: ganzer Oktober auf der Achse, keine Ringe");
}
$einspeisung = array_sum($reihe($verbrauch, 'export', 1, $okt, $nov));
$r = teil($verbrauch, ['part' => 'feed_in', 'period' => 'this_month']);
pruefe(str_contains($r['text'], sprintf('Grid export %s kWh.', rtrim(rtrim(number_format($einspeisung, 1, '.', ''), '0'), '.'))), 'Text nennt die Summe des Teils (' . round($einspeisung, 1) . ' kWh): ' . $r['text']);

/* --- Summenkurve der Einspeisung mit Vergleich ------------------------------------------------ */

$r = teil($verbrauch, ['part' => 'feed_in', 'cumulative' => true, 'period' => 'this_month', 'compare' => 'previous']);
$d = $r['structuredContent'] ?? [];
$werte = array_values(array_filter($d['values'] ?? [], static fn ($v): bool => $v !== null));
$september = array_values($reihe($verbrauch, 'export', 1, $sep, $okt));
pruefe(!$r['isError'] && ($d['kind'] ?? '') === 'cumulative' && abs(((float)end($werte)) - $einspeisung) < 0.01, 'Summenkurve der Einspeisung bis ' . round($einspeisung, 1) . ' kWh: ' . json_encode($werte === [] ? $r['text'] : end($werte)));
pruefe(abs(($d['compare']['values'][29] ?? -1) - array_sum($september)) < 0.01 && ($d['revenue'] ?? null) === null, 'Vergleich: September aufaddiert, keine zweite Linie: ' . json_encode($d['compare']['values'][29] ?? null));
pruefe(($d['labels']['total'] ?? '') === 'grid export', 'die Linie heißt nach dem Teil: ' . json_encode($d['labels']['total'] ?? null));

/* --- Kosten: Netzbezug in Euro, Vergütung der Einspeisung ------------------------------------- */

$verg = 0.0;
foreach ($reihe($kosten, 'export', 0, $sep, $okt) as $kwh) {
    $verg += $kwh * $kosten['verguetung'] / 100;
}
$r = teil($kosten, ['part' => 'feed_in', 'costs' => true, 'period' => 'last_month'], true);
$d = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($d['unit'] ?? '') === '€' && ($d['series'][0]['key'] ?? '') === 'feed_in' && abs(array_sum($tage($d)) - $verg) < 0.01 && min($tage($d) ?: [-1]) >= 0,
    'Vergütung je Tag, positiv, zusammen ' . round($verg, 2) . ' €: ' . substr($r['text'], 0, 160));
pruefe(str_contains($r['text'], sprintf('Feed-in %s €.', number_format($verg, 2, '.', ''))), 'Text nennt die Vergütung: ' . $r['text']);
$r = teil($kosten, ['part' => 'feed_in', 'costs' => true, 'cumulative' => true, 'period' => 'last_month'], true);
$d = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && abs(((float)end($d['values'])) - $verg) < 0.01 && ($d['revenue'] ?? null) === null && ($d['labels']['total'] ?? '') === 'grid export', 'Summenkurve der Vergütung bis ' . round($verg, 2) . ' €, ohne Saldo-Linie: ' . json_encode(($d['values'] ?? []) === [] ? $r['text'] : end($d['values'])));
pruefe(str_contains($r['text'], sprintf('In total %s €.', number_format($verg, 2, '.', ''))) && !str_contains($r['text'], 'net '), 'Text: Summe der Vergütung, kein Saldo: ' . $r['text']);
$r = teil($kosten, ['part' => 'grid', 'costs' => true, 'cumulative' => true, 'period' => 'last_month'], true);
$d = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && abs(((float)end($d['values'])) - 38.21) < 0.01 && ($d['revenue'] ?? null) === null, 'Netzbezug in Euro aufaddiert bis 38,21 € ohne Vergütung: ' . json_encode(($d['values'] ?? []) === [] ? $r['text'] : end($d['values'])));

/* --- Heatmap eines Teils ---------------------------------------------------------------------- */

$pvStunde = $reihe($verbrauch, 'solar', 0, $sep, $okt);
$r = teil($verbrauch, ['part' => 'pv', 'heatmap' => true, 'period' => 'last_month']);
$d = $r['structuredContent'] ?? [];
$summe = 0.0;
foreach ($d['cells'] ?? [] as $zeile) {
    $summe += array_sum(array_filter($zeile, static fn ($v): bool => $v !== null));
}
pruefe(!$r['isError'] && ($d['kind'] ?? '') === 'heatmap' && abs($summe - array_sum($pvStunde)) < 0.05, 'Heatmap der PV im September, Summe ' . round(array_sum($pvStunde), 1) . ' kWh: ' . round($summe, 1) . ' ' . substr($r['text'], 0, 120));

/* --- Absagen ---------------------------------------------------------------------------------- */

$r = teil($kosten, ['part' => 'pv', 'costs' => true, 'period' => 'last_month'], true);
pruefe($r['isError'] && str_contains($r['text'], 'grid') && str_contains($r['text'], 'feed_in'), 'Kosten nur für grid und feed_in: ' . $r['text']);
$r = teil($verbrauch, ['part' => 'feed_in', 'room' => 'Keller', 'period' => 'this_month']);
pruefe($r['isError'] && str_contains($r['text'], 'whole house'), 'part nur fürs ganze Haus: ' . $r['text']);
$r = teil($verbrauch, ['part' => 'wind', 'period' => 'this_month']);
pruefe($r['isError'] && str_contains($r['text'], 'feed_in'), 'unbekannter Teil nennt die erlaubten: ' . $r['text']);
$r = teil($verbrauch, ['part' => 'feed_in', 'period' => 'this_month'], false, false);
pruefe($r['isError'] && str_contains($r['text'], 'energy balance'), 'ohne Bilanz kein Teil: ' . $r['text']);
$r = teil($verbrauch, ['part' => 'feed_in', 'versus' => 'Außentemperatur', 'period' => 'this_month']);
pruefe($r['isError'] && str_contains($r['text'], 'part'), 'keine Punktwolke mit part: ' . $r['text']);

// Die Absage auf einen Namen, der ein Teil der Bilanz ist, verweist auf part (Claude-App 13.10.2026: name=Einspeisung,
// danach 22 Aufrufe get_state)
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
$anlage = new FakeBackend($verbrauch['variablen'], $de);
$anlage->jetzt = $verbrauch['jetzt'];
$index = new DeviceIndex($verbrauch['geraete'], $anlage, null, '', $verbrauch['bilanz']);
foreach (['Einspeisung' => 'feed_in', 'Netzbezug' => 'grid', 'PV-Ertrag' => 'pv', 'Batterie geladen' => 'battery_charge'] as $name => $part) {
    $r = $index->chart(['name' => $name, 'cumulative' => true, 'period' => 'this_year']);
    pruefe($r['isError'] && str_contains($r['text'], 'part=' . $part), "name=$name verweist auf part=$part: " . $r['text']);
}

ende('Teil der Bilanz');
