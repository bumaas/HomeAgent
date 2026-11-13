<?php

declare(strict_types=1);

/*
 * Raumklima (Stufe 1, Burkhard 13.11.2026): Die Typen temperature und humidity meinen das Klima des Raums, die
 * Temperatur eines Geräts oder einer Anlage (Vorlauf, Speicher, Kühltruhe) ist measurement. Anlass: Blindtest des
 * Einrichtungsauftrags am nuc vom 12.11.2026. Der Auftrag legte elf Messwerte der Heizung, acht Strangfühler und drei
 * Kühlgeräte als temperature in den Keller, „Wie warm ist es im Keller?“ (room=Keller, type=temperature) lieferte danach
 * 17 Zeilen statt 3. An der Suche ändert diese Stufe nichts; der Selbsttest nennt Räume, in denen mehr Temperaturen
 * oder Feuchten stehen, als ein Raum als Klima hat.
 * Mitschnitt nuc_2026-11-12_raumklima.json: die Freigabeliste nach dem Blindtest, unverändert. Der Stand „aufgeräumt“
 * weiter unten entsteht im Test aus denselben Zeilen, nur mit dem Typ measurement an den technischen Werten.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';

$mitschnitt = fixture('nuc_2026-11-12_raumklima.json');
$geraete = $mitschnitt['geraete'];

/** Fängt eine fehlende Methode ab, damit der rote Lauf Zusicherungen zeigt statt eines Abbruchs. */
function gedraenge(array $zeilen): mixed
{
    try {
        return DeviceIndex::crowdedClimate($zeilen);
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage();
    }
}

/* --- die Grenze ---------------------------------------------------------------------------------- */

pruefe(defined('DeviceIndex::ROOM_CLIMATE_MAX') && constant('DeviceIndex::ROOM_CLIMATE_MAX') === 3, 'bis zu drei verschiedene Temperaturen oder Feuchten je Raum sind unauffällig');

/* --- der Stand nach dem Blindtest ---------------------------------------------------------------- */

// Keller: Raumfühler und Raumthermostat teilen sich #26722, dazu der Speicher als Thermostat und 14 technische Werte
$r = gedraenge($geraete);
pruefe($r === [['room' => 'Keller', 'type' => 'temperature', 'count' => 16]], 'nur der Keller fällt auf, mit 16 verschiedenen Temperaturen: ' . json_encode($r, JSON_UNESCAPED_UNICODE));

// Wintergarten mit Raumfühler, Fühler am Heizkörper und Thermostat (zwei Variablen), Wohnzimmer und Bad mit einer
foreach (['Wintergarten', 'Wohnzimmer', 'Bad', 'Garten'] as $raum) {
    $zeilen = array_values(array_filter($geraete, static fn (array $g): bool => $g['Room'] === $raum));
    pruefe(gedraenge($zeilen) === [], $raum . ' bleibt ohne Hinweis: ' . json_encode(gedraenge($zeilen), JSON_UNESCAPED_UNICODE));
}

/* --- dieselbe Variable zählt einmal, der Raum ohne Rücksicht auf die Schreibweise --------------------- */

$keller = array_values(array_filter($geraete, static fn (array $g): bool => $g['Room'] === 'Keller' && in_array($g['Type'], ['temperature', 'thermostat'], true)));
$drei = array_slice($keller, 0, 4); // Temperatur, Thermostat (dieselbe Variable), Warmwasserspeicher, Vorlauf
pruefe(count(array_unique(array_column($drei, 'StateVariableID'))) === 3 && gedraenge($drei) === [], 'vier Zeilen mit drei verschiedenen Variablen sind noch kein Hinweis: ' . json_encode(gedraenge($drei), JSON_UNESCAPED_UNICODE));
$vier = array_slice($keller, 0, 5);
pruefe(gedraenge($vier) === [['room' => 'Keller', 'type' => 'temperature', 'count' => 4]], 'die vierte verschiedene Variable löst ihn aus: ' . json_encode(gedraenge($vier), JSON_UNESCAPED_UNICODE));
$gemischt = $vier;
$gemischt[4]['Room'] = ' keller ';
pruefe(gedraenge($gemischt) === [['room' => 'Keller', 'type' => 'temperature', 'count' => 4]], 'der Raum zählt ohne Rücksicht auf Groß- und Kleinschreibung, genannt wird die erste Schreibweise: ' . json_encode(gedraenge($gemischt), JSON_UNESCAPED_UNICODE));
$ohneRaum = array_map(static fn (array $g): array => ['Room' => ''] + $g, $vier);
pruefe(gedraenge($ohneRaum) === [], 'Zeilen ohne Raum zählen nicht: ' . json_encode(gedraenge($ohneRaum), JSON_UNESCAPED_UNICODE));

