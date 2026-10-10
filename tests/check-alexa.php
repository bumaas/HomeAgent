<?php

declare(strict_types=1);

/*
 * Vorschlag der Freigabeliste aus der Alexa-Instanz (Mitschnitt nuc 10.10.2026: #10110 mit 76
 * Einträgen, Raum- und Geräteliste von #15877). Zuordnung je Alexa-Liste, Raum aus den Wörtern von
 * Alexa-Name und Variablenpfad gegen die Raumliste, gekürzter Name mit dem Alexa-Namen als Alias,
 * Fensterantriebe unter „Rollladen“, Thermostat und Temperatursensor zusammengelegt, schon
 * freigegebene und doppelte Variablen übersprungen, nicht passende Arten mit Grund gezählt.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once dirname(__DIR__) . '/libs/RoomIndex.php';
if (is_file(dirname(__DIR__) . '/libs/AlexaImport.php')) {
    require_once dirname(__DIR__) . '/libs/AlexaImport.php';
}

$mitschnitt = fixture('nuc_2026-10-10_alexa.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
$objekte = $mitschnitt['objekte'];
$info = static fn (int $id): ?array => isset($objekte[$id]) ? ['parent' => (int)$objekte[$id]['eltern'], 'path' => (string)$objekte[$id]['pfad']] : null;
$raeume = new RoomIndex($mitschnitt['raeume']);
$fehlend = [];
$englisch = static fn (string $t): string => $t;
$deutsch = static function (string $t) use ($de, &$fehlend): string {
    if (!isset($de[$t])) {
        $fehlend[] = $t;
    }
    return $de[$t] ?? $t;
};

function vorschlag(array $geraete, callable $uebersetzer): array
{
    global $mitschnitt, $info, $raeume;
    if (!class_exists('AlexaImport')) {
        return ['rows' => [], 'report' => [], 'message' => 'FEHLT: AlexaImport'];
    }
    try {
        return AlexaImport::propose($mitschnitt['alexa'], $info, $geraete, $raeume, $uebersetzer);
    } catch (Throwable $e) {
        return ['rows' => [], 'report' => [], 'message' => 'FEHLT: ' . $e->getMessage()];
    }
}

/** Zeile zu einer Variable, gleich in welcher Fähigkeit */
function zeile(array $zeilen, int $variable): ?array
{
    foreach ($zeilen as $z) {
        foreach (DeviceIndex::ROLES as $schluessel) {
            if (($z[$schluessel] ?? 0) === $variable) {
                return $z;
            }
        }
    }
    return null;
}

$kurz = static fn (?array $z): ?array => $z === null ? null : array_filter($z, static fn ($v, $k): bool => !str_ends_with($k, 'VariableID') || $v > 1, ARRAY_FILTER_USE_BOTH);

/* --- Gegen die echte Liste von #15877: nur, was noch fehlt ------------------------------------- */

$r = vorschlag($mitschnitt['geraete'], $englisch);
$zeilen = $r['rows'];
pruefe(count($zeilen) === 21, '21 Geräte fehlen noch in der Liste von #15877: ' . count($zeilen));
pruefe(($r['report']['proposed'] ?? null) === 21 && ($r['report']['released'] ?? null) === 47 && ($r['report']['scenes'] ?? null) === 2, 'Zählung: 21 vorgeschlagen, 47 schon freigegeben, 2 Szenen: ' . json_encode($r['report']));
pruefe(($r['report']['unsupported'] ?? null) === ['color light' => 1, 'lock' => 4, 'television' => 1], 'ohne passenden Typ in der Reihenfolge der Alexa-Listen: ' . json_encode($r['report']['unsupported'] ?? null));
pruefe($r['message'] === '21 device(s) proposed, 47 already released, 6 without a matching type (color light 1, lock 4, television 1), 2 scene(s) are Alexa actions and were left out. Tick the devices to release and press Release selected.', 'Meldung: ' . $r['message']);

