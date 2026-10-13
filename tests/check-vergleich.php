<?php

declare(strict_types=1);

/*
 * Vergleich zweier Zeiträume in show_chart (Backlog B69, Burkhard 13.10.2026): „compare“ mit „previous“ (der Zeitraum
 * davor: gestern, letzte Woche, letzter Monat …) oder „year_before“ (derselbe Zeitraum ein Jahr früher). Beim Verlauf
 * kommt je Messwert eine zweite, gestrichelte Linie, beim Verbrauch je Balken eine Marke mit der Summe des
 * Vergleichszeitraums. Die Zeitpunkte des Vergleichs sind auf den Zeitraum verschoben, damit beides übereinanderliegt.
 * Das Orakel ist jeweils das Diagramm des Vergleichszeitraums selbst. Mitschnitte nuc_2026-10-12_verbrauch.json
 * (Energiebilanz, jetzt 12.10.2026 17:19) und nuc_2026-10-10_verlauf.json (Temperaturen, jetzt 10.10.2026 15:30).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$verbrauch = fixture('nuc_2026-10-12_verbrauch.json');
$verlauf = fixture('nuc_2026-10-10_verlauf.json');
date_default_timezone_set($verbrauch['zeitzone']);

function diagramm(array $mitschnitt, array $argumente): array
{
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage, null, '', $mitschnitt['bilanz'] ?? []);
    try {
        return $index->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

/** Summe je Balken, wie der Stapel sie zeigt: alle positiven Teile (die Einspeisung liegt darunter). */
$summen = static function (array $daten): array {
    $werte = [];
    foreach ($daten['times'] ?? [] as $i => $t) {
        $werte[$i] = 0.0;
        foreach ($daten['series'] ?? [] as $s) {
            $werte[$i] += max(0.0, (float)($s['values'][$i] ?? 0));
        }
    }
    return $werte;
};
$gleich = static function (array $a, array $b): bool {
    if (count($a) !== count($b)) {
        return false;
    }
    foreach ($a as $i => $x) {
        if (abs($x - ($b[$i] ?? INF)) > 1e-9) {
            return false;
        }
    }
    return true;
};

/* --- Hausverbrauch: heute gegen gestern (stündlich) -------------------------------------------- */

$heute = diagramm($verbrauch, ['period' => 'today']);
$gestern = diagramm($verbrauch, ['period' => 'yesterday']);
$r = diagramm($verbrauch, ['period' => 'today', 'compare' => 'previous']);
$daten = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($daten['kind'] ?? '') === 'consumption', 'Hausverbrauch mit Vergleich: ' . substr($r['text'], 0, 200));
pruefe($gleich($daten['compare']['values'] ?? [], $summen($gestern['structuredContent'] ?? [])), 'je Stunde die Summe von gestern: ' . json_encode($daten['compare'] ?? null));
pruefe(($daten['compare']['label'] ?? '') === '2026-10-11', 'Beschriftung des Vergleichs: ' . json_encode($daten['compare']['label'] ?? null));
$n = count($heute['structuredContent']['times'] ?? []);
pruefe($n === 18 && count($daten['times'] ?? []) === 24 && array_slice($daten['times'], 0, $n) === $heute['structuredContent']['times']
    && ($daten['times'][23] ?? 0) === strtotime('2026-10-12 23:00'), 'die Achse reicht bis zum Ende des Tages, die Stunden danach ohne Balken: ' . json_encode($daten['times'] ?? null));
