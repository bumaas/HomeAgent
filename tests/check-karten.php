<?php

declare(strict_types=1);

/*
 * Gerätekarten als MCP App (Burkhard 12.10.2026: „wir machen die gerätekarten“). show_devices zeigt die Geräte wie
 * get_state als Karten mit Schalter, Schieberegler, Auf/Stopp/Zu, Sollwert und Wiedergabe; die Seite bedient sie über
 * das Werkzeug control_device, das nur sie sieht (_meta.ui.visibility ["app"]). Geschaltet wird über set_state, die
 * Rückmeldung bleibt also dieselbe. Ein Nur-Lese-Zugang bekommt Karten ohne Bedienelemente.
 * Mitschnitte testbox_2026-10-06_schalten.json (Testlampe, Rollladen, Steckdosen) und nuc_2026-10-10_thermostat.json
 * (Thermostate, deren Ist-Temperatur derselbe Fühler ist wie ein eigenes Temperaturgerät).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$fx = fixture('testbox_2026-10-06_schalten.json');
$thermo = fixture('nuc_2026-10-10_thermostat.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @return array{0: DeviceIndex, 1: FakeBackend} */
function anlage(array $mitschnitt, ?array $uebersetzungen = null): array
{
    $backend = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    if (isset($mitschnitt['jetzt'])) {
        $backend->jetzt = $mitschnitt['jetzt'];
    }
    return [new DeviceIndex($mitschnitt['geraete'], $backend), $backend];
}