$z = zeile($zeilen, 15165);
pruefe(is_array($z) && array_keys($z) === array_merge(['Room', 'Name', 'Aliases', 'Type', 'Invert', 'ReadOnly', 'Tilt'], array_values(DeviceIndex::ROLES)), 'Zeile im Format der Property Devices: ' . json_encode(array_keys($z ?? [])));
pruefe($kurz($z) === ['Room' => 'Gast', 'Name' => 'Deckenlicht', 'Aliases' => 'Deckenlicht Gästezimmer', 'Type' => 'light', 'Invert' => false, 'ReadOnly' => false, 'Tilt' => false, 'StateVariableID' => 15165, 'LevelVariableID' => 15288], 'Licht mit Schalter und Helligkeit, Raum über den Alias Gästezimmer: ' . json_encode($kurz($z), JSON_UNESCAPED_UNICODE));
pruefe(($z['MovingVariableID'] ?? null) === 1 && ($z['SetpointVariableID'] ?? null) === 1, 'ungesetzte Fähigkeiten stehen auf 1 wie im Formular');
pruefe(($kurz(zeile($zeilen, 33255))['LevelVariableID'] ?? 0) === 59411 && (zeile($zeilen, 33255)['Name'] ?? '') === 'Fensterlicht' && (zeile($zeilen, 33255)['Room'] ?? '') === 'Küche', 'Farbtemperatur fällt weg, Name ohne Raum');
pruefe((zeile($zeilen, 53640)['Name'] ?? '') === 'Wandlicht links' && (zeile($zeilen, 40902)['Name'] ?? '') === 'Wandlicht rechts', 'der Raum fällt mitten aus dem Namen heraus');
$dimmer = $kurz(zeile($zeilen, 19752));
pruefe(($dimmer['Type'] ?? '') === 'light' && !isset($dimmer['StateVariableID']) && ($dimmer['Room'] ?? '') === 'Wohnzimmer' && ($dimmer['Name'] ?? '') === 'Weiß Nachbar', 'Dimmer nur mit Helligkeit: ' . json_encode($dimmer, JSON_UNESCAPED_UNICODE));
$pc = $kurz(zeile($zeilen, 12432));
pruefe(($pc['Type'] ?? '') === 'switch' && ($pc['Room'] ?? '') === 'Wintergarten' && ($pc['Name'] ?? '') === 'PC' && ($pc['Aliases'] ?? '') === 'PC Wintergarten', 'Schalter: ' . json_encode($pc, JSON_UNESCAPED_UNICODE));
$spar = $kurz(zeile($zeilen, 59154));
pruefe(($spar['Room'] ?? 'x') === '' && ($spar['Name'] ?? '') === 'Sparmodus Waschmaschine' && ($spar['Aliases'] ?? 'x') === '', 'ohne erkennbaren Raum bleibt der Name ganz und ohne Alias: ' . json_encode($spar, JSON_UNESCAPED_UNICODE));

$thermo = $kurz(zeile($zeilen, 48140));
pruefe($thermo === ['Room' => 'Wohnzimmer', 'Name' => 'Thermostat', 'Aliases' => 'Thermostat Wohnzimmer', 'Type' => 'thermostat', 'Invert' => false, 'ReadOnly' => false, 'Tilt' => false, 'SetpointVariableID' => 48140], 'Thermostat ohne Ist-Temperatur, weil der Sensor schon eine eigene Zeile hat: ' . json_encode($thermo, JSON_UNESCAPED_UNICODE));
pruefe((zeile($zeilen, 48076)['Room'] ?? '') === 'Musikzimmer', '„Thermostat Musik“ über den Alias Musik');
pruefe((zeile($zeilen, 45107)['Room'] ?? '') === 'Küche oben' && (zeile($zeilen, 45107)['Name'] ?? '') === 'Thermostat', '„Thermostat DG Küche“ mit Pfad DG_Küche trifft den Alias Küche_DG, nicht die Küche unten: ' . json_encode(zeile($zeilen, 45107), JSON_UNESCAPED_UNICODE));
pruefe((zeile($zeilen, 55269)['Room'] ?? '') === 'Bad oben', '„Thermostat DG Bad“ trifft Bad oben');
pruefe((zeile($zeilen, 11218)['Room'] ?? '') === 'Atelier' && (zeile($zeilen, 11218)['Name'] ?? '') === 'Thermostat DG', 'was nicht zum Raum gehört, bleibt im Namen: ' . json_encode(zeile($zeilen, 11218), JSON_UNESCAPED_UNICODE));
pruefe(($kurz(zeile($zeilen, 53076))['Type'] ?? '') === 'temperature' && (zeile($zeilen, 53076)['Room'] ?? '') === 'Garten', 'Temperatursensor ohne Thermostat bleibt ein Messwert');
pruefe(zeile($zeilen, 10028) === null && zeile($zeilen, 49785) === null && zeile($zeilen, 16893) !== null, 'Freigegebenes fehlt, auch ein Licht, dessen Helligkeit schon freigegeben ist; „Testlicht“ auf derselben Schaltvariable kommt einmal');
pruefe(zeile($zeilen, 18065) === null && zeile($zeilen, 59064) === null && zeile($zeilen, 54728) === null, 'Schlösser, Fernseher und Farblicht kommen nicht');

/* --- Gegen eine leere Liste: alles, Thermostate mit Ist-Temperatur ----------------------------- */

