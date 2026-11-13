<?php

declare(strict_types=1);

/*
 * Raumklima Stufe 3 (Burkhard 13.11.2026): Die Innentemperatur ist eine Fähigkeit des Geräts. Kühlschrank, Kühltruhe
 * und Weinkühlschrank tragen ihren Fühler als InsideVariableID an der eigenen Zeile (Typen switch, socket, appliance),
 * so wie Leistung und Zähler schon dort hängen. Die Zeile nennt dann Zustand, Leistung und Innentemperatur; unter
 * type=temperature taucht der Fühler nicht auf, er ist nicht das Klima des Raums. Noch nicht: Verlauf und Diagramm
 * der Innentemperatur (dafür bleibt der Fühler als measurement eintragbar).
 * Mitschnitt nuc_2026-11-13_innentemperatur.json: Zeilen und Werte echt; dass der Fühler am Gerät hängt, legt der Test
 * fest (das Feld gab es beim Mitschnitt noch nicht).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once dirname(__DIR__) . '/libs/RoomIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-13_innentemperatur.json');
date_default_timezone_set($mitschnitt['zeitzone']);

/** Die Zeilen des Mitschnitts, die Geräte mit ihrem Fühler; die drei Messwertzeilen der Fühler entfallen dabei. */
function mitFuehler(): array
{
    global $mitschnitt;
    $zeilen = [];
    foreach ($mitschnitt['geraete'] as $g) {
        if ($g['Type'] === 'measurement') {
            continue;
        }
        $zeilen[] = isset($mitschnitt['fuehler'][$g['Name']]) && $g['Type'] === 'switch' ? $g + ['InsideVariableID' => $mitschnitt['fuehler'][$g['Name']]] : $g;
    }
    return $zeilen;
}