/* --- Luftfeuchte ebenso -------------------------------------------------------------------------- */

// Vier echte Feuchtezeilen (Keller, Wintergarten, Wohnzimmer, Bad), für die Probe in einen Raum gelegt
$feuchten = array_values(array_filter($geraete, static fn (array $g): bool => $g['Type'] === 'humidity' && $g['Room'] !== 'Garten'));
$feuchten = array_map(static fn (array $g): array => ['Room' => 'Keller'] + $g, $feuchten);
pruefe(count($feuchten) === 4 && gedraenge($feuchten) === [['room' => 'Keller', 'type' => 'humidity', 'count' => 4]], 'vier Feuchten in einem Raum fallen auf: ' . json_encode(gedraenge($feuchten), JSON_UNESCAPED_UNICODE));
pruefe(gedraenge(array_slice($feuchten, 0, 3)) === [], 'drei Feuchten nicht');

/* --- aufgeräumt: technische Werte als measurement ------------------------------------------------- */

$raumklima = ['Temperatur', 'Thermostat', 'Warmwasserspeicher'];
$aufgeraeumt = array_map(static fn (array $g): array => $g['Room'] === 'Keller' && $g['Type'] === 'temperature' && !in_array($g['Name'], $raumklima, true) ? ['Type' => 'measurement'] + $g : $g, $geraete);
$umgestellt = count(array_filter($aufgeraeumt, static fn (array $g): bool => $g['Type'] === 'measurement')) - count(array_filter($geraete, static fn (array $g): bool => $g['Type'] === 'measurement'));
pruefe($umgestellt === 14, '14 Zeilen im Keller sind technische Temperaturen: ' . $umgestellt);
pruefe(gedraenge($aufgeraeumt) === [], 'als measurement eingetragen fällt der Keller nicht mehr auf: ' . json_encode(gedraenge($aufgeraeumt), JSON_UNESCAPED_UNICODE));
// Messwerte zählen nie, egal wie viele ein Raum hat (im Keller danach 23)
$messwerte = count(array_filter($aufgeraeumt, static fn (array $g): bool => $g['Room'] === 'Keller' && $g['Type'] === 'measurement'));
pruefe($messwerte === 23, 'der Keller hat danach 23 Messwerte: ' . $messwerte);

/* ================================================================================================== *
 * Stufe 2 (Burkhard 13.11.2026): Der Raum trägt sein Klima selbst. Die Raumliste nennt je Raum die Variable für
 * Temperatur und Luftfeuchte (TemperatureVariableID, HumidityVariableID), wie Home Assistant es am Bereich hat. Hat der
 * Raum eine Angabe, liefert type=temperature bzw. type=humidity nur sie, die übrigen zählt eine Schlusszeile. Ein Raum
 * ohne Angabe antwortet wie bisher. Steht die Variable in keiner Gerätezeile, antwortet der Raum trotzdem.
 * Mitschnitt nuc_2026-11-13_raumklima_werte.json: Werte echt, die Angabe an der Raumliste legt der Test fest.
 * ================================================================================================== */

require_once dirname(__DIR__) . '/libs/RoomIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$werte = fixture('nuc_2026-11-13_raumklima_werte.json');
date_default_timezone_set($werte['zeitzone']);

/** Raumliste des Mitschnitts, im Keller mit den Variablen des Raumthermostats. */
function raeume(array $klima = ['Keller' => [26722, 56003]]): array
{
    global $werte;
    return array_map(static fn (array $r): array => $r + ['TemperatureVariableID' => $klima[$r['Name']][0] ?? 0, 'HumidityVariableID' => $klima[$r['Name']][1] ?? 0], $werte['raeume']);
}

