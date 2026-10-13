<?php

declare(strict_types=1);

/*
 * Summenkurve mit Hochrechnung (Backlog B73, Burkhard 13.10.2026): show_chart mit cumulative=true addiert den Verbrauch
 * (Haus, Gerät mit Zähler oder mit costs=true die Kosten) Tag für Tag bzw. bis zwei Tage Stunde für Stunde auf. Läuft der
 * Zeitraum noch, rechnet das Modul mit dem bisherigen Mittel bis zu seinem Ende hoch; mit compare liegt der
 * Vergleichszeitraum aufaddiert darüber. Ohne Zeitraum das laufende Jahr. Mitschnitte nuc_2026-10-12_verbrauch.json (jetzt
 * 12.10.2026 17:19) und nuc_2026-10-13_kosten.json (September). Die Erwartungen rechnet dieser Test selbst nach.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$verbrauch = fixture('nuc_2026-10-12_verbrauch.json');
$kosten = fixture('nuc_2026-10-13_kosten.json');
date_default_timezone_set($verbrauch['zeitzone']);

function kurve(array $mitschnitt, array $argumente, bool $preis = false): array
{
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage, null, '', $mitschnitt['bilanz']);
    if ($preis) {
        $index->setTariff($mitschnitt['preis'], $mitschnitt['verguetung']);
    }
    try {
        return $index->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}
$zahl = static fn (float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

/* --- Nachrechnen: Hausverbrauch je Tag aus den Tagesaggregaten -------------------------------- */

$tag = static function (array $mitschnitt, int $von, int $bis): array {
    $vorzeichen = ['import' => 1, 'solar' => 1, 'discharge' => 1, 'export' => -1, 'charge' => -1];
    $summe = [];
    foreach ($vorzeichen as $teil => $v) {
        $var = $mitschnitt['variablen'][(string)$mitschnitt['bilanz'][$teil]];
        $faktor = strtolower($var['einheit']) === 'wh' ? 0.001 : 1.0;
        foreach ($var['aggregate']['1'] as $a) {
            if ($a['zeit'] >= $von && $a['zeit'] < $bis) {
                $summe[$a['zeit']] = ($summe[$a['zeit']] ?? 0.0) + $v * $a['summe'] * $faktor;
            }
        }
    }
    ksort($summe);
    return array_map(static fn (float $x): float => max(0.0, $x), $summe);
};
$okt = strtotime('2026-10-01 00:00');
$nov = strtotime('2026-11-01 00:00');
$sep = strtotime('2026-09-01 00:00');
$oktober = $tag($verbrauch, $okt, $nov);
$september = $tag($verbrauch, $sep, $okt);
$bisher = array_sum($oktober);
$anteil = ($verbrauch['jetzt'] - $okt) / ($nov - $okt);
$hochrechnung = $bisher / $anteil;
$vorher = array_sum(array_slice(array_values($september), 0, count($oktober)));

/* --- Hausverbrauch Oktober: laufend, hochgerechnet, mit September ----------------------------- */

$r = kurve($verbrauch, ['cumulative' => true, 'period' => 'this_month', 'compare' => 'previous']);
$d = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($d['kind'] ?? '') === 'cumulative' && ($d['per'] ?? '') === 'day' && count($d['times'] ?? []) === 31, 'Summenkurve je Tag über den ganzen Oktober: ' . substr($r['text'], 0, 200));
$werte = $d['values'] ?? [];
pruefe(abs(($werte[0] ?? -1) - array_values($oktober)[0]) < 0.01 && abs(($werte[count($oktober) - 1] ?? -1) - $bisher) < 0.01 && array_key_exists(count($oktober), $werte) && $werte[count($oktober)] === null, 'aufaddiert bis heute, danach leer: ' . json_encode(array_slice($werte, 10, 3)));
pruefe(abs(($d['projection'][1][1] ?? -1) - $hochrechnung) < 0.05 && ($d['projection'][1][0] ?? 0) === $nov && ($d['projection'][0][0] ?? 0) === $verbrauch['jetzt'], 'Hochrechnung bis Monatsende: ' . json_encode($d['projection'] ?? null) . ' / ' . round($hochrechnung, 2));
pruefe(abs(($d['compare']['values'][count($oktober) - 1] ?? -1) - $vorher) < 0.01 && count($d['compare']['values'] ?? []) === 30, 'September aufaddiert, am selben Tag ' . round($vorher, 1) . ' kWh');
$prozent = (int)round(($bisher - $vorher) / $vorher * 100);
pruefe(str_contains($r['text'], sprintf('So far %s kWh, projected %s kWh for the whole period.', $zahl($bisher), $zahl($hochrechnung))), 'Text mit Stand und Hochrechnung: ' . $r['text']);
pruefe(str_contains($r['text'], sprintf('Comparison by the same time %s kWh (%s%d %%).', $zahl($vorher), $prozent >= 0 ? '+' : '', $prozent)), 'Text mit dem Vergleich zur selben Zeit: ' . $r['text']);

/* --- Kosten im September: abgeschlossen ------------------------------------------------------- */

