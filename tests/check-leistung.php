<?php

declare(strict_types=1);

/*
 * Aktuelle Leistung (Mitschnitt nuc 09.10.2026, 23:37): get_state mit period=now nennt statt des
 * Verbrauchs die Leistung jetzt, aufgebaut wie der Verbrauch. Fürs ganze Haus rechnet das Werkzeug
 * die Hausleistung wie Home Assistant aus fünf Leistungen (Netzbezug + PV + Batterie entladen −
 * Einspeisung − Batterie geladen); die Geräte mit Leistungsmessung sind ein Teil davon, der Rest heißt
 * „nicht erfasst“. Nach Raum, Typ oder Name zählen nur die Geräte. Einheiten W, kW und MW, ohne
 * Einheit W. Ein einzelner Sensor mit Vorzeichen geht auch: Netzbezug negativ heißt Einspeisung,
 * Batterie entladen negativ heißt laden (wie bei Home Assistant).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-09_leistung.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$leistung = $mitschnitt['bilanzLeistung'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var list<FakeBackend> */
$anlagen = [];

function index(array $liste, array $werte, array $leistung, ?array $uebersetzungen = null): DeviceIndex
{
    global $anlagen;
    $anlagen[] = $backend = new FakeBackend($werte, $uebersetzungen);
    return new DeviceIndex($liste, $backend, null, '', [], $leistung);
}

