<?php

declare(strict_types=1);

/*
 * Luftentfeuchter (Typ humidifier): Abfrage mit Ist-Feuchte und Zielfeuchte, Ein/Aus, Zielfeuchte stellen mit
 * set_state humidity (wie HassHumidifierSetpoint bei Home Assistant).
 * Mitschnitt nuc_2026-10-11_entfeuchter.json: Luftentfeuchter im Keller über das HomeAssistant-Modul, mit Burkhards
 * OK gestellt. Zielfeuchte 60 kam nach 2,3 s an, 55 nach 0,5 s, 56 nie (das Gerät nimmt offenbar nur Fünferschritte,
 * die Darstellung nennt Schritt 1). Ein/Aus ist nicht gemessen und wird hier nur ohne Befehl geprüft.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-11_entfeuchter.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function rufe(array $werte, string $werkzeug, array $argumente, ?array $uebersetzungen = null): array
{
    global $anlage, $liste;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    try {
        $idx = new DeviceIndex($liste, $anlage);
        return $werkzeug === 'get' ? $idx->getState($argumente) : $idx->setState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$gesendet = static function (int $variable): array {
    global $anlage;
    return array_values(array_map(static fn (array $q): mixed => $q[1], array_filter($anlage->requests, static fn (array $q): bool => $q[0] === $variable)));
};
$keller = ['room' => 'Keller', 'type' => 'humidifier'];

/* --- Typ und Angebot ------------------------------------------------------------------------------ */

pruefe(array_key_exists('humidifier', DeviceIndex::TYPES) && in_array('humidifier', DeviceIndex::CONTROLLABLE, true), 'Typ humidifier, steuerbar');
pruefe((DeviceIndex::ROLES['humidity'] ?? null) === 'HumidityVariableID', 'Fähigkeit humidity: ' . json_encode(DeviceIndex::ROLES));
pruefe((DeviceIndex::TYPE_ROLES['humidifier']['required'] ?? null) === ['state', 'setpoint'], 'Ein/Aus oder Zielfeuchte ist Pflicht: ' . json_encode(DeviceIndex::TYPE_ROLES['humidifier'] ?? null));
pruefe(array_slice(DeviceIndex::abilitiesOf($liste[0]), 0, 3) === ['on', 'off', 'humidity'], 'versteht on, off, humidity: ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));
pruefe(in_array('humidity', DeviceIndex::offeredAbilities($liste), true), 'das Schema bietet humidity an');

/* --- Abfrage -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'get', $keller);
pruefe($zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | on (51 %, setpoint 55 %, Smart, fan Niedrig)', 'Zeile mit Ist, Soll, Modus und Lüfter: ' . $r['text']);
$r = rufe($werte, 'get', $keller, $de);
pruefe($zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | an (51 %, Soll 55 %, Smart, Lüfter Niedrig)', 'auf Deutsch: ' . $r['text']);
$r = rufe($werte, 'get', $keller + ['active_only' => true]);
pruefe(str_starts_with($r['text'], '1 of 1 checked devices active'), 'eingeschaltet zählt als aktiv: ' . $r['text']);
$aus = $werte;
$aus['21135']['wert'] = false;
$r = rufe($aus, 'get', $keller);
pruefe($zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | off', 'ausgeschaltet: ' . $r['text']);

/* --- Zielfeuchte ---------------------------------------------------------------------------------- */

$hoch = $werte;
$hoch['31445']['wert'] = 60.0;
$r = rufe($hoch, 'set', $keller + ['humidity' => 55]);
pruefe($gesendet(31445) === [55.0] && $zeile($r, 0) === '1 of 1 devices set to 55 % (room=Keller, type=humidifier).', 'von 60 auf 55, nach 0,5 s bestätigt: ' . $r['text']);
pruefe($zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | on (51 %, setpoint 55 %, Smart, fan Niedrig)', 'die Zeile nennt den neuen Zustand: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['humidity' => 60], $de);
pruefe($gesendet(31445) === [60.0] && $zeile($r, 0) === '0 von 1 Geräten auf 60 % gestellt, 1 noch nicht bestätigt (room=Keller, type=humidifier).', '60 brauchte 2,3 s, länger als die Wartezeit: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['humidity' => 56]);
pruefe($gesendet(31445) === [56.0] && $zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | sent, but the device has not confirmed yet (currently setpoint 55 %)', '56 nimmt das Gerät nicht an, die Antwort sagt es: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['humidity' => 55]);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | already set to 55 %', 'schon 55 %, nichts gesendet: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['humidity' => 90]);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | not set: 90 % is outside 35 to 85 %', 'außerhalb des Bereichs: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['humidity' => 50.4]);
pruefe($gesendet(31445) === [50.0], 'auf die Schrittweite gerundet: ' . json_encode($anlage->requests));

/* --- Ein/Aus und Absagen -------------------------------------------------------------------------- */

