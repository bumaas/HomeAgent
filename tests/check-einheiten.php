<?php

declare(strict_types=1);

/*
 * Vorsilben der Einheiten: „m“ ist milli, „M“ ist Mega (Prompttest am nuc, 22.10.2026). Das Modul schrieb jede Einheit
 * klein, bevor es sie verglich; „mW“ wurde so zu „mw“ und zählte als Megawatt, „mWh“ als Megawattstunde. Matter meldet
 * Leistung in mW und Energie in mWh: Die Deckenleuchte im Gästezimmer stand mit 600 mW als 600 Millionen W da.
 *
 * Echt sind Variablen, Werte und Einheiten (nuc_2026-10-22_matter_mw.json). Keine der Matter-Variablen ist archiviert;
 * für Verlauf und Zählersumme prüft der Test die Umrechnung deshalb mit den echten Einheiten an angenommenen Zahlen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-22_matter_mw.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$leuchte = 35903;
$stecker = 20930;
pruefe($werte[(string)$leuchte]['einheit'] === 'mW' && $werte[(string)$leuchte]['wert'] == 600, 'Mitschnitt: Leuchte mit 600 mW');
$energieEinheit = $mitschnitt['instanzen']['28603']['kinder']['35373']['einheit'];
pruefe($energieEinheit === 'mWh', 'Mitschnitt: Energiezähler des Steckers in mWh: ' . $energieEinheit);

$liste = [
    ['Room' => 'Gast', 'Name' => 'Deckenlicht', 'Aliases' => '', 'Type' => 'appliance', 'StateVariableID' => 0, 'PowerVariableID' => $leuchte, 'Invert' => false, 'ReadOnly' => true],
    ['Room' => 'Garten', 'Name' => 'Grill', 'Aliases' => '', 'Type' => 'appliance', 'StateVariableID' => 0, 'PowerVariableID' => $stecker, 'Invert' => false, 'ReadOnly' => true],
];
$anlage = new FakeBackend($werte);
$anlage->jetzt = $mitschnitt['jetzt'];
$idx = new DeviceIndex($liste, $anlage);

/* --- Leistung jetzt ------------------------------------------------------------------------------- */

$r = $idx->getState(['room' => 'Gast', 'period' => 'now']);
pruefe(!str_contains($r['text'], '600000000') && str_contains($r['text'], 'Gast | Deckenlicht | appliance | 1 W'), '600 mW sind gerundet 1 W, nicht 600 Millionen: ' . $r['text']);
$r = $idx->getState(['room' => 'Garten', 'period' => 'now']);
pruefe(str_contains($r['text'], 'Garten | Grill | appliance | 0 W'), '0 mW sind 0 W: ' . $r['text']);
pruefe(DeviceIndex::isPowerUnit('mW') && DeviceIndex::isPowerUnit('MW') && DeviceIndex::isPowerUnit('kW') && DeviceIndex::isPowerUnit('W') && DeviceIndex::isPowerUnit(''), 'mW, MW, kW, W und keine Einheit sind Leistung');
pruefe(!DeviceIndex::isPowerUnit('mVA') && !DeviceIndex::isPowerUnit('mWh') && !DeviceIndex::isPowerUnit('mA'), 'mVA, mWh und mA sind keine Leistung');

/* --- Zählersumme in kWh --------------------------------------------------------------------------- */

$kwh = new ReflectionMethod(DeviceIndex::class, 'kilowattHours');
$summe = static fn (string $einheit): ?float => $kwh->invoke(null, ['sum' => 5000.0, 'unit' => $einheit]);
pruefe(abs($summe($energieEinheit) - 0.005) < 1e-12, '5000 mWh sind 0,005 kWh: ' . var_export($summe($energieEinheit), true));
pruefe($summe('MWh') === 5000000.0 && $summe('kWh') === 5000.0 && $summe('Wh') === 5.0 && $summe('') === 5000.0, 'MWh, kWh, Wh und ohne Einheit (kWh) wie bisher');
pruefe(DeviceIndex::isEnergyUnit('mWh') && DeviceIndex::isEnergyUnit('MWh') && !DeviceIndex::isEnergyUnit('mW'), 'mWh und MWh sind Energie, mW nicht');

/* --- Verlauf in kW --------------------------------------------------------------------------------- */

$verlauf = new ReflectionMethod(DeviceIndex::class, 'kilowattHistory');
$zeile = ['time' => $mitschnitt['jetzt'], 'min' => 600.0, 'avg' => 600.0, 'max' => 600.0];
$kw = static fn (string $einheit): float => $verlauf->invoke(null, ['unit' => $einheit, 'rows' => [$zeile]], false)['rows'][0]['avg'];
pruefe(abs($kw('mW') - 0.0006) < 1e-12, '600 mW sind 0,0006 kW: ' . $kw('mW'));
pruefe($kw('MW') === 600000.0 && $kw('kW') === 600.0 && $kw('W') === 0.6, 'MW, kW und W wie bisher');

ende('Einheiten');
