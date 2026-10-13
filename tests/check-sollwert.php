<?php

declare(strict_types=1);

/*
 * Heizungs-Sollwert (Mitschnitt nuc 10.10.2026): Typ „thermostat“ mit dem Sollwert als Pflicht und
 * der Ist-Temperatur als Option, wie „climate“ bei Home Assistant. get_state nennt Soll und Ist,
 * set_state stellt mit „temperature“ in °C. Werte außerhalb des Bereichs werden abgewiesen, ohne
 * etwas zu senden; krumme Werte gehen auf die Schrittweite. Ein Wert außerhalb des Bereichs auf der
 * Anlage (HM-CC-TC 100 = Ventil offen) heißt Sonderwert. type=temperature findet auch die
 * Ist-Temperatur der Thermostate.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-10_thermostat.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function index(array $liste, array $werte, ?array $uebersetzungen = null): DeviceIndex
{
    global $anlage;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    return new DeviceIndex($liste, $anlage);
}

function rufe(DeviceIndex $idx, string $werkzeug, array $argumente): array
{
    try {
        return $werkzeug === 'get' ? $idx->getState($argumente) : $idx->setState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$konstante = static fn (string $name): mixed => defined(DeviceIndex::class . '::' . $name) ? constant(DeviceIndex::class . '::' . $name) : null;

/* --- Modell --------------------------------------------------------------------------------- */

pruefe(array_key_exists('thermostat', $konstante('TYPES') ?? []) && $konstante('TYPES')['thermostat'] === null, 'Typ thermostat ohne an/aus-Wörter');
pruefe(in_array('thermostat', $konstante('CONTROLLABLE') ?? [], true), 'thermostat ist stellbar');
pruefe(($konstante('ROLES')['setpoint'] ?? null) === 'SetpointVariableID', 'Fähigkeit setpoint in SetpointVariableID');
pruefe(($konstante('TYPE_ROLES')['thermostat'] ?? null) === ['roles' => ['state', 'setpoint', 'mode', 'fan'], 'required' => ['setpoint']], 'Thermostat: Ist-Temperatur, Modus und Lüfterstufe wahlweise (Klimagerät, B19), Sollwert Pflicht');
$heizung = $liste[5];
pruefe(DeviceIndex::rolesOf($heizung) === ['state' => 41351, 'setpoint' => 10028], 'rolesOf liest Soll und Ist: ' . json_encode(DeviceIndex::rolesOf($heizung)));
pruefe(DeviceIndex::abilitiesOf($heizung) === ['temperature'], 'ein Thermostat versteht temperature: ' . json_encode(DeviceIndex::abilitiesOf($heizung)));
pruefe(DeviceIndex::abilitiesOf(['Type' => 'thermostat', 'StateVariableID' => 41351]) === [], 'ohne Sollwert versteht es nichts');
pruefe(DeviceIndex::abilitiesOf($heizung + ['ReadOnly' => true]) === [], 'mit „nur abfragen“ versteht es nichts');
pruefe(DeviceIndex::offeredAbilities($liste) === ['open', 'close', 'percent', 'temperature'], 'temperature steht nach percent im Angebot: ' . json_encode(DeviceIndex::offeredAbilities($liste)));

/* --- get_state ------------------------------------------------------------------------------ */

$idx = index($liste, $werte);
$r = rufe($idx, 'get', ['room' => 'Wintergarten', 'type' => 'thermostat']);
pruefe(!$r['isError'] && $zeile($r, 1) === 'Wintergarten | Heizung | thermostat | setpoint 21 °C (currently 20.6 °C)', 'Soll und Ist, der Sollwert gerechnet statt „21,0 °C“: ' . $r['text']);

$r = rufe($idx, 'get', ['room' => 'Gast', 'type' => 'thermostat']);
pruefe($zeile($r, 1) === 'Gast | Heizung | thermostat | special setpoint 100 °C outside 6 to 30 °C (currently 21.7 °C)', 'HM-CC-TC auf 100 (Ventil offen) ist ein Sonderwert: ' . $r['text']);

$ohneIst = [['Room' => 'Wintergarten', 'Name' => 'Heizung', 'Aliases' => '', 'Type' => 'thermostat', 'SetpointVariableID' => 11093]];
$r = rufe(index($ohneIst, $werte), 'get', ['type' => 'thermostat']);
pruefe($zeile($r, 1) === 'Wintergarten | Heizung | thermostat | setpoint 21 °C', 'ohne Ist-Temperatur nur der Sollwert: ' . $r['text']);

$r = rufe($idx, 'get', ['room' => 'Gast', 'type' => 'temperature']);
pruefe($zeile($r, 0) === '2 device(s) found (room=Gast, type=temperature).' && str_contains($r['text'], "\nGast | Temperatur | temperature | 21.7 °C")
    && str_contains($r['text'], "\nGast | Heizung | thermostat | special setpoint 100 °C"), 'type=temperature findet auch das Thermostat mit Ist-Temperatur: ' . $r['text']);

$r = rufe($idx, 'get', ['type' => 'temperature', 'room' => 'Wintergarten']);
pruefe($zeile($r, 0) === '1 device(s) found (room=Wintergarten, type=temperature).', 'im Wintergarten nur das Thermostat, die Luftfeuchte nicht: ' . $r['text']);

$r = rufe(index($ohneIst, $werte), 'get', ['type' => 'temperature']);
pruefe($r['isError'], 'ein Thermostat ohne Ist-Temperatur ist kein Temperaturmesser: ' . $r['text']);

