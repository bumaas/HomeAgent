<?php

declare(strict_types=1);

/*
 * Wärmemenge, Arbeitszahl und Leistungszahl der Wärmepumpe (Backlog B83, Nachfrage aus einer betreuten Anlage 21.10.2026:
 * „mein Claude hat keinen Zugriff auf den Wärmemengenzähler“). Ein Wärmezähler zählt nie in einer Stromsumme; mit dem
 * Stromzähler desselben Geräts nennt die Zeile die Arbeitszahl (Summe Wärme ÷ Summe Strom), mit Wärmeleistung und Leistung
 * die Leistungszahl von jetzt (COP).
 *
 * Echt sind alle Werte und die beiden Zeilen der Freigabeliste (betreut_2026-10-21_waermepumpe.json). Angenommen ist die
 * Zuordnung: Wärmezähler Heizen #58176 und Warmwasser #47620 an den Zeilen mit den Stromzählern Heizen bzw. Warmwasser,
 * Wärmeleistung #53194 und Aufnahmeleistung #27660 an der Zeile „Wärmepumpe“. Freigegeben sind sie dort noch nicht.
 * Orakel sind die Tagesaggregate der Fixture.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$wp = fixture('betreut_2026-10-21_waermepumpe.json');
date_default_timezone_set($wp['zeitzone']);
[$heizen, $warmwasser] = $wp['geraete'];
$heizen = array_merge($heizen, ['HeatVariableID' => 58176, 'HeatPowerVariableID' => 53194, 'PowerVariableID' => 27660]);
$warmwasser = array_merge($warmwasser, ['HeatVariableID' => 47620]);

function frage(array $liste, array $argumente): array
{
    global $wp;
    $anlage = new FakeBackend($wp['variablen']);
    $anlage->jetzt = $wp['jetzt'];
    try {
        return (new DeviceIndex($liste, $anlage))->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

/** kWh eines Zählers aus den Tagesaggregaten, von $von bis vor $bis. */
function kwh(int $id, int $von, int $bis): float
{
    global $wp;
    $summe = 0.0;
    foreach ($wp['variablen'][(string)$id]['aggregate']['1'] as $a) {
        if ($a['zeit'] >= $von && $a['zeit'] < $bis) {
            $summe += $a['summe'];
        }
    }
    return strtolower($wp['variablen'][(string)$id]['einheit']) === 'mwh' ? $summe * 1000 : $summe;
}

$zahl = static fn (float $wert): string => rtrim(rtrim(number_format($wert, 1, '.', ''), '0'), '.');
$zeilen = static fn (array $r): array => explode("\n", $r['text']);

/* --- Rollen ---------------------------------------------------------------------------------------- */

pruefe((DeviceIndex::ROLES['heat'] ?? '') === 'HeatVariableID' && (DeviceIndex::ROLES['heatpower'] ?? '') === 'HeatPowerVariableID', 'Rollen heat und heatpower mit eigenen Feldern');
$rollen = DeviceIndex::TYPE_ROLES['appliance']['roles'];
pruefe(in_array('heat', $rollen, true) && in_array('heatpower', $rollen, true), 'Gerät: Wärmezähler und Wärmeleistung wahlweise: ' . json_encode($rollen));
pruefe(DeviceIndex::TYPE_ROLES['appliance']['required'] === ['state', 'power'], 'die Pflichtfelder des Geräts bleiben');
pruefe(!in_array('heat', DeviceIndex::TYPE_ROLES['meter']['roles'], true), 'ein Zähler (meter) bekommt keine Wärme, er misst Strom');

/* --- Arbeitszahl über einen Monat ------------------------------------------------------------------ */

$von = mktime(0, 0, 0, 9, 1, 2026);
$bis = mktime(0, 0, 0, 10, 1, 2026);
$stromH = kwh(55642, $von, $bis);
$stromW = kwh(38886, $von, $bis);
$waermeH = kwh(58176, $von, $bis);
$waermeW = kwh(47620, $von, $bis);

