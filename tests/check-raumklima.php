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

ende('Raumklima');
