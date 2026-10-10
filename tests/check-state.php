<?php

declare(strict_types=1);

/*
 * Werkzeug „get_state“ gegen echte Werte der Testbox (Mitschnitt 06.10.2026, alle Lichter aus,
 * Küchenfenster offen, Steckdose an). Geprüft wird, was ein Sprachassistent sonst selbst
 * leisten müsste: zählen, Raum und Name trotz Umlauten und Aliasen finden, invertierte
 * Kontakte richtig deuten, und bei leerem Treffer sagen, was es gibt. Der zweite Teil prüft
 * dieselben Antworten auf Deutsch über die locale.json des Moduls.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$werte = fixture('testbox_2026-10-06_werte.json')['variablen'];
$liste = fixture('testbox_freigabe.json')['Devices'];

/** @var list<FakeBackend> alle Anlagen dieses Laufs, für die Prüfung auf fehlende Übersetzungen */
$anlagen = [];

function index(array $liste, array $werte, ?array $uebersetzungen = null): DeviceIndex
{
    global $anlagen;
    $anlagen[] = $backend = new FakeBackend($werte, $uebersetzungen);
    return new DeviceIndex($liste, $backend);
}

$idx = index($liste, $werte);

// Zählen statt den Assistenten zählen lassen
$r = $idx->getState(['type' => 'light', 'active_only' => true]);
pruefe(!$r['isError'], 'Lichterabfrage ist kein Fehler');
pruefe(str_starts_with($r['text'], '0 of 3 checked devices active (type=light).'), 'alle drei Lichter aus: ' . $r['text']);
pruefe(substr_count($r['text'], "\n") === 0, 'bei active_only ohne Treffer keine Gerätezeilen: ' . $r['text']);

// Raum oder Name, von dem nach dem Normalisieren nichts übrig bleibt, ist kein leerer Filter
// (Sicherheitsreview 06.10.2026): vorher lieferte room=„-“ das ganze Haus.
foreach (['-', '.', "\u{00A0}"] as $leer) {
    $r = $idx->getState(['room' => $leer]);
    pruefe($r['isError'] && str_starts_with($r['text'], 'No released device matches'), 'room=' . json_encode($leer) . ' liefert nicht das ganze Haus: ' . strtok($r['text'], "\n"));
    $r = $idx->getState(['room' => 'Küche', 'name' => $leer]);
    pruefe($r['isError'], 'name=' . json_encode($leer) . ' liefert nicht den ganzen Raum: ' . strtok($r['text'], "\n"));
}

// Invertierter Kontakt: Matter BooleanState false = „Geöffnet“
$r = $idx->getState(['type' => 'window', 'active_only' => true]);
pruefe(str_starts_with($r['text'], '1 of 1'), 'Küchenfenster zählt als offen: ' . $r['text']);
pruefe(str_contains($r['text'], 'Küche | Fenster | window | open'), 'Zeile nennt „open“: ' . $r['text']);
$ohneUmkehr = $liste;
foreach ($ohneUmkehr as &$e) {
    $e['Invert'] = false;
}
unset($e);
$r = index($ohneUmkehr, $werte)->getState(['type' => 'window']);
pruefe(str_contains($r['text'], '| closed'), 'ohne Umkehr wäre dasselbe Fenster „closed“: ' . $r['text']);

// Messwerte kommen formatiert, ohne aktiv/inaktiv
$r = $idx->getState(['room' => 'Küche', 'type' => 'temperature']);
pruefe(str_contains($r['text'], 'Küche | Temperatur | temperature | 23.74 °C'), 'Küchentemperatur formatiert: ' . $r['text']);
pruefe(str_starts_with($r['text'], '1 device(s) found'), 'nur die Küche, nicht das Schlafzimmer: ' . $r['text']);

// Raum ohne Umlaut, Groß-/Kleinschreibung egal
foreach (['Kueche', 'küche', 'KÜCHE'] as $raum) {
    $r = $idx->getState(['room' => $raum]);
    pruefe(str_starts_with($r['text'], '5 device(s) found'), 'Raum „' . $raum . '“ findet die fünf Küchengeräte: ' . strtok($r['text'], "\n"));
}

