<?php

declare(strict_types=1);

/*
 * Raumvorschlag beim Alexa-Import ohne passende Raumliste. Anlass: Auf einer betreuten Anlage (Symcon 9.0,
 * 10.10.2026) blieben alle 15 Räume leer, weil die Anlage keine Raumliste hatte; der Raum steckt dort
 * im Alexa-Namen („Küche links“) und im Objektbaum (Hardware\Shelly\EG\Küche\…).
 * Regel: Zuerst die Kategorien im eigenen Pfad der Variable (ohne Instanz und Variable), von der
 * Wurzel her; die erste, die ein Wort mit dem Alexa-Namen teilt, wird der Raum. Kategorien aus
 * höchstens zwei Buchstaben (EG, OG, DG) gelten als Etage und zählen nicht. Sonst ein Wort des Namens,
 * das irgendwo im Import als eigene Kategorie vorkommt. Wörter des Raums fallen aus dem Namen.
 * Mitschnitte: betreute Anlage (die Vornamen der Bewohner anonymisiert) und nuc #10110,
 * beide ohne Raumliste ausgewertet.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once dirname(__DIR__) . '/libs/RoomIndex.php';
require_once dirname(__DIR__) . '/libs/AlexaImport.php';

function vorschlagOhneRaeume(string $datei): array
{
    $mitschnitt = fixture($datei);
    $objekte = $mitschnitt['objekte'];
    $info = static fn (int $id): ?array => isset($objekte[$id]) ? ['parent' => (int)$objekte[$id]['eltern'], 'path' => (string)$objekte[$id]['pfad'], 'profile' => (string)($objekte[$id]['profil'] ?? '')] : null;
    return AlexaImport::propose($mitschnitt['alexa'], $info, [], new RoomIndex([]), static fn (string $t): string => $t)['rows'];
}

/** Räume, die der Vorschlag für die Raumliste mitbringt */
function raeumeOhneListe(string $datei): array
{
    $mitschnitt = fixture($datei);
    $objekte = $mitschnitt['objekte'];
    $info = static fn (int $id): ?array => isset($objekte[$id]) ? ['parent' => (int)$objekte[$id]['eltern'], 'path' => (string)$objekte[$id]['pfad']] : null;
    return AlexaImport::propose($mitschnitt['alexa'], $info, [], new RoomIndex([]), static fn (string $t): string => $t)['rooms'] ?? [];
}

/** Zeile zum Alexa-Namen: der steht als Alias, wenn der Name gekürzt wurde, sonst als Name. */
function zu(array $zeilen, string $alexaName): ?array
{
    foreach ($zeilen as $z) {
        if ($z['Aliases'] === $alexaName || ($z['Aliases'] === '' && $z['Name'] === $alexaName)) {
            return $z;
        }
    }
    return null;
}

$erwarte = static function (array $zeilen, string $alexaName, string $raum, string $name): void {
    $z = zu($zeilen, $alexaName);
    pruefe($z !== null && $z['Room'] === $raum && $z['Name'] === $name, $alexaName . ' → Raum „' . $raum . '“, Name „' . $name . '“: ' . json_encode($z === null ? null : [$z['Room'], $z['Name']], JSON_UNESCAPED_UNICODE));
};

/* --- Betreute Anlage: Raum aus dem eigenen Pfad, sonst aus den Kategorien anderer Geräte ------------------ */

