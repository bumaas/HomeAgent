<?php

declare(strict_types=1);

/*
 * Ventilstellung am Thermostat (B101, Mitschnitt nuc 25.10.2026): bis zu drei Variablen (ValveVariableID,
 * Valve2VariableID, Valve3VariableID), gezeigt und gewertet wird der Höchstwert in Prozent des Bereichs. Ein Thermostat
 * mit Ventil zählt bei active_only als aktiv, solange ein Ventil über 0 steht; auf einem Sonder-Sollwert („Ventil offen“)
 * nie (Entscheidung Burkhard 25.10.2026), die Stellung steht trotzdem in der Zeile.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-25_ventile.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
// „Heizung falsch“ trägt den Adaptionsstatus statt der Stellung, er gehört nur in den Selbsttest (check-module.php)
$liste = array_values(array_filter($mitschnitt['geraete'], static fn (array $g): bool => $g['Name'] !== 'Heizung falsch'));
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function index(array $liste, array $werte, ?array $uebersetzungen = null): DeviceIndex
{
    global $anlage;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    return new DeviceIndex($liste, $anlage);
}

function rufe(DeviceIndex $idx, array $argumente): array
{
    try {
        return $idx->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeilen = static fn (array $r): array => explode("\n", $r['text']);
$hat = static fn (array $r, string $zeile): bool => in_array($zeile, explode("\n", $r['text']), true);
$geraet = static function (string $raum) use ($liste): array {
    foreach ($liste as $g) {
        if ($g['Room'] === $raum) {
            return $g;
        }
    }
    return [];
};

/* --- Modell --------------------------------------------------------------------------------- */

pruefe(array_intersect_key(DeviceIndex::ROLES, ['valve' => 1, 'valve2' => 1, 'valve3' => 1]) === ['valve' => 'ValveVariableID', 'valve2' => 'Valve2VariableID', 'valve3' => 'Valve3VariableID'], 'drei Fähigkeiten für Ventile: ' . json_encode(array_keys(DeviceIndex::ROLES)));
pruefe((DeviceIndex::TYPE_ROLES['thermostat'] ?? null) === ['roles' => ['state', 'setpoint', 'mode', 'fan', 'power', 'energy', 'valve', 'valve2', 'valve3'], 'required' => ['setpoint']], 'Thermostat: Ventile wahlweise, Sollwert bleibt Pflicht: ' . json_encode(DeviceIndex::TYPE_ROLES['thermostat'] ?? null));
pruefe(!in_array('valve', DeviceIndex::TYPE_ROLES['humidifier']['roles'], true) && !in_array('valve', DeviceIndex::TYPE_ROLES['measurement']['roles'], true), 'Ventile nur am Thermostat');
pruefe(DeviceIndex::rolesOf($geraet('Bad')) === ['state' => 45568, 'setpoint' => 16206, 'valve' => 55547, 'valve2' => 39353], 'rolesOf liest beide Ventile: ' . json_encode(DeviceIndex::rolesOf($geraet('Bad'))));
pruefe(DeviceIndex::abilitiesOf($geraet('Bad')) === ['temperature'], 'ein Ventil bringt kein Feld in set_state: ' . json_encode(DeviceIndex::abilitiesOf($geraet('Bad'))));
pruefe(DeviceIndex::offeredAbilities($liste) === ['temperature'], 'das Angebot bleibt temperature: ' . json_encode(DeviceIndex::offeredAbilities($liste)));

/* --- get_state ------------------------------------------------------------------------------ */

$idx = index($liste, $werte);
$r = rufe($idx, ['type' => 'thermostat']);
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | setpoint 21.5 °C (currently 20.4 °C, valve 97 %)'), 'geregelt mit offenem Ventil: ' . $r['text']);
pruefe($hat($r, 'Atelier | Heizung | thermostat | setpoint 16 °C (currently 17.3 °C, valve 0 %)'), 'geschlossenes Ventil: ' . $r['text']);
pruefe($hat($r, 'BadEG | Heizung | thermostat | setpoint 20 °C (currently 20.8 °C, valve 68 %)'), 'Homematic IP: LEVEL 0,68 von 0 bis 1 sind 68 %: ' . $r['text']);
// Die Ist-Temperatur im Gästezimmer formatiert Symcon mit schmalem geschütztem Leerzeichen (U+202F, so im Mitschnitt)
pruefe($hat($r, 'Gast | Heizung | thermostat | special setpoint 100 °C outside 6 to 30 °C (currently 21.9 °C, valve 98 %)'), 'Sonder-Sollwert: die Stellung steht trotzdem da: ' . $r['text']);
pruefe($hat($r, 'Bad | Heizung | thermostat | special setpoint 30.5 °C outside 6 to 30 °C (currently 22.2 °C, valves up to 98 %)'), 'zwei Ventile: der Höchstwert: ' . $r['text']);
pruefe($hat($r, 'Wintergarten | Heizung | thermostat | setpoint 20.5 °C (currently 21.3 °C, valve 0 %)'), 'Wandthermostat mit Heizkörperthermostat: ' . $r['text']);

