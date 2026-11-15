<?php

declare(strict_types=1);

/*
 * Klimagerät mit eigener Ein-Aus-Variable (Backlog B116, 15.11.2026). Das Modul Panasonic ComfortCloud führt an/aus in
 * „Betrieb“ (Wahrheitswert mit Aktion) und die Betriebsart ohne „Aus“; sie behält die zuletzt gewählte Art. Fünf
 * ausgeschaltete Geräte erschienen deshalb als „Heizen“ bzw. „Kühlen“, und active_only kannte bei ihnen kein an/aus.
 *
 * Mitschnitt betreut_2026-11-15_klima_ein_aus.json (nur gelesen, nichts geschaltet). ECHT sind Werte, formatierte Texte,
 * Aktion ja/nein, Archivstatus und der Verlauf von „Betrieb“ zweier Geräte. ANGENOMMEN ist, was unten so heißt: die
 * Zeilen der Freigabeliste, ein eingeschaltetes Gerät, die Rückmeldung von „Betrieb“ nach dem Schalten (sofort), eine
 * Aktion an Sollwert und Betriebsart (im Mitschnitt hat nur „Betrieb“ eine) und die Einträge der Betriebsart (die Profile
 * sind nicht mitgeschnitten, echt sind nur 2 = „Kühlen“ und 3 = „Heizen“).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once dirname(__DIR__) . '/libs/RoomIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('betreut_2026-11-15_klima_ein_aus.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

// Büro: Betrieb #49255, Betriebsart #42300, Sollwert #22372
const BETRIEB = 49255;
const BETRIEBSART = 42300;
const SOLLWERT = 22372;

// ANGENOMMEN: das Büro ist eingeschaltet
$an = $werte;
$an[BETRIEB]['wert'] = true;
$an[BETRIEB]['formatiert'] = 'An';
// ANGENOMMEN: Sollwert und Betriebsart lassen sich stellen
$stellbar = static function (array $werte): array {
    $werte[SOLLWERT]['aktion'] = true;
    $werte[SOLLWERT]['sollbereich'] = ['min' => 16, 'max' => 30, 'schritt' => 0.5];
    $werte[BETRIEBSART]['aktion'] = true;
    $werte[BETRIEBSART]['optionen'] = [[2, 'Kühlen'], [3, 'Heizen']];
    unset($werte[BETRIEBSART]['formatiert']);
    return $werte;
};

/** @var FakeBackend */
$anlage = null;