function rufe(callable $aufruf): array
{
    try {
        return $aufruf();
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

/** Karte nach Name aus einem Ergebnis. */
function karte(array $r, string $name): array
{
    foreach ($r['structuredContent']['cards'] ?? [] as $k) {
        if (($k['name'] ?? null) === $name) {
            return $k;
        }
    }
    return [];
}

/* --- Karten im Testraum ------------------------------------------------------------------------ */

[$idx] = anlage($fx);
$r = rufe(static fn () => $idx->cards(['room' => 'Testraum'], false));
$zeilen = explode("\n", $r['text']);
pruefe(!$r['isError'] && str_starts_with($zeilen[0], '6 device(s) found (room=Testraum)') && end($zeilen) === 'The devices are shown as cards in the chat.', 'das Modell bekommt die Zeilen wie bei get_state und die Schlusszeile: ' . $r['text']);
pruefe(count($r['structuredContent']['cards'] ?? []) === 6, 'sechs Karten: ' . count($r['structuredContent']['cards'] ?? []));
$lampe = karte($r, 'Testlampe');
pruefe(($lampe['room'] ?? null) === 'Testraum' && ($lampe['type'] ?? null) === 'light' && ($lampe['active'] ?? null) === false && is_string($lampe['text'] ?? null), 'Karte mit Raum, Typ, Zustand und Text: ' . json_encode($lampe, JSON_UNESCAPED_UNICODE));
pruefe(($lampe['controls']['switch'] ?? null) === true && ($lampe['controls']['percent'] ?? null) === 100, 'Testlampe: Schalter und Helligkeit 254 von 1 bis 254 = 100 %: ' . json_encode($lampe['controls'] ?? null));
$rollo = karte($r, 'Rollladen');
pruefe(!isset($rollo['controls']['switch']) && ($rollo['controls']['cover'] ?? null) === ['stop' => false] && ($rollo['controls']['percent'] ?? null) === 100, 'Rollladen: Auf/Zu ohne Stopp, Öffnungsgrad 100 % (Wert 0 bei ~Shutter): ' . json_encode($rollo['controls'] ?? null));
$ohne = karte($r, 'Lampe ohne Aktion');
pruefe(($ohne['controls']['switch'] ?? null) === true, 'auch ohne Aktion gibt es den Schalter, die Absage kommt wie bei set_state beim Schalten');
pruefe(count(array_unique(array_column($r['structuredContent']['cards'] ?? [], 'key'))) === 6, 'jede Karte hat einen eigenen Schlüssel');

$r = rufe(static fn () => $idx->cards(['room' => 'Testraum'], true));
pruefe(!$r['isError'] && array_filter(array_column($r['structuredContent']['cards'] ?? [], 'controls')) === [] && count($r['structuredContent']['cards'] ?? []) === 6, 'Nur-Lese-Zugang: Karten ohne Bedienelemente: ' . json_encode(array_column($r['structuredContent']['cards'] ?? [], 'controls')));
pruefe(($r['structuredContent']['readOnly'] ?? null) === true, 'die Seite erfährt, dass der Zugang nur abfragen kann');

$r = rufe(static fn () => $idx->cards(['room' => 'Nirgendwo'], false));
pruefe($r['isError'] && !isset($r['structuredContent']), 'kein Treffer: Fehler wie bei get_state: ' . $r['text']);

/* --- Thermostat: Sollwert, eigener Schlüssel ------------------------------------------------------ */

[$idx] = anlage($thermo);
$r = rufe(static fn () => $idx->cards(['room' => 'Wintergarten'], false));
$heizung = karte($r, 'Heizung');
pruefe(($heizung['controls']['setpoint'] ?? null) === ['field' => 'temperature', 'value' => 21.0, 'min' => 6.0, 'max' => 30.0, 'step' => 0.5, 'unit' => '°C'], 'Thermostat Wintergarten: Sollwert 21 °C von 6 bis 30 in 0,5: ' . json_encode($heizung['controls'] ?? null, JSON_UNESCAPED_UNICODE));
$r = rufe(static fn () => $idx->cards(['room' => 'Gast'], false));
pruefe((karte($r, 'Heizung')['key'] ?? 1) !== (karte($r, 'Temperatur')['key'] ?? 1), 'Thermostat und Fühler mit derselben Variable haben verschiedene Schlüssel: ' . json_encode(array_column($r['structuredContent']['cards'] ?? [], 'key')));
pruefe(!isset(karte($r, 'Temperatur')['controls']['switch']) && (karte($r, 'Temperatur')['controls'] ?? null) === [], 'ein Messwert hat keine Bedienelemente');

/* --- Bedienen über control_device ------------------------------------------------------------------- */

[$idx, $b] = anlage($fx, $de);
$schluessel = karte(rufe(static fn () => $idx->cards(['room' => 'Testraum'], false)), 'Testlampe')['key'] ?? '';
$r = rufe(static fn () => $idx->controlDevice(['device' => $schluessel, 'command' => 'on']));
pruefe(!$r['isError'] && $b->requests === [[15886, true]], 'Testlampe per Karte eingeschaltet, nur sie: ' . json_encode($b->requests) . ' / ' . $r['text']);
pruefe(str_contains($r['text'], 'Testlampe') && (($r['structuredContent']['cards'][0]['active'] ?? null) === true), 'Antwort wie set_state und die neue Karte, jetzt an: ' . json_encode($r['structuredContent'] ?? null, JSON_UNESCAPED_UNICODE));
$r = rufe(static fn () => $idx->controlDevice(['device' => $schluessel, 'percent' => 50]));
pruefe(!$r['isError'] && ($r['structuredContent']['cards'][0]['controls']['percent'] ?? null) === 50, 'Helligkeit per Schieberegler auf 50 %: ' . json_encode($r['structuredContent']['cards'][0]['controls'] ?? null) . ' / ' . $r['text']);

// Die Karte zeigt die Antwort nur, wenn etwas nicht geklappt hat (Burkhard 12.10.2026: nach dem Schalten stand dort
// „1 von 1 Geräten sind jetzt aus (name=Blumenfenster). Wohnzimmer | Blumenfenster | light | aus“). Dafür sagt
// control_device, ob es ein Problem gab, mit derselben Art wie „Was nicht geklappt hat“.
pruefe(array_key_exists('problem', $r['structuredContent'] ?? []) && $r['structuredContent']['problem'] === null, 'geklappt: kein Problem, die Karte zeigt nur den neuen Zustand: ' . json_encode($r['structuredContent']['problem'] ?? 'fehlt'));
[$idx, $b] = anlage($fx);
$karten = rufe(static fn () => $idx->cards(['room' => 'Testraum'], false));
$r = rufe(static fn () => $idx->controlDevice(['device' => karte($karten, 'Lampe ohne Aktion')['key'] ?? '', 'command' => 'on']));
pruefe(is_string($r['structuredContent']['problem'] ?? null), 'Lampe ohne Aktion: die Karte bekommt die Art des Problems und zeigt die Antwort: ' . json_encode($r['structuredContent']['problem'] ?? null) . ' / ' . $r['text']);

[$idx, $b] = anlage($fx);
$karten = rufe(static fn () => $idx->cards(['room' => 'Testraum'], false));
$r = rufe(static fn () => $idx->controlDevice(['device' => karte($karten, 'Rollladen')['key'] ?? '', 'command' => 'close']));
pruefe(!$r['isError'] && $b->requests === [[13696, 100]], 'Rollladen per Karte zu: ' . json_encode($b->requests) . ' / ' . $r['text']);

[$idx, $b] = anlage($fx);
$r = rufe(static fn () => $idx->controlDevice(['device' => '99999', 'command' => 'on']));
pruefe($r['isError'] && $b->requests === [] && str_contains($r['text'], 'show the devices again'), 'unbekannter Schlüssel: nichts geschaltet, Hinweis: ' . $r['text']);
$r = rufe(static fn () => $idx->controlDevice(['command' => 'on']));
pruefe($r['isError'] && $b->requests === [], 'ohne Schlüssel nichts: ' . $r['text']);

// Thermostat Gast: Schlüssel trifft die Heizung, nicht den Fühler mit derselben Variable
[$idx, $b] = anlage($thermo);
$karten = rufe(static fn () => $idx->cards(['room' => 'Gast'], false));
$r = rufe(static fn () => $idx->controlDevice(['device' => karte($karten, 'Heizung')['key'] ?? '', 'temperature' => 21]));
pruefe(!$r['isError'] && ($b->requests[0] ?? null) === [10028, 21.0], 'Sollwert über die Karte der Heizung: ' . json_encode($b->requests) . ' / ' . $r['text']);

/* --- Auswahl: Modus und Lüfterstufe ------------------------------------------------------------------ */

// Die Karten konnten Modus und Lüfterstufe nicht stellen (README: „gehen auf den Karten noch nicht“). Jetzt je eine
// Auswahl mit den Beschriftungen aus der Darstellung bzw. den Profilzuordnungen, gesendet als Name wie bei set_state.
// Mitschnitte nuc_2026-10-11_entfeuchter.json (Text-Werte aus OPTIONS) und nuc_2026-10-12_lueftung.json (Ganzzahlen).
$entfeuchter = fixture('nuc_2026-10-11_entfeuchter.json');
$lueftung = fixture('nuc_2026-10-12_lueftung.json');

[$idx] = anlage($entfeuchter, $de);
$k = karte(rufe(static fn () => $idx->cards(['name' => 'Luftentfeuchter'], false)), 'Luftentfeuchter');
pruefe(($k['controls']['options'] ?? null) === [
    ['field' => 'mode', 'label' => 'Modus', 'value' => 'Smart', 'entries' => ['Setzen', 'Smart', 'Kontinuierlich', 'Entfeuchten']],
    ['field' => 'fan_speed', 'label' => 'Lüfterstufe', 'value' => 'Niedrig', 'entries' => ['Niedrig', 'Medium', 'Hoch']],
], 'Entfeuchter: Auswahl für Modus und Lüfterstufe mit den Beschriftungen und dem aktuellen Eintrag: ' . json_encode($k['controls']['options'] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(($k['controls']['switch'] ?? null) === true && ($k['controls']['setpoint']['field'] ?? null) === 'humidity', 'Schalter und Zielfeuchte bleiben: ' . json_encode(array_keys($k['controls'] ?? [])));

[$idx] = anlage($lueftung);
$k = karte(rufe(static fn () => $idx->cards(['room' => 'Büro'], false)), 'Lüftung');
pruefe(($k['controls']['options'][0]['value'] ?? null) === 'Manuelle Wärmerückgewinnung' && count($k['controls']['options'][0]['entries'] ?? []) === 12
    && ($k['controls']['options'][1] ?? null) === ['field' => 'fan_speed', 'label' => 'Fan speed', 'value' => 'niedrig', 'entries' => ['niedrig', 'mittel', 'hoch']],
    'Lüftung: Ganzzahlwerte aus den Profilzuordnungen, angezeigt als Beschriftung: ' . json_encode($k['controls']['options'] ?? null, JSON_UNESCAPED_UNICODE));
$r = rufe(static fn () => $idx->cards(['room' => 'Büro'], true));
pruefe((karte($r, 'Lüftung')['controls'] ?? null) === [], 'Nur-Lese-Zugang: keine Auswahl');

[$idx, $b] = anlage($entfeuchter, $de);
$schluessel = karte(rufe(static fn () => $idx->cards(['name' => 'Luftentfeuchter'], false)), 'Luftentfeuchter')['key'] ?? '';
$r = rufe(static fn () => $idx->controlDevice(['device' => $schluessel, 'fan_speed' => 'Medium']));
pruefe(!$r['isError'] && $b->requests === [[28873, 'Medium']] && array_key_exists('problem', $r['structuredContent'] ?? []) && $r['structuredContent']['problem'] === null, 'Lüfterstufe per Karte auf Medium, bestätigt: ' . json_encode($b->requests) . ' / ' . $r['text']);
pruefe((karte($r, 'Luftentfeuchter')['controls']['options'][1]['value'] ?? null) === 'Medium', 'die neue Karte zeigt Medium: ' . json_encode(karte($r, 'Luftentfeuchter')['controls']['options'] ?? null));
[$idx, $b] = anlage($entfeuchter, $de);
$r = rufe(static fn () => $idx->controlDevice(['device' => $schluessel, 'mode' => 'Kontinuierlich']));
pruefe(!$r['isError'] && $b->requests === [[25833, 'Continuous']], 'Modus per Karte über die Beschriftung, gesendet wird der Wert: ' . json_encode($b->requests) . ' / ' . $r['text']);

[$idx, $b] = anlage($lueftung);
$schluessel = karte(rufe(static fn () => $idx->cards(['room' => 'Büro'], false)), 'Lüftung')['key'] ?? '';
$r = rufe(static fn () => $idx->controlDevice(['device' => $schluessel, 'fan_speed' => 'hoch']));
pruefe(!$r['isError'] && ($b->requests[0] ?? null) === [20833, 3], 'Lüftung: Stufe hoch per Karte, gesendet 3: ' . json_encode($b->requests) . ' / ' . $r['text']);
/* --- Gestaltung nach pitti: Gruppe, Hauptwert, kurzer Zustand ------------------------------------------ */

// pitti hat die Karten am 13.10.2026 neu gezeichnet (PN t/144633 #9): Gruppen je Art mit Überschrift, der Wert groß
// rechts (100 %, 21 W, 22,1 °C), links nur der kurze Zustand. Dafür bekommt jede Karte eine Gruppe, einen Hauptwert
// (`figure`, Zahl und Einheit) und `status`, den Zustandstext ohne den Hauptwert. Mitschnitte wie oben, dazu
// nuc_2026-10-09_energie.json (Leistung) und betreut_2026-10-13_klima.json.
$energie = fixture('nuc_2026-10-09_energie.json');
$klima = fixture('betreut_2026-10-13_klima.json');

/** Karte nach Name über die Namenssuche. */
function karteVon(array $mitschnitt, string $name, ?array $de): array
{
    [$idx] = anlage($mitschnitt, $de);
    return karte(rufe(static fn () => $idx->cards(['name' => $name], false)), $name);
}

[$idx] = anlage($fx, $de);
$r = rufe(static fn () => $idx->cards(['room' => 'Testraum'], false));
$lampe = karte($r, 'Testlampe');
pruefe(($lampe['group'] ?? null) === 'light' && array_key_exists('figure', $lampe) && $lampe['figure'] === null && ($lampe['status'] ?? null) === 'aus', 'Testlampe aus: Gruppe Licht, kein Hauptwert (die Stufe 100 % gilt erst eingeschaltet), Zustand „aus“: ' . json_encode($lampe, JSON_UNESCAPED_UNICODE));
$rollo = karte($r, 'Rollladen');
pruefe(($rollo['group'] ?? null) === 'cover' && ($rollo['figure'] ?? null) === ['value' => '100', 'unit' => '%'] && ($rollo['status'] ?? null) === 'offen', 'Rollladen: Gruppe Rollläden, 100 %, offen: ' . json_encode($rollo, JSON_UNESCAPED_UNICODE));
pruefe(array_column($r['structuredContent']['labels']['groups'] ?? [], 0) === ['cover', 'light', 'power', 'climate', 'opening', 'sensor', 'media']
    && ($r['structuredContent']['labels']['groups'][0][1] ?? null) === 'Rollläden', 'Überschriften der Gruppen in fester Reihenfolge, übersetzt: ' . json_encode($r['structuredContent']['labels']['groups'] ?? null, JSON_UNESCAPED_UNICODE));

[$idx, $b] = anlage($fx, $de);
$schluessel = karte(rufe(static fn () => $idx->cards(['room' => 'Testraum'], false)), 'Testlampe')['key'] ?? '';
$r = rufe(static fn () => $idx->controlDevice(['device' => $schluessel, 'percent' => 60]));
$k = $r['structuredContent']['cards'][0] ?? [];
pruefe(($k['figure'] ?? null) === ['value' => '60', 'unit' => '%'] && ($k['status'] ?? null) === 'an', 'Testlampe auf 60 %: der Hauptwert ist die Stufe: ' . json_encode($k, JSON_UNESCAPED_UNICODE));

$k = karteVon($energie, 'Entfeuchter', $de);
pruefe(($k['group'] ?? null) === 'power' && ($k['figure'] ?? null) === ['value' => '26.1', 'unit' => 'W'] && ($k['status'] ?? null) === 'an', 'Schalter mit Leistung: 26.1 W, Zustand „an“ statt „an (26.1 W)“: ' . json_encode($k, JSON_UNESCAPED_UNICODE));
$k = karteVon($energie, 'Deckenlicht', $de);
pruefe(($k['figure'] ?? null) === ['value' => '0.0', 'unit' => 'W'] && ($k['status'] ?? null) === 'aus', 'Licht aus mit Leistung: die Leistung ist der Hauptwert: ' . json_encode($k, JSON_UNESCAPED_UNICODE));

[$idx] = anlage($thermo, $de);
$r = rufe(static fn () => $idx->cards(['room' => 'Gast'], false));
$k = karte($r, 'Temperatur');
pruefe(($k['group'] ?? null) === 'climate' && ($k['figure'] ?? null) === ['value' => '21.7', 'unit' => '°C'] && array_key_exists('status', $k) && $k['status'] === null, 'Messwert: nur der Hauptwert, kein Zustand: ' . json_encode($k, JSON_UNESCAPED_UNICODE));
$r = rufe(static fn () => $idx->cards(['room' => 'Wintergarten'], false));
$k = karte($r, 'Heizung');
pruefe(($k['figure'] ?? null) === ['value' => '20.6', 'unit' => '°C'] && ($k['status'] ?? null) === 'Soll 21 °C', 'Thermostat: Ist-Temperatur groß, links der Sollwert: ' . json_encode($k, JSON_UNESCAPED_UNICODE));
$k = karte($r, 'Fenster links') ?: karte(rufe(static fn () => $idx->cards(['name' => 'Fenster links'], false)), 'Fenster links');
pruefe(($k['group'] ?? null) === 'opening' && ($k['status'] ?? null) === 'geschlossen', 'Fenster: Gruppe Fenster und Türen: ' . json_encode($k, JSON_UNESCAPED_UNICODE));

$k = karteVon($klima, 'Klimagerät', $de);
pruefe(($k['figure'] ?? null) === ['value' => '20.0', 'unit' => '°C'] && ($k['status'] ?? null) === 'Soll 24 °C, Aus, Lüfter Auto', 'Klimagerät: Ist groß, Soll, Modus und Lüfter links: ' . json_encode($k, JSON_UNESCAPED_UNICODE));
$k = karteVon($entfeuchter, 'Luftentfeuchter', $de);
pruefe(($k['figure'] ?? null) === ['value' => '51', 'unit' => '%'] && ($k['status'] ?? null) === 'an (Soll 55 %, Smart, Lüfter Niedrig)', 'Luftentfeuchter: Ist-Feuchte groß, der Rest bleibt: ' . json_encode($k, JSON_UNESCAPED_UNICODE));
$k = karteVon(fixture('nuc_2026-10-10_medien.json'), 'Echo', $de);
pruefe(($k['group'] ?? null) === 'media' && array_key_exists('figure', $k) && $k['figure'] === null && ($k['status'] ?? null) === ($k['text'] ?? 1), 'Mediaplayer: vorerst ohne Hauptwert, Zustand = Text: ' . json_encode($k, JSON_UNESCAPED_UNICODE));

/* --- Übersetzung ---------------------------------------------------------------------------------- */

[$idx] = anlage($fx, $de);
$r = rufe(static fn () => $idx->cards(['room' => 'Testraum'], false));
pruefe(str_ends_with($r['text'], 'Die Geräte stehen als Karten im Chat.'), 'deutsch: ' . $r['text']);

ende('Gerätekarten');