$r = kurve($kosten, ['cumulative' => true, 'costs' => true, 'period' => 'last_month'], true);
$d = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($d['unit'] ?? '') === '€' && abs((($d['values'] ?? []) === [] ? 0 : end($d['values'])) - 38.21) < 0.01 && array_key_exists('projection', $d) && $d['projection'] === null, 'Kosten im September aufaddiert bis 38,21 €, ohne Hochrechnung: ' . json_encode($d['projection'] ?? 'fehlt'));
pruefe(str_contains($r['text'], 'In total 38.21 €.'), 'abgeschlossener Zeitraum: Summe: ' . $r['text']);
// Die Vergütung als zweite Linie, im Text mit dem Saldo (Burkhard 13.10.2026: „kann ich mir auch die einspeisevergütung in € anzeigen lassen?“)
$verg = 0.0;
foreach ($kosten['variablen'][(string)$kosten['bilanz']['export']]['aggregate']['0'] as $a) {
    $verg += $a['summe'] * 0.0918;
}
$rev = $d['revenue'] ?? [];
pruefe(count($rev) === 30 && abs((($rev === []) ? 0 : end($rev)) - $verg) < 0.01, 'Vergütung aufaddiert bis ' . round($verg, 2) . ' €: ' . json_encode($rev === [] ? null : end($rev)));
pruefe(str_contains($r['text'], sprintf('Feed-in %s €, net %s €.', number_format($verg, 2, '.', ''), number_format((float)end($d['values']) - $verg, 2, '.', ''))), 'Text mit Vergütung und Saldo: ' . $r['text']);

// Ohne Werte im Vorjahr (der Mitschnitt beginnt im September 2026) bleibt die Hochrechnung linear
$r = kurve($verbrauch, ['cumulative' => true, 'period' => 'this_month']);
pruefe(($r['structuredContent']['projectionMethod'] ?? '') === 'linear' && count($r['structuredContent']['projection'] ?? []) === 2, 'ohne Vorjahr linear: ' . json_encode($r['structuredContent']['projectionMethod'] ?? null));

/* --- Hochrechnung mit dem Verlauf des Vorjahres (Mitschnitt mit Tageswerten ab 2025) ---------- */

// Burkhard 13.10.2026: linear unterschätzt über ein Jahr den Winter. Hochgerechnet wird deshalb mit dem Verhältnis „Vorjahr
// gesamt zu Vorjahr bis zur selben Zeit“ (der heutige Tag anteilig), die Kurve folgt dem Vorjahr.
$vorjahr = fixture('nuc_2026-10-13_vorjahr.json');
$j26 = strtotime('2026-01-01 00:00');
$j25 = strtotime('2025-01-01 00:00');
$j27 = strtotime('2027-01-01 00:00');
$jahr26 = array_values($tag($vorjahr, $j26, $j27));
$jahr25 = array_values($tag($vorjahr, $j25, $j26));
$letzter = count($jahr26) - 1;
$heute = strtotime('today', $vorjahr['jetzt']);
$anteilHeute = ($vorjahr['jetzt'] - $heute) / 86400;
$summe26 = array_sum($jahr26);
$bis25 = array_sum(array_slice($jahr25, 0, $letzter)) + $jahr25[$letzter] * $anteilHeute;
$gesamt25 = array_sum($jahr25);
$erwartet = $summe26 * $gesamt25 / $bis25;
$r = kurve($vorjahr, ['cumulative' => true, 'period' => 'this_year']);
$d = $r['structuredContent'] ?? [];
$p = $d['projection'] ?? [];
pruefe(!$r['isError'] && ($d['projectionMethod'] ?? '') === 'year_before' && count($p) > 2, 'Hochrechnung nach dem Vorjahresverlauf: ' . json_encode($d['projectionMethod'] ?? $r['text']));
pruefe(($p[0][0] ?? 0) === $vorjahr['jetzt'] && abs(($p[0][1] ?? -1) - $summe26) < 0.01 && (end($p) ?: [0])[0] === $j27 && abs((end($p) ?: [0, 0])[1] - $erwartet) < 0.05, 'von jetzt bis Jahresende, Endwert ' . round($erwartet, 1) . ' kWh: ' . json_encode([$p[0] ?? null, end($p)]));
$linear = $summe26 / (($vorjahr['jetzt'] - $j26) / ($j27 - $j26));
pruefe(str_contains($r['text'], sprintf('So far %s kWh, projected %s kWh for the whole period (after the course of the year before).', $zahl($summe26), $zahl($erwartet))) && abs($erwartet - $linear) > 50, 'Text nennt die Hochrechnung nach dem Vorjahr (linear wären es ' . round($linear) . ' kWh): ' . $r['text']);

/* --- Absagen ---------------------------------------------------------------------------------- */

// Ein Messwert (Außentemperatur, Mitschnitt der Punktwolke) hat keine Summenkurve
$r = kurve(fixture('nuc_2026-10-13_punktwolke.json'), ['cumulative' => true, 'name' => 'Außentemperatur', 'period' => 'last_month']);
pruefe($r['isError'] && str_contains($r['text'], 'cumulative chart'), 'ein Messwert hat keine Summenkurve: ' . $r['text']);

ende('Summenkurve');
