<?php

declare(strict_types=1);

/*
 * Sonder-Sollwerte per command (Mitschnitt nuc 26.10.2026): Ein Thermostat ohne Modus, dessen Sollwert einen
 * Sonderwert mit dem Text „On“ oder „Off“ kennt, geht mit command=on bzw. off auf diesen Wert. Der Wert kommt aus
 * der Variable (HM-CC-TC 100 und 0, Wandthermostat 30,5 und 4,5), das Modul rät keinen. Nur mit type=thermostat
 * oder einem Namen, „Musikzimmer aus“ lässt die Heizung stehen. Anlass: Claude konnte „On“ nicht setzen und riet
 * 30,5 °C für ein HM-CC-TC. Die Karte bekommt keinen Schalter. Die betreute Anlage (Homematic IP, nur „On“ bei
 * 30,5) ist dort nur abfragbar; im Test ist „nur abfragen“ ausgeschaltet, das ist angenommen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-26_sonderwert_befehl.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];

/** @var FakeBackend */
$anlage = null;

function index(array $liste, array $werte): DeviceIndex
{
    global $anlage;
    $anlage = new FakeBackend($werte);
    return new DeviceIndex($liste, $anlage);
}

function rufe(callable $aufruf): array
{
    try {
        return $aufruf();
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$eintrag = static function (DeviceIndex $idx, string $raum): array {
    foreach ($idx->entries() as $e) {
        if ($e['Room'] === $raum) {
            return $e;
        }
    }
    return [];
};

/* --- Fähigkeiten ------------------------------------------------------------------------------ */

$idx = index($liste, $werte);
$musik = $eintrag($idx, 'Musikzimmer');
pruefe(($musik['SpecialVia'] ?? null) === ['on' => ['value' => 100.0, 'caption' => 'On'], 'off' => ['value' => 0.0, 'caption' => 'Off']],
    'HM-CC-TC: On = 100, Off = 0 aus den Intervallen der Darstellung: ' . json_encode($musik['SpecialVia'] ?? null));
$winter = $eintrag($idx, 'Wintergarten');
pruefe(($winter['SpecialVia'] ?? null) === ['on' => ['value' => 30.5, 'caption' => 'On'], 'off' => ['value' => 4.5, 'caption' => 'Off']],
    'Wandthermostat: On = 30,5, Off = 4,5 am Rand: ' . json_encode($winter['SpecialVia'] ?? null));
pruefe(DeviceIndex::abilitiesOf($musik) === ['temperature', 'on', 'off'], 'versteht temperature, on und off: ' . json_encode(DeviceIndex::abilitiesOf($musik)));
pruefe(DeviceIndex::abilitiesOf($liste[3]) === ['temperature'], 'eine Zeile ohne Blick auf die Anlage bleibt bei temperature: ' . json_encode(DeviceIndex::abilitiesOf($liste[3])));
pruefe(array_values(array_intersect(['on', 'off', 'temperature'], DeviceIndex::offeredAbilities($idx->entries()))) === ['on', 'off', 'temperature'],
    'das Schema bietet on und off an: ' . json_encode(DeviceIndex::offeredAbilities($idx->entries())));

$betreut = fixture('betreut_2026-10-23_sonder_sollwert.json');
$nurOn = array_map(static fn (array $g): array => ['ReadOnly' => false] + $g, $betreut['geraete']);
$idxNurOn = new DeviceIndex($nurOn, new FakeBackend($betreut['variablen']));
pruefe(DeviceIndex::abilitiesOf($idxNurOn->entries()[1]) === ['temperature', 'on'], 'Homematic IP nur mit „On“ bei 30,5: on, kein off: ' . json_encode(DeviceIndex::abilitiesOf($idxNurOn->entries()[1])));

/* --- set_state ---------------------------------------------------------------------------------- */

$idx = index($liste, $werte);
$r = rufe(static fn () => $idx->setState(['room' => 'Musikzimmer', 'type' => 'thermostat', 'command' => 'on']));
pruefe(!$r['isError'] && $zeile($r, 0) === '1 of 1 devices set to On (room=Musikzimmer, type=thermostat).', 'Kopfzeile nennt den Text des Sonderwerts: ' . $r['text']);
pruefe($anlage->requests === [[48076, 100.0]], 'gesendet wird 100, nicht 30,5: ' . json_encode($anlage->requests));
pruefe(str_starts_with($zeile($r, 1), 'Musikzimmer | Heizung | thermostat | special setpoint “On” (100 °C, outside 6 to 30 °C)'), 'Gerätezeile wie bei get_state: ' . $r['text']);

$idx = index($liste, $werte);
$r = rufe(static fn () => $idx->setState(['room' => 'Wintergarten', 'name' => 'Heizung', 'command' => 'off']));
pruefe(!$r['isError'] && $zeile($r, 0) === '1 of 1 devices set to Off (room=Wintergarten, name=Heizung).', 'mit Namen ohne Typ: ' . $r['text']);
pruefe($anlage->requests === [[11093, 4.5]], 'Wandthermostat bekommt 4,5: ' . json_encode($anlage->requests));

$idx = index($liste, $werte);
$r = rufe(static fn () => $idx->setState(['room' => 'Gast', 'type' => 'thermostat', 'command' => 'on']));
pruefe(!$r['isError'] && $zeile($r, 0) === '1 of 1 devices set to On (room=Gast, type=thermostat).' && $anlage->requests === [], 'steht schon auf On: nichts gesendet: ' . $r['text'] . ' ' . json_encode($anlage->requests));

$idx = index($liste, $werte);
$r = rufe(static fn () => $idx->setState(['type' => 'thermostat', 'command' => 'off']));
pruefe(!$r['isError'] && $zeile($r, 0) === '4 of 4 devices set to Off (type=thermostat).', 'alle vier auf Off: ' . $r['text']);
pruefe(count($anlage->requests) === 4 && in_array([32272, 0.0], $anlage->requests, true) && in_array([11093, 4.5], $anlage->requests, true), 'je Gerät sein eigener Wert: ' . json_encode($anlage->requests));

$idx = index($liste, $werte);
$r = rufe(static fn () => $idx->setState(['room' => 'Musikzimmer', 'command' => 'off']));
pruefe($r['isError'] && str_contains($r['text'], 'type=thermostat') && $anlage->requests === [], 'nur der Raum: die Heizung bleibt, die Absage nennt type=thermostat: ' . $r['text']);

$idx = index($liste, $werte);
$r = rufe(static fn () => $idx->setState(['room' => 'Musikzimmer', 'type' => 'thermostat', 'temperature' => 30.5]));
pruefe($anlage->requests === [] && str_contains($r['text'], 'outside 6 to 30 °C') && str_contains($r['text'], 'command on') && str_contains($r['text'], '“On”'),
    '30,5 wird abgewiesen, die Absage nennt command on für „On“: ' . $r['text']);

$idxNurOn = new DeviceIndex($nurOn, $nurOnAnlage = new FakeBackend($betreut['variablen']));
$r = rufe(static fn () => $idxNurOn->setState(['room' => 'Kinderzimmer', 'type' => 'thermostat', 'command' => 'off']));
pruefe($r['isError'] && $nurOnAnlage->requests === [], 'ohne „Off“ versteht das Thermostat off nicht: ' . $r['text']);

/* --- Karte -------------------------------------------------------------------------------------- */

$idx = index($liste, $werte);
$r = rufe(static fn () => $idx->cards(['room' => 'Musikzimmer'], false));
$karte = $r['structuredContent']['cards'][0] ?? [];
pruefe(!isset($karte['controls']['switch']) && isset($karte['controls']['setpoint']), 'die Karte behält den Sollwert, ohne Schalter: ' . json_encode($karte['controls'] ?? null));
// Knöpfe „Off“ und „On“ am Sollwert (Burkhard 26.10.2026), in der Reihenfolge Off, On wie −, +
pruefe(($karte['controls']['setpoint']['specials'] ?? null) === [['command' => 'off', 'caption' => 'Off', 'active' => false], ['command' => 'on', 'caption' => 'On', 'active' => false]]
    && array_key_exists('valueCaption', $karte['controls']['setpoint'] ?? []) && $karte['controls']['setpoint']['valueCaption'] === null,
    'Musikzimmer auf 30 °C: Knöpfe Off und On, keiner gewählt: ' . json_encode($karte['controls']['setpoint'] ?? null, JSON_UNESCAPED_UNICODE));
$r = rufe(static fn () => $idx->cards(['room' => 'Gast'], false));
$gast = $r['structuredContent']['cards'][0]['controls']['setpoint'] ?? [];
pruefe(($gast['valueCaption'] ?? null) === 'On' && ($gast['specials'][1]['active'] ?? null) === true && ($gast['specials'][0]['active'] ?? null) === false,
    'Gast auf 100: der Wert heißt „On“, der Knopf On ist gewählt: ' . json_encode($gast, JSON_UNESCAPED_UNICODE));
$r = rufe(static fn () => $idx->cards(['room' => 'Gast'], true));
pruefe(!isset($r['structuredContent']['cards'][0]['controls']['setpoint']), 'nur abfragen: keine Bedienung, also auch keine Knöpfe');
$schluessel = $karte['key'] ?? '';
$r = rufe(static fn () => $idx->controlDevice(['device' => $schluessel, 'command' => 'on']));
pruefe($anlage->requests === [[48076, 100.0]] && ($r['structuredContent']['cards'][0]['controls']['setpoint']['valueCaption'] ?? null) === 'On',
    'Knopf On auf der Karte sendet 100, die neue Karte zeigt „On“: ' . json_encode($anlage->requests) . ' ' . ($r['text'] ?? ''));
$r = rufe(static fn () => $idx->controlDevice(['device' => $schluessel, 'temperature' => 21]));
pruefe(end($anlage->requests) === [48076, 21.0], 'von „On“ zurück auf eine Temperatur: ' . json_encode($anlage->requests));

ende('Sonder-Sollwert per command');