$r = vorschlag([], $englisch);
$zeilen = $r['rows'];
pruefe(($r['report']['released'] ?? null) === 0 && ($r['report']['duplicates'] ?? null) === 1, 'leere Liste: nichts freigegeben, „Testlicht“ ist doppelt: ' . json_encode($r['report']));
pruefe(($kurz(zeile($zeilen, 11093))['StateVariableID'] ?? 0) === 19960 && zeile($zeilen, 19960)['Type'] === 'thermostat', 'Wintergarten: Sensor in derselben Instanz wird die Ist-Temperatur');
pruefe(($kurz(zeile($zeilen, 10028))['StateVariableID'] ?? 0) === 41351 && (zeile($zeilen, 10028)['Room'] ?? '') === 'Gast', 'Gästezimmer: genau ein Thermostat und ein Sensor im Raum');
pruefe(($kurz(zeile($zeilen, 45107))['StateVariableID'] ?? 0) === 55817, 'Küche oben: „Temperatursensor Dachgeschoss Küche“ gehört dazu');
pruefe(($kurz(zeile($zeilen, 55269))['StateVariableID'] ?? 0) === 16439, 'Bad oben ebenso');
pruefe(($kurz(zeile($zeilen, 26722))['Type'] ?? '') === 'temperature', 'Keller ohne Thermostat: der Sensor bleibt ein Messwert');
pruefe(count(array_filter($zeilen, static fn (array $z): bool => $z['Type'] === 'temperature')) === 2, 'nur Keller und Garten bleiben Temperaturzeilen: ' . implode(', ', array_column(array_filter($zeilen, static fn (array $z): bool => $z['Type'] === 'temperature'), 'Room')));
pruefe(($r['report']['merged'] ?? null) === 13, '13 Sensoren wurden Ist-Temperatur: ' . json_encode($r['report']['merged'] ?? null));
$fenster = $kurz(zeile($zeilen, 24234));
pruefe(($fenster['Type'] ?? '') === 'window' && ($fenster['LevelVariableID'] ?? 0) === 24234 && ($fenster['Room'] ?? '') === 'Bad' && ($fenster['Name'] ?? '') === 'Fenster', 'Fensterantrieb unter Alexa-Rollläden wird ein Fenster: ' . json_encode($fenster, JSON_UNESCAPED_UNICODE));
$rollo = $kurz(zeile($zeilen, 36600));
pruefe(($rollo['Type'] ?? '') === 'blind' && ($rollo['Name'] ?? '') === 'Rollladen Blumenfenster' && ($rollo['Room'] ?? '') === 'Wohnzimmer', '„Blumenfenster“ im Namen macht keinen Fensterantrieb: ' . json_encode($rollo, JSON_UNESCAPED_UNICODE));
$dg = $kurz(zeile($zeilen, 55817));
pruefe(($dg['Name'] ?? '') === 'Thermostat', 'zusammengelegte Zeile trägt den Namen des Thermostats');
pruefe(count($zeilen) === count(array_unique(array_map(static fn (array $z): string => json_encode($z), $zeilen))), 'keine doppelten Zeilen');
pruefe(str_contains($r['message'], '13 temperature sensor(s) became the current temperature of a thermostat'), 'die Meldung nennt die Zusammenlegung: ' . $r['message']);

/* --- Rollladen nur mit Fahrbefehl (Alexa-Doku „Rollladen“: ~ShutterMoveStop oder ~ShutterMoveStep) ---
 * Ein solches Gerät gibt es weder am nuc noch auf der Testbox (gesucht 10.10.2026). Profilnamen und Werte
 * sind echt (nuc: ~ShutterMoveStop 0 Öffnen, 2 Stop, 4 Schließen; ~ShutterMoveStep dazu 1 und 3 Schritt);
 * angenommen ist nur, dass zwei der Alexa-Rollläden vom nuc diese Profile trügen. Als Position gelesen
 * hieße „auf 50 %“ der Wert 2, also Stop.
 */
$mitProfil = static fn (int $id): ?array => $info($id) === null ? null : $info($id) + ['profile' => [33220 => '~ShutterMoveStop', 59648 => '~ShutterMoveStep'][$id] ?? ''];
$r3 = class_exists('AlexaImport') ? AlexaImport::propose($mitschnitt['alexa'], $mitProfil, [], $raeume, $englisch) : ['rows' => [], 'report' => [], 'message' => ''];
pruefe(zeile($r3['rows'], 33220) === null && zeile($r3['rows'], 59648) === null && zeile($r3['rows'], 36600) !== null, 'Rollläden mit Fahrbefehl fallen weg, die übrigen bleiben');
pruefe(($r3['report']['unsupported']['blind without position'] ?? null) === 2, 'sie zählen als „ohne passenden Typ“: ' . json_encode($r3['report']['unsupported'] ?? null));
pruefe(str_contains($r3['message'], 'blind without position 2'), 'die Meldung nennt sie: ' . $r3['message']);

/* --- Fehlende Variable, Deutsch ---------------------------------------------------------------- */

$ohne = static fn (int $id): ?array => $id === 15165 ? null : $info($id);
$r2 = class_exists('AlexaImport') ? AlexaImport::propose($mitschnitt['alexa'], $ohne, [], $raeume, $englisch) : ['report' => []];
pruefe(($r2['report']['missing'] ?? null) === 1 && zeile($r2['rows'] ?? [], 15288) === null, 'Eintrag mit gelöschter Variable fällt weg und wird gezählt');
$r = vorschlag([], $deutsch);
pruefe($fehlend === [] && class_exists('AlexaImport'), 'jeder Text hat eine deutsche Übersetzung, es fehlen: ' . implode(' | ', array_unique($fehlend)));
pruefe(str_contains($r['message'], 'Gerät(e) vorgeschlagen') && str_contains($r['message'], 'Schloss 4'), 'deutsche Meldung: ' . $r['message']);

ende('Alexa-Import');