function frage(DeviceIndex $idx, array $argumente): array
{
    try {
        return $idx->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';

pruefe((defined(DeviceIndex::class . '::BALANCE_POWER') ? constant(DeviceIndex::class . '::BALANCE_POWER') : null) === [
    'import' => 'GridImportPowerVariableID', 'export' => 'GridExportPowerVariableID', 'solar' => 'SolarPowerVariableID',
    'charge' => 'BatteryChargePowerVariableID', 'discharge' => 'BatteryDischargePowerVariableID',
], 'fünf Leistungsfelder mit den Namen der Properties');

/* --- Ganzes Haus ---------------------------------------------------------------------------- */

$idx = index($liste, $werte, $leistung);
$r = frage($idx, ['period' => 'now']);
pruefe(!$r['isError'] && $zeile($r, 0) === 'House power 1896 W (all devices, period=now): grid import 8, solar 0, battery discharge 1888, grid export 0, battery charge 0 W.', 'Hausleistung aus der Bilanz, die Batterie deckt den Abend: ' . $r['text']);
pruefe($zeile($r, 1) === 'Of this, 926 W by 4 of 4 device(s) with power measurement, 970 W not metered.', 'Geräte als Teil davon, Rest nicht erfasst: ' . $r['text']);
pruefe(str_contains($r['text'], "\nKeller | Wärmepumpe | appliance | 900 W") && str_contains($r['text'], "\nKeller | Entfeuchter | switch | 26 W") && str_contains($r['text'], "\nKeller | Kühlschrank | switch | 0 W"), 'je Gerät die Leistung in W, die Wärmepumpe von kW umgerechnet: ' . $r['text']);
pruefe(str_ends_with($r['text'], "\n1 device(s) without power measurement were left out."), 'Geräte ohne Leistungsmessung werden gezählt: ' . $r['text']);

$geraeteZeilen = array_values(array_filter(explode("\n", $r['text']), static fn (string $z): bool => substr_count($z, ' | ') === 3));
pruefe(array_map(static fn (string $z): string => explode(' | ', $z)[1], $geraeteZeilen) === ['Wärmepumpe', 'Entfeuchter', 'Waschmaschine', 'Kühlschrank'], 'ganzes Haus: nach Leistung sortiert (Waschmaschine 0,02 W vor Kühlschrank 0 W): ' . json_encode($geraeteZeilen, JSON_UNESCAPED_UNICODE));

/* --- Nach Raum, Typ oder Name nur die Geräte ---------------------------------------------------- */

$r = frage($idx, ['room' => 'Keller', 'period' => 'now']);
pruefe($zeile($r, 0) === '926 W used by 4 of 4 device(s) with power measurement (room=Keller, period=now).' && !str_contains($r['text'], 'House power'), 'Keller: Summe der Geräte: ' . $r['text']);
$r = frage($idx, ['name' => 'Wandlicht', 'period' => 'now']);
pruefe($r['isError'] && $r['text'] === 'None of the matching devices measures power (name=Wandlicht, period=now).', 'ohne Leistungsmessung eine Absage: ' . $r['text']);
$r = frage(index($liste, $werte, []), ['period' => 'now']);
pruefe($zeile($r, 0) === '926 W used by 4 of 4 device(s) with power measurement (all devices, period=now).', 'ohne Bilanz die Gerätesumme: ' . $r['text']);

/* --- Einheiten und Vorzeichen ---------------------------------------------------------------- */

$r = frage(index($liste, $werte, ['import' => 24942]), ['period' => 'now']);
pruefe($zeile($r, 0) === 'House power 8 W (all devices, period=now): grid import 8 W.', 'nur Netzbezug: ' . $r['text']);
$einspeisen = $werte;
$einspeisen['24942']['wert'] = -2350.0;
$r = frage(index($liste, $einspeisen, ['import' => 24942]), ['period' => 'now']);
pruefe($zeile($r, 0) === 'House power 0 W (all devices, period=now): grid import 0, grid export 2350 W.', 'ein Netzsensor mit Vorzeichen: negativ heißt Einspeisung: ' . $r['text']);
$laden = $werte;
$laden['25414']['wert'] = -1200;
$r = frage(index($liste, $laden, ['import' => 24942, 'discharge' => 25414]), ['period' => 'now']);
pruefe($zeile($r, 0) === 'House power 0 W (all devices, period=now): grid import 8, battery discharge 0, battery charge 1200 W.', 'Batteriesensor mit Vorzeichen: negativ heißt laden, die Hausleistung fällt nicht unter 0: ' . $r['text']);
$r = frage(index($liste, $werte, ['import' => 43989] + $leistung), ['period' => 'now']);
pruefe($zeile($r, 0) === 'House power unknown (all devices, period=now): grid import is not a power value (unit kWh).', 'ein Zähler statt einer Leistung: Hausleistung unbekannt: ' . $r['text']);
$fehlt = $werte;
unset($fehlt['14114']);
$r = frage(index($liste, $fehlt, $leistung), ['period' => 'now']);
pruefe($zeile($r, 0) === 'House power unknown (all devices, period=now): solar is not available.', 'eine fehlende Variable: Hausleistung unbekannt: ' . $r['text']);
pruefe($zeile($r, 1) === '926 W used by 4 of 4 device(s) with power measurement (all devices, period=now).', 'dann die Geräte wie ohne Bilanz: ' . $r['text']);

/* --- Verbrauch über Zeiträume bleibt, unbekannter Zeitraum ------------------------------------- */

$r = frage($idx, ['period' => 'gerade']);
pruefe($r['isError'] && str_starts_with($r['text'], 'Unknown period "gerade".'), 'unbekannter Zeitraum wie bisher: ' . $r['text']);

/* --- Deutsch ---------------------------------------------------------------------------------- */

$r = frage(index($liste, $werte, $leistung, $de), ['period' => 'now']);
pruefe($zeile($r, 0) === 'Hausleistung 1896 W (alle Geräte, period=now): Netzbezug 8, PV-Ertrag 0, Batterie entladen 1888, Einspeisung 0, Batterie geladen 0 W.', 'deutsche Kopfzeile: ' . $r['text']);
pruefe($zeile($r, 1) === 'Davon 926 W durch 4 von 4 Gerät(en) mit Leistungsmessung, 970 W nicht erfasst.', 'deutsche zweite Zeile: ' . $r['text']);
frage(index($liste, $werte, $leistung, $de), ['room' => 'Keller', 'period' => 'now']);
frage(index($liste, $werte, $leistung, $de), ['name' => 'Wandlicht', 'period' => 'now']);
frage(index($liste, $werte, ['import' => 43989] + $leistung, $de), ['period' => 'now']);
frage(index($liste, $fehlt, $leistung, $de), ['period' => 'now']);

$fehlend = [];
foreach ($anlagen as $anlage) {
    $fehlend = array_merge($fehlend, $anlage->missing);
}
pruefe($fehlend === [], 'jeder neue Text hat eine deutsche Übersetzung, es fehlen: ' . implode(' / ', array_unique($fehlend)));

ende('Aktuelle Leistung');