pruefe(array_sum(array_map(static fn (array $s): float => array_sum(array_map('abs', array_slice($s['values'], $n))), $daten['series'] ?? [])) === 0.0 && $gleich($summen(['times' => array_slice($daten['times'], 0, $n), 'series' => $daten['series']]), $summen($heute['structuredContent'])), 'die Balken von heute bleiben, wie sie waren');
$kopfGestern = strtok($gestern['text'], "\n");
pruefe(str_contains($r['text'], "\nCompared with 2026-10-11:\n" . str_replace('period=yesterday', 'from=2026-10-11, to=2026-10-11', $kopfGestern)), 'der Text nennt den Hausverbrauch von gestern: ' . $r['text']);
$bisJetzt = array_sum(array_slice($daten['compare']['values'] ?? [], 0, $n));
pruefe(str_contains($r['text'], 'By the same time: ' . rtrim(rtrim(number_format($bisJetzt, 1, '.', ''), '0'), '.') . ' kWh.'), 'und bis zur selben Stunde, weil heute noch läuft: ' . $r['text']);

/* --- Monat gegen Vormonat (täglich), Jahr gegen Vorjahr (monatlich) ---------------------------- */

$r = diagramm($verbrauch, ['period' => 'this_month', 'compare' => 'previous']);
$september = diagramm($verbrauch, ['period' => 'last_month']);
pruefe(!$r['isError'] && ($r['structuredContent']['compare']['label'] ?? '') === '2026-09-01 – 2026-09-30' && $gleich($r['structuredContent']['compare']['values'] ?? [], $summen($september['structuredContent'] ?? [])), 'Oktober gegen September: ' . json_encode($r['structuredContent']['compare'] ?? $r['text']));
$r = diagramm($verbrauch, ['period' => 'this_year', 'compare' => 'year_before']);
$vorjahr = diagramm($verbrauch, ['period' => 'last_year']);
pruefe(!$r['isError'] && ($r['structuredContent']['compare']['label'] ?? '') === '2025-01-01 – 2025-12-31' && $gleich($r['structuredContent']['compare']['values'] ?? [], $summen($vorjahr['structuredContent'] ?? [])), '2026 gegen 2025: ' . json_encode($r['structuredContent']['compare'] ?? $r['text']));

/* --- Verlauf: Temperatur heute gegen gestern ----------------------------------------------------- */

$r = diagramm($verlauf, ['room' => 'Gast', 'type' => 'temperature', 'period' => 'today', 'compare' => 'previous']);
$gestern = diagramm($verlauf, ['room' => 'Gast', 'type' => 'temperature', 'period' => 'yesterday']);
$linie = $r['structuredContent']['series'][0] ?? [];
$alt = $gestern['structuredContent']['series'][0]['points'] ?? [];
pruefe(!$r['isError'] && count($linie['compare'] ?? []) === count($alt) && count($alt) === 24, 'zweite Linie mit den 24 Stunden von gestern: ' . count($linie['compare'] ?? []));
pruefe(($linie['compare'][0] ?? []) === [$alt[0][0] + 86400, $alt[0][1], $alt[0][2], $alt[0][3]], 'um einen Tag verschoben, Werte unverändert: ' . json_encode($linie['compare'][0] ?? null));
pruefe(($r['structuredContent']['compare']['label'] ?? '') === '2026-10-09', 'Beschriftung: ' . json_encode($r['structuredContent']['compare'] ?? null));
$geraetGestern = explode("\n", $gestern['text'])[1] ?? '';
pruefe(str_contains($r['text'], "\nCompared with 2026-10-09:\n") && str_contains($r['text'], "\n" . $geraetGestern), 'der Text nennt Minimum und Maximum von gestern: ' . $r['text']);

/* --- Grenzen ------------------------------------------------------------------------------------- */

$r = diagramm($verlauf, ['room' => 'Gast, Garten', 'type' => 'temperature', 'period' => 'today', 'compare' => 'previous']);
pruefe($r['isError'] && $r['text'] === 'A comparison takes one room at a time.', 'Vergleich mit mehreren Räumen: Absage: ' . $r['text']);
$r = diagramm($verlauf, ['room' => 'Gast', 'type' => 'temperature', 'period' => 'today', 'compare' => 'sometime']);
pruefe($r['isError'] && $r['text'] === 'Unknown compare "sometime". Allowed: previous, year_before.', 'unbekannter Vergleich: ' . $r['text']);

ende('Vergleich');
