<?php

declare(strict_types=1);

/*
 * Farbtemperatur beim Licht (Entscheidung Burkhard, 10.10.2026: Kelvin wie bei Home Assistant, erst
 * die Farbtemperatur, die Farbe folgt). Fähigkeit „colortemp“, Feld „color_temperature“ von set_state.
 * Werte in Mired (Hue Aurelle über Matter, 153 bis 454) rechnet das Modul um; ein Bereich mit einem
 * Höchstwert bis 1000 gilt als Mired. Werte außerhalb des Bereichs gehen auf das nächste Ende, ein
 * ausgeschaltetes Licht wird eingeschaltet wie beim Stellen der Helligkeit. get_state nennt bei
 * eingeschaltetem Licht die Farbtemperatur in Kelvin. Mitschnitt mix_2026-10-10_farbtemperatur.json.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once dirname(__DIR__) . '/libs/RoomIndex.php';
require_once dirname(__DIR__) . '/libs/AlexaImport.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('mix_2026-10-10_farbtemperatur.json');
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

/* --- Fähigkeit und Angebot ------------------------------------------------------------------------ */

pruefe((DeviceIndex::ROLES['colortemp'] ?? '') === 'ColorTempVariableID', 'Fähigkeit colortemp mit dem Schlüssel ColorTempVariableID');
pruefe((DeviceIndex::rolesOf($liste[0])['colortemp'] ?? 0) === 53845, 'ein Licht liest seine Farbtemperatur-Variable');
pruefe(in_array('color_temperature', DeviceIndex::abilitiesOf($liste[0]), true), 'ein Licht mit Farbtemperatur versteht color_temperature: ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));
$ohne = $liste[0];
unset($ohne['ColorTempVariableID']);
pruefe(!in_array('color_temperature', DeviceIndex::abilitiesOf($ohne), true), 'ohne Farbtemperatur nicht');
pruefe(in_array('color_temperature', DeviceIndex::offeredAbilities($liste), true) && !in_array('color_temperature', DeviceIndex::offeredAbilities([$ohne]), true), 'angeboten wird es nur, wenn ein Licht es kann');

/* --- Stellen in Mired (Hue Aurelle) --------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Fensterleuchte', 'color_temperature' => 4000]);
pruefe(!$r['isError'] && $zeile($r, 0) === '1 of 1 devices set to 4000 K (name=Fensterleuchte).', 'Kopfzeile in Kelvin: ' . $r['text']);
pruefe($gesendet(53845) === [250], '4000 K sind 250 Mired: ' . json_encode($gesendet(53845)));
pruefe($gesendet(20946) === [true], 'das ausgeschaltete Licht wird eingeschaltet: ' . json_encode($gesendet(20946)));
pruefe($zeile($r, 1) === 'Küche | Fensterleuchte | light | on (100 %, 4000 K)', 'Zeile mit Stufe und Farbtemperatur: ' . $zeile($r, 1));

$r = rufe($werte, 'set', ['name' => 'Fensterleuchte', 'color_temperature' => 2700]);
// Die Schrittweite 5 des Schiebereglers ist nur dessen Raster: Die Hue nahm 250 Mired direkt an (Messung).
pruefe($gesendet(53845) === [370], '2700 K sind 370,4 Mired, auf ganze Mired gerundet 370: ' . json_encode($gesendet(53845)));
pruefe($zeile($r, 0) === '1 of 1 devices set to 2700 K (name=Fensterleuchte).', 'die Kopfzeile nennt den gesendeten Wert, auf 10 K gerundet: ' . $zeile($r, 0));

$r = rufe($werte, 'set', ['name' => 'Fensterleuchte', 'color_temperature' => 9000]);
pruefe($gesendet(53845) === [153] && $zeile($r, 0) === '1 of 1 devices set to 6540 K (name=Fensterleuchte).', 'zu kalt geht auf das kälteste Ende (153 Mired): ' . $r['text']);

/* --- Stellen in Kelvin (Deckenpanel über das HomeAssistant-Modul) --------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Deckenpanel', 'color_temperature' => 3000]);
pruefe($gesendet(53701) === [3000] && $gesendet(33255) === [true] && $zeile($r, 0) === '1 of 1 devices set to 3000 K (name=Deckenpanel).', 'Kelvin geht unverändert hinaus: ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Deckenpanel', 'color_temperature' => 6500]);
pruefe($gesendet(53701) === [4000] && $zeile($r, 0) === '1 of 1 devices set to 4000 K (name=Deckenpanel).', 'zu kalt geht auf 4000 K: ' . $r['text']);
$r = rufe($werte, 'set', ['room' => 'Küche', 'color_temperature' => 3500]);
pruefe($gesendet(53701) === [3500] && $gesendet(53845) === [286] && $zeile($r, 0) === '2 of 2 devices set to 3500 K (room=Küche).', 'beide Lichter des Raums, je in ihrer Einheit: ' . $r['text']);

/* --- Abfrage -------------------------------------------------------------------------------------- */

$an = $werte;
$an['20946']['wert'] = true;
$r = rufe($an, 'get', ['name' => 'Fensterleuchte']);
pruefe($zeile($r, 1) === 'Küche | Fensterleuchte | light | on (100 %, 2700 K)', 'eingeschaltet mit Farbtemperatur (370 Mired ≈ 2700 K): ' . $zeile($r, 1));
$r = rufe($werte, 'get', ['name' => 'Fensterleuchte']);
pruefe($zeile($r, 1) === 'Küche | Fensterleuchte | light | off', 'ausgeschaltet ohne Farbtemperatur: ' . $zeile($r, 1));

/* --- Absagen -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Fensterleuchte', 'color_temperature' => 'warm']);
pruefe($r['isError'] && $r['text'] === 'Parameter "color_temperature" must be a number in Kelvin from 1000 to 20000.' && $anlage->requests === [], 'kein Text: ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Fensterleuchte', 'color_temperature' => 300]);
pruefe($r['isError'] && $anlage->requests === [], 'Mired statt Kelvin wird abgewiesen: ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Fensterleuchte', 'color_temperature' => 3000, 'percent' => 50]);
pruefe($r['isError'] && $r['text'] === 'Nothing was changed. Give color_temperature alone, without command, percent or temperature.' && $anlage->requests === [], 'nicht zusammen mit anderen Feldern: ' . $r['text']);

/* --- Übersetzung ---------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Fensterleuchte', 'color_temperature' => 4000], $de);
pruefe($zeile($r, 0) === '1 von 1 Geräten auf 4000 K gestellt (name=Fensterleuchte).' && $anlage->missing === [], 'deutsch: ' . $r['text'] . ' fehlend: ' . json_encode($anlage->missing));

/* --- Alexa-Import: „Licht Experte“ bringt die Farbtemperatur mit ---------------------------------- */

$alexa = fixture('nuc_2026-10-10_alexa.json');
$objekte = $alexa['objekte'];
$info = static fn (int $id): ?array => isset($objekte[$id]) ? ['parent' => (int)$objekte[$id]['eltern'], 'path' => (string)$objekte[$id]['pfad']] : null;
$zeilen = AlexaImport::propose($alexa['alexa'], $info, [], new RoomIndex($alexa['raeume']), static fn (string $t): string => $t)['rows'];
$panel = array_values(array_filter($zeilen, static fn (array $z): bool => ($z['StateVariableID'] ?? 0) === 33255))[0] ?? [];
pruefe(($panel['ColorTempVariableID'] ?? 0) === 53701, 'Fensterlicht Küche aus Alexa mit Farbtemperatur 53701: ' . json_encode($panel, JSON_UNESCAPED_UNICODE));

ende('Farbtemperatur');
