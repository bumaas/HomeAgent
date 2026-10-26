<?php

declare(strict_types=1);

/*
 * Wärme als Umweltertrag (Mitschnitt nuc 26.10.2026): Eine Vaillant über ebusd meldet als „Energieertrag“ und
 * „Ertragsleistung“ nur die Wärme aus der Umwelt, ohne den Strom. Mit dem Haken „Wärme ist Umweltertrag“ (HeatIsYield)
 * rechnet Home Agent den Strom dazu: Wärme = Ertrag + Strom, Arbeitszahl = Wärme ÷ Strom, jetzt ebenso mit den Leistungen.
 * Anlass: Home Agent meldete für September Arbeitszahl 3,5, die Wärmepumpe selbst 4,5.
 *
 * Echt sind die Zeile der Freigabeliste und alle Archivwerte. Angenommen sind die Momentanwerte für „jetzt“: Beim
 * Mitschnitt stand die Wärmepumpe, der Test setzt die Werte aus dem Archiv von 13:55 ein (Ertragsleistung 3,9 kW,
 * Strom 1,0 kW). Ohne Strom am Gerät (Zeile ohne Zähler und Leistung) ist angenommen, die gibt es am nuc nicht.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$wp = fixture('nuc_2026-10-26_umweltertrag.json');
date_default_timezone_set($wp['zeitzone']);
$zeile = $wp['geraete'][0];
$ertrag = $zeile + ['HeatIsYield' => true];

function frage(array $liste, array $argumente, array $werte = []): array
{
    global $wp;
    $variablen = $wp['variablen'];
    foreach ($werte as $id => $wert) {
        $variablen[(string)$id]['wert'] = $wert;
    }
    $anlage = new FakeBackend($variablen);
    $anlage->jetzt = $wp['jetzt'];
    try {
        return (new DeviceIndex($liste, $anlage))->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

/** Summe der Tagesaggregate eines Zählers von $von bis vor $bis. */
function kwh(int $id, int $von, int $bis): float
{
    global $wp;
    $summe = 0.0;
    foreach ($wp['variablen'][(string)$id]['aggregate']['1'] as $a) {
        if ($a['zeit'] >= $von && $a['zeit'] < $bis) {
            $summe += $a['summe'];
        }
    }
    return $summe;
}

/** kWh aus den Stundenmitteln einer Leistung in kW, von $von bis vor $bis. */
function ausLeistung(int $id, int $von, int $bis): float
{
    global $wp;
    $summe = 0.0;
    foreach ($wp['variablen'][(string)$id]['aggregate']['0'] as $a) {
        if ($a['zeit'] >= $von && $a['zeit'] < $bis) {
            $summe += $a['mittel'];
        }
    }
    return $summe;
}

$zahl = static fn (float $wert): string => rtrim(rtrim(number_format($wert, 1, '.', ''), '0'), '.');
$zeilen = static fn (array $r): array => explode("\n", $r['text']);

/* --- Feld ------------------------------------------------------------------------------------------ */

$idx = new DeviceIndex([$ertrag, $zeile], new FakeBackend($wp['variablen']));
pruefe(($idx->entries()[0]['HeatIsYield'] ?? null) === true && ($idx->entries()[1]['HeatIsYield'] ?? null) === false, 'HeatIsYield wird übernommen, ohne Angabe aus: ' . json_encode(array_column($idx->entries(), 'HeatIsYield')));

/* --- Arbeitszahl über einen Monat ------------------------------------------------------------------ */

$von = mktime(0, 0, 0, 9, 1, 2026);
$bis = mktime(0, 0, 0, 10, 1, 2026);
$strom = kwh(46487, $von, $bis);
$umwelt = kwh(56021, $von, $bis);
pruefe($strom === 50.0 && $umwelt === 173.0, 'Orakel September: Strom 50 kWh, Umweltertrag 173 kWh: ' . $strom . ' / ' . $umwelt);

