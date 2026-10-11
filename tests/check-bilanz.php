<?php

declare(strict_types=1);

/*
 * Energiebilanz (Mitschnitt nuc 09.10.2026, 23:08): Den Hausverbrauch rechnet das Modul wie Home
 * Assistant selbst, Netzbezug + PV-Ertrag + Batterie entladen − Einspeisung − Batterie geladen, aus
 * den Zählern, die jede Anlage hat (Entscheidung Burkhard 09.10.2026: keine berechneten Zähler
 * voraussetzen). Die Geräte mit eigenem Zähler sind ein Teil davon und werden nie dazuaddiert, der
 * Rest heißt „nicht erfasst“. Dazu die Wärmepumpe als Gerät nur mit Leistung und Zähler, ohne
 * Schaltzustand. Die Erwartungswerte sind aus denselben Aggregaten von Hand gerechnet; September
 * 2026 stimmt mit Burkhards Skript #23386 überein (Haushaltsbedarf 634,1 + Auto 140,7 = 774,8 kWh).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-09_energiebilanz.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$bilanz = $mitschnitt['bilanz'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var list<FakeBackend> */
$anlagen = [];

function index(array $liste, array $werte, array $bilanz, ?array $uebersetzungen = null): DeviceIndex
{
    global $anlagen, $mitschnitt;
    $anlagen[] = $backend = new FakeBackend($werte, $uebersetzungen);
    $backend->jetzt = $mitschnitt['jetzt'];
    return new DeviceIndex($liste, $backend, null, '', $bilanz);
}