// Alias und Teilwort
$r = $idx->getState(['name' => 'Esstischlampe']);
pruefe(str_contains($r['text'], 'Wohnzimmer | Esstisch (Esstischlampe) | light | off'), 'Alias „Esstischlampe“, in Klammern genannt: ' . $r['text']);
$r = $idx->getState(['name' => 'shelly']);
pruefe(str_contains($r['text'], 'Büro | Steckdose (Shelly) | socket | on'), 'Alias klein geschrieben, in der Schreibweise der Liste genannt: ' . $r['text']);

// Stufe erscheint nur bei eingeschaltetem Licht
$an = $werte;
$an['20946']['wert'] = true;
$r = index($liste, $an)->getState(['name' => 'Deckenpanel']);
pruefe(str_contains($r['text'], '| on (100 %)'), 'eingeschaltetes Panel mit Helligkeit: ' . $r['text']);
$r = $idx->getState(['name' => 'Deckenpanel']);
pruefe(str_ends_with($r['text'], '| off'), 'ausgeschaltetes Panel ohne Helligkeit: ' . $r['text']);

// Kein Treffer: Antwort sagt, was es gibt
$r = $idx->getState(['room' => 'Dachboden']);
pruefe($r['isError'], 'unbekannter Raum ist ein Fehler');
pruefe(str_contains($r['text'], 'Known rooms: Büro, Keller, Küche, Schlafzimmer, Wohnzimmer'), 'Fehler nennt die Räume: ' . $r['text']);
$r = $idx->getState(['type' => 'licht']);
pruefe($r['isError'] && str_contains($r['text'], 'Allowed: light'), 'unbekannter (deutscher) Typ nennt die erlaubten: ' . $r['text']);

// Gelöschte Variable
$ohneWasser = $werte;
unset($ohneWasser['27044']);
$r = index($liste, $ohneWasser)->getState(['type' => 'water_leak']);
pruefe(str_contains($r['text'], 'not available (variable 27044 is missing)'), 'fehlende Variable wird benannt: ' . $r['text']);

// Alles ohne Filter
$r = $idx->getState([]);
pruefe(str_starts_with($r['text'], '12 device(s) found (all devices)'), 'ohne Filter alle zwölf: ' . strtok($r['text'], "\n"));

/* --- Deutsch über die locale.json: jeder Text der Bibliothek braucht dort einen Schlüssel --- */

$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
$deutsch = $de;
$idxDe = index($liste, $werte, $deutsch);

$r = $idxDe->getState(['type' => 'light', 'active_only' => true]);
pruefe($r['text'] === '0 von 3 geprüften Geräten aktiv (type=light).', 'deutsche Kopfzeile, Filter mit Parameternamen: ' . $r['text']);
// Was ohne an/aus nicht gezählt wurde, nennt eine Schlusszeile (Blindtest 08.10.2026, Befund 3:
// „0 von 4 geprüften Geräten aktiv“ im Raum mit sechs Geräten ließ offen, welche vier).
$r = $idx->getState(['room' => 'Küche', 'active_only' => true]);
pruefe($r['text'] === "2 of 3 checked devices active (room=Küche).\nKüche | Fenster | window | open\nKüche | Bewegungsmelder | presence | detected\n2 device(s) without on/off were not counted (temperature, humidity).", 'Messwerte der Küche als Schlusszeile: ' . $r['text']);
$r = $idxDe->getState(['active_only' => true, 'room' => 'all']);
pruefe(str_ends_with($r['text'], "\n4 Gerät(e) ohne an/aus wurden nicht gezählt (temperature, humidity, air_quality)."), 'jeder Typ einmal, auf Deutsch: ' . $r['text']);
$r = $idxDe->getState(['room' => 'Küche']);
pruefe(!str_contains($r['text'], 'nicht gezählt'), 'ohne active_only zählt nichts, also keine Schlusszeile: ' . $r['text']);
$r = $idxDe->getState(['room' => 'Küche']);
pruefe(str_contains($r['text'], 'Küche | Fenster | window | offen') && str_contains($r['text'], '| presence | erkannt'), 'deutsche Zustandswörter: ' . $r['text']);
$r = $idxDe->getState([]);
pruefe(str_starts_with($r['text'], '12 Gerät(e) gefunden (alle Geräte).') && str_contains($r['text'], '| kein Leck') && str_contains($r['text'], '| socket | an'), 'ohne Filter auf Deutsch: ' . $r['text']);
$r = $idxDe->getState(['room' => 'Dachboden']);
pruefe(str_starts_with($r['text'], 'Kein freigegebenes Gerät passt zu room=Dachboden. Bekannte Räume:'), 'deutsche Fehlantwort: ' . $r['text']);
index([], [], $deutsch)->getState(['name' => 'x']);            // „(keine)“
index($liste, $ohneWasser, $deutsch)->getState(['type' => 'water_leak']); // „nicht verfügbar“
index($liste, $an, $deutsch)->getState(['type' => 'heizung']);  // „Unbekannter Typ“
index($ohneUmkehr, $an, $deutsch)->getState([]);                // an, geschlossen, erkannt/Leck je nach Wert
/* --- Raumliste, Etagen und Standardraum (Testkonfiguration, kein Mitschnitt) --------------- */