$n = vorschlagOhneRaeume('betreut_2026-10-10_alexa.json');
pruefe(count($n) === 15, '15 Vorschläge: ' . count($n));
$erwarte($n, 'Küche links', 'Küche', 'links');
$erwarte($n, 'Küche rechts', 'Küche', 'rechts');
$erwarte($n, 'Schlafzimmer Deckenlicht', 'Schlafzimmer', 'Deckenlicht');
$erwarte($n, 'Schlafzimmer links', 'Schlafzimmer', 'links');
$erwarte($n, 'Wohnzimmer Deckenlicht', 'Wohnzimmer', 'Deckenlicht');
// Die Flurlichter hängen unter EG, OG und DG: je Etage ein Raum (Burkhard, 10.10.2026).
$erwarte($n, 'Flur Erdgeschoss', 'Flur EG', 'Erdgeschoss');
$erwarte($n, 'Flur Obergeschoss', 'Flur OG', 'Obergeschoss');
// Pfad Hardware\Zigbee\EG\Küche Deckenlicht: „EG“ zählt nicht, „Küche“ kommt bei anderen Geräten als Kategorie vor.
$erwarte($n, 'Küche Deckenlicht', 'Küche', 'Deckenlicht');
// Pfad Hardware\Shelly\DG\Flur_DG_…: die Kategorie „Flur“ der anderen Flurlichter.
$erwarte($n, 'Flur Dachgeschoss', 'Flur DG', 'Dachgeschoss');
// Ein Wort, das ganz der Raum ist, bleibt auch der Name.
$erwarte($n, 'Wohnzimmer', 'Wohnzimmer', 'Wohnzimmer');
$erwarte($n, 'Garten', 'Garten', 'Garten');
// Kein Wort des Namens im Pfad oder als Kategorie: kein Raum.
$erwarte($n, 'Esszimmer', '', 'Esszimmer');
$erwarte($n, 'Freisitz', '', 'Freisitz');
$erwarte($n, 'Terasse', '', 'Terasse');
pruefe((zu($n, 'Küche links')['Aliases'] ?? '') === 'Küche links', 'der Alexa-Name bleibt Alias');

/* --- Neue Räume für die Raumliste: Etage aus der Kategorie EG/OG/DG im Pfad ------------------------- */

$neueRaeume = raeumeOhneListe('betreut_2026-10-10_alexa.json');
$sortiert = static function (array $raeume): array {
    usort($raeume, static fn (array $a, array $b): int => strcmp($a['Name'], $b['Name']));
    return $raeume;
};
pruefe($sortiert($neueRaeume) === [
    ['Name' => 'Flur DG', 'Aliases' => 'Flur', 'Floor' => 'DG'],
    ['Name' => 'Flur EG', 'Aliases' => 'Flur', 'Floor' => 'EG'],
    ['Name' => 'Flur OG', 'Aliases' => 'Flur', 'Floor' => 'OG'],
    ['Name' => 'Garten', 'Aliases' => '', 'Floor' => ''],
    ['Name' => 'Küche', 'Aliases' => '', 'Floor' => 'EG'],
    ['Name' => 'Schlafzimmer', 'Aliases' => '', 'Floor' => 'OG'],
    ['Name' => 'Wohnzimmer', 'Aliases' => '', 'Floor' => 'EG'],
], 'neue Räume mit Etage, der Flur geteilt: ' . json_encode($neueRaeume, JSON_UNESCAPED_UNICODE));
pruefe(raeumeOhneListe('nuc_2026-10-10_alexa.json') !== [] && array_filter(raeumeOhneListe('nuc_2026-10-10_alexa.json'), static fn (array $r): bool => $r['Floor'] !== '') === [], 'am nuc gibt es keine Etagen-Kategorien, also keine Etage');

/* --- nuc ohne Raumliste ------------------------------------------------------------------------- */

$k = vorschlagOhneRaeume('nuc_2026-10-10_alexa.json');
$erwarte($k, 'Deckenlicht Bad', 'Bad', 'Deckenlicht');
// Pfad Hardware\Homematic\Bad\Licht\…: die Kategorie nahe der Wurzel gewinnt, nicht „Licht“.
$erwarte($k, 'Nischen Licht Bad', 'Bad', 'Nischen Licht');
$erwarte($k, 'Rollladen Wohnzimmer Blumenfenster', 'Wohnzimmer', 'Rollladen Blumenfenster');
// Pfad Program\WOL\WOL\Wintergarten PC: „Wintergarten“ ist anderswo Kategorie.
$erwarte($k, 'PC Wintergarten', 'Wintergarten', 'PC');
// Kategorie DG_Küche wird der Raum „DG Küche“; Thermostat und Sensor landen dort und werden eins.
$erwarte($k, 'Thermostat DG Küche', 'DG Küche', 'Thermostat');
pruefe((zu($k, 'Thermostat DG Küche')['StateVariableID'] ?? 0) === 55817, 'DG Küche: der Sensor wird die Ist-Temperatur');
$erwarte($k, 'Thermostat DG Atelier', 'DG Atelier', 'Thermostat');
// Pfad Hardware\Matter\Gast: „Gästezimmer“ ist ein anderes Wort, kein Raum.
$erwarte($k, 'Deckenlicht Gästezimmer', '', 'Deckenlicht Gästezimmer');
$erwarte($k, 'Sparmodus Waschmaschine', '', 'Sparmodus Waschmaschine');

ende('Raumvorschlag');
