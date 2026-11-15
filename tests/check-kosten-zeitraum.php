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

/** @return array{0: array, 1: FakeBackend} Antwort und die Anlage mit ihren Abfragen */
function frage(string $werkzeug, array $argumente): array
{
    global $mitschnitt;
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

ende('Kosten über lange Zeiträume');