if (is_file(dirname(__DIR__) . '/libs/RoomIndex.php')) {
    require_once dirname(__DIR__) . '/libs/RoomIndex.php';
}
$raeume = fixture('testbox_freigabe.json')['Rooms'];

function mitRaeumen(array $liste, array $werte, array $raeume, string $standardraum = '', ?array $uebersetzungen = null): DeviceIndex
{
    global $anlagen;
    $anlagen[] = $backend = new FakeBackend($werte, $uebersetzungen);
    return new DeviceIndex($liste, $backend, class_exists('RoomIndex') ? new RoomIndex($raeume) : null, $standardraum);
}

$idxR = mitRaeumen($liste, $werte, $raeume);
$r = $idxR->getState(['room' => 'Stube']);
pruefe(!$r['isError'] && str_starts_with($r['text'], '3 device(s) found (room=Stube).') && str_contains($r['text'], 'Wohnzimmer | Esstisch | light'), 'Alias aus der Raumliste findet die Geräte des Raums: ' . $r['text']);
$r = $idxR->getState(['room' => 'ARBEITSZIMMER', 'type' => 'socket']);
pruefe(str_starts_with($r['text'], '1 device(s) found'), 'Alias mit anderer Schreibweise: ' . strtok($r['text'], "\n"));
$r = mitRaeumen($liste, $werte, [$raeume[0]])->getState(['room' => 'keller']);
pruefe(str_starts_with($r['text'], '1 device(s) found (room=keller).'), 'ein Raum, der nicht in der Raumliste steht, geht wie bisher: ' . $r['text']);

$r = $idxR->getState(['floor' => 'Erdgeschoss', 'type' => 'light']);
pruefe(str_starts_with($r['text'], '3 device(s) found (floor=Erdgeschoss, type=light).'), 'Etage: die drei Lichter im Erdgeschoss: ' . $r['text']);
$r = $idxR->getState(['floor' => 'obergeschoss']);
pruefe(str_starts_with($r['text'], '2 device(s) found (floor=obergeschoss).') && str_contains($r['text'], 'Schlafzimmer | Luftqualität'), 'Etage klein geschrieben: ' . $r['text']);
$r = $idxR->getState(['floor' => 'Erdgeschoss', 'room' => 'Küche', 'type' => 'window']);
pruefe(str_starts_with($r['text'], '1 device(s) found (room=Küche, floor=Erdgeschoss, type=window).'), 'Etage und Raum zusammen: ' . strtok($r['text'], "\n"));
$r = $idxR->getState(['floor' => 'Dachboden']);
pruefe($r['isError'] && $r['text'] === 'Unknown floor "Dachboden". Known floors: Erdgeschoss, Obergeschoss, Keller.', 'unbekannte Etage nennt die bekannten: ' . $r['text']);
$r = $idx->getState(['floor' => 'Erdgeschoss']);
pruefe($r['isError'] && $r['text'] === 'Unknown floor "Erdgeschoss". Known floors: (none).', 'ohne Raumliste gibt es keine Etagen: ' . $r['text']);
$r = $idxR->getState(['room' => 'Dachboden']);
pruefe($r['isError'] && str_contains($r['text'], 'Known rooms: Büro, Keller, Küche, Schlafzimmer, Wohnzimmer.') && str_ends_with($r['text'], 'Known floors: Erdgeschoss, Obergeschoss, Keller.'), 'Fehlantwort nennt auch die Etagen: ' . $r['text']);
pruefe(!str_contains($idx->getState(['room' => 'Dachboden'])['text'], 'floors'), 'ohne Raumliste bleibt die Fehlantwort, wie sie war');

