<?php

declare(strict_types=1);

/*
 * Kosten über lange Zeiträume (Backlog B113, gemeldet 14.11.2026): An einer betreuten Anlage (Symcon 9.0, memory_limit
 * 32M) endete show_chart from=2023-10-01 to=2026-11-14 ohne Antwort, über Connect als 502. Mit einem Strompreis hängt
 * jeder Hausverbrauch die Kostenzeile an, und die Kostenrechnung las dafür alle Zähler der Bilanz und den Preis je
 * Stunde in einer einzigen Abfrage über den ganzen Zeitraum; 27 Monate von acht Zählern sprengten dort den Speicher
 * (Gegenprobe an der Anlage: „Allowed memory size of 33554432 bytes exhausted“ bei AC_GetAggregatedValues).
 *
 * Echt ist der Mitschnitt nuc_2026-10-13_kosten.json (September 2026, nur gelesen). Angenommen ist der lange Zeitraum:
 * Der Mitschnitt hat nur den September, davor liefert das Archiv nichts. Den Speicher selbst misst der Test nicht, er
 * prüft die Ursache: Keine Stundenabfrage ans Archiv darf mehr als einen Monat umfassen, und das Ergebnis über den
 * langen Zeitraum muss dem des Septembers gleichen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-13_kosten.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$original = $mitschnitt;

/** @return array{0: array, 1: FakeBackend} Antwort und die Anlage mit ihren Abfragen */
function frage(string $werkzeug, array $argumente, ?array $aufnahme = null): array
{
    global $mitschnitt;
    $mitschnitt = $aufnahme ?? $GLOBALS['original'];
    $anlage = new FakeBackend($mitschnitt['variablen'], null);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage, null, '', $mitschnitt['bilanz']);
    $index->setTariff($mitschnitt['preis'], $mitschnitt['verguetung']);
    try {
        return [$werkzeug === 'chart' ? $index->chart($argumente) : $index->getState($argumente), $anlage];
    } catch (Throwable $e) {
        return [['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true], $anlage];
    }
}

/** Längste Stundenabfrage (Stufe 0) in Tagen und ihre Anzahl, über Zähler und Verläufe. */
function stunden(FakeBackend $anlage): array
{
    $spannen = array_filter(array_merge($anlage->seriesSpans, $anlage->historySpans), static fn (array $s): bool => $s[1] === 0);
    $tage = array_map(static fn (array $s): float => ($s[3] - $s[2]) / 86400, $spannen);
    return [$tage === [] ? 0.0 : round(max($tage), 1), count($spannen)];
}
$kostenzeile = static function (array $r): string {
    foreach (explode("\n", $r['text']) as $zeile) {
        if (str_starts_with($zeile, 'Costs ')) {
            return $zeile;
        }
    }
    return '';
};
const MONAT = 31.01;

/* --- get_state: Kostenzeile über 21 Monate ------------------------------------------------------ */

[$kurz] = frage('get', ['from' => '2026-09-01', 'to' => '2026-09-30']);
[$lang, $anlage] = frage('get', ['from' => '2025-01-01', 'to' => '2026-09-30']);
[$laengste, $anzahl] = stunden($anlage);
pruefe($kostenzeile($kurz) !== '' && str_contains($kostenzeile($kurz), 'Costs 38.21 € for 122.7 kWh'), 'September wie an der Anlage: ' . $kostenzeile($kurz));
pruefe($anzahl > 0 && $laengste <= MONAT, 'get_state über 21 Monate: keine Stundenabfrage über einen Monat hinaus, längste ' . $laengste . ' Tage bei ' . $anzahl . ' Abfragen');
pruefe($kostenzeile($lang) === $kostenzeile($kurz), 'Kostenzeile über 21 Monate wie die des Septembers (davor ist das Archiv leer): ' . $kostenzeile($lang));

/* --- get_state bis heute: der laufende Monat ist die letzte Scheibe ----------------------------- */

[$offen, $anlage] = frage('get', ['from' => '2026-09-01', 'to' => '2026-10-13']);
[$laengste] = stunden($anlage);
pruefe($laengste <= MONAT && $kostenzeile($offen) === $kostenzeile($kurz), 'bis heute (13.10.), Oktober ohne Werte: ' . $laengste . ' Tage, ' . $kostenzeile($offen));

/* --- show_chart part=pv: der gemeldete Aufruf --------------------------------------------------- */

[$r, $anlage] = frage('chart', ['from' => '2025-01-01', 'to' => '2026-09-30', 'part' => 'pv']);
[$laengste] = stunden($anlage);
pruefe(!$r['isError'] && $laengste <= MONAT, 'show_chart part=pv über 21 Monate: längste Stundenabfrage ' . $laengste . ' Tage: ' . substr($r['text'], 0, 120));

/* --- show_chart costs=true: Stapel je Monat ----------------------------------------------------- */

$reihe = static function (array $d, string $key): array {
    foreach ($d['series'] ?? [] as $s) {
        if ($s['key'] === $key) {
            return array_combine($d['times'], $s['values']);
        }
    }
    return [];
};
[$r, $anlage] = frage('chart', ['costs' => true, 'from' => '2025-01-01', 'to' => '2026-09-30']);
[$laengste] = stunden($anlage);
$daten = $r['structuredContent'] ?? [];
$netz = $reihe($daten, 'grid');
$ein = $reihe($daten, 'export');
pruefe(!$r['isError'] && $laengste <= MONAT, 'Kostendiagramm über 21 Monate: längste Stundenabfrage ' . $laengste . ' Tage: ' . substr($r['text'], 0, 120));
pruefe(($daten['per'] ?? '') === 'month' && abs(($netz[strtotime('2026-09-01 00:00')] ?? -1) - 38.21) < 0.01, 'September im Monatsstapel 38,21 €: ' . json_encode($netz[strtotime('2026-09-01 00:00')] ?? null));
pruefe(abs(array_sum($netz) - 38.21) < 0.01 && array_sum($ein) < 0, 'Summe der Monate wie der September, Vergütung darunter: ' . round(array_sum($netz), 2) . ' / ' . round(array_sum($ein), 2));

/* --- Heatmap der Kosten ------------------------------------------------------------------------- */

[$einzeln] = frage('chart', ['costs' => true, 'heatmap' => true, 'from' => '2026-09-01', 'to' => '2026-09-30']);
[$r, $anlage] = frage('chart', ['costs' => true, 'heatmap' => true, 'from' => '2026-07-01', 'to' => '2026-09-30']);
[$laengste] = stunden($anlage);
pruefe(!$r['isError'] && $laengste <= MONAT, 'Heatmap der Kosten über drei Monate: längste Stundenabfrage ' . $laengste . ' Tage: ' . substr($r['text'], 0, 120));
$summe = static fn (array $d): float => round(array_sum(array_map(static fn (array $zeile): float => array_sum(array_map('floatval', $zeile)), $d['cells'] ?? [])), 4);
pruefe(!$einzeln['isError'] && ($einzeln['structuredContent']['kind'] ?? '') === 'heatmap' && $summe($einzeln['structuredContent']) > 0, 'Heatmap des Septembers hat Werte: ' . $summe($einzeln['structuredContent'] ?? []));

/* --- Review 15.11.2026, Befund 1: Monate ganz ohne Preis --------------------------------------- */

/*
 * Seit den Monatsscheiben fiel eine Scheibe ohne einen einzigen Preis still weg, ihre Stunden fehlten in „No price for
 * … hour(s)“ (Preis erst später archiviert als die Zähler). Echt sind die Zähler und Preise des Septembers. Angenommen
 * ist der Monat davor: dieselben Zählerwerte, 30 Tage früher (02. bis 31.08.), ohne Preis.
 */
$frueher = $original;
foreach ($original['bilanz'] as $zaehler) {
    foreach ($original['variablen'][$zaehler]['aggregate']['0'] as $a) {
        $frueher['variablen'][$zaehler]['aggregate']['0'][] = ['zeit' => $a['zeit'] - 30 * 86400] + $a;
    }
}
[$r, $anlage] = frage('get', ['from' => '2026-08-01', 'to' => '2026-09-30'], $frueher);
[$laengste] = stunden($anlage);
pruefe(!str_contains($kostenzeile($kurz), 'No price'), 'der September hat für jede Stunde einen Preis');
pruefe($kostenzeile($r) === $kostenzeile($kurz) . ' No price for 720 hour(s).', 'August ohne Preis: Kosten des Septembers und 720 Stunden ohne Preis: ' . $kostenzeile($r));
pruefe($laengste <= MONAT, 'auch dann keine Stundenabfrage über einen Monat hinaus: ' . $laengste . ' Tage');

/* --- Nachreview 15.11.2026, Befund 2: Cent oder Euro sagt nur die Einheit der Variable ----------- */

/*
 * Bis build 307 galten Preise ohne Einheit mit einem Mittel über 2 als Cent, zuletzt über den ganzen abgefragten Zeitraum
 * (Review 15.11.2026, Befund 4). Ein kurzer Zeitraum mit niedrigen Preisen galt so weiter als Euro und kostete das
 * Hundertfache. Jetzt rechnet das Modul nur mit einer Einheit an der Variable (ct, Cent, € oder EUR); ohne sie gibt es
 * keine Kosten und einen Hinweis (Burkhard 15.11.2026). Echt sind Zähler und Preise, die Variable trägt „Cent“.
 * Angenommen: die Preise des 30.09. durch 25 geteilt (um 1,4 ct, wie ein Börsenpreis an einem sonnigen Feiertag), die
 * Variable ohne Einheit, und die Preise in Euro mit „€/kWh“.
 */
$euro = static fn (string $zeile): float => preg_match('/^Costs ([0-9.]+) €/', $zeile, $m) === 1 ? (float)$m[1] : -1.0;
$tag = ['from' => '2026-09-30', 'to' => '2026-09-30'];
[$am30] = frage('get', $tag);
$grenze = strtotime('2026-09-30 00:00');
$billig = $original;
$hoechster = 0.0;
foreach ($billig['variablen'][$original['preis']]['verlauf']['0'] as &$zeile) {
    if ($zeile[0] >= $grenze) {
        $zeile = [$zeile[0], $zeile[1] / 25, $zeile[2] / 25, $zeile[3] / 25];
        $hoechster = max($hoechster, $zeile[2]);
    }
}
unset($zeile);
// Mit Einheit bleibt ein billiger Tag Cent, auch für sich allein abgefragt
[$r] = frage('get', $tag, $billig);
pruefe($hoechster > 0 && $hoechster <= 2 && $euro($kostenzeile($am30)) > 0.5, 'der billige Tag liegt unter 2 (höchstes Stundenmittel ' . round($hoechster, 2) . '), echt kostete der 30.09. ' . $euro($kostenzeile($am30)) . ' €');
pruefe(abs($euro($kostenzeile($r)) - $euro($kostenzeile($am30)) / 25) < 0.02, 'mit Einheit „Cent“ bleibt der billige Tag Cent: ' . $kostenzeile($r));
// Ohne Einheit: keine Kosten, weder das Hundertfache noch geraten, dafür ein Hinweis
$hinweis = 'No costs: the electricity price has no unit. Its variable needs ct/kWh or €/kWh as suffix in Symcon.';
$ohne = $billig;
$ohne['variablen'][$original['preis']]['einheit'] = '';
[$r] = frage('get', $tag, $ohne);
pruefe(!$r['isError'] && $kostenzeile($r) === '' && in_array($hinweis, explode("\n", $r['text']), true), 'ohne Einheit am billigen Tag keine Kosten, mit Hinweis: ' . mb_substr($r['text'], 0, 400));
$einheitlos = $original;
$einheitlos['variablen'][$original['preis']]['einheit'] = '';
[$r] = frage('get', ['from' => '2026-09-01', 'to' => '2026-09-30'], $einheitlos);
pruefe(!$r['isError'] && $kostenzeile($r) === '' && in_array($hinweis, explode("\n", $r['text']), true), 'ohne Einheit auch über den September keine Kosten: ' . mb_substr($r['text'], 0, 400));
[$r] = frage('chart', ['costs' => true, 'from' => '2026-09-01', 'to' => '2026-09-30'], $einheitlos);
pruefe($r['isError'] && $r['text'] === $hinweis, 'das Kostendiagramm sagt ab und nennt den Grund: ' . mb_substr($r['text'], 0, 300));
// Nachreview 15.11.2026 (Builds 307 und 308), Befund 2: Eine Einheit, die weder Cent noch Euro ist (Rappen; angenommen,
// der Mitschnitt trägt „Cent“), heißt nicht „keine Einheit“
$fremd = 'No costs: the unit "Rp./kWh" of the electricity price is neither cents nor euros. Its variable needs ct/kWh or €/kWh as suffix in Symcon.';
$rappen = $original;
$rappen['variablen'][$original['preis']]['einheit'] = ' Rp./kWh';
[$r] = frage('get', ['from' => '2026-09-01', 'to' => '2026-09-30'], $rappen);
pruefe(!$r['isError'] && $kostenzeile($r) === '' && in_array($fremd, explode("\n", $r['text']), true), 'in Rappen keine Kosten, der Hinweis nennt die Einheit: ' . mb_substr($r['text'], 0, 400));
[$r] = frage('chart', ['costs' => true, 'from' => '2026-09-01', 'to' => '2026-09-30'], $rappen);
pruefe($r['isError'] && $r['text'] === $fremd, 'das Kostendiagramm nennt die fremde Einheit: ' . mb_substr($r['text'], 0, 300));
// Preise in Euro mit „€/kWh“ ergeben dieselben Kosten
$inEuro = $original;
$inEuro['variablen'][$original['preis']]['einheit'] = '€/kWh';
foreach ($inEuro['variablen'][$original['preis']]['verlauf']['0'] as &$zeile) {
    $zeile = [$zeile[0], $zeile[1] / 100, $zeile[2] / 100, $zeile[3] / 100];
}
unset($zeile);
[$r] = frage('get', ['from' => '2026-09-01', 'to' => '2026-09-30'], $inEuro);
pruefe(abs($euro($kostenzeile($r)) - $euro($kostenzeile($kurz))) < 0.02, 'mit „€/kWh“ dieselben Kosten: ' . $kostenzeile($r));

// Nachreview 15.11.2026 (Builds 307 und 308), Befund 6 (kein Fix): Der Hinweis auf die fehlende Einheit steht dort, wo
// sonst die Kostenzeile stünde. Das gilt auch für den Text zum Diagramm des Hausverbrauchs, der die Kostenzeile trägt.
[$mitEinheit] = frage('chart', ['from' => '2026-09-01', 'to' => '2026-09-30']);
[$r] = frage('chart', ['from' => '2026-09-01', 'to' => '2026-09-30'], $einheitlos);
pruefe($kostenzeile($mitEinheit) !== '' && $kostenzeile($r) === '' && in_array($hinweis, explode("\n", $r['text']), true), 'Diagramm des Hausverbrauchs: Kostenzeile mit Einheit, Hinweis ohne: ' . mb_substr($r['text'], 0, 300));
// Befund 7: Wechselt der Strompreis am selben Index, gilt die Einheit der neuen Variable. Angenommen ist die zweite
// Preisvariable #69990, eine Kopie der echten ohne Einheit.
$zwei = $original;
$zwei['variablen'][69990] = ['einheit' => ''] + $original['variablen'][$original['preis']];
$anlageZwei = new FakeBackend($zwei['variablen'], null);
$anlageZwei->jetzt = $zwei['jetzt'];
$indexZwei = new DeviceIndex($zwei['geraete'], $anlageZwei, null, '', $zwei['bilanz']);
$indexZwei->setTariff(69990, $zwei['verguetung']);
$erst = $indexZwei->getState(['from' => '2026-09-01', 'to' => '2026-09-30']);
$indexZwei->setTariff($zwei['preis'], $zwei['verguetung']);
$dann = $indexZwei->getState(['from' => '2026-09-01', 'to' => '2026-09-30']);
pruefe(in_array($hinweis, explode("\n", $erst['text']), true) && $kostenzeile($dann) !== '' && !str_contains($dann['text'], 'No costs'), 'nach setTariff gilt die Einheit der neuen Preisvariable: ' . mb_substr($dann['text'], 0, 300));

ende('Kosten über lange Zeiträume');