$r = rufe($idx, 'get', ['type' => 'thermostat', 'active_only' => true]);
pruefe($zeile($r, 0) === '0 of 0 checked devices active (type=thermostat).' && $zeile($r, 1) === '2 device(s) without on/off were not counted (thermostat).', 'Thermostate kennen kein an und aus: ' . $r['text']);

/* --- set_state: ein Thermostat -------------------------------------------------------------- */

$idx = index($liste, $werte);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21]);
pruefe(!$r['isError'] && $zeile($r, 0) === '1 of 1 devices set to 21 °C (room=Gast, name=Heizung).', 'Kopfzeile mit dem Sollwert: ' . $r['text']);
pruefe($zeile($r, 1) === 'Gast | Heizung | thermostat | setpoint 21 °C (currently 21.7 °C)', 'Gerätezeile wie bei get_state: ' . $r['text']);
pruefe($anlage->requests === [[10028, 21.0]], 'genau ein Befehl an den Sollwert: ' . json_encode($anlage->requests));
pruefe($anlage->slept >= 311 && $anlage->slept < 500, 'gewartet bis zur Rückmeldung nach 311 ms: ' . $anlage->slept);

$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21]);
pruefe(!$r['isError'] && $zeile($r, 1) === 'Gast | Heizung | thermostat | already set to 21 °C' && count($anlage->requests) === 1, 'steht schon auf 21: nichts gesendet: ' . $r['text']);

$idx = index($liste, $werte);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21.3]);
pruefe($anlage->requests === [[10028, 21.5]], 'krumme Werte gehen auf die Schrittweite 0,5: ' . json_encode($anlage->requests));
pruefe($zeile($r, 0) === '1 of 1 devices set to 21.5 °C (room=Gast, name=Heizung).', 'die Kopfzeile nennt den gesendeten Wert: ' . $r['text']);

$idx = index($liste, $werte);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 35]);
pruefe($r['isError'] && $zeile($r, 1) === 'Gast | Heizung | thermostat | not set: 35 °C is outside 6 to 30 °C' && $anlage->requests === [], 'außerhalb des Bereichs: nichts gesendet: ' . $r['text']);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 5.9]);
pruefe($r['isError'] && $anlage->requests === [], 'auch unten nicht: ' . $r['text']);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 30]);
pruefe(!$r['isError'] && $anlage->requests === [[10028, 30.0]], 'die Grenze selbst geht: ' . json_encode($anlage->requests));

/* --- set_state: Widersprüche und Absagen ---------------------------------------------------- */

$idx = index($liste, $werte);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21, 'command' => 'on']);
pruefe($r['isError'] && str_contains($r['text'], 'Give only one of command, percent and temperature') && $anlage->requests === [], 'temperature mit command ist ein Widerspruch: ' . $r['text']);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21, 'percent' => 50]);
pruefe($r['isError'] && $anlage->requests === [], 'temperature mit percent ebenso: ' . $r['text']);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 'warm']);
pruefe($r['isError'] && str_contains($r['text'], '"temperature" must be a number') && $anlage->requests === [], 'temperature muss eine Zahl sein: ' . $r['text']);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'command' => 'on']);
pruefe($r['isError'] && $r['text'] === 'Nothing was changed: Heizung (Gast) cannot do "on". It understands: temperature.', 'ein Thermostat nennt, was es versteht: ' . $r['text']);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Rollladen', 'temperature' => 21]);
pruefe($r['isError'] && $r['text'] === 'Nothing was changed: Rollladen (Gast) cannot do "temperature". It understands: open, close, percent.', 'ein Rollladen versteht keinen Sollwert: ' . $r['text']);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung']);
pruefe($r['isError'] && str_contains($r['text'], 'temperature') && $anlage->requests === [], 'ohne Befehl nennt die Absage auch temperature: ' . $r['text']);

/* --- set_state: Gruppe ---------------------------------------------------------------------- */

$idx = index($liste, $werte);
$r = rufe($idx, 'set', ['room' => 'Gast', 'temperature' => 21]);
pruefe(!$r['isError'] && $zeile($r, 0) === '1 of 1 devices set to 21 °C (room=Gast).' && $anlage->requests === [[10028, 21.0]], 'eine Gruppe stellt nur die Thermostate: ' . $r['text']);
pruefe(str_ends_with($r['text'], "\n1 device(s) of the selection do not understand \"temperature\" and were left out."), 'der Rollladen wird ausgelassen und gezählt: ' . $r['text']);

$idx = index([$heizung + ['ReadOnly' => true]], $werte);
$r = rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21]);
pruefe($r['isError'] && str_contains($r['text'], 'can only be read') && $anlage->requests === [], '„nur abfragen“ gilt auch hier: ' . $r['text']);

/* --- Deutsch ------------------------------------------------------------------------------- */

$idx = index($liste, $werte, $de);
$texte = [
    rufe($idx, 'get', ['type' => 'thermostat'])['text'],
    rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21])['text'],
    rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21])['text'],
    rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 40])['text'],
    rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 21, 'command' => 'on'])['text'],
    rufe($idx, 'set', ['room' => 'Gast', 'name' => 'Heizung', 'temperature' => 'x'])['text'],
];
pruefe($anlage->missing === [], 'jeder neue Text hat eine deutsche Übersetzung: ' . implode(' | ', array_unique($anlage->missing)));
pruefe(str_contains($texte[0], 'Wintergarten | Heizung | thermostat | Soll 21 °C (derzeit 20.6 °C)'), 'deutsche Zeile: ' . $texte[0]);
pruefe(str_contains($texte[1], '1 von 1 Geräten auf 21 °C gestellt'), 'deutsche Kopfzeile: ' . $texte[1]);

ende('Heizungs-Sollwert');