$r = frage([$heizen, $warmwasser], ['room' => 'Heizungsraum', 'period' => 'last_month']);
$z = $zeilen($r);
pruefe(!$r['isError'] && str_starts_with($z[0], $zahl($stromH + $stromW) . ' kWh consumed by 2 of 2 metered device(s)'), 'die Summe zählt nur Strom (' . $zahl($stromH + $stromW) . ' kWh): ' . $z[0]);
$erwartet = 'Heat ' . $zahl($waermeH + $waermeW) . ' kWh from ' . $zahl($stromH + $stromW) . ' kWh electricity, performance factor ' . $zahl(($waermeH + $waermeW) / ($stromH + $stromW)) . ' (2 device(s) with heat meter).';
pruefe(($z[1] ?? '') === $erwartet, 'Kopfzeile mit Wärme und Arbeitszahl beider Zeilen: ' . ($z[1] ?? '-') . ' / erwartet ' . $erwartet);
$zeileH = 'Heizungsraum | Wärmepumpe | appliance | ' . $zahl($stromH) . ' kWh, heat ' . $zahl($waermeH) . ' kWh, performance factor ' . $zahl($waermeH / $stromH);
pruefe(in_array($zeileH, $z, true), 'Zeile Heizen mit Wärme und Arbeitszahl (' . $zeileH . '): ' . $r['text']);
$zeileW = 'Heizungsraum | Wärmepumpe Warmwasser | appliance | ' . $zahl($stromW) . ' kWh, heat ' . $zahl($waermeW) . ' kWh, performance factor ' . $zahl($waermeW / $stromW);
pruefe(in_array($zeileW, $z, true), 'Zeile Warmwasser (' . $zeileW . '): ' . $r['text']);

// Ein Gerät allein: keine eigene Kopfzeile, die Zeile sagt alles
$r = frage([$heizen, $warmwasser], ['name' => 'Wärmepumpe Warmwasser', 'period' => 'last_month']);
pruefe(!$r['isError'] && !str_contains($r['text'], 'device(s) with heat meter') && in_array($zeileW, $zeilen($r), true), 'ein Gerät: nur seine Zeile: ' . $r['text']);

/* --- Im Juni zählte der Verdichter fürs Heizen keine ganze kWh: keine Arbeitszahl aus 0 kWh -------- */

$juni = [mktime(0, 0, 0, 6, 1, 2026), mktime(0, 0, 0, 7, 1, 2026)];
$r = frage([$heizen], ['name' => 'Wärmepumpe', 'room' => 'Heizungsraum', 'from' => '2026-06-01', 'to' => '2026-06-30']);
$zeile = 'Heizungsraum | Wärmepumpe | appliance | 0 kWh, heat ' . $zahl(kwh(58176, ...$juni)) . ' kWh';
pruefe(kwh(55642, ...$juni) === 0.0 && kwh(58176, ...$juni) > 0 && !$r['isError'] && in_array($zeile, $zeilen($r), true), 'Strom 0, Wärme ' . $zahl(kwh(58176, ...$juni)) . ' kWh: ohne Arbeitszahl (' . $zeile . '): ' . $r['text']);

/* --- Wärmezähler ohne Stromzähler, falsche Einheit ----------------------------------------------- */

$ohneStrom = array_merge($warmwasser, ['EnergyVariableID' => 0, 'StateVariableID' => 0, 'PowerVariableID' => 0]);
$ohneStrom['Type'] = 'appliance';
$r = frage([array_merge($ohneStrom, ['PowerVariableID' => 27660])], ['name' => 'Wärmepumpe Warmwasser', 'period' => 'last_month']);
pruefe(!$r['isError'] && in_array('Heizungsraum | Wärmepumpe Warmwasser | appliance | heat ' . $zahl($waermeW) . ' kWh', $zeilen($r), true), 'nur Wärmezähler: Wärme ohne Arbeitszahl: ' . $r['text']);

$falsch = array_merge($warmwasser, ['HeatVariableID' => 53194]);
$r = frage([$falsch], ['name' => 'Wärmepumpe Warmwasser', 'period' => 'last_month']);
// Wie beim Stromzähler: Was nicht als Zähler archiviert ist, hat für das Backend keine Summe
pruefe(str_contains($r['text'], '28 kWh, heat: no history archived') && !str_contains($r['text'], 'performance factor'), 'eine Leistung als Wärmezähler ergibt keine Wärme: ' . $r['text']);

/* --- Leistungszahl jetzt ------------------------------------------------------------------------- */

$strom = (float)$wp['variablen']['27660']['wert'] * 1000;
$waerme = (float)$wp['variablen']['53194']['wert'] * 1000;
$r = frage([$heizen, $warmwasser], ['room' => 'Heizungsraum', 'period' => 'now']);
$zeile = 'Heizungsraum | Wärmepumpe | appliance | ' . round($strom) . ' W, heat ' . round($waerme) . ' W, COP ' . $zahl($waerme / $strom);
pruefe(!$r['isError'] && in_array($zeile, $zeilen($r), true), 'Leistung jetzt mit Wärmeleistung und COP (' . $zeile . '): ' . $r['text']);
pruefe(str_starts_with($r['text'], round($strom) . ' W used by 1 of 1 device(s) with power measurement'), 'die Summe zählt nur die elektrische Leistung: ' . $zeilen($r)[0]);

ende('Wärmemenge und Arbeitszahl');
