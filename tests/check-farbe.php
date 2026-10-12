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

/* --- Licht ohne Schaltzustand: an, wenn die Farbe nicht schwarz ist (Probe am nuc, build 58) ------ */

// Nach color=blue stand der Weißkanal weiter auf 0 und die Farbe auf 255; die Zeile sagte trotzdem „aus“.
$blau = $werte;
$blau['54728']['wert'] = 0x0000FF;
$r = rufe($blau, 'get', ['name' => 'Leiste']);
pruefe($zeile($r, 1) === 'Wohnzimmer | Leiste | light | on (blue)', 'die blaue Leiste ist an: ' . $zeile($r, 1));
$r = rufe($blau, 'get', ['type' => 'light', 'active_only' => true]);
pruefe(str_starts_with($r['text'], '1 of 4') && str_contains($r['text'], 'Leiste'), 'active_only zählt sie mit: ' . $r['text']);
$r = rufe($werte, 'set', ['name' => 'Leiste', 'color' => 'blue']);
pruefe($zeile($r, 1) === 'Wohnzimmer | Leiste | light | on (blue)', 'die Antwort auf das Stellen sagt „an (blau)“: ' . $zeile($r, 1));
$r = rufe($werte, 'get', ['name' => 'Leiste']);
pruefe($zeile($r, 1) === 'Wohnzimmer | Leiste | light | off', 'schwarz und Weißkanal 0 bleibt aus: ' . $zeile($r, 1));

// „aus“ bei einem Licht ohne Schalter setzt auch die Farbe auf Schwarz, sonst leuchtete die Leiste weiter.
$r = rufe($blau, 'set', ['name' => 'Leiste', 'command' => 'off']);
pruefe($gesendet(54728) === [0] && !$r['isError'] && $zeile($r, 1) === 'Wohnzimmer | Leiste | light | off', 'off schaltet die Farbe ab: ' . json_encode($anlage->requests) . ' ' . $r['text']);
// Am nuc (build 59) stand die Farbe nach „aus“ wieder auf 255: Der Weißbefehl schrieb den alten Farbwert
// zurück. Die Farbe geht deshalb erst hinaus, wenn der Weißkanal bestätigt ist, und ohne Weißbefehl,
// wenn er schon auf 0 steht.
$r = rufe($blau, 'set', ['name' => 'Leiste', 'command' => 'off']);
pruefe($anlage->read(54728)['value'] === 0 && $gesendet(19752) === [], 'Weißkanal schon 0: nur die Farbe, und sie bleibt schwarz: ' . json_encode($anlage->requests));
$hell = $blau;
$hell['19752']['wert'] = 128;
$r = rufe($hell, 'set', ['name' => 'Leiste', 'command' => 'off']);
pruefe($anlage->read(54728)['value'] === 0 && $anlage->read(19752)['value'] === 0 && array_column($anlage->requests, 0) === [19752, 54728], 'erst Weiß, dann Farbe, am Ende beides aus: ' . json_encode($anlage->requests) . ' ' . $r['text']);
pruefe($zeile($r, 1) === 'Wohnzimmer | Leiste | light | off', 'die Zeile sagt aus: ' . $zeile($r, 1));
$r = rufe($werte, 'set', ['name' => 'Leiste', 'command' => 'off']);
pruefe($gesendet(54728) === [], 'ist die Farbe schon schwarz, geht dafür nichts hinaus');

// Code-Review 12.10.2026 (Befund 6): Hat die Leiste Weißkanal und Farbe, meldete „aus“ schon „aus“, sobald der
// Weißkanal 0 war, auch wenn die Farbe nie schwarz wurde. Angenommener Fall: Die Farbe bestätigt nicht.
$stur = $hell;
$stur['54728']['verhalten'] = 'stumm';
$r = rufe($stur, 'set', ['name' => 'Leiste', 'command' => 'off']);
pruefe(str_contains($zeile($r, 1), 'has not confirmed yet') && !str_starts_with($zeile($r, 0), '1 of 1'), 'Farbe nicht schwarz: noch nicht bestätigt, nicht „aus“: ' . $r['text']);
// Befund 2: Ließ sich der Farbbefehl gar nicht auslösen, ging der Fehler verloren. Angenommener Fall, Fehlertext
// wie in SymconDeviceBackend::runAction.
$gesperrt = $hell;
$gesperrt['54728']['verhalten'] = 'nichtAusgeloest';
$gesperrt['54728']['fehlertext'] = 'IPS_RunScriptTextEx returned false';
$r = rufe($gesperrt, 'set', ['name' => 'Leiste', 'command' => 'off']);
pruefe(str_contains($zeile($r, 1), 'failed: IPS_RunScriptTextEx returned false'), 'nicht ausgelöster Farbbefehl: die Zeile sagt fehlgeschlagen: ' . $r['text']);
$dunkel = $blau;
$dunkel['54728']['verhalten'] = 'nichtAusgeloest';
$dunkel['54728']['fehlertext'] = 'IPS_RunScriptTextEx returned false';
$r = rufe($dunkel, 'set', ['name' => 'Leiste', 'command' => 'off']);
pruefe(str_contains($zeile($r, 1), 'failed: IPS_RunScriptTextEx returned false'), 'ebenso, wenn der Weißkanal schon 0 war: ' . $r['text']);

/* --- Übersetzung ---------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', ['name' => 'Leiste', 'color' => 'blue'], $de);
pruefe($zeile($r, 0) === '1 von 1 Geräten auf blau gestellt (name=Leiste).' && $anlage->missing === [], 'deutsch: ' . $r['text'] . ' fehlend: ' . json_encode($anlage->missing));

ende('Farbe');