function rufe(string $werkzeug, array $argumente, array $werte, ?array $zeilen = null, ?array $uebersetzungen = null): array
{
    global $anlage, $liste, $mitschnitt;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($zeilen ?? $liste, $anlage);
        return match ($werkzeug) {
            'get'    => $index->getState($argumente),
            'set'    => $index->setState($argumente),
            'chart'  => $index->chart($argumente),
            'karten' => $index->cards($argumente, false),
            'nurLesen' => $index->cards($argumente, true),
        };
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

$zeilen = static fn (array $r): array => explode("\n", $r['text']);
$hat = static fn (array $r, string $z): bool => in_array($z, explode("\n", $r['text']), true);
$gesendet = static function (): array {
    global $anlage;
    return $anlage->requests;
};
$karte = static fn (array $r): array => $r['structuredContent']['cards'][0] ?? [];

/* --- Zustand ------------------------------------------------------------------------------------- */

$r = rufe('get', ['type' => 'thermostat'], $werte);
pruefe($hat($r, 'Büro | Klimaanlage | thermostat | off (setpoint 24 °C, currently 17.0 °C)'), 'ausgeschaltet: „off“ mit Sollwert und Ist-Temperatur: ' . $r['text']);
pruefe(!str_contains($r['text'], 'Heizen') && !str_contains($r['text'], 'Kühlen') && !str_contains($r['text'], 'fan '), 'ausgeschaltet: weder Betriebsart noch Lüfter: ' . $r['text']);
pruefe(($zeilen($r)[0] ?? '') === '5 device(s) found (type=thermostat).', 'die erste Zeile zählt weiter alle fünf: ' . ($zeilen($r)[0] ?? ''));
$r = rufe('get', ['type' => 'thermostat'], $werte, null, $de);
pruefe($hat($r, 'Büro | Klimaanlage | thermostat | aus (Soll 24 °C, derzeit 17.0 °C)'), 'auf Deutsch: ' . $r['text']);
// ANGENOMMEN eingeschaltet: die Zeile bleibt, wie sie war
$r = rufe('get', ['room' => 'Büro'], $an);
pruefe($hat($r, 'Büro | Klimaanlage | thermostat | setpoint 24 °C (currently 17.0 °C), Heizen, fan Automatisch'), 'eingeschaltet: Zeile wie bisher: ' . $r['text']);
// Ohne die Variable bleibt alles wie bisher (schon vorher richtig)
$ohne = array_map(static fn (array $z): array => array_diff_key($z, ['SwitchVariableID' => true]), $liste);
$r = rufe('get', ['room' => 'Büro'], $werte, $ohne);
pruefe($hat($r, 'Büro | Klimaanlage | thermostat | setpoint 24 °C (currently 17.0 °C), Heizen, fan Automatisch'), 'ohne Ein-Aus-Variable: Zeile wie bisher: ' . $r['text']);

/* --- active_only --------------------------------------------------------------------------------- */

$r = rufe('get', ['type' => 'thermostat', 'active_only' => true], $werte);
pruefe(!str_contains($r['text'], 'None of them knows on/off') && !str_contains($r['text'], '| Klimaanlage |') && str_starts_with($r['text'], '0 '), 'alle aus: active_only zählt null und listet keins: ' . $r['text']);
$r = rufe('get', ['type' => 'thermostat', 'active_only' => true], $an);
pruefe(str_starts_with($r['text'], '1 ') && $hat($r, 'Büro | Klimaanlage | thermostat | setpoint 24 °C (currently 17.0 °C), Heizen, fan Automatisch') && substr_count($r['text'], '| Klimaanlage |') === 1,
    'eins an: active_only nennt genau das eingeschaltete: ' . $r['text']);

/* --- Zeitleiste an/aus --------------------------------------------------------------------------- */

// Echt: Betrieb im Büro am 11.11.2026 von 11:35:23 bis 11:58:51 an
$tag = ['room' => 'Büro', 'type' => 'thermostat', 'from' => '2026-11-11', 'to' => '2026-11-11'];
$r = rufe('get', $tag, $werte);
pruefe(!$r['isError'] && str_ends_with($zeilen($r)[0] ?? '', '1 on/off timeline(s).'), 'Verlauf: die erste Zeile nennt die Zeitleiste an/aus: ' . ($zeilen($r)[0] ?? ''));
pruefe(str_contains($r['text'], 'Büro | Klimaanlage | thermostat | on/off: on 23 min, off 23 h 37 min') && $hat($r, 'on 11:35-11:58'), 'Verlauf: an 23 Minuten, mit Abschnitt: ' . $r['text']);
$r = rufe('get', $tag, $werte, null, $de);
pruefe(str_contains($r['text'], 'Büro | Klimaanlage | thermostat | Ein/Aus: an 23 min, aus 23 h 37 min'), 'Verlauf auf Deutsch: ' . $r['text']);
$r = rufe('chart', $tag, $werte);
pruefe(!$r['isError'] && in_array('Klimaanlage (on/off)', array_column($r['structuredContent']['bands'] ?? [], 'name'), true), 'Diagramm: Band an/aus: ' . json_encode(array_column($r['structuredContent']['bands'] ?? [], 'name'), JSON_UNESCAPED_UNICODE) . ' ' . $r['text']);
// Code-Review 15.11.2026, Befund 4. ANGENOMMEN: „Betrieb“ im Büro ist nicht archiviert (an der Anlage sind alle fünf
// archiviert). Die erste Zeile zählt dann keine Zeitleiste an/aus, und die Zeile nennt die Variable.
$ohneArchiv = $werte;
$ohneArchiv[BETRIEB]['geloggt'] = false;
unset($ohneArchiv[BETRIEB]['rohwerte']);
$r = rufe('get', $tag, $ohneArchiv);
pruefe(!$r['isError'] && !str_contains($zeilen($r)[0] ?? '', 'on/off timeline'), 'ohne Archiv: die erste Zeile zählt keine Zeitleiste an/aus: ' . ($zeilen($r)[0] ?? ''));
// Nachreview 15.11.2026, Befund 4: Die Variable ohne Archiv steht wie jede Auslassung in einer Schlusszeile, nicht als
// Gerätezeile zwischen den Verläufen.
pruefe(!str_contains($r['text'], '| thermostat | on/off') && $hat($r, '1 on/off variable(s) without archive were left out. Not archived in Symcon: Klimaanlage: on/off #49255.'), 'ohne Archiv: eine Schlusszeile nennt Gerät und Variable: ' . $r['text']);
$r = rufe('get', $tag, $ohneArchiv, null, $de);
pruefe($hat($r, '1 Ein-Aus-Variable(n) ohne Archiv wurden ausgelassen. In Symcon nicht archiviert: Klimaanlage: Ein/Aus #49255.'), 'ohne Archiv auf Deutsch: ' . $r['text']);
// Nachreview 15.11.2026, Befund 3: Ob die Zeitleisten kurz ausfallen, hängt an den archivierten Variablen. Im Mitschnitt
// tragen nur Essen und Büro Rohwerte von „Betrieb“. ANGENOMMEN ist ein sechstes Klimagerät im Büro mit einer eigenen
// Ein-Aus-Variable ohne Archiv: sechs Variablen, zwei Zeitleisten, also mit Abschnitten.
$sechstes = ['Name' => 'Klimaanlage Nord', 'SwitchVariableID' => 69991] + array_values(array_filter($liste, static fn (array $z): bool => $z['Room'] === 'Büro'))[0];
$r = rufe('get', ['type' => 'thermostat', 'from' => '2026-11-11', 'to' => '2026-11-11'], $werte + [69991 => ['name' => 'Büro: Betrieb Nord', 'wert' => false, 'formatiert' => 'Aus', 'aktion' => true, 'geloggt' => false]], array_merge($liste, [$sechstes]));
pruefe(!$r['isError'] && str_ends_with($zeilen($r)[0] ?? '', '2 on/off timeline(s).') && $hat($r, 'on 11:35-11:58'), 'sechs Ein-Aus-Variablen, zwei archiviert: die Zeitleisten kommen mit Abschnitten: ' . implode(' / ', array_filter($zeilen($r), static fn (string $z): bool => str_contains($z, 'on/off') || str_starts_with($z, 'on '))));
pruefe(str_starts_with($zeilen($r)[count($zeilen($r)) - 1] ?? '', '4 on/off variable(s) without archive were left out. Not archived in Symcon: Klimaanlage (Schlafzimmer): on/off #17759, '), 'vier ohne Archiv in einer Schlusszeile, aus mehreren Räumen mit Raum: ' . ($zeilen($r)[count($zeilen($r)) - 1] ?? ''));
// type=temperature fragt nach dem Klima des Raums, nicht nach dem Gerät
$r = rufe('get', ['room' => 'Büro', 'type' => 'temperature', 'from' => '2026-11-11', 'to' => '2026-11-11'], $werte);
pruefe(!str_contains($r['text'], 'on/off'), 'type=temperature: keine Zeitleiste an/aus: ' . $r['text']);

/* --- on und off, ANGENOMMEN: „Betrieb“ meldet sofort zurück --------------------------------------- */

$r = rufe('set', ['room' => 'Büro', 'type' => 'thermostat', 'command' => 'on'], $werte);
pruefe(!$r['isError'] && $gesendet() === [[BETRIEB, true]] && ($zeilen($r)[0] ?? '') === '1 of 1 devices are now on (room=Büro, type=thermostat).', 'on mit Typ schaltet „Betrieb“ ein: ' . $r['text'] . ' ' . json_encode($gesendet()));
$r = rufe('set', ['name' => 'Klimaanlage', 'room' => 'Büro', 'command' => 'on'], $werte);
pruefe(!$r['isError'] && $gesendet() === [[BETRIEB, true]], 'on mit Namen schaltet ein: ' . $r['text']);
$r = rufe('set', ['room' => 'Büro', 'command' => 'on'], $werte);
pruefe($r['isError'] && $gesendet() === [] && $r['text'] === 'Nothing was changed: "on" switches an air conditioner on only with its name (room=Büro). Call again with name: Klimaanlage.', '„Büro an“ ohne Typ schaltet das Klimagerät nicht ein und nennt es beim Namen: ' . $r['text']);
// Code-Review 15.11.2026, Befund 1: Die Absage empfahl type=thermostat. Der Aufruf mit Raum stellt aber auch einen
// Heizkörperthermostat mit Sonder-Sollwert auf „On“. ANGENOMMEN ist die Zusammenstellung: das Thermostat aus dem
// Mitschnitt nuc_2026-10-26_sonderwert_befehl.json (Musikzimmer, On = 100) im Raum des Klimageräts.
$sonder = fixture('nuc_2026-10-26_sonderwert_befehl.json');
$heizung = ['Room' => 'Büro'] + array_values(array_filter($sonder['geraete'], static fn (array $z): bool => $z['Room'] === 'Musikzimmer'))[0];
$r = rufe('set', ['room' => 'Büro', 'command' => 'on'], $werte + $sonder['variablen'], array_merge($liste, [$heizung]));
pruefe($r['isError'] && $gesendet() === [] && str_contains($r['text'], 'only with its name (room=Büro). Call again with name: Klimaanlage.') && !str_contains($r['text'], 'type=thermostat'),
    'Klimagerät und Heizkörper im Raum: die Absage nennt das Klimagerät beim Namen, nicht type=thermostat: ' . $r['text']);
// ANGENOMMEN: ein Licht im selben Raum. „Büro an“ meint das Licht, das Klimagerät bleibt aus und wird genannt.
$mitLicht = array_merge($liste, [['Room' => 'Büro', 'Name' => 'Deckenlicht', 'Aliases' => '', 'Type' => 'light', 'ReadOnly' => false, 'StateVariableID' => 69990]]);
$r = rufe('set', ['room' => 'Büro', 'command' => 'on'], $werte + [69990 => ['name' => 'Büro: Deckenlicht', 'wert' => false, 'aktion' => true]], $mitLicht);
pruefe(!$r['isError'] && $gesendet() === [[69990, true]] && $hat($r, '1 air conditioner(s) left out: "on" switches one on only with its name (Klimaanlage).'), '„Büro an“ schaltet das Licht, nicht das Klimagerät: ' . $r['text'] . ' ' . json_encode($gesendet()));
// Nachreview 15.11.2026, Befund 1: „Call again with name: A, B“ ist bei mehreren Geräten kein gültiger name, und ein
// Raum in Klammern gehört nach room. ANGENOMMEN: das Gerät aus „Büro DG“ steht als „Klimaanlage Nord“ im Büro.
$nord = ['Room' => 'Büro', 'Name' => 'Klimaanlage Nord'] + array_values(array_filter($liste, static fn (array $z): bool => $z['Room'] === 'Büro DG'))[0];
$zweiImBuero = array_merge(array_values(array_filter($liste, static fn (array $z): bool => $z['Room'] !== 'Büro DG')), [$nord]);
$r = rufe('set', ['room' => 'Büro', 'command' => 'on'], $werte, $zweiImBuero);
pruefe($r['isError'] && $gesendet() === [] && $r['text'] === 'Nothing was changed: "on" switches an air conditioner on only with its name (room=Büro). Make one call per device with its name in name: Klimaanlage, Klimaanlage Nord.',
    'zwei Klimageräte im Raum: je Gerät ein Aufruf: ' . $r['text']);
$r = rufe('set', ['room' => 'Büro', 'command' => 'on'], $werte, $zweiImBuero, $de);
pruefe($r['text'] === 'Nichts geändert: „on“ schaltet ein Klimagerät nur mit seinem Namen ein (room=Büro). Je Gerät ein Aufruf mit seinem Namen in name: Klimaanlage, Klimaanlage Nord.', 'zwei Klimageräte im Raum, auf Deutsch: ' . $r['text']);
// ANGENOMMEN sind die Etagen (die Raumliste ist nicht mitgeschnitten). Aus mehreren Räumen steht der Raum in Klammern.
$etage = new RoomIndex([['Name' => 'Essen', 'Aliases' => '', 'Floor' => 'Erdgeschoss'], ['Name' => 'Schlafzimmer', 'Aliases' => '', 'Floor' => 'Erdgeschoss']]);
$anlage = new FakeBackend($werte, null);
$r = (new DeviceIndex($liste, $anlage, $etage))->setState(['floor' => 'Erdgeschoss', 'command' => 'on']);
pruefe($r['isError'] && $anlage->requests === [] && str_ends_with($r['text'], 'Make one call per device with its name in name and the room in brackets in room: Klimaanlage (Essen), Klimaanlage (Schlafzimmer).'),
    'Klimageräte in zwei Räumen: Name nach name, Raum nach room: ' . $r['text']);
// Nachreview 15.11.2026, Befund 5: Die Schlusszeile hängt an jeder Antwort; sie nennt höchstens fünf Namen. ANGENOMMEN:
// sechs Klimageräte und ein Licht im Büro (die fünf aus dem Mitschnitt umbenannt, das sechste erfunden).
$viele = [];
foreach ($liste as $n => $z) {
    $viele[] = ['Room' => 'Büro', 'Name' => 'Klima ' . ($n + 1)] + $z;
}
$viele[] = ['Name' => 'Klima 6', 'SwitchVariableID' => 69991] + $viele[0];
$viele[] = ['Room' => 'Büro', 'Name' => 'Deckenlicht', 'Aliases' => '', 'Type' => 'light', 'ReadOnly' => false, 'StateVariableID' => 69990];
$r = rufe('set', ['room' => 'Büro', 'command' => 'on'], $werte + [69990 => ['name' => 'Büro: Deckenlicht', 'wert' => false, 'aktion' => true], 69991 => ['name' => 'Büro: Betrieb 6', 'wert' => false, 'formatiert' => 'Aus', 'aktion' => true, 'geloggt' => false]], $viele);
pruefe(!$r['isError'] && $hat($r, '6 air conditioner(s) left out: "on" switches one on only with its name (Klima 1, Klima 2, Klima 3, Klima 4, Klima 5 and 1 more).'), 'sechs ausgelassene Klimageräte: die Schlusszeile nennt fünf: ' . implode(' / ', array_filter($zeilen($r), static fn (string $z): bool => str_contains($z, 'left out'))));
// Nachreview 15.11.2026, Befund 2: Neben einem ausgelassenen Klimagerät nennt die Schlusszeile zum Sonder-Sollwert bei
// „on“ nur den Namen; type=thermostat schaltete das Klimagerät mit ein. ANGENOMMEN wie oben: Heizkörper und Licht im Büro.
$r = rufe('set', ['room' => 'Büro', 'command' => 'on'], $werte + $sonder['variablen'] + [69990 => ['name' => 'Büro: Deckenlicht', 'wert' => false, 'aktion' => true]], array_merge($mitLicht, [$heizung]));
pruefe(!$r['isError'] && $hat($r, '1 thermostat(s) left out: "on" sets a special setpoint only with a name.') && !str_contains($r['text'], 'type=thermostat'),
    'Licht, Klimagerät und Heizkörper: keine Schlusszeile empfiehlt type=thermostat: ' . implode(' / ', array_filter($zeilen($r), static fn (string $z): bool => str_contains($z, 'left out'))));
// off geht über „Betrieb“, die Betriebsart bleibt
$r = rufe('set', ['room' => 'Büro', 'type' => 'thermostat', 'command' => 'off'], $stellbar($an));
pruefe(!$r['isError'] && $gesendet() === [[BETRIEB, false]] && ($zeilen($r)[0] ?? '') === '1 of 1 devices are now off (room=Büro, type=thermostat).', 'off schaltet „Betrieb“ aus, nicht die Betriebsart: ' . $r['text'] . ' ' . json_encode($gesendet()));
$r = rufe('set', ['room' => 'Büro', 'command' => 'off'], $an);
pruefe(!$r['isError'] && $gesendet() === [[BETRIEB, false]], '„Büro aus“ schaltet das Klimagerät wie bisher mit aus: ' . $r['text']);
$r = rufe('set', ['room' => 'Büro', 'type' => 'thermostat', 'command' => 'off'], $werte);
pruefe(!$r['isError'] && $gesendet() === [] && str_contains($r['text'], 'already off'), 'off bei ausgeschaltetem Gerät sendet nichts: ' . $r['text']);
// Was die Zeile versteht
$zeileBuero = array_values(array_filter($liste, static fn (array $z): bool => $z['Room'] === 'Büro'))[0];
pruefe(array_intersect(['on', 'off'], DeviceIndex::abilitiesOf($zeileBuero)) === ['on', 'off'] || array_values(array_intersect(DeviceIndex::abilitiesOf($zeileBuero), ['on', 'off'])) === ['on', 'off'],
    'mit Ein-Aus-Variable versteht die Zeile on und off: ' . json_encode(DeviceIndex::abilitiesOf($zeileBuero)));
pruefe(!in_array('on', DeviceIndex::abilitiesOf(array_diff_key($zeileBuero, ['SwitchVariableID' => true])), true), 'ohne sie gibt es on weiter nicht');
pruefe(DeviceIndex::abilitiesOf(['ReadOnly' => true] + $zeileBuero) === [], 'nur abfragen: versteht nichts');

/* --- Stellen bei ausgeschaltetem Gerät, ANGENOMMEN: Sollwert und Betriebsart haben eine Aktion ---- */

$bleibtAus = '1 device(s) are switched off and stay off (Klimaanlage). Switch one on with command=on and its name.';
$r = rufe('set', ['room' => 'Büro', 'type' => 'thermostat', 'temperature' => 22], $stellbar($werte));
pruefe(!$r['isError'] && $gesendet() === [[SOLLWERT, 22.0]] && $hat($r, 'Büro | Klimaanlage | thermostat | off (setpoint 22 °C, currently 17.0 °C)') && $hat($r, $bleibtAus),
    'Sollwert bei ausgeschaltetem Gerät: gestellt, bleibt aus, und die Antwort sagt es: ' . $r['text'] . ' ' . json_encode($gesendet()));
$r = rufe('set', ['room' => 'Büro', 'type' => 'thermostat', 'mode' => 'Kühlen'], $stellbar($werte));
pruefe(!$r['isError'] && $gesendet() === [[BETRIEBSART, 2]] && ($zeilen($r)[0] ?? '') === '1 of 1 devices set to Kühlen (room=Büro, type=thermostat).' && $hat($r, $bleibtAus),
    'Betriebsart bei ausgeschaltetem Gerät: gestellt, nicht eingeschaltet, und die Antwort sagt es: ' . $r['text'] . ' ' . json_encode($gesendet()));
$r = rufe('set', ['room' => 'Büro', 'type' => 'thermostat', 'mode' => 'Kühlen'], $stellbar($an));
pruefe(!$r['isError'] && !str_contains($r['text'], 'stay off'), 'eingeschaltet: kein Hinweis „bleibt aus“: ' . $r['text']);
$r = rufe('set', ['room' => 'Büro', 'type' => 'thermostat', 'temperature' => 22], $stellbar($werte), null, $de);
pruefe($hat($r, '1 Gerät(e) sind ausgeschaltet und bleiben aus (Klimaanlage). Einschalten mit command=on und dem Namen des Geräts.'), 'Hinweis auf Deutsch: ' . $r['text']);

/* --- Karte --------------------------------------------------------------------------------------- */

$k = $karte(rufe('karten', ['room' => 'Büro'], $werte));
pruefe(($k['active'] ?? null) === false && ($k['controls']['switch'] ?? null) === true && ($k['status'] ?? null) === 'off', 'Karte ausgeschaltet: Schalter, nicht aktiv, Status „off“: ' . json_encode(array_intersect_key($k, ['active' => 1, 'status' => 1, 'controls' => 1]), JSON_UNESCAPED_UNICODE));
$k = $karte(rufe('karten', ['room' => 'Büro'], $an));
pruefe(($k['active'] ?? null) === true && ($k['controls']['switch'] ?? null) === true && ($k['status'] ?? null) !== 'off', 'Karte eingeschaltet: aktiv, mit Schalter: ' . json_encode(array_intersect_key($k, ['active' => 1, 'status' => 1, 'controls' => 1]), JSON_UNESCAPED_UNICODE));
$k = $karte(rufe('nurLesen', ['room' => 'Büro'], $werte));
pruefe(($k['active'] ?? null) === false && !isset($k['controls']['switch']), 'Karte bei „Nur abfragen“: kein Schalter, aber aus: ' . json_encode(array_intersect_key($k, ['active' => 1, 'status' => 1, 'controls' => 1]), JSON_UNESCAPED_UNICODE));
$k = $karte(rufe('karten', ['room' => 'Büro'], $werte, $ohne));
pruefe(!isset($k['controls']['switch']), 'Karte ohne Ein-Aus-Variable: kein Schalter (schon vorher richtig)');

/* --- Selbsttest: Betriebsart ohne „Aus“ und keine Ein-Aus-Variable ------------------------------- */

// ANGENOMMEN sind die Einträge der Betriebsart (siehe oben). Ohne Ein-Aus-Variable und ohne Eintrag „Aus“ zeigt ein
// ausgeschaltetes Gerät seine letzte Betriebsart; der Selbsttest nennt solche Zeilen.
$mitEintraegen = $stellbar($werte);
$nenne = static function (array $zeilen, array $werte): array {
    $anlage = new FakeBackend($werte, null);
    return method_exists(DeviceIndex::class, 'modesWithoutOff') ? (new DeviceIndex($zeilen, $anlage))->modesWithoutOff() : ['FEHLT'];
};
pruefe($nenne($ohne, $mitEintraegen) === [['Klimaanlage', 'Büro']], 'Betriebsart ohne „Aus“ und ohne Ein-Aus-Variable wird genannt: ' . json_encode($nenne($ohne, $mitEintraegen), JSON_UNESCAPED_UNICODE));
pruefe($nenne($liste, $mitEintraegen) === [], 'mit Ein-Aus-Variable kein Hinweis: ' . json_encode($nenne($liste, $mitEintraegen), JSON_UNESCAPED_UNICODE));
$mitAus = $mitEintraegen;
$mitAus[BETRIEBSART]['optionen'][] = [0, 'Aus'];
$anlageDe = static function (array $zeilen, array $werte) use ($de): array {
    $anlage = new FakeBackend($werte, $de);
    return method_exists(DeviceIndex::class, 'modesWithoutOff') ? (new DeviceIndex($zeilen, $anlage))->modesWithoutOff() : ['FEHLT'];
};
pruefe($anlageDe($ohne, $mitAus) === [], 'Betriebsart mit Eintrag „Aus“: kein Hinweis: ' . json_encode($anlageDe($ohne, $mitAus), JSON_UNESCAPED_UNICODE));
pruefe($nenne($ohne, $werte) === [], 'unbekannte Einträge: kein Hinweis, geraten wird nicht: ' . json_encode($nenne($ohne, $werte), JSON_UNESCAPED_UNICODE));

ende('Klimagerät mit Ein-Aus-Variable');