$r = frage([$zeile], ['name' => 'Wärmepumpe', 'period' => 'last_month']);
pruefe(in_array('Keller | Wärmepumpe | appliance | 50 kWh, heat 173 kWh, performance factor 3.5', $zeilen($r), true), 'ohne Haken bleibt es, wie es war: ' . $r['text']);

$r = frage([$ertrag], ['name' => 'Wärmepumpe', 'period' => 'last_month']);
$erwartet = 'Keller | Wärmepumpe | appliance | 50 kWh, heat ' . $zahl($umwelt + $strom) . ' kWh, performance factor ' . $zahl(($umwelt + $strom) / $strom) . ' (environmental yield ' . $zahl($umwelt) . ' kWh plus electricity)';
pruefe(!$r['isError'] && in_array($erwartet, $zeilen($r), true), 'mit Haken: Wärme 223 kWh, Arbeitszahl 4,5, der Ertrag genannt (' . $erwartet . '): ' . $r['text']);
pruefe(str_starts_with($zeilen($r)[0], '50 kWh consumed by 1 of 1 metered device(s)'), 'die Stromsumme bleibt Strom: ' . $zeilen($r)[0]);

/* --- Wärme aus den Leistungen ---------------------------------------------------------------------- */

$gestern = strtotime('yesterday', $wp['jetzt']);
$heute = strtotime('today', $wp['jetzt']);
$ohneZaehler = array_merge($ertrag, ['HeatVariableID' => 1]);
$umweltL = ausLeistung(52797, $gestern, $heute);
$stromL = ausLeistung(10025, $gestern, $heute);
pruefe($umweltL > 0 && $stromL > 0, 'Orakel gestern aus den Leistungen: ' . $umweltL . ' / ' . $stromL);
$r = frage([$ohneZaehler], ['name' => 'Wärmepumpe', 'period' => 'yesterday']);
$erwartet = 'from power values: heat ' . $zahl($umweltL + $stromL) . ' kWh from ' . $zahl($stromL) . ' kWh electricity, performance factor ' . $zahl(($umweltL + $stromL) / $stromL) . ' (environmental yield ' . $zahl($umweltL) . ' kWh plus electricity)';
pruefe(str_contains($r['text'], $erwartet), 'aus Ertragsleistung und Leistung (' . $erwartet . '): ' . $r['text']);

/* --- jetzt ----------------------------------------------------------------------------------------- */

$r = frage([$zeile], ['name' => 'Wärmepumpe', 'type' => 'power'], [52797 => 3.9, 10025 => 1.0]);
pruefe(str_contains($r['text'], '1000 W, heat 3900 W, COP 3.9'), 'ohne Haken: COP aus dem Ertrag allein: ' . $r['text']);
$r = frage([$ertrag], ['name' => 'Wärmepumpe', 'type' => 'power'], [52797 => 3.9, 10025 => 1.0]);
pruefe(str_contains($r['text'], '1000 W, heat 4900 W, COP 4.9 (environmental yield 3900 W plus electricity)'), 'mit Haken: 4,9 kW Wärme wie aus Durchfluss und Spreizung: ' . $r['text']);

/* --- ohne Strom ------------------------------------------------------------------------------------ */

$ohneStrom = array_merge($ertrag, ['EnergyVariableID' => 1, 'PowerVariableID' => 1, 'StateVariableID' => 10025]);
$r = frage([$ohneStrom], ['name' => 'Wärmepumpe', 'period' => 'last_month']);
pruefe(str_contains($r['text'], 'environmental yield 173 kWh, no electricity to add') && !str_contains($r['text'], 'performance factor'), 'ohne Strom keine Wärme und keine Arbeitszahl, nur der Ertrag: ' . $r['text']);
$ohne = method_exists(DeviceIndex::class, 'yieldWithoutElectricity') ? DeviceIndex::yieldWithoutElectricity([$ohneStrom, $ertrag, $zeile]) : null;
pruefe($ohne === [0], 'der Selbsttest findet die Zeile ohne Strom: ' . json_encode($ohne));

ende('Wärme als Umweltertrag');
