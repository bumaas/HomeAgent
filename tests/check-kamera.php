<?php

declare(strict_types=1);

/*
 * Typ „camera“ (B102, Build 1): Eine Kamera ist ein Gerät mit einem Standbild, einem Medienobjekt vom Typ Bild. get_state
 * nennt sie als Text mit der Aufnahmezeit des Bildes; das Bild selbst liefert erst show_camera (nächster Build). Das Modul
 * frischt nichts auf (Burkhard 10.11.2026: es könnte das letzte Bewegungsbild gewünscht sein), es nennt, von wann das
 * Bild ist. Anlass: Claude-App 10.11.2026, „Gibt es eine Kamera?“ („kennt keinen Gerätetyp für Kameras“).
 * Mitschnitt nuc_2026-11-10_kamera.json: Metadaten der Medienobjekte echt, die Bilder liegen nicht im Repo; was
 * angenommen ist, steht in „_quelle“.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-10_kamera.json');
date_default_timezone_set($mitschnitt['zeitzone']);

/** @var ?FakeBackend */
$anlage = null;

function frage(array $argumente, string $werkzeug = 'get', ?array $uebersetzungen = null): array
{
    global $mitschnitt, $anlage;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $anlage->medien = $mitschnitt['medien'];
    try {
        $index = new DeviceIndex($mitschnitt['geraete'], $anlage);
        return match ($werkzeug) {
            'set'   => $index->setState($argumente),
            'chart' => $index->chart($argumente),
            'cards' => $index->cards($argumente, false),
            default => $index->getState($argumente),
        };
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

/* --- der Typ ------------------------------------------------------------------------------------- */

pruefe(array_key_exists('camera', DeviceIndex::TYPES) && DeviceIndex::TYPES['camera'] === null, 'camera ist ein Typ ohne an/aus');
pruefe((DeviceIndex::ROLES['image'] ?? null) === 'ImageMediaID', 'die Fähigkeit image steht in der Zeile als ImageMediaID: ' . json_encode(DeviceIndex::ROLES['image'] ?? null));
pruefe((DeviceIndex::TYPE_ROLES['camera'] ?? null) === ['roles' => ['image'], 'required' => ['image']], 'camera hat genau die Pflichtfähigkeit image: ' . json_encode(DeviceIndex::TYPE_ROLES['camera'] ?? null));
pruefe(!in_array('camera', DeviceIndex::CONTROLLABLE, true), 'eine Kamera lässt sich nicht schalten');
pruefe(DeviceIndex::rolesOf($mitschnitt['geraete'][0]) === ['image' => 34336], 'rolesOf liest das Medienobjekt: ' . json_encode(DeviceIndex::rolesOf($mitschnitt['geraete'][0])));
pruefe(DeviceIndex::rolesOf(['Type' => 'light', 'StateVariableID' => 12345, 'ImageMediaID' => 34336]) === ['state' => 12345], 'ein Bild an einem Licht zählt nicht: ' . json_encode(DeviceIndex::rolesOf(['Type' => 'light', 'StateVariableID' => 12345, 'ImageMediaID' => 34336])));
pruefe(DeviceIndex::abilitiesOf($mitschnitt['geraete'][0]) === [], 'keine Befehle für eine Kamera');
pruefe(!DeviceIndex::isMeasured($mitschnitt['geraete'][0]) && !DeviceIndex::hasTimeline($mitschnitt['geraete'][0]), 'weder Verlauf noch Zeitleiste');

/* --- get_state: Text mit Aufnahmezeit ------------------------------------------------------------ */

// Die Frage aus dem Chat, jetzt mit Kamera
$r = frage(['type' => 'camera', 'room' => 'Außen']);
pruefe(!$r['isError'] && $r['text'] === "2 device(s) found (room=Außen, type=camera).\n"
    . "Außen | Einfahrt | camera | image from 2026-11-10 10:44\n"
    . 'Außen | Einfahrt letzte Bewegung | camera | image from 2026-11-10 12:06', 'zwei Kameras draußen, jede mit der Aufnahmezeit ihres Bildes: ' . $r['text']);
$r = frage(['name' => 'Hof']);
pruefe(!$r['isError'] && $r['text'] === "1 device(s) found (name=Hof).\nAußen | Einfahrt (Hof) | camera | image from 2026-11-10 10:44", 'über den Alias gefunden, der Alias steht wie bei jedem Gerät dabei: ' . $r['text']);
// Der Zustand sagt nie, es sei ein Livebild
pruefe(!str_contains($r['text'], 'live') && !str_contains($r['text'], 'idle'), 'kein Wort über live oder idle: ' . $r['text']);
// Ein altes Bild nennt sein Datum (Sonos-Cover vom 04.11.)
$r = frage(['room' => 'Wohnzimmer']);
pruefe(!$r['isError'] && str_ends_with($r['text'], 'Wohnzimmer | Wohnzimmerkamera | camera | image from 2026-11-04 09:09'), 'ein altes Bild steht mit seinem Datum da: ' . $r['text']);

// Kein Bild ist eine klare Auskunft, kein leerer Zustand
$r = frage(['room' => 'Keller']);
$zeilen = explode("\n", $r['text']);
pruefe(!$r['isError'] && ($zeilen[0] ?? '') === '3 device(s) found (room=Keller).', 'drei Kameras im Keller: ' . $r['text']);
pruefe(($zeilen[1] ?? '') === 'Keller | Leere Kamera | camera | no image available', 'leeres Medienobjekt: kein Bild vorhanden: ' . ($zeilen[1] ?? ''));
pruefe(($zeilen[2] ?? '') === 'Keller | Gelöschte Kamera | camera | not available (image 69990 is missing)', 'gelöschtes Medienobjekt: nicht verfügbar mit ID: ' . ($zeilen[2] ?? ''));
pruefe(($zeilen[3] ?? '') === 'Keller | Streamkamera | camera | not available (image 15920 is missing)', 'ein Stream ist kein Bild: ' . ($zeilen[3] ?? ''));

// Gemischt mit anderen Geräten, ohne Filter
$r = frage(['room' => 'all']);
pruefe(!$r['isError'] && str_starts_with($r['text'], '7 device(s) found (') && str_contains($r['text'], "\nFlur | Haustürschloss | door | open") && str_contains($r['text'], "\nAußen | Einfahrt | camera | image from 2026-11-10 10:44\n"), 'Kameras stehen neben den anderen Geräten: ' . $r['text']);
// active_only: Kameras kennen kein an/aus und werden wie Messwerte genannt
$r = frage(['room' => 'all', 'active_only' => true]);
pruefe(!$r['isError'] && str_starts_with($r['text'], '1 of 1 checked devices active (') && str_contains($r['text'], '6 device(s) without on/off were not counted (camera).'), 'active_only zählt Kameras nicht mit und sagt es: ' . $r['text']);
$r = frage(['type' => 'camera', 'active_only' => true]);
pruefe(!$r['isError'] && str_starts_with($r['text'], '6 device(s) found (type=camera). None of them knows on/off') && str_contains($r['text'], 'image from 2026-11-10 10:44'), 'nur Kameras mit active_only: alle mit Zustand: ' . $r['text']);

/* --- Zeitraum, Leistung, Diagramm: nichts davon gibt es für eine Kamera -------------------------- */

foreach ([[['type' => 'camera', 'period' => 'today'], 'None of the matching devices measures energy (type=camera, period=today).'],
    [['name' => 'Einfahrt', 'period' => 'now'], 'None of the matching devices measures power (name=Einfahrt, period=now).'],
    [['name' => 'Einfahrt', 'from' => '2026-11-09'], 'None of the matching devices measures energy (name=Einfahrt, from=2026-11-09, to=2026-11-09).']] as [$argumente, $absage]) {
    $r = frage($argumente);
    pruefe($r['isError'] && $r['text'] === $absage, 'Zeitraum ' . json_encode($argumente) . ': Absage wie bei jedem Gerät ohne Zähler: ' . $r['text']);
}
$r = frage(['name' => 'Einfahrt', 'period' => 'today'], 'chart');
pruefe($r['isError'] && !str_starts_with($r['text'], 'FEHLT') && !isset($r['structuredContent']), 'kein Diagramm für eine Kamera: ' . $r['text']);

/* --- set_state: eine Kamera versteht keinen Befehl ----------------------------------------------- */

$r = frage(['name' => 'Einfahrt', 'command' => 'on'], 'set');
pruefe($r['isError'] && !str_starts_with($r['text'], 'FEHLT') && $anlage->requests === [], 'on an eine Kamera wird abgewiesen, nichts ausgelöst: ' . $r['text']);
$r = frage(['room' => 'Außen', 'command' => 'off'], 'set');
pruefe(!str_starts_with($r['text'], 'FEHLT') && $anlage->requests === [], 'off im Raum mit nur Kameras löst nichts aus: ' . $r['text']);

/* --- show_devices: Karte ohne Bedienung ---------------------------------------------------------- */

$r = frage(['room' => 'Außen'], 'cards');
$karten = $r['structuredContent']['cards'] ?? [];
pruefe(!$r['isError'] && count($karten) === 2, 'zwei Karten: ' . $r['text']);
$karte = $karten[0] ?? [];
pruefe(($karte['key'] ?? null) === '34336' && ($karte['type'] ?? null) === 'camera' && ($karte['controls'] ?? null) === [] && array_key_exists('active', $karte) && $karte['active'] === null,
    'Karte mit dem Medienobjekt als Schlüssel, ohne Bedienelemente, weder an noch aus: ' . json_encode($karte, JSON_UNESCAPED_UNICODE));
pruefe(($karte['text'] ?? null) === 'image from 2026-11-10 10:44' && ($karte['status'] ?? null) === 'image from 2026-11-10 10:44' && array_key_exists('figure', $karte) && $karte['figure'] === null,
    'die Karte nennt die Aufnahmezeit als Status, ohne Hauptwert: ' . json_encode($karte, JSON_UNESCAPED_UNICODE));
pruefe(($karten[1]['key'] ?? null) === '29094', 'jede Kamera hat ihren eigenen Schlüssel: ' . json_encode($karten[1]['key'] ?? null));

/* --- Übersetzung --------------------------------------------------------------------------------- */

$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
$r = frage(['room' => 'Keller'], 'get', $de);
pruefe($anlage->missing === [], 'alle Texte sind übersetzt: ' . json_encode($anlage->missing, JSON_UNESCAPED_UNICODE));
pruefe(str_contains($r['text'], 'Keller | Leere Kamera | camera | kein Bild vorhanden') && str_contains($r['text'], 'nicht verfügbar (Bild 69990 fehlt)'), 'deutsche Texte: ' . $r['text']);
$r = frage(['name' => 'Einfahrt letzte Bewegung'], 'get', $de);
pruefe($anlage->missing === [] && str_ends_with($r['text'], 'Außen | Einfahrt letzte Bewegung | camera | Bild von 2026-11-10 12:06'), 'deutsch: Bild von …: ' . $r['text']);

ende('Kamera');