/** Ruft getState bzw. setState auf und fängt Fehler ab, damit der Test vor dem Code durchläuft. */
function frage(DeviceIndex $idx, array $argumente, bool $stellen = false): array
{
    try {
        return $stellen ? $idx->setState($argumente) : $idx->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';

/* --- Bilanzfelder --------------------------------------------------------------------------- */

pruefe((defined(DeviceIndex::class . '::BALANCE') ? constant(DeviceIndex::class . '::BALANCE') : null) === [
    'import' => 'GridImportVariableID', 'export' => 'GridExportVariableID', 'solar' => 'SolarVariableID',
    'charge' => 'BatteryChargeVariableID', 'discharge' => 'BatteryDischargeVariableID',
], 'fünf Bilanzfelder mit den Namen der Properties');

/* --- Hausverbrauch fürs ganze Haus ------------------------------------------------------------ */

$idx = index($liste, $werte, $bilanz);
$r = frage($idx, ['period' => 'last_month']);
pruefe(!$r['isError'] && $zeile($r, 0) === 'House consumption 774.8 kWh (all devices, period=last_month): grid import 122.7, solar 1356.8, battery discharge 285, grid export 658.7, battery charge 330.9 kWh.', 'September: Hausverbrauch aus der Bilanz, mit allen Teilen: ' . $r['text']);
pruefe($zeile($r, 1) === 'Self-sufficiency 84 %, self-consumed solar 51 %.', 'Autarkie und Eigenverbrauch der PV (wie HA, von Hand: 84,17 % und 51,45 %): ' . $r['text']);
pruefe($zeile($r, 2) === 'Of this, 174.5 kWh by 4 of 4 metered device(s), 600.3 kWh not metered.', 'zweite Zeile: Geräte als Teil davon, Rest nicht erfasst: ' . $r['text']);
pruefe(str_contains($r['text'], "\nTechnik | Wärmepumpe | appliance | 86 kWh") && str_contains($r['text'], "\nKeller | Entfeuchter | switch | 74.1 kWh"), 'danach die Geräte, die Wärmepumpe mit ihrem Zähler: ' . $r['text']);
pruefe(!str_contains($r['text'], 'consumed by'), 'die alte Kopfzeile mit der Gerätesumme entfällt fürs ganze Haus: ' . $r['text']);

$r = frage($idx, ['period' => 'today']);
pruefe(str_starts_with($zeile($r, 0), 'House consumption 27.2 kWh (all devices, period=today): grid import 21.6, solar 7.4, battery discharge 10.4, grid export 0.1, battery charge 12.2 kWh.'), 'heute bis jetzt: ' . $r['text']);
pruefe($zeile($r, 1) === 'Self-sufficiency 21 %, self-consumed solar 99 %.', 'heute: Autarkie (von Hand 20,58 % und 99,07 %): ' . $r['text']);
pruefe($zeile($r, 2) === 'Of this, 6.8 kWh by 4 of 4 metered device(s), 20.4 kWh not metered.', 'heute: Geräte und Rest: ' . $r['text']);

$r = frage($idx, ['room' => 'all', 'period' => 'last_month']);
pruefe(str_starts_with($zeile($r, 0), 'House consumption 774.8 kWh'), 'room=all ist ebenfalls das ganze Haus: ' . $r['text']);

// Nach Raum, Typ oder Name gefragt: wie bisher die Summe der Geräte, ohne Bilanz
$r = frage($idx, ['room' => 'Keller', 'period' => 'last_month']);
pruefe($zeile($r, 0) === '88.5 kWh consumed by 3 of 3 metered device(s) (room=Keller, period=last_month).' && !str_contains($r['text'], 'House consumption'), 'Keller: Gerätesumme wie bisher: ' . $r['text']);
$r = frage($idx, ['type' => 'appliance', 'period' => 'last_month']);
pruefe($zeile($r, 0) === '86 kWh consumed by 1 of 1 metered device(s) (type=appliance, period=last_month).', 'Typ: Gerätesumme: ' . $r['text']);

// Ohne PV und Batterie ist der Bezug der Hausverbrauch
$r = frage(index($liste, $werte, ['import' => 43989]), ['period' => 'last_month']);
pruefe($zeile($r, 0) === 'House consumption 122.7 kWh (all devices, period=last_month): grid import 122.7 kWh.', 'nur Netzbezug: ' . $r['text']);

// Ein Bilanzzähler ohne Archiv: kein Hausverbrauch, die Geräte wie bisher
$ohnePv = $werte;
$ohnePv['10639']['archiviert'] = false;
$r = frage(index($liste, $ohnePv, $bilanz), ['period' => 'last_month']);
pruefe($zeile($r, 0) === 'House consumption unknown (all devices, period=last_month): no history archived for solar.', 'PV ohne Archiv: Hausverbrauch unbekannt, mit Grund: ' . $r['text']);
pruefe($zeile($r, 1) === '174.5 kWh consumed by 4 of 4 metered device(s) (all devices, period=last_month).', 'dann die Gerätesumme wie bisher: ' . $r['text']);

// Bilanz ohne ein einziges Gerät mit Zähler: keine Absage, nur der Hausverbrauch
$ohneZaehler = array_map(static fn (array $g): array => ['EnergyVariableID' => 0] + $g, $liste);
$r = frage(index($ohneZaehler, $werte, $bilanz), ['period' => 'last_month']);
pruefe(!$r['isError'] && $zeile($r, 0) === 'House consumption 774.8 kWh (all devices, period=last_month): grid import 122.7, solar 1356.8, battery discharge 285, grid export 658.7, battery charge 330.9 kWh.' && !str_contains($r['text'], 'Of this'), 'nur die Bilanz: ' . $r['text']);

// Ohne Bilanz bleibt alles wie in build 27
$r = frage(index($liste, $werte, []), ['period' => 'last_month']);
pruefe($zeile($r, 0) === '174.5 kWh consumed by 4 of 4 metered device(s) (all devices, period=last_month).', 'ohne Bilanz die Gerätesumme: ' . $r['text']);

/* --- Wärmepumpe: Gerät nur mit Leistung, nicht schaltbar --------------------------------------- */

$typRollen = DeviceIndex::TYPE_ROLES['appliance'] ?? [];
pruefe(($typRollen['required'] ?? null) === ['state', 'power'], 'ein Gerät braucht Schaltzustand oder Leistung: ' . json_encode($typRollen));
pruefe(DeviceIndex::rolesOf($liste[3]) === ['power' => 10025, 'energy' => 20404], 'Wärmepumpe: Leistung und Zähler: ' . json_encode(DeviceIndex::rolesOf($liste[3])));
pruefe(DeviceIndex::abilitiesOf($liste[3]) === [], 'ohne Schaltzustand versteht sie keinen Befehl: ' . json_encode(DeviceIndex::abilitiesOf($liste[3])));
$r = frage($idx, ['name' => 'Wärmepumpe']);
pruefe(str_ends_with($r['text'], "\nTechnik | Wärmepumpe | appliance | 0.0 kW"), 'die Zeile nennt die Leistung als Zustand: ' . $r['text']);
$r = frage($idx, ['type' => 'appliance', 'active_only' => true]);
pruefe(str_starts_with($r['text'], '0 of 0 checked devices active (type=appliance).') && str_ends_with($r['text'], "\n1 device(s) without on/off were not counted (appliance)."), 'bei „nur aktive“ zählt sie als Gerät ohne an/aus: ' . $r['text']);
$r = frage($idx, ['name' => 'Wärmepumpe', 'command' => 'off'], true);
pruefe($r['isError'] && $r['text'] === 'Nothing was changed: the devices matching name=Wärmepumpe can only be read.', 'schalten lässt sie sich nicht: ' . $r['text']);

/* --- Autarkie nur mit PV oder Batterie; fürs ganze Haus nur die größten fünf Geräte ----------- */

$r = frage(index($liste, $werte, ['import' => 43989]), ['period' => 'last_month']);
pruefe(!str_contains($r['text'], 'Self-sufficiency') && str_starts_with($zeile($r, 1), 'Of this,'), 'nur Netzbezug: keine Autarkie (sie wäre immer 0 %): ' . $r['text']);
$r = frage(index($liste, $werte, ['import' => 43989, 'solar' => 10639]), ['period' => 'last_month']);
pruefe(str_starts_with($zeile($r, 1), 'Self-sufficiency ') && !str_contains($zeile($r, 1), 'self-consumed solar'), 'ohne Einspeisefeld kein Eigenverbrauch der PV: ' . $r['text']);

// Fürs ganze Haus nach Verbrauch sortiert
$r = frage($idx, ['period' => 'last_month']);
$geraeteZeilen = array_values(array_filter(explode("\n", $r['text']), static fn (string $z): bool => substr_count($z, ' | ') === 3));
pruefe($geraeteZeilen === ['Technik | Wärmepumpe | appliance | 86 kWh', 'Keller | Entfeuchter | switch | 74.1 kWh', 'Keller | Kühlschrank | switch | 9 kWh', 'Keller | Waschmaschine | switch | 5.4 kWh'], 'ganzes Haus: die Geräte nach Verbrauch sortiert: ' . json_encode($geraeteZeilen, JSON_UNESCAPED_UNICODE));
$r = frage($idx, ['room' => 'Keller', 'period' => 'last_month']);
pruefe(str_contains($r['text'], "Entfeuchter | switch | 74.1 kWh\nKeller | Waschmaschine | switch | 5.4 kWh\nKeller | Kühlschrank"), 'nach Raum bleibt die Reihenfolge der Liste: ' . $r['text']);
// Mehr als fünf: dieselben Zähler unter weiteren Namen (die Werte sind echt, nur die Liste ist erweitert)
// Drei weitere Geräte mit Kopien echter Zähler unter eigenen IDs: Dieselbe Variable an zwei Geräten zählt seit
// build 69 nur einmal (Messsteckdose und Gerät dahinter), hier sollen es sieben verschiedene Zähler sein.
foreach ([49570 => 949570, 41402 => 941402, 20404 => 920404] as $echt => $kopie) {
    $werte[(string)$kopie] = $werte[(string)$echt];
}
$sieben = array_merge($liste, [
    ['Name' => 'Entfeuchter 2', 'EnergyVariableID' => 949570] + $liste[0],
    ['Name' => 'Waschmaschine 2', 'EnergyVariableID' => 941402] + $liste[1],
    ['Name' => 'Wärmepumpe 2', 'EnergyVariableID' => 920404] + $liste[3],
]);
$r = frage(index($sieben, $werte, $bilanz), ['period' => 'last_month']);
$geraeteZeilen = array_values(array_filter(explode("\n", $r['text']), static fn (string $z): bool => substr_count($z, ' | ') === 3));
pruefe(count($geraeteZeilen) === 5 && $geraeteZeilen[0] === 'Technik | Wärmepumpe | appliance | 86 kWh' && $geraeteZeilen[4] === 'Keller | Kühlschrank | switch | 9 kWh', 'ganzes Haus mit sieben Geräten: die größten fünf: ' . $r['text']);
pruefe(str_contains($r['text'], "\n2 more device(s) together 10.9 kWh."), 'der Rest zusammengefasst: ' . $r['text']);
pruefe($zeile($r, 2) === 'Of this, 340 kWh by 7 of 7 metered device(s), 434.8 kWh not metered.', 'die Summe zählt alle sieben: ' . $r['text']);
$r = frage(index($sieben, $werte, $bilanz), ['room' => 'Keller', 'period' => 'last_month']);
pruefe(substr_count($r['text'], ' | ') === 15, 'nach Raum alle fünf Kellergeräte ohne Zusammenfassung: ' . $r['text']);

/* --- Zähler, die keine Energie zählen (Wasser in Liter, Gas in m³) ------------------------------ */
// Vorher galt jede unbekannte Einheit als kWh: Ein Wasserzähler als „Zähler“ eines Geräts hätte
// „1970 kWh“ ergeben und wäre in die Summe gegangen.

$mitWasser = $liste;
$mitWasser[3]['EnergyVariableID'] = 10320;
$r = frage(index($mitWasser, $werte, []), ['room' => 'Technik', 'period' => 'last_month']);
pruefe(str_ends_with($r['text'], "\nTechnik | Wärmepumpe | appliance | not an energy meter (unit Liter)"), 'Wasserzähler: kein Energiezähler, mit seiner Einheit: ' . $r['text']);
pruefe($zeile($r, 0) === '0 kWh consumed by 0 of 1 metered device(s) (room=Technik, period=last_month).', 'und er zählt nicht mit: ' . $r['text']);
$r = frage(index($mitWasser, $werte, $bilanz), ['period' => 'last_month']);
pruefe($zeile($r, 2) === 'Of this, 88.5 kWh by 3 of 4 metered device(s), 686.3 kWh not metered.', 'auch nicht im Anteil der Geräte am Hausverbrauch: ' . $r['text']);
$r = frage(index($liste, $werte, ['import' => 23320] + $bilanz), ['period' => 'last_month']);
pruefe($zeile($r, 0) === 'House consumption unknown (all devices, period=last_month): grid import is not an energy counter (unit m³).', 'Gaszähler als Netzbezug: Hausverbrauch unbekannt, mit Grund: ' . $r['text']);
// Ohne Einheit gilt weiter kWh
$ohneEinheit = $werte;
$ohneEinheit['20404']['einheit'] = '';
$r = frage(index($liste, $ohneEinheit, []), ['name' => 'Wärmepumpe', 'period' => 'last_month']);
pruefe(str_ends_with($r['text'], "\nTechnik | Wärmepumpe | appliance | 86 kWh"), 'ohne Einheit wie bisher kWh: ' . $r['text']);

/* --- Deutsch ---------------------------------------------------------------------------------- */

$r = frage(index($liste, $werte, $bilanz, $de), ['period' => 'last_month']);
pruefe($zeile($r, 0) === 'Hausverbrauch 774.8 kWh (alle Geräte, period=last_month): Netzbezug 122.7, PV-Ertrag 1356.8, Batterie entladen 285, Einspeisung 658.7, Batterie geladen 330.9 kWh.', 'deutsche Kopfzeile: ' . $r['text']);
pruefe($zeile($r, 1) === 'Autarkie 84 %, Eigenverbrauch der PV 51 %.', 'deutsche Autarkie: ' . $r['text']);
pruefe($zeile($r, 2) === 'Davon 174.5 kWh durch 4 von 4 Gerät(en) mit eigenem Zähler, 600.3 kWh nicht erfasst.', 'deutsche zweite Zeile: ' . $r['text']);
frage(index($liste, $ohnePv, $bilanz, $de), ['period' => 'last_month']);
$r = frage(index($sieben, $werte, $bilanz, $de), ['period' => 'last_month']);
pruefe(str_contains($r['text'], "\n2 weitere Gerät(e) zusammen 10.9 kWh."), 'deutsch: der Rest zusammengefasst: ' . $r['text']);
$r = frage(index($mitWasser, $werte, [], $de), ['room' => 'Technik', 'period' => 'last_month']);
pruefe(str_ends_with($r['text'], "\nTechnik | Wärmepumpe | appliance | kein Energiezähler (Einheit Liter)"), 'deutsch: kein Energiezähler: ' . $r['text']);
$r = frage(index($liste, $werte, ['import' => 23320] + $bilanz, $de), ['period' => 'last_month']);
pruefe($zeile($r, 0) === 'Hausverbrauch unbekannt (alle Geräte, period=last_month): Netzbezug ist kein Energiezähler (Einheit m³).', 'deutsch: Bilanzzähler ohne Energie: ' . $r['text']);

$fehlend = [];
foreach ($anlagen as $anlage) {
    $fehlend = array_merge($fehlend, $anlage->missing);
}
pruefe($fehlend === [], 'jeder neue Text hat eine deutsche Übersetzung, es fehlen: ' . implode(' / ', array_unique($fehlend)));

ende('Energiebilanz');