// Bei mehreren Ventilen zählt das offenste, auch wenn es nicht das erste ist (Werte wie im Mitschnitt, nur die Reihenfolge
// ist für den Test getauscht: Atelier 0 % als erstes, Musikzimmer 97 % als zweites Ventil)
$gemischt = [$geraet('Atelier') + ['Valve2VariableID' => 27238]];
$r = rufe(index($gemischt, $werte), ['type' => 'thermostat']);
pruefe($hat($r, 'Atelier | Heizung | thermostat | setpoint 16 °C (currently 17.3 °C, valves up to 97 %)'), 'das zweite Ventil ist offener: ' . $r['text']);

// Ohne Ist-Temperatur steht das Ventil allein in der Klammer
$ohneIst = $geraet('Musikzimmer');
unset($ohneIst['StateVariableID']);
$r = rufe(index([$ohneIst], $werte), ['type' => 'thermostat']);
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | setpoint 21.5 °C (valve 97 %)'), 'ohne Ist-Temperatur: ' . $r['text']);

/* --- active_only ---------------------------------------------------------------------------- */

$r = rufe($idx, ['type' => 'thermostat', 'active_only' => true]);
pruefe(($zeilen($r)[0] ?? '') === '2 of 14 checked devices active (type=thermostat).', 'heizend sind Musikzimmer und BadEG, die zwölf auf „Ventil offen“ nicht: ' . $r['text']);
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | setpoint 21.5 °C (currently 20.4 °C, valve 97 %)') && $hat($r, 'BadEG | Heizung | thermostat | setpoint 20 °C (currently 20.8 °C, valve 68 %)')
    && count($zeilen($r)) === 3, 'nur die beiden heizenden: ' . $r['text']);

// Ein Thermostat ohne Ventil kennt weiter kein „aktiv“ und wird am Ende genannt
$ohneVentil = $geraet('Musikzimmer');
unset($ohneVentil['ValveVariableID']);
$r = rufe(index([$ohneVentil, $geraet('BadEG')], $werte), ['type' => 'thermostat', 'active_only' => true]);
pruefe(($zeilen($r)[0] ?? '') === '1 of 1 checked devices active (type=thermostat).' && $hat($r, '1 device(s) without on/off were not counted (thermostat).'), 'ohne Ventil nicht gezählt: ' . $r['text']);

// Fehlt die Variable eines Ventils, zählt das andere; fehlen alle, steht es in der Zeile und das Gerät zählt nicht
$einFehlt = $geraet('Musikzimmer') + ['Valve2VariableID' => 69991];
$r = rufe(index([$einFehlt], $werte), ['type' => 'thermostat', 'active_only' => true]);
pruefe(($zeilen($r)[0] ?? '') === '1 of 1 checked devices active (type=thermostat).', 'ein fehlendes zweites Ventil stört nicht: ' . $r['text']);
$alleFehlen = array_merge($geraet('Musikzimmer'), ['ValveVariableID' => 69991]);
$r = rufe(index([$alleFehlen], $werte), ['type' => 'thermostat']);
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | setpoint 21.5 °C (currently 20.4 °C, valve not available (variable 69991 is missing))'), 'fehlendes Ventil wird genannt: ' . $r['text']);

/* --- Karten --------------------------------------------------------------------------------- */

$karte = static function (DeviceIndex $idx, string $raum, bool $nurLesen): array {
    $karten = $idx->cards(['room' => $raum, 'type' => 'thermostat'], $nurLesen);
    return $karten['structuredContent']['cards'][0] ?? [];
};
$k = $karte($idx, 'Musikzimmer', false);
pruefe(($k['active'] ?? null) === true && ($k['status'] ?? null) === 'valve 97 %', 'Karte: heizt, das Ventil steht im Status: ' . json_encode($k, JSON_UNESCAPED_UNICODE));
$k = $karte($idx, 'Gast', false);
pruefe(($k['active'] ?? null) === false && ($k['status'] ?? null) === 'valve 98 %', 'Karte auf Sonder-Sollwert: nicht aktiv, Ventil im Status: ' . json_encode($k, JSON_UNESCAPED_UNICODE));
$k = $karte(index([$ohneVentil], $werte), 'Musikzimmer', false);
pruefe(array_key_exists('active', $k) && $k['active'] === null && array_key_exists('status', $k) && $k['status'] === null, 'Karte ohne Ventil wie bisher: ' . json_encode($k, JSON_UNESCAPED_UNICODE));

/* --- Deutsch -------------------------------------------------------------------------------- */

$idx = index($liste, $werte, $de);
$r = rufe($idx, ['type' => 'thermostat']);
pruefe($anlage->missing === [], 'jeder neue Text ist übersetzt: ' . implode(' | ', array_unique($anlage->missing)));
pruefe($hat($r, 'Musikzimmer | Heizung | thermostat | Soll 21.5 °C (derzeit 20.4 °C, Ventil 97 %)'), 'deutsche Zeile: ' . $r['text']);
pruefe($hat($r, 'Bad | Heizung | thermostat | Sonder-Sollwert 30.5 °C außerhalb von 6 bis 30 °C (derzeit 22.2 °C, Ventile bis 98 %)'), 'deutsche Zeile mit zwei Ventilen: ' . $r['text']);

ende('Ventilstellung');
