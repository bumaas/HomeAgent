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

function sprunge(string $anlage, ?array $geraete = null, ?array $quellen = null): array
{
    global $mitschnitt;
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

/* --- nuc ------------------------------------------------------------------------------------------ */

$n = sprunge('nuc');
pruefe(array_keys(monate($n[56021] ?? null)) === ['2026-07', '2026-08', '2026-10'], 'Wärmepumpe: Fehlwerte im Juli, August und Oktober 2026 (B40): ' . json_encode(monate($n[56021] ?? null)));
pruefe((monate($n[56021] ?? null)['2026-08'] ?? 0) > 4.2e9, 'der August mit über vier Milliarden kWh: ' . json_encode(monate($n[56021] ?? null)));
pruefe(($n[56021]['label'] ?? '') === 'Keller Wärmepumpe Heizen', 'mit Raum: ' . ($n[56021]['label'] ?? ''));
pruefe(!isset($n[47491]), 'Einspeisung: 2.5 kWh Abweichung im November 2024 sind kein Befund: ' . json_encode(monate($n[47491] ?? null)));
pruefe(monate($n[36579] ?? null) === ['2026-05' => 27.2], 'Wallbox in Wh, gerechnet in kWh: 41 Rücksprünge im Mai 2026, der einzelne im August und kleine unter einem Fünftel eines üblichen Monats zählen nicht: ' . json_encode(monate($n[36579] ?? null)));
pruefe(!isset($n[16183]), 'Schalterleiste: Rücksetzungen auf 0 in 2023 und 2024 sind kein Befund: ' . json_encode(monate($n[16183] ?? null)));
pruefe(!isset($n[37729]), 'Steckdose Fernseher: Rücksetzungen im August 2023 bei doppeltem Sommerverbrauch sind kein Befund: ' . json_encode(monate($n[37729] ?? null)));
pruefe(!isset($n[17393]),'Kühltruhe: 34 Monate mit Rücksetzung, keiner ein Befund: ' . json_encode(monate($n[17393] ?? null)));

/* --- Bilanz: Teil und Name der Quelle ------------------------------------------------------------- */

// Derselbe Zähler als Quelle statt als Gerät: der Befund nennt den Teil der Bilanz und den Namen der Zeile.
$alsQuelle = sprunge('betreut', [], [['Type' => 'grid', 'Name' => 'Netz', 'EnergyIn' => 14339, 'EnergyOut' => 0, 'PowerIn' => 0, 'PowerOut' => 0, 'Invert' => false]]);
pruefe(($alsQuelle[14339]['key'] ?? '') === 'import' && ($alsQuelle[14339]['label'] ?? '') === 'Netz', 'als Quelle: Teil import, Name der Zeile: ' . json_encode(array_diff_key($alsQuelle[14339] ?? [], ['months' => 0]), JSON_UNESCAPED_UNICODE));
// Steht ein Zähler in der Bilanz und an einem Gerät, gibt es ihn einmal, unter der Bilanz.
$doppelt = sprunge('betreut', null, [['Type' => 'grid', 'Name' => 'Netz', 'EnergyIn' => 14339, 'EnergyOut' => 0, 'PowerIn' => 0, 'PowerOut' => 0, 'Invert' => false]]);
pruefe(count($doppelt) === 1 && ($doppelt[14339]['key'] ?? '') === 'import', 'derselbe Zähler zweimal: ein Befund, unter der Bilanz: ' . json_encode(array_map(static fn (mixed $s): string => is_array($s) ? $s['key'] . ' ' . $s['label'] : (string)$s, $doppelt), JSON_UNESCAPED_UNICODE));

ende('Plausibilität der Zähler');
