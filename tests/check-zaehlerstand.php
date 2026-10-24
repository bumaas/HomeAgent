<?php

declare(strict_types=1);

/*
 * Plausibilität der Energiezähler im Selbsttest (Backlog B78, Burkhard 14.10.2026). Das Archiv zählt bei einem Zähler die
 * positiven Sprünge (Doku AC_GetAggregatedValues: „Summe der positiven Delta“). Ein einzelner Fehlwert nach oben und
 * zurück zählt deshalb als Verbrauch: Die Summe des Monats ist größer, als der Zählerstand gestiegen ist, und zwar um
 * etwa so viel, wie sie über einem üblichen Monat liegt. Setzt ein Zähler dagegen zurück (Homematic ENERGY_COUNTER nach
 * einem Stromausfall, Tausch), ist die Summe auch größer als der Zuwachs, aber normal hoch; das Archiv zählt richtig.
 *
 * Mitschnitt mix_2026-10-17_zaehlerstand.json (echte Werte zweier Anlagen, siehe _quelle). Die Erwartungen sind die
 * bekannten Befunde: die Ausreißer im Januar 2024 beim „Verbrauch“ der betreuten Anlage (der Neustart im Mai 2026 ist
 * keiner), die am 17.10.2026 reparierten PV- und Einspeisezähler dort, die Fehlwerte der Wärmepumpe (B40) und die
 * hin- und herspringende Wallbox am nuc; Rücksetzungen der Homematic-Zähler auf 0 sind keine Befunde. Die Beträge und
 * die Art jedes Rücksprungs stammen aus eigenen Abfragen der Rohwerte an den Anlagen (17.10.2026).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('mix_2026-10-17_zaehlerstand.json');
date_default_timezone_set($mitschnitt['zeitzone']);

/** @var array<int, int> abgefragte Zählerstände je Variable beim letzten Aufruf von sprunge() */
$abgelesen = [];
/** @var list<array{0: int, 1: int}> abgefragte Zählerreihen [Variable, Stufe] beim letzten Aufruf von sprunge() */
$reihen = [];

function sprunge(string $anlage, ?array $geraete = null, ?array $quellen = null): array
{
    global $mitschnitt, $abgelesen, $reihen;
    $a = $mitschnitt[$anlage];
    $backend = new FakeBackend($a['variablen']);
    $backend->jetzt = $a['jetzt'];
    $index = new DeviceIndex($geraete ?? $a['geraete'], $backend);
    $index->addSources($quellen ?? $a['quellen']);
    if (!method_exists($index, 'counterJumps')) {
        return ['fehlt' => 'counterJumps'];
    }
    $liste = [];
    foreach ($index->counterJumps() as $sprung) {
        $liste[$sprung['variable']] = $sprung;
    }
    $abgelesen = $backend->readingRequests;
    $reihen = $backend->seriesRequests;
    return $liste;
}

/** Monate eines Befunds als JJJJ-MM => kWh auf eine Stelle. */
function monate(?array $sprung): array
{
    $liste = [];
    foreach ($sprung['months'] ?? [] as $beginn => $kwh) {
        $liste[date('Y-m', (int)$beginn)] = round((float)$kwh, 1);
    }
    return $liste;
}

/* --- betreute Anlage ------------------------------------------------------------------------------ */

$b = sprunge('betreut');
pruefe(!isset($b['fehlt']), 'DeviceIndex::counterJumps gibt es');
pruefe(monate($b[14339] ?? null) === ['2024-01' => 659.8], 'Verbrauch: Ausreißer im Januar 2024, der Neustart im Mai 2026 zählt nicht: ' . json_encode(monate($b[14339] ?? null)));
pruefe(($b[14339]['label'] ?? '') === 'Verbrauch' && ($b[14339]['key'] ?? null) === '', 'ein Gerät heißt nach Raum und Name, ohne Teil der Bilanz: ' . json_encode(array_diff_key($b[14339] ?? [], ['months' => 0]), JSON_UNESCAPED_UNICODE));
pruefe(!isset($b[25173]), 'der reparierte PV-Zähler geht auf: ' . json_encode(monate($b[25173] ?? null)));
pruefe(!isset($b[11314]), 'die Einspeisung (Nullen ignoriert, der erste Satz 0) geht auf: ' . json_encode(monate($b[11314] ?? null)));
pruefe(count($b) === 1, 'nur der Verbrauch fällt auf: ' . json_encode(array_keys($b)));
// Mit „Nullen ignorieren“ und einem ersten Satz 0 fehlt der Stand vor dem ersten Satz; die Gesamtprobe beginnt dann mit
// dem zweiten Monat und erspart die Monatsprüfung (alter Einspeisezähler der betreuten Anlage, Sommermonate weit über dem
// Median: 1,9 s, 18.10.2026)
pruefe(!isset($b[18681]) && ($abgelesen[18681] ?? 99) <= 3, 'alte Einspeisung mit erstem Satz 0: kein Befund, die Gesamtprobe ab dem zweiten Monat reicht: ' . ($abgelesen[18681] ?? 0) . ' Zeitpunkte abgelesen');

/* --- nuc ------------------------------------------------------------------------------------------ */

