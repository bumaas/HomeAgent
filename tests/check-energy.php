<?php

declare(strict_types=1);

/*
 * Leistung und Verbrauch (Mitschnitt nuc 09.10.2026, 19:49): Entfeuchter, Waschmaschine und
 * Kühlschrank im Keller mit archiviertem Zähler (Aggregationstyp Zähler, Einheit Wh), dazu das
 * Deckenlicht im Flur, dessen Zähler nicht archiviert ist. Die aktuelle Leistung steht in jeder
 * Zeile, der Verbrauch über einen Zeitraum kommt über get_state mit „period“; die Summe rechnet
 * das Werkzeug. Die Erwartungswerte sind aus denselben Aggregaten von Hand gerechnet (kWh, eine
 * Nachkommastelle).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-09_energie.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var list<FakeBackend> */
$anlagen = [];

function index(array $liste, array $werte, ?array $uebersetzungen = null): DeviceIndex
{
    global $anlagen, $mitschnitt;
    $anlagen[] = $backend = new FakeBackend($werte, $uebersetzungen);
    $backend->jetzt = $mitschnitt['jetzt'];
    return new DeviceIndex($liste, $backend);
}

/** Ruft getState auf und fängt fehlende Methoden ab, damit der Test vor dem Code durchläuft. */
function frage(DeviceIndex $idx, array $argumente): array
{
    try {
        return $idx->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$idx = index($liste, $werte);

/* --- Fähigkeiten ---------------------------------------------------------------------------- */

pruefe((DeviceIndex::ROLES['power'] ?? '') === 'PowerVariableID' && (DeviceIndex::ROLES['energy'] ?? '') === 'EnergyVariableID', 'Fähigkeiten „power“ und „energy“ mit ihren Schlüsseln: ' . json_encode(DeviceIndex::ROLES));
pruefe(DeviceIndex::rolesOf($liste[0]) === ['state' => 51561, 'power' => 40189, 'energy' => 49570], 'eine Steckdose bzw. ein Schalter liest Leistung und Zähler: ' . json_encode(DeviceIndex::rolesOf($liste[0])));
foreach (['light', 'switch', 'socket'] as $typ) {
    $rollen = DeviceIndex::TYPE_ROLES[$typ]['roles'];
    pruefe(in_array('power', $rollen, true) && in_array('energy', $rollen, true) && !in_array('power', DeviceIndex::TYPE_ROLES[$typ]['required'], true), $typ . ': Leistung und Zähler wahlweise');
}
// Ein Gerät wie die Wärmepumpe hat keinen Schaltzustand, nur Leistung (Energiebilanz 09.10.2026)
pruefe(in_array('energy', DeviceIndex::TYPE_ROLES['appliance']['roles'], true) && DeviceIndex::TYPE_ROLES['appliance']['required'] === ['state', 'power'], 'appliance: Schaltzustand oder Leistung, Zähler wahlweise');
foreach (['window', 'blind', 'temperature', 'measurement'] as $typ) {
    pruefe(!in_array('energy', DeviceIndex::TYPE_ROLES[$typ]['roles'], true), $typ . ': kein Zähler');
}

/* --- Leistung in jeder Zeile ------------------------------------------------------------------ */

$r = frage($idx, ['room' => 'Keller']);
pruefe(!$r['isError'] && str_starts_with($r['text'], '3 device(s) found (room=Keller).'), 'drei Geräte im Keller: ' . $r['text']);
pruefe(str_contains($r['text'], 'Keller | Entfeuchter | switch | on (26.1 W)'), 'Entfeuchter an, mit Leistung: ' . $r['text']);
pruefe(str_contains($r['text'], 'Keller | Waschmaschine | switch | on (0.0 W)'), 'Waschmaschine an, steht aber: ' . $r['text']);
pruefe(str_contains($r['text'], 'Keller | Kühlschrank | switch | off (0.0 W)'), 'Kühlschrank aus: ' . $r['text']);
$r = frage($idx, ['room' => 'Flur']);
pruefe(str_contains($r['text'], 'Flur | Deckenlicht | light | off (0.0 W)'), 'Leistung geht auch ohne Archiv: ' . $r['text']);
$r = frage($idx, ['type' => 'switch', 'active_only' => true]);
pruefe(str_starts_with($r['text'], '2 of 3 checked devices active (type=switch).') && str_contains($r['text'], '| on (26.1 W)'), 'active_only zählt wie bisher, die Zeile trägt die Leistung: ' . $r['text']);
$ohneLeistung = $werte;
unset($ohneLeistung['40189']);
$r = frage(index($liste, $ohneLeistung), ['name' => 'Entfeuchter']);
pruefe(str_ends_with($r['text'], "\nKeller | Entfeuchter | switch | on") && !str_contains($r['text'], 'missing'), 'fehlt die Leistungsvariable, bleibt der Zustand: ' . $r['text']);
// Licht mit Stufe und Leistung: beides in einer Klammer
$dimmbar = $liste;
$dimmbar[3]['LevelVariableID'] = 90001;
$hell = $werte;
$hell['30955']['wert'] = true;
$hell['21010'] = ['wert' => 12.4, 'formatiert' => '12.4 W'];
$hell['90001'] = ['wert' => 50, 'bereich' => ['min' => 0, 'max' => 100]];
$r = frage(index($dimmbar, $hell), ['room' => 'Flur']);
pruefe(str_contains($r['text'], 'Flur | Deckenlicht | light | on (50 %, 12.4 W)'), 'Licht mit Stufe und Leistung: ' . $r['text']);

/* --- Verbrauch über Zeiträume ----------------------------------------------------------------- */

$erwartet = [
    'today'      => ['1.4', '0', '0.2'],
    'yesterday'  => ['2.9', '0', '0.3'],
    'this_week'  => ['13.2', '0', '1.4'],
    'last_week'  => ['18.4', '0.6', '2'],
    'this_month' => ['22.5', '0.4', '2.5'],
    'last_month' => ['74.1', '5.4', '9'],
    'this_year'  => ['442.4', '78.3', '78.2'],
    'last_year'  => ['225.2', '111', '95.3'],
];
$summen = ['today' => '1.6', 'yesterday' => '3.2', 'this_week' => '14.6', 'last_week' => '21', 'this_month' => '25.5', 'last_month' => '88.5', 'this_year' => '598.9', 'last_year' => '431.6'];
foreach ($erwartet as $zeitraum => [$entfeuchter, $wasch, $kuehl]) {
    $r = frage($idx, ['room' => 'Keller', 'period' => $zeitraum]);
    $kopf = $summen[$zeitraum] . ' kWh consumed by 3 of 3 metered device(s) (room=Keller, period=' . $zeitraum . ').';
    pruefe(!$r['isError'] && strtok($r['text'], "\n") === $kopf, $zeitraum . ': Kopfzeile mit Summe: ' . $r['text']);
    pruefe(str_contains($r['text'], "\nKeller | Entfeuchter | switch | " . $entfeuchter . ' kWh')
        && str_contains($r['text'], "\nKeller | Waschmaschine | switch | " . $wasch . ' kWh')
        && str_contains($r['text'], "\nKeller | Kühlschrank | switch | " . $kuehl . ' kWh'), $zeitraum . ': je Gerät der Verbrauch: ' . $r['text']);
}

$r = frage($idx, ['name' => 'Entfeuchter', 'period' => 'last_month']);
pruefe($r['text'] === "74.1 kWh consumed by 1 of 1 metered device(s) (name=Entfeuchter, period=last_month).\nKeller | Entfeuchter | switch | 74.1 kWh", 'einzelnes Gerät: ' . $r['text']);

// Zähler ohne Archiv: Leistung ja, Verbrauch nicht
$r = frage($idx, ['room' => 'Flur', 'period' => 'today']);
pruefe(!$r['isError'] && $r['text'] === "0 kWh consumed by 0 of 1 metered device(s) (room=Flur, period=today).\nFlur | Deckenlicht | light | no history archived", 'Flur: kein Verlauf archiviert: ' . $r['text']);
$r = frage($idx, ['period' => 'this_year']);
pruefe(strtok($r['text'], "\n") === '598.9 kWh consumed by 3 of 4 metered device(s) (all devices, period=this_year).', 'ganzes Haus, das Flurlicht zählt nicht mit: ' . $r['text']);

// Geräte ohne Zähler bleiben außen vor und werden genannt
$mitWandlicht = array_merge($liste, [['Room' => 'Flur', 'Name' => 'Wandlicht', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => 30955, 'Invert' => false]]);
$r = frage(index($mitWandlicht, $werte), ['room' => 'Flur', 'period' => 'today']);
pruefe(str_starts_with($r['text'], '0 kWh consumed by 0 of 1 metered device(s) (room=Flur, period=today).') && !str_contains($r['text'], 'Wandlicht') && str_ends_with($r['text'], "\n1 device(s) without energy meter were left out."), 'Gerät ohne Zähler: ausgelassen und gezählt: ' . $r['text']);
$r = frage(index($mitWandlicht, $werte), ['name' => 'Wandlicht', 'period' => 'today']);
pruefe($r['isError'] && $r['text'] === 'None of the matching devices measures energy (name=Wandlicht, period=today).', 'nur Geräte ohne Zähler: Absage: ' . $r['text']);
$r = frage(index($mitWandlicht, $werte), ['name' => 'Wandlicht']);
pruefe(str_contains($r['text'], 'Flur | Wandlicht | light | off') && !str_contains($r['text'], 'Wandlicht | light | off ('), 'ohne Leistungsvariable keine Klammer: ' . $r['text']);

// Unbekannter Zeitraum
$r = frage($idx, ['room' => 'Keller', 'period' => 'last_decade']);
pruefe($r['isError'] && $r['text'] === 'Unknown period "last_decade". Allowed: today, yesterday, this_week, last_week, this_month, last_month, this_year, last_year.', 'unbekannter Zeitraum: ' . $r['text']);
pruefe((defined(DeviceIndex::class . '::PERIODS') ? constant(DeviceIndex::class . '::PERIODS') : null) === ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'], 'die Zeiträume als Liste fürs Schema');

// Zähler in kWh statt Wh
$inKwh = $werte;
$inKwh['49570']['einheit'] = 'kWh';
foreach ($inKwh['49570']['aggregate'] as $stufe => $liste1) {
    foreach ($liste1 as $i => $a) {
        $inKwh['49570']['aggregate'][$stufe][$i]['summe'] = $a['summe'] / 1000;
    }
}
$r = frage(index($liste, $inKwh), ['name' => 'Entfeuchter', 'period' => 'last_month']);
pruefe(str_ends_with($r['text'], 'Keller | Entfeuchter | switch | 74.1 kWh'), 'ein Zähler in kWh wird nicht noch einmal geteilt: ' . $r['text']);

/* --- Deutsch ---------------------------------------------------------------------------------- */

$r = frage(index($liste, $werte, $de), ['room' => 'Keller', 'period' => 'last_month']);
pruefe(strtok($r['text'], "\n") === '88.5 kWh verbraucht durch 3 von 3 Gerät(en) mit Zähler (room=Keller, period=last_month).', 'deutsche Kopfzeile: ' . $r['text']);
$r = frage(index($liste, $werte, $de), ['room' => 'Keller']);
pruefe(str_contains($r['text'], 'Keller | Entfeuchter | switch | an (26.1 W)'), 'deutsche Zeile mit Leistung: ' . $r['text']);
frage(index($liste, $werte, $de), ['room' => 'Flur', 'period' => 'today']);
frage(index($mitWandlicht, $werte, $de), ['room' => 'Flur', 'period' => 'today']);
frage(index($mitWandlicht, $werte, $de), ['name' => 'Wandlicht', 'period' => 'today']);
frage(index($liste, $werte, $de), ['period' => 'gestern']);

$fehlend = [];
foreach ($anlagen as $anlage) {
    $fehlend = array_merge($fehlend, $anlage->missing);
}
pruefe($fehlend === [], 'jeder neue Text hat eine deutsche Übersetzung, es fehlen: ' . implode(' / ', array_unique($fehlend)));

ende('Leistung und Verbrauch');