$r = rufe($werte, 'set', $keller + ['command' => 'on']);
pruefe($anlage->requests === [] && str_contains($zeile($r, 1), 'already on'), 'schon an, nichts gesendet: ' . $r['text']);
foreach ([
    [['humidity' => 150], 'Parameter "humidity" must be a number from 0 to 100.'],
    [['humidity' => 'feucht'], 'Parameter "humidity" must be a number from 0 to 100.'],
    [['humidity' => 50, 'command' => 'on'], 'Nothing was changed. Give humidity alone, without command, percent or temperature.'],
    [['humidity' => 50, 'temperature' => 21], 'Nothing was changed. Give humidity alone, without command, percent or temperature.'],
] as [$argumente, $erwartet]) {
    $r = rufe($werte, 'set', $keller + $argumente);
    pruefe($r['isError'] && $anlage->requests === [] && $r['text'] === $erwartet, json_encode($argumente) . ': ' . $r['text']);
}

/* --- Auswahl per Name: Modus und Lüfterstufe (build 66) ------------------------------------------- */

pruefe(DeviceIndex::abilitiesOf($liste[0]) === ['on', 'off', 'humidity', 'mode', 'fan_speed'], 'versteht auch mode und fan_speed: ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));
pruefe(array_slice(DeviceIndex::offeredAbilities($liste), -2) === ['mode', 'fan_speed'], 'das Schema bietet beide an: ' . json_encode(DeviceIndex::offeredAbilities($liste)));
pruefe((DeviceIndex::ROLES['mode'] ?? null) === 'ModeVariableID' && (DeviceIndex::ROLES['fan'] ?? null) === 'FanVariableID', 'Fähigkeiten mode und fan');
$r = rufe($werte, 'set', $keller + ['fan_speed' => 'Medium']);
pruefe($gesendet(28873) === ['Medium'] && $zeile($r, 0) === '1 of 1 devices set to Medium (room=Keller, type=humidifier).', 'Lüfter auf Medium, nach 1,3 s bestätigt: ' . $r['text']);
pruefe($zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | on (51 %, setpoint 55 %, Smart, fan Medium)', 'die Zeile nennt die neue Stufe: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['fan_speed' => 'medium']);
pruefe($gesendet(28873) === ['Medium'], 'Groß- und Kleinschreibung zählt nicht: ' . json_encode($anlage->requests));
$r = rufe($werte, 'set', $keller + ['fan_speed' => 'high']);
pruefe($gesendet(28873) === ['High'], 'der Wert trifft wie die Beschriftung („high“ → High, angezeigt „Hoch“): ' . json_encode($anlage->requests));
$r = rufe($werte, 'set', $keller + ['fan_speed' => 'Hoch']);
pruefe($gesendet(28873) === ['High'], 'die deutsche Beschriftung trifft: ' . json_encode($anlage->requests));
$r = rufe($werte, 'set', $keller + ['fan_speed' => 'Niedrig'], $de);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | stand schon auf Niedrig', 'schon Niedrig, nichts gesendet: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['mode' => 'Kontinuierlich'], $de);
pruefe($gesendet(25833) === ['Continuous'] && $zeile($r, 0) === '1 von 1 Geräten auf Kontinuierlich gestellt (room=Keller, type=humidifier).', 'Modus Kontinuierlich, nach 0,8 s bestätigt: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['mode' => 'Turbo']);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | unknown entry "Turbo", known: Setzen, Smart, Kontinuierlich, Entfeuchten', 'unbekannter Modus, die Zeile nennt die Einträge: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['fan_speed' => 'Smart']);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | "Smart" is a mode, give it as mode', 'im falschen Feld: Hinweis statt Fehlgriff: ' . $r['text']);
$r = rufe($werte, 'set', $keller + ['mode' => 'Hoch']);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Keller | Luftentfeuchter | humidifier | "Hoch" is a fan speed, give it as fan_speed', 'umgekehrt ebenso: ' . $r['text']);
foreach ([
    [['mode' => 5], 'Parameter "mode" must be the name of an entry.'],
    [['fan_speed' => ''], 'Parameter "fan_speed" must be the name of an entry.'],
    [['mode' => 'Smart', 'humidity' => 50], 'Nothing was changed. Give humidity alone, without command, percent or temperature.'],
    [['mode' => 'Smart', 'fan_speed' => 'Hoch'], 'Nothing was changed. Give mode or fan_speed alone, without another field.'],
    [['fan_speed' => 'Hoch', 'command' => 'on'], 'Nothing was changed. Give mode or fan_speed alone, without another field.'],
] as [$argumente, $erwartet]) {
    $r = rufe($werte, 'set', $keller + $argumente);
    pruefe($r['isError'] && $anlage->requests === [] && $r['text'] === $erwartet, json_encode($argumente) . ': ' . $r['text']);
}

/* --- Übersetzungen -------------------------------------------------------------------------------- */

foreach (['fan %s', 'Mode', 'Fan speed', 'unknown entry "%s", known: %s', '"%s" is a mode, give it as mode', '"%s" is a fan speed, give it as fan_speed', 'cannot be set (no entries known)', 'cannot be set (the variable has no action)', 'Parameter "%s" must be the name of an entry.', 'Nothing was changed. Give mode or fan_speed alone, without another field.'] as $text) {
    pruefe(isset($de[$text]), 'deutsche Übersetzung für „' . $text . '“');
}
foreach (['Humidifier', 'Current humidity', 'Target humidity', 'Parameter "humidity" must be a number from 0 to 100.', 'Nothing was changed. Give humidity alone, without command, percent or temperature.'] as $text) {
    pruefe(isset($de[$text]), 'deutsche Übersetzung für „' . $text . '“');
}

ende('Luftentfeuchter');