$n = sprunge('nuc');
pruefe(array_keys(monate($n[56021] ?? null)) === ['2026-07', '2026-08', '2026-10'], 'Wärmepumpe: Fehlwerte im Juli, August und Oktober 2026 (B40): ' . json_encode(monate($n[56021] ?? null)));
pruefe((monate($n[56021] ?? null)['2026-08'] ?? 0) > 4.2e9, 'der August mit über vier Milliarden kWh: ' . json_encode(monate($n[56021] ?? null)));
// B99: „Name (Raum)“ wie die Fehler der Liste, nicht „Raum Name“ ohne Grenze
pruefe(($n[56021]['label'] ?? '') === 'Wärmepumpe Heizen (Keller)', 'mit Raum in Klammern: ' . ($n[56021]['label'] ?? ''));
pruefe(!isset($n[47491]), 'Einspeisung: 2.5 kWh Abweichung im November 2024 sind kein Befund: ' . json_encode(monate($n[47491] ?? null)));
pruefe(monate($n[36579] ?? null) === ['2026-05' => 27.2], 'Wallbox in Wh, gerechnet in kWh: 41 Rücksprünge im Mai 2026, der einzelne im August und kleine unter einem Fünftel eines üblichen Monats zählen nicht: ' . json_encode(monate($n[36579] ?? null)));
pruefe(!isset($n[16183]), 'Schalterleiste: Rücksetzungen auf 0 in 2023 und 2024 sind kein Befund: ' . json_encode(monate($n[16183] ?? null)));
pruefe(!isset($n[37729]), 'Steckdose Fernseher: Rücksetzungen im August 2023 bei doppeltem Sommerverbrauch sind kein Befund: ' . json_encode(monate($n[37729] ?? null)));
pruefe(!isset($n[17393]),'Kühltruhe: 34 Monate mit Rücksetzung, keiner ein Befund: ' . json_encode(monate($n[17393] ?? null)));
// Abgelesen werden nur die Monate, deren Summe überhaupt auffallen kann (mehr als drei Viertel der Schwelle über dem
// Median): In einer betreuten Anlage kostete jede Ablesung bei einem Zähler, der alle paar Sekunden schreibt, bis zu 35 ms,
// der Selbsttest brauchte 8,2 s (18.10.2026).
// Vorher 111 bzw. 75 Zeitpunkte (je Monat beide Grenzen), jetzt die Gesamtprobe (bei der Kühltruhe mit erstem Satz 0 auch
// ab dem zweiten Monat) und die Grenzen der auffälligen Monate.
pruefe(($abgelesen[17393] ?? 0) <= 17, 'Kühltruhe: nur die Grenzen auffälliger Monate abgelesen: ' . ($abgelesen[17393] ?? 0));
pruefe(($abgelesen[37729] ?? 0) <= 40, 'Steckdose Fernseher (Sommermonate über dem Median): ebenso: ' . ($abgelesen[37729] ?? 0));

/* --- Bilanz: Teil und Name der Quelle ------------------------------------------------------------- */

// Derselbe Zähler als Quelle statt als Gerät: der Befund nennt den Teil der Bilanz und den Namen der Zeile.
$alsQuelle = sprunge('betreut', [], [['Type' => 'grid', 'Name' => 'Netz', 'EnergyIn' => 14339, 'EnergyOut' => 0, 'PowerIn' => 0, 'PowerOut' => 0, 'Invert' => false]]);
pruefe(($alsQuelle[14339]['key'] ?? '') === 'import' && ($alsQuelle[14339]['label'] ?? '') === 'Netz', 'als Quelle: Teil import, Name der Zeile: ' . json_encode(array_diff_key($alsQuelle[14339] ?? [], ['months' => 0]), JSON_UNESCAPED_UNICODE));
// Steht ein Zähler in der Bilanz und an einem Gerät, gibt es ihn einmal, unter der Bilanz.
$doppelt = sprunge('betreut', null, [['Type' => 'grid', 'Name' => 'Netz', 'EnergyIn' => 14339, 'EnergyOut' => 0, 'PowerIn' => 0, 'PowerOut' => 0, 'Invert' => false]]);
pruefe(count($doppelt) === 1 && ($doppelt[14339]['key'] ?? '') === 'import', 'derselbe Zähler zweimal: ein Befund, unter der Bilanz: ' . json_encode(array_map(static fn (mixed $s): string => is_array($s) ? $s['key'] . ' ' . $s['label'] : (string)$s, $doppelt), JSON_UNESCAPED_UNICODE));

// Der Stand „jetzt“ geht als DeviceBackend::LATEST ans Backend, nicht als Uhrzeit (Review 18.10.2026, N3): Das Backend
// vergleicht mit seiner eigenen, späteren Uhr, und eine Sekunde später kam der Satz wieder teuer aus dem Archiv.
$a = $mitschnitt['nuc'];
$backend = new FakeBackend($a['variablen']);
$backend->jetzt = $a['jetzt'];
$index = new DeviceIndex($a['geraete'], $backend);
$index->addSources($a['quellen']);
$index->counterJumps();
$latest = defined('DeviceBackend::LATEST') ? constant('DeviceBackend::LATEST') : -1;
pruefe(in_array($latest, $backend->readingTimes, true) && !in_array($a['jetzt'] + 1, $backend->readingTimes, true), 'der Selbsttest fragt den Stand jetzt als LATEST ab: ' . json_encode(array_values(array_unique(array_filter($backend->readingTimes, static fn (int $t): bool => $t >= $a['jetzt'])))));

ende('Plausibilität der Zähler');
