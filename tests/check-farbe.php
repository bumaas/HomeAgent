<?php

declare(strict_types=1);

/*
 * Farbe beim Licht (Entscheidung Burkhard, 10.10.2026: Farbname aus einer festen Liste wie bei Home
 * Assistant). Fähigkeit „color“, Feld „color“ von set_state. Zuerst nur Variablen mit einer Ganzzahl
 * (RGB 0xRRGGBB, laut Doku bei Integer fest); Farbe als Text bleibt außen vor, bis das Textformat
 * belegt ist (Doku-Feedback Nr. 15). Ein ausgeschaltetes Licht mit eigenem Schalter wird eingeschaltet.
 * get_state nennt bei eingeschaltetem Licht den nächstliegenden Farbnamen. Mitschnitt mix_2026-10-10_farbe.json.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('mix_2026-10-10_farbe.json');
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

pruefe((DeviceIndex::ROLES['color'] ?? '') === 'ColorVariableID', 'Fähigkeit color mit dem Schlüssel ColorVariableID');
pruefe(in_array('color', DeviceIndex::abilitiesOf($liste[0]), true) && in_array('color', DeviceIndex::offeredAbilities($liste), true), 'ein Licht mit Farbe versteht color');
pruefe(array_keys(defined(DeviceIndex::class . '::COLORS') ? constant(DeviceIndex::class . '::COLORS') : []) === ['red', 'orange', 'yellow', 'green', 'turquoise', 'blue', 'purple', 'pink', 'white'], 'feste Liste der Farbnamen');

/* --- Stellen -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Leiste', 'color' => 'blue']);
pruefe(!$r['isError'] && $gesendet(54728) === [0x0000FF] && $zeile($r, 0) === '1 of 1 devices set to blue (name=Leiste).', 'Blau als 0x0000FF an die Leiste: ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'WLED', 'color' => 'orange']);
pruefe($gesendet(41119) === [0xFF8000] && $gesendet(37242) === [true], 'Orange an das WLED-Segment, das ausgeschaltete Licht wird eingeschaltet: ' . json_encode($anlage->requests));
pruefe($zeile($r, 0) === '0 of 1 devices set to orange, 1 not confirmed yet (name=WLED).', 'der stromlose Streifen bestätigt nicht (Mitschnitt): ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Panel', 'color' => 'red']);
pruefe($gesendet(48366) === [0xFF0000] && str_contains($r['text'], '1 not confirmed yet'), 'das weiße Hue-Panel bestätigt keine Farbe (Mitschnitt): ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Esstisch', 'color' => 'red']);
pruefe($r['isError'] && $gesendet(11687) === [] && str_contains($r['text'], 'not set: colour as text is not supported yet'), 'Farbe als Text geht nicht hinaus: ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Leiste', 'color' => 'Blau']);
pruefe(!$r['isError'] && $gesendet(54728) === [0x0000FF], 'Groß- und Kleinschreibung zählen nicht, deutsche Namen gehen auch: ' . $r['text']);

/* --- Absagen -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Leiste', 'color' => 'magenta']);
pruefe($r['isError'] && $r['text'] === 'Unknown colour "magenta". Allowed: red, orange, yellow, green, turquoise, blue, purple, pink, white.' && $anlage->requests === [], 'unbekannte Farbe: ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Leiste', 'color' => 'blue', 'percent' => 50]);
pruefe($r['isError'] && $r['text'] === 'Nothing was changed. Give color alone, without command, percent, temperature or color_temperature.' && $anlage->requests === [], 'nicht zusammen mit anderen Feldern: ' . $r['text']);

/* --- Abfrage -------------------------------------------------------------------------------------- */

$an = $werte;
$an['37242']['wert'] = true;
$r = rufe($an, 'get', ['name' => 'WLED']);
pruefe($zeile($r, 1) === 'Wohnzimmer | WLED | light | on (100 %, red)', '0x7D0000 ist ein dunkles Rot: ' . $zeile($r, 1));
$r = rufe($an, 'get', ['name' => 'WLED'], $de);
pruefe($zeile($r, 1) === 'Wohnzimmer | WLED | light | an (100 %, rot)' && $anlage->missing === [], 'deutsch: ' . $zeile($r, 1) . ' fehlend: ' . json_encode($anlage->missing));
$r = rufe($werte, 'get', ['name' => 'WLED']);
pruefe($zeile($r, 1) === 'Wohnzimmer | WLED | light | off', 'ausgeschaltet ohne Farbe: ' . $zeile($r, 1));

/* --- Übersetzung ---------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Leiste', 'color' => 'blue'], $de);
pruefe($zeile($r, 0) === '1 von 1 Geräten auf blau gestellt (name=Leiste).' && $anlage->missing === [], 'deutsch: ' . $r['text'] . ' fehlend: ' . json_encode($anlage->missing));

ende('Farbe');