$idxK = mitRaeumen($liste, $werte, $raeume, 'Küche');
$r = $idxK->getState(['type' => 'light']);
pruefe(str_starts_with($r['text'], '1 device(s) found (room=Küche, type=light).'), 'Typ-Abfrage ohne Raum meint den Standardraum, die Kopfzeile nennt ihn: ' . $r['text']);
$r = $idxK->getState([]);
pruefe(str_starts_with($r['text'], '5 device(s) found (room=Küche).'), 'ganz ohne Angaben: der Standardraum: ' . strtok($r['text'], "\n"));
$r = $idxK->getState(['room' => 'all', 'type' => 'light']);
pruefe(str_starts_with($r['text'], '3 device(s) found (type=light).'), 'room=all meint das ganze Haus: ' . strtok($r['text'], "\n"));
$r = $idxK->getState(['room' => '*']);
pruefe(str_starts_with($r['text'], '12 device(s) found (all devices).'), 'room=* ebenso: ' . strtok($r['text'], "\n"));
$r = $idxK->getState(['name' => 'Temperatur']);
pruefe(str_starts_with($r['text'], '2 device(s) found (name=Temperatur).'), 'mit Namen sucht get_state im ganzen Haus und liefert alle Treffer: ' . $r['text']);
$r = $idxK->getState(['floor' => 'Obergeschoss']);
pruefe(str_starts_with($r['text'], '2 device(s) found (floor=Obergeschoss).'), 'mit Etage gilt der Standardraum nicht: ' . strtok($r['text'], "\n"));
$r = $idxK->getState(['room' => 'Wohnzimmer', 'type' => 'light']);
pruefe(str_starts_with($r['text'], '2 device(s) found (room=Wohnzimmer, type=light).'), 'ein genannter Raum geht vor: ' . strtok($r['text'], "\n"));
$r = mitRaeumen($liste, $werte, $raeume, 'Kochbereich')->getState(['type' => 'window']);
pruefe(str_starts_with($r['text'], '1 device(s) found (room=Kochbereich, type=window).'), 'der Standardraum darf ein Alias sein: ' . strtok($r['text'], "\n"));

// dieselben neuen Texte auf Deutsch
$r = mitRaeumen($liste, $werte, $raeume, '', $deutsch)->getState(['floor' => 'Dachboden']);
pruefe($r['text'] === 'Unbekannte Etage „Dachboden“. Bekannte Etagen: Erdgeschoss, Obergeschoss, Keller.', 'deutsche Fehlantwort zur Etage: ' . $r['text']);
$r = mitRaeumen($liste, $werte, $raeume, '', $deutsch)->getState(['room' => 'Dachboden']);
pruefe(str_ends_with($r['text'], 'Bekannte Etagen: Erdgeschoss, Obergeschoss, Keller.'), 'deutsche Fehlantwort nennt die Etagen: ' . $r['text']);
index($liste, $werte, $deutsch)->getState(['floor' => 'Dachboden']); // „(keine)“ bei den Etagen

$fehlend = [];
foreach ($anlagen as $anlage) {
    $fehlend = array_merge($fehlend, $anlage->missing);
}
foreach (DeviceIndex::TYPES as $woerter) {
    foreach ($woerter ?? [] as $wort) {
        if (!isset($de[$wort])) {
            $fehlend[] = $wort;
        }
    }
}
pruefe($fehlend === [], 'jeder Text der Bibliothek hat eine deutsche Übersetzung, es fehlen: ' . implode(' / ', array_unique($fehlend)));

ende('get_state');