function frage(array $argumente, array $zeilen, string $werkzeug = 'get', ?array $uebersetzungen = null, array $werte = []): array
{
    global $mitschnitt;
    $anlage = new FakeBackend(array_replace($mitschnitt['variablen'], $werte), $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($zeilen, $anlage);
        return $werkzeug === 'cards' ? $index->cards($argumente, true) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

/* --- die Fähigkeit --------------------------------------------------------------------------------- */

pruefe((DeviceIndex::ROLES['inside'] ?? null) === 'InsideVariableID', 'die Fähigkeit inside steht in der Zeile als InsideVariableID: ' . json_encode(DeviceIndex::ROLES['inside'] ?? null));
foreach (['switch', 'socket', 'appliance'] as $typ) {
    pruefe(in_array('inside', DeviceIndex::TYPE_ROLES[$typ]['roles'], true) && !in_array('inside', DeviceIndex::TYPE_ROLES[$typ]['required'], true), $typ . ' kann eine Innentemperatur haben, muss aber nicht');
}
foreach (['light', 'thermostat', 'media', 'measurement', 'temperature', 'meter'] as $typ) {
    pruefe(!in_array('inside', DeviceIndex::TYPE_ROLES[$typ]['roles'], true), $typ . ' hat keine Innentemperatur');
}
$truhe = array_values(array_filter(mitFuehler(), static fn (array $g): bool => $g['Name'] === 'Kühltruhe'))[0];
pruefe(DeviceIndex::rolesOf($truhe) === ['state' => 41438, 'power' => 29008, 'energy' => 17393, 'inside' => 10645], 'rolesOf liest den Fühler der Kühltruhe: ' . json_encode(DeviceIndex::rolesOf($truhe)));
pruefe(DeviceIndex::rolesOf(['Type' => 'light', 'StateVariableID' => 12345, 'InsideVariableID' => 10645]) === ['state' => 12345], 'an einem Licht zählt das Feld nicht');
pruefe(DeviceIndex::abilitiesOf($truhe) === DeviceIndex::abilitiesOf(array_diff_key($truhe, ['InsideVariableID' => 0])), 'die Innentemperatur bringt keinen Befehl mit');

/* --- get_state -------------------------------------------------------------------------------------- */

$r = frage(['room' => 'Keller', 'name' => 'Kühltruhe'], mitFuehler());
pruefe(!$r['isError'] && $r['text'] === "1 device(s) found (room=Keller, name=Kühltruhe).\nKeller | Kühltruhe | switch | off (0.0 W, inside -19.0 °C)", 'die Kühltruhe nennt Zustand, Leistung und Innentemperatur: ' . $r['text']);
$r = frage(['room' => 'Keller', 'name' => 'Gefriertruhe'], mitFuehler(), 'get', ['inside %s' => 'innen %s', 'off' => 'aus', '%d device(s) found (%s).' => '%d Gerät(e) gefunden (%s).']);
pruefe(str_ends_with($r['text'], 'Keller | Kühltruhe (Gefriertruhe) | switch | aus (0.0 W, innen -19.0 °C)'), 'übersetzt „innen“, auch über den Alias: ' . $r['text']);
$r = frage(['room' => 'Keller', 'type' => 'switch'], mitFuehler());
pruefe($r['text'] === "3 device(s) found (room=Keller, type=switch).\n"
    . "Keller | Kühlschrank | switch | off (0.0 W, inside 11.9 °C)\n"
    . "Keller | Kühltruhe | switch | off (0.0 W, inside -19.0 °C)\n"
    . 'Keller | Weinkühlschrank | switch | off (0.0 W, inside 17.4 °C)', 'alle drei Kühlgeräte: ' . $r['text']);

// Ohne Fühler bleibt die Zeile, wie sie war
$r = frage(['room' => 'Keller', 'name' => 'Kühltruhe'], array_map(static fn (array $g): array => array_diff_key($g, ['InsideVariableID' => 0]), mitFuehler()));
pruefe(str_ends_with($r['text'], 'Keller | Kühltruhe | switch | off (0.0 W)'), 'ohne Fühler unverändert: ' . $r['text']);

// Ein Gerät ohne Leistung: Zustand und Innentemperatur
$ohneLeistung = array_map(static fn (array $g): array => $g['Name'] === 'Kühltruhe' ? array_diff_key($g, ['PowerVariableID' => 0, 'EnergyVariableID' => 0]) : $g, mitFuehler());
$r = frage(['room' => 'Keller', 'name' => 'Kühltruhe'], $ohneLeistung);
pruefe(str_ends_with($r['text'], 'Keller | Kühltruhe | switch | off (inside -19.0 °C)'), 'ohne Leistung: ' . $r['text']);

// Ein Gerät nur mit Leistung und Fühler (kein Schaltzustand): die Leistung bleibt der Zustand
$nurLeistung = array_map(static fn (array $g): array => $g['Name'] === 'Kühltruhe' ? ['Type' => 'appliance'] + array_diff_key($g, ['StateVariableID' => 0]) : $g, mitFuehler());
$r = frage(['room' => 'Keller', 'name' => 'Kühltruhe'], $nurLeistung);
pruefe(str_ends_with($r['text'], 'Keller | Kühltruhe | appliance | 0.0 W (inside -19.0 °C)'), 'nur Leistung und Fühler: ' . $r['text']);

// Liefert der Fühler keinen Wert, fehlt nur die Innentemperatur. Am Modul sperrt eine gelöschte Variable die Zeile schon
// vorher (findProblems, wie bei jeder Fähigkeit); hier prüft DeviceIndex allein.
$r = frage(['room' => 'Keller', 'name' => 'Kühltruhe'], array_map(static fn (array $g): array => $g['Name'] === 'Kühltruhe' ? ['InsideVariableID' => 69990] + $g : $g, mitFuehler()));
pruefe(str_ends_with($r['text'], 'Keller | Kühltruhe | switch | off (0.0 W)'), 'ein Fühler, den es nicht gibt, lässt die Zeile ohne Innentemperatur: ' . $r['text']);

/* --- nicht das Klima des Raums ------------------------------------------------------------------------ */

$r = frage(['room' => 'Keller', 'type' => 'temperature'], mitFuehler());
pruefe($r['text'] === "2 device(s) found (room=Keller, type=temperature).\nKeller | Temperatur | temperature | 19.3 °C\nKeller | Thermostat | thermostat | setpoint 17 °C (currently 19.3 °C)", 'unter type=temperature steht nur der Raum: ' . $r['text']);
pruefe(DeviceIndex::crowdedClimate(mitFuehler()) === [], 'die Fühler in den Geräten zählen im Selbsttest nicht als Temperaturen des Raums');

/* --- Karte ---------------------------------------------------------------------------------------------- */

$r = frage(['room' => 'Keller', 'name' => 'Kühltruhe'], mitFuehler(), 'cards');
$karte = $r['structuredContent']['cards'][0] ?? [];
pruefe(($karte['text'] ?? '') === 'off (0.0 W, inside -19.0 °C)' && ($karte['figure'] ?? null) === ['value' => '0.0', 'unit' => 'W'] && ($karte['key'] ?? '') === '41438.29008.17393.10645', 'die Karte trägt die Innentemperatur im Text, ihr Hauptwert bleibt die Leistung: ' . json_encode($karte, JSON_UNESCAPED_UNICODE));

ende('Innentemperatur');