function frage(array $argumente, array $raeume, ?array $zeilen = null, string $werkzeug = 'get', ?array $uebersetzungen = null): array
{
    global $werte, $geraete;
    $anlage = new FakeBackend($werte['variablen'], $uebersetzungen);
    $anlage->jetzt = $werte['jetzt'];
    try {
        $index = new DeviceIndex($zeilen ?? $geraete, $anlage, new RoomIndex($raeume));
        return $werkzeug === 'cards' ? $index->cards($argumente, true) : $index->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

/* --- ohne Angabe bleibt alles, wie es war ---------------------------------------------------------- */

$r = frage(['room' => 'Keller', 'type' => 'temperature'], raeume([]));
pruefe(str_starts_with($r['text'], "17 device(s) found (room=Keller, type=temperature).\n") && !str_contains($r['text'], 'further'), 'ohne Angabe am Raum alle 17 Zeilen wie am 12.11.: ' . $r['text']);

/* --- mit Angabe nur das Klima des Raums ---------------------------------------------------------- */

// Die Schlusszeile nennt die übrigen Werte beim Namen (Code-Review 13.11.2026, Befund 2): Ohne Namen riet das Modell
// name=Temperatur und bekam wieder nur den Raumwert, weil der genaue Treffer die Teiltreffer verdeckt.
$kellerNamen = 'Warmwasserspeicher, Vorlauf, Rücklauf, Vorlauf-Soll, Temperatur Kühlschrank, Temperatur Kühltruhe, Temperatur Weinkühlschrank, '
    . 'Strang 1 Vorlauf, Strang 1 Rücklauf, Strang 2 Vorlauf, Strang 2 Rücklauf, Strang 3 Vorlauf, Strang 3 Rücklauf, Warmwasser Vorlauf, Warmwasser Rücklauf';

$r = frage(['room' => 'Keller', 'type' => 'temperature'], raeume());
pruefe(!$r['isError'] && $r['text'] === "2 device(s) found (room=Keller, type=temperature).\n"
    . "Keller | Temperatur | temperature | 19.3 °C\n"
    . "Keller | Thermostat | thermostat | setpoint 17 °C (currently 19.3 °C)\n"
    . '15 further temperature value(s) there are not the room temperature; ask for them with name: ' . $kellerNamen . '.', 'der Keller nennt Raumfühler und Raumthermostat (dieselbe Variable), die übrigen 15 nennt die Schlusszeile beim Namen: ' . $r['text']);
$r = frage(['room' => 'Keller', 'type' => 'temperature'], raeume(), null, 'get', ['%d further temperature value(s) there are not the room temperature; ask for them with name: %s.' => '%d weitere Temperatur(en) dort sind nicht die Raumtemperatur; mit name abfragen: %s.',
    '%d device(s) found (%s).' => '%d Gerät(e) gefunden (%s).', 'setpoint %s (currently %s)' => 'Soll %s (derzeit %s)']);
pruefe(str_ends_with($r['text'], "\n15 weitere Temperatur(en) dort sind nicht die Raumtemperatur; mit name abfragen: " . $kellerNamen . '.'), 'die Schlusszeile ist übersetzbar: ' . $r['text']);

// Aus mehreren Räumen weggelassen: jeder Name mit seinem Raum, damit das Modell auch room angeben kann
$r = frage(['type' => 'temperature'], raeume(['Keller' => [26722, 56003], 'Wintergarten' => [19960, 28691]]));
$schluss = (string)array_last(explode("\n", $r['text']));
pruefe(str_starts_with($schluss, '16 further temperature value(s) there are not the room temperature; ask for them with name: Warmwasserspeicher (Keller), Heizkörper (Wintergarten), Vorlauf (Keller), ')
    && str_ends_with($schluss, ', Warmwasser Rücklauf (Keller).'), 'aus zwei Räumen nennt die Schlusszeile Name und Raum: ' . $schluss);

// Höchstens 20 Namen, der Rest als Anzahl. Echt sind die 15 Fühler des Kellers; die sechs Zeilen „Strang 4“ bis „Strang 6“
// sind angenommen (ein Keller mit sechs Heizkreisen), sie lesen die Variablen der Stränge 1 bis 3 noch einmal.
$mehr = $geraete;
foreach ([4 => [42662, 10801], 5 => [37795, 17808], 6 => [15208, 53796]] as $strang => [$vor, $rueck]) {
    $mehr[] = ['Room' => 'Keller', 'Name' => 'Strang ' . $strang . ' Vorlauf', 'Aliases' => '', 'Type' => 'temperature', 'StateVariableID' => $vor];
    $mehr[] = ['Room' => 'Keller', 'Name' => 'Strang ' . $strang . ' Rücklauf', 'Aliases' => '', 'Type' => 'temperature', 'StateVariableID' => $rueck];
}
$r = frage(['room' => 'Keller', 'type' => 'temperature'], raeume(), $mehr);
pruefe(str_ends_with($r['text'], "\n21 further temperature value(s) there are not the room temperature; ask for them with name: " . $kellerNamen . ', Strang 4 Vorlauf, Strang 4 Rücklauf, Strang 5 Vorlauf, Strang 5 Rücklauf, Strang 6 Vorlauf and 1 more.'),
    'nach 20 Namen nur noch die Anzahl: ' . $r['text']);
$r = frage(['room' => 'Keller', 'type' => 'temperature'], raeume(), $mehr, 'get', ['%s and %d more' => '%s und %d weitere']);
pruefe(str_ends_with($r['text'], 'Strang 6 Vorlauf und 1 weitere.'), 'der Rest ist übersetzbar: ' . $r['text']);

$r = frage(['room' => 'Keller', 'type' => 'humidity'], raeume());
pruefe($r['text'] === "1 device(s) found (room=Keller, type=humidity).\nKeller | Luftfeuchte | humidity | 61 %", 'die Luftfeuchte des Kellers, ohne Schlusszeile: ' . $r['text']);

/* --- mit Namen und ohne Typ gilt die Angabe nicht -------------------------------------------------- */

$r = frage(['room' => 'Keller', 'name' => 'Vorlauf-Soll'], raeume());
pruefe($r['text'] === "1 device(s) found (room=Keller, name=Vorlauf-Soll).\nKeller | Vorlauf-Soll | temperature | 33.0 °C", 'mit Namen kommt jeder Wert: ' . $r['text']);
$r = frage(['room' => 'Keller', 'type' => 'temperature', 'name' => 'Warmwasserspeicher'], raeume());
pruefe(str_starts_with($r['text'], "1 device(s) found (room=Keller, type=temperature, name=Warmwasserspeicher).\nKeller | Warmwasserspeicher | thermostat | setpoint 46 °C"), 'auch mit Typ und Namen: ' . $r['text']);
$ohne = frage(['room' => 'Keller'], raeume([]));
$mit = frage(['room' => 'Keller'], raeume());
pruefe(!$mit['isError'] && $mit['text'] === $ohne['text'], 'der Raum ohne Typ nennt alles wie bisher');

/* --- das ganze Haus: je Raum nach dessen Angabe ---------------------------------------------------- */

$alle = frage(['type' => 'temperature'], raeume([]));
$haus = frage(['type' => 'temperature'], raeume());
$zeilen = static fn (array $antwort): array => array_slice(explode("\n", $antwort['text']), 1);
pruefe(count($zeilen($alle)) === 25 && count($zeilen($haus)) === 11 && str_starts_with($haus['text'], '10 device(s) found (type=temperature).'), 'im ganzen Haus fallen nur die 15 des Kellers weg: ' . $haus['text']);
pruefe(str_contains($haus['text'], "\nWintergarten | Heizkörper | temperature | 20.0 °C\n") && str_contains($haus['text'], "\nGarten | Außentemperatur | temperature | "), 'Räume ohne Angabe nennen weiter jeden Fühler');

/* --- der Raum genügt allein ------------------------------------------------------------------------ */

// Ohne die Zeilen „Temperatur“, „Thermostat“ und „Luftfeuchte“ steht #26722 bzw. #56003 in keiner Gerätezeile mehr
$ohneZeilen = array_values(array_filter($geraete, static fn (array $g): bool => !($g['Room'] === 'Keller' && in_array($g['Name'], ['Temperatur', 'Thermostat', 'Luftfeuchte'], true))));
$r = frage(['room' => 'Keller', 'type' => 'temperature'], raeume(), $ohneZeilen);
pruefe(!$r['isError'] && $r['text'] === "1 device(s) found (room=Keller, type=temperature).\nKeller | Temperature | temperature | 19.3 °C\n"
    . '15 further temperature value(s) there are not the room temperature; ask for them with name: ' . $kellerNamen . '.', 'ohne Gerätezeile antwortet der Raum selbst: ' . $r['text']);
$r = frage(['room' => 'Keller', 'type' => 'humidity'], raeume(), $ohneZeilen, 'get', ['Humidity' => 'Luftfeuchte', '%d device(s) found (%s).' => '%d Gerät(e) gefunden (%s).']);
pruefe($r['text'] === "1 Gerät(e) gefunden (room=Keller, type=humidity).\nKeller | Luftfeuchte | humidity | 61 %", 'ebenso die Luftfeuchte, mit übersetztem Namen: ' . $r['text']);
$ohneRaumklima = frage(['room' => 'Keller'], raeume([]), $ohneZeilen);
$r = frage(['room' => 'Keller'], raeume(), $ohneZeilen);
pruefe(count($zeilen($r)) === count($zeilen($ohneRaumklima)) + 2 && str_contains($r['text'], "\nKeller | Temperature | temperature | 19.3 °C") && str_contains($r['text'], "\nKeller | Humidity | humidity | 61 %"), 'das Klima des Raums gehört auch zur Abfrage des ganzen Raums: ' . $r['text']);
$r = frage(['room' => 'Keller', 'name' => 'Temperature'], raeume(), $ohneZeilen);
pruefe(str_starts_with($r['text'], '1 device(s) found') && str_contains($r['text'], 'Keller | Temperature | temperature | 19.3 °C'), 'und lässt sich beim Namen nennen: ' . $r['text']);
// Steht die Variable schon in einer Zeile, entsteht keine zweite
pruefe(substr_count(frage(['room' => 'Keller'], raeume())['text'], '19.3 °C') === 2, 'mit Gerätezeile keine zusätzliche: Raumfühler und Thermostat, sonst nichts');

/* --- Angaben, die nicht wirken ---------------------------------------------------------------------- */

foreach ([0, 1] as $leer) {
    $r = frage(['room' => 'Keller', 'type' => 'temperature'], raeume(['Keller' => [$leer, $leer]]));
    pruefe(str_starts_with($r['text'], '17 device(s) found'), 'die ID ' . $leer . ' heißt „nicht gesetzt“: ' . strtok($r['text'], "\n"));
}
// Ein Gerät in einem Raum, der nicht in der Raumliste steht, bleibt wie es ist
$fremd = array_map(static fn (array $g): array => $g['Room'] === 'Keller' ? ['Room' => 'Werkstatt'] + $g : $g, $geraete);
$r = frage(['room' => 'Werkstatt', 'type' => 'temperature'], raeume(), $fremd);
pruefe(str_starts_with($r['text'], '17 device(s) found (room=Werkstatt'), 'ein Raum außerhalb der Raumliste hat keine Angabe: ' . strtok($r['text'], "\n"));

/* --- Karten wie get_state ---------------------------------------------------------------------------- */

$r = frage(['room' => 'Keller', 'type' => 'temperature'], raeume(), null, 'cards');
pruefe(!$r['isError'] && count($r['structuredContent']['cards'] ?? []) === 2 && str_contains($r['text'], '15 further temperature value(s)'), 'show_devices zeigt dieselben zwei als Karten: ' . json_encode(array_column($r['structuredContent']['cards'] ?? [], 'name'), JSON_UNESCAPED_UNICODE));

/* --- Raumliste und Selbsttest ---------------------------------------------------------------------- */

$liste = new RoomIndex(raeume());
$klima = static function (RoomIndex $liste, string $raum): mixed {
    try {
        return $liste->climateOf($raum);
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage();
    }
};
pruefe($klima($liste, ' keller ') === ['temperature' => 26722, 'humidity' => 56003], 'die Raumliste nennt das Klima eines Raums, ohne Rücksicht auf die Schreibweise: ' . json_encode($klima($liste, ' keller ')));
pruefe($klima($liste, 'Bad') === ['temperature' => 0, 'humidity' => 0] && $klima($liste, 'Werkstatt') === ['temperature' => 0, 'humidity' => 0], 'ohne Angabe und außerhalb der Liste 0');
$gedraengeMit = static function (array $zeilen, RoomIndex $liste): mixed {
    try {
        return DeviceIndex::crowdedClimate($zeilen, $liste);
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage();
    }
};
pruefe($gedraengeMit($geraete, $liste) === [], 'mit Angabe am Raum fällt der Keller im Selbsttest nicht mehr auf: ' . json_encode($gedraengeMit($geraete, $liste), JSON_UNESCAPED_UNICODE));
pruefe($gedraengeMit($geraete, new RoomIndex(raeume(['Keller' => [0, 56003]]))) === [['room' => 'Keller', 'type' => 'temperature', 'count' => 16]], 'nur die Luftfeuchte anzugeben hilft der Temperatur nicht');

/* --- Verlauf: zu viele Fühler in einem genannten Raum ------------------------------------------------ */

// Code-Review 13.11.2026, Befund 5: Die Absage aus build 286 riet auch bei genanntem Raum „Make one call per room“. Das
// Modell hätte denselben Aufruf wiederholt. Mit Raum nennt sie die Geräte beim Namen, wie die Schlusszeile des
// Raumklimas; ohne Raum (auch mit Etage) bleibt der Satz aus build 286. Der Keller ohne Angabe hat 16 Fühler (Raumfühler
// und Thermostat lesen dieselbe Variable).
$verlaufNamen = 'Temperatur, Warmwasserspeicher, Vorlauf, Rücklauf, Vorlauf-Soll, Temperatur Kühlschrank, Temperatur Kühltruhe, Temperatur Weinkühlschrank, '
    . 'Strang 1 Vorlauf, Strang 1 Rücklauf, Strang 2 Vorlauf, Strang 2 Rücklauf, Strang 3 Vorlauf, Strang 3 Rücklauf, Warmwasser Vorlauf, Warmwasser Rücklauf';
$r = frage(['room' => 'Keller', 'type' => 'temperature', 'period' => 'last_week'], raeume([]));
pruefe($r['isError'] && $r['text'] === 'Too many measured values (16) for a history (room=Keller, type=temperature, period=last_week), at most 5 per call. Ask for each device on its own with name: ' . $verlaufNamen . '.',
    'mit Raum nennt die Absage die Geräte, nicht „je Raum“: ' . $r['text']);
$r = frage(['room' => 'Keller', 'type' => 'temperature', 'period' => 'last_week'], raeume([]), null, 'get', ['Too many measured values (%d) for a history (%s), at most %d per call. Ask for each device on its own with name: %s.' => 'Zu viele Messwerte (%d) für einen Verlauf (%s), höchstens %d je Aufruf. Frag jedes Gerät einzeln mit name ab: %s.']);
pruefe($r['text'] === 'Zu viele Messwerte (16) für einen Verlauf (room=Keller, type=temperature, period=last_week), höchstens 5 je Aufruf. Frag jedes Gerät einzeln mit name ab: ' . $verlaufNamen . '.', 'übersetzbar: ' . $r['text']);
$r = frage(['floor' => 'Keller', 'type' => 'temperature', 'period' => 'last_week'], raeume([]));
pruefe($r['isError'] && str_ends_with($r['text'], 'room takes a single room, not a list. Make one call per room or give one device in name.'), 'mit Etage ohne Raum bleibt „je Raum“ ein Weg: ' . $r['text']);
// Der genannte Weg führt zum Ziel
$r = frage(['room' => 'Keller', 'name' => 'Strang 2 Vorlauf', 'period' => 'last_week'], raeume([]));
pruefe(!$r['isError'] && str_starts_with($r['text'], 'History of 1 measured value(s) (room=Keller, name=Strang 2 Vorlauf, period=last_week)'), 'ein Name aus der Absage liefert den Verlauf: ' . strtok($r['text'], "\n"));

ende('Raumklima');
