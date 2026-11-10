<?php

declare(strict_types=1);

/*
 * Werkzeug „show_camera“ (B102, Build 2): liefert das Standbild genau einer Kamera mit seiner Aufnahmezeit. Das Bild
 * kommt aus dem Medienobjekt, wie es ist (kein Auffrischen, Burkhard 10.11.2026), und wird für das Sprachmodell auf
 * höchstens 1280 Pixel Breite verkleinert: Das Bild der Hikvision am nuc hat 3840 x 2160 und 2 MB (Mitschnitt
 * nuc_2026-11-10_kamera.json), als Base64 wären das 2,7 MB je Antwort.
 * Gemessen 10.11.2026: GD gibt es im eingebetteten PHP aller drei Anlagen, das memory_limit liegt aber zwischen 32 und
 * 128 MB, und ein 4K-Bild braucht entpackt rund 33 MB. Reicht der Speicher nicht, sagt das Werkzeug ab, statt den
 * Thread abzubrechen.
 * Echt: Metadaten der Medienobjekte und das Cover des Sonos (#27512, PNG 1 x 1, 68 Byte, hier als Base64). Angenommen:
 * der Inhalt der Kamerabilder; ein echtes Kamerabild gehört nicht ins öffentliche Repo, der Test erzeugt deshalb ein
 * JPEG mit den echten Maßen 3840 x 2160.
 */

// Das lokale PHP lädt GD nicht von selbst; der Test braucht es zum Erzeugen des Bildes und fürs Verkleinern.
if (!function_exists('imagecreatetruecolor')) {
    if (getenv('HOMEAGENT_GD') === '1') {
        echo "ROT  GD lässt sich nicht laden, ohne GD prüft dieser Test nichts\nKamerabild: 0 von 1 Prüfungen grün\n";
        exit(1);
    }
    putenv('HOMEAGENT_GD=1');
    passthru(escapeshellarg(PHP_BINARY) . ' -d extension=gd ' . escapeshellarg(__FILE__), $code);
    exit($code);
}

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once dirname(__DIR__) . '/libs/CameraImage.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-10_kamera.json');
date_default_timezone_set($mitschnitt['zeitzone']);

/** JPEG mit den Maßen des echten Kamerabilds; der Inhalt (Verlauf mit Rauschen) ist angenommen. */
function testbild(int $breite, int $hoehe, int $qualitaet = 90): string
{
    $bild = imagecreatetruecolor($breite, $hoehe);
    for ($y = 0; $y < $hoehe; $y += 8) {
        imagefilledrectangle($bild, 0, $y, $breite, $y + 7, imagecolorallocate($bild, (int)(255 * $y / $hoehe), 120, 200 - (int)(150 * $y / $hoehe)));
    }
    mt_srand(4711);
    for ($i = 0; $i < 4000; $i++) {
        imagefilledrectangle($bild, $x = mt_rand(0, $breite), $y = mt_rand(0, $hoehe), $x + mt_rand(4, 60), $y + mt_rand(4, 60), imagecolorallocate($bild, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    }
    ob_start();
    imagejpeg($bild, null, $qualitaet);
    return (string)ob_get_clean();
}

$gross = testbild(3840, 2160);
$klein = testbild(640, 360);
$cover = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

/* --- CameraImage::prepare ------------------------------------------------------------------------ */

pruefe(CameraImage::MAX_WIDTH === 1280, 'Zielbreite 1280 Pixel: ' . CameraImage::MAX_WIDTH);

$r = CameraImage::prepare($gross);
$masse = isset($r['data']) ? getimagesizefromstring($r['data']) : false;
pruefe(!isset($r['error']) && $masse !== false && $masse[0] === 1280 && $masse[1] === 720 && $masse['mime'] === 'image/jpeg', '4K-Bild wird auf 1280 x 720 verkleinert, als JPEG: ' . json_encode($masse === false ? $r : [$masse[0], $masse[1], $masse['mime']]));
pruefe(($r['mimeType'] ?? '') === 'image/jpeg' && ($r['width'] ?? 0) === 1280 && ($r['height'] ?? 0) === 720 && ($r['scaled'] ?? false) === true, 'die Angaben zum Ergebnis stimmen: ' . json_encode(array_diff_key($r, ['data' => 0])));
pruefe(strlen($gross) > 1_000_000 && isset($r['data']) && strlen($r['data']) < 400_000, 'aus ' . strlen($gross) . ' Byte werden weniger als 400 KB: ' . strlen($r['data'] ?? ''));

// Ein kleines Bild bleibt, wie es ist, Byte für Byte
$r = CameraImage::prepare($klein);
pruefe(($r['data'] ?? null) === $klein && ($r['scaled'] ?? true) === false && ($r['width'] ?? 0) === 640 && ($r['mimeType'] ?? '') === 'image/jpeg', 'ein Bild bis 1280 Pixel Breite bleibt unverändert: ' . json_encode(array_diff_key($r, ['data' => 0])));
$r = CameraImage::prepare($cover);
pruefe(($r['data'] ?? null) === $cover && ($r['mimeType'] ?? '') === 'image/png' && ($r['width'] ?? 0) === 1, 'das echte PNG von 1 x 1 kommt als PNG durch: ' . json_encode(array_diff_key($r, ['data' => 0])));
// Auch ohne GD
$r = CameraImage::prepare($klein, false);
pruefe(($r['data'] ?? null) === $klein, 'ein kleines Bild braucht kein GD');

// Zu groß und nicht zu verkleinern: klare Absage mit den Maßen, kein Abbruch
$r = CameraImage::prepare($gross, false);
pruefe(($r['error'] ?? '') === 'too_large' && ($r['width'] ?? 0) === 3840 && ($r['height'] ?? 0) === 2160 && !isset($r['data']), 'ohne GD ist ein 4K-Bild zu groß: ' . json_encode($r));
// 32 MB wie an der Anlage mit dem kleinsten memory_limit: 3840 x 2160 x 4 Byte passen nicht hinein
$r = CameraImage::prepare($gross, true, 32 * 1024 * 1024);
pruefe(($r['error'] ?? '') === 'too_large' && !isset($r['data']), 'bei 32 MB freiem Speicher wird ein 4K-Bild gar nicht erst entpackt: ' . json_encode(array_diff_key($r, ['data' => 0])));
$r = CameraImage::prepare($gross, true, 64 * 1024 * 1024);
pruefe(!isset($r['error']) && ($r['width'] ?? 0) === 1280, 'bei 64 MB geht es: ' . json_encode(array_diff_key($r, ['data' => 0])));
pruefe(CameraImage::memoryNeeded(3840, 2160) > 33_000_000 && CameraImage::memoryNeeded(3840, 2160) < 64 * 1024 * 1024, 'Speicherbedarf eines 4K-Bilds liegt über den 33 MB der Pixel und unter 64 MB: ' . CameraImage::memoryNeeded(3840, 2160));
// Kein Bild
$r = CameraImage::prepare('kein Bild, nur Text');
pruefe(($r['error'] ?? '') === 'unreadable', 'Bytes, die kein Bild sind: unreadable: ' . json_encode($r));
$r = CameraImage::prepare('');
pruefe(($r['error'] ?? '') === 'unreadable', 'leerer Inhalt: unreadable: ' . json_encode($r));
// Ein Format, das ein Sprachmodell nicht annimmt (BMP), wird zu JPEG
ob_start();
imagebmp(imagecreatefromstring($klein));
$bmp = (string)ob_get_clean();
$r = CameraImage::prepare($bmp);
pruefe(($r['mimeType'] ?? '') === 'image/jpeg' && ($r['width'] ?? 0) === 640 && ($r['scaled'] ?? false) === true, 'BMP wird zu JPEG: ' . json_encode(array_diff_key($r, ['data' => 0])));
$r = CameraImage::prepare($bmp, false);
pruefe(($r['error'] ?? '') === 'format', 'BMP ohne GD: Absage wegen des Formats: ' . json_encode(array_diff_key($r, ['data' => 0])));

/* --- DeviceIndex::camera ------------------------------------------------------------------------- */

/** @var ?FakeBackend */
$anlage = null;
/** @var ?DeviceIndex */
$index = null;

function zeige(array $argumente, ?array $uebersetzungen = null, ?array $geraete = null): array
{
    global $mitschnitt, $anlage, $index, $gross, $cover;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $anlage->medien = $mitschnitt['medien'];
    // Inhalt der Bilder: angenommen für die Kamera, echt für das Cover
    $anlage->bilder = [34336 => $gross, 29094 => $gross, 27512 => $cover];
    try {
        $index = new DeviceIndex($geraete ?? $mitschnitt['geraete'], $anlage);
        return $index->camera($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

// Genau eine Kamera: Text mit Aufnahmezeit und das Bild
$r = zeige(['name' => 'Hof']);
pruefe(!$r['isError'] && $r['text'] === "1 device(s) found (type=camera, name=Hof).\nAußen | Einfahrt (Hof) | camera | image from 2026-11-10 10:44", 'eine Kamera: Kopfzeile und Zeile wie bei get_state: ' . $r['text']);
$bild = isset($r['image']['data']) ? base64_decode($r['image']['data'], true) : false;
$masse = is_string($bild) ? getimagesizefromstring($bild) : false;
pruefe(($r['image']['mimeType'] ?? '') === 'image/jpeg' && $masse !== false && $masse[0] === 1280 && $masse[1] === 720, 'das Bild kommt als Base64, verkleinert auf 1280 x 720: ' . json_encode($masse === false ? array_keys($r) : [$masse[0], $masse[1]]));
pruefe(array_keys($r) === ['text', 'isError', 'image', 'structuredContent'] && $index->problem() === null, 'Ergebnis mit text, isError, image und den Angaben für die Ansicht; kein Problem gemerkt: ' . json_encode([array_keys($r), $index->problem()]));

// Mehrere Treffer: kein Bild, die Kandidaten zur Rückfrage
$r = zeige(['room' => 'Außen']);
pruefe(!isset($r['image']) && $r['text'] === "2 cameras match room=Außen, type=camera, no image was fetched. Ask which one is meant:\nAußen | Einfahrt | camera | image from 2026-11-10 10:44\nAußen | Einfahrt letzte Bewegung | camera | image from 2026-11-10 12:06",
    'zwei Kameras im Raum: Kandidatenliste ohne Bild: ' . $r['text']);
pruefe($r['isError'] && $index->problem() === 'ambiguous', 'als mehrdeutig gemerkt, ein Fehler wie bei den Szenen: ' . json_encode([$r['isError'], $index->problem()]));
// „Einfahrt“ trifft genau den Namen, nicht auch „Einfahrt letzte Bewegung“ (beste Stufe der Namenssuche)
$r = zeige(['name' => 'Einfahrt']);
pruefe(!$r['isError'] && isset($r['image']) && str_contains($r['text'], "\nAußen | Einfahrt | camera | "), 'der genaue Name gewinnt: ' . $r['text']);
// Ohne Angabe: alle Kameras als Kandidaten
$r = zeige([]);
pruefe($r['isError'] && !isset($r['image']) && str_starts_with($r['text'], '6 cameras match type=camera, no image was fetched.'), 'ohne Namen bei mehreren Kameras: Liste: ' . $r['text']);
// Ohne Angabe bei genau einer Kamera: das Bild
$r = zeige([], null, [$mitschnitt['geraete'][0], $mitschnitt['geraete'][3]]);
pruefe(!$r['isError'] && isset($r['image']), 'eine einzige Kamera braucht keinen Namen: ' . $r['text']);

// Kein Treffer
$r = zeige(['name' => 'Garage']);
pruefe($r['isError'] && !isset($r['image']) && str_starts_with($r['text'], "No released camera matches type=camera, name=Garage. Cameras:\nAußen | Einfahrt | camera | ") && $index->problem() === 'unknown_name', 'unbekannte Kamera: Absage mit der Liste der Kameras: ' . $r['text'] . ' / ' . json_encode($index->problem()));
// Ein anderes Gerät ist keine Kamera
$r = zeige(['name' => 'Haustürschloss']);
pruefe($r['isError'] && !isset($r['image']) && str_starts_with($r['text'], 'No released camera matches type=camera, name=Haustürschloss.'), 'ein Türkontakt ist keine Kamera: ' . $r['text']);
$r = zeige(['floor' => 'Mond']);
pruefe($r['isError'] && !str_starts_with($r['text'], 'FEHLT') && !isset($r['image']), 'unbekannte Etage: Absage: ' . $r['text']);
// Gar keine Kamera freigegeben
$r = zeige(['name' => 'Einfahrt'], null, [$mitschnitt['geraete'][3]]);
pruefe($r['isError'] && $r['text'] === 'No camera is released.', 'ohne Kamera: ' . $r['text']);

// Kein Bild ist eine klare Absage
$r = zeige(['name' => 'Leere Kamera']);
pruefe($r['isError'] && !isset($r['image']) && $r['text'] === 'Camera "Leere Kamera" has no image yet.', 'leeres Medienobjekt: ' . $r['text']);
$r = zeige(['name' => 'Gelöschte Kamera']);
pruefe($r['isError'] && !isset($r['image']) && $r['text'] === 'The image of camera "Gelöschte Kamera" is not available (image 69990 is missing).', 'gelöschtes Medienobjekt: ' . $r['text']);
$r = zeige(['name' => 'Streamkamera']);
pruefe($r['isError'] && !isset($r['image']) && str_contains($r['text'], '(image 15920 is missing)'), 'ein Stream liefert kein Bild: ' . $r['text']);
// Das echte Cover: klein, kommt unverändert
$r = zeige(['room' => 'Wohnzimmer']);
pruefe(!$r['isError'] && ($r['image']['mimeType'] ?? '') === 'image/png' && base64_decode($r['image']['data'] ?? '', true) === $cover && str_ends_with($r['text'], 'image from 2026-11-04 09:09'), 'kleines PNG unverändert, mit seinem alten Datum: ' . $r['text']);
// Inhalt nicht lesbar (Datei fehlt, obwohl das Objekt ein Bild meldet)
$r = zeige(['name' => 'Wohnzimmerkamera']);
$anlage->bilder = [];
$r = $index->camera(['name' => 'Wohnzimmerkamera']);
pruefe($r['isError'] && !isset($r['image']) && $r['text'] === 'The image of camera "Wohnzimmerkamera" could not be read.', 'Inhalt nicht lesbar: ' . $r['text']);
$anlage->bilder = [27512 => 'kaputt'];
$r = $index->camera(['name' => 'Wohnzimmerkamera']);
pruefe($r['isError'] && $r['text'] === 'The image of camera "Wohnzimmerkamera" could not be read.', 'Inhalt ist kein Bild: ' . $r['text']);

/* --- Ansicht in der App: Angaben für die Seite und das Bild in Stücken --------------------------- */
// claude.ai begrenzt ein Werkzeugergebnis auf rund 150.000 Zeichen (Doku der Connectors, gelesen 10.11.2026); größere
// Ergebnisse erreichen die Seite nicht. Das Bild für das Modell hat als Base64 über 400.000 Zeichen. Die Seite holt ihr
// Bild deshalb selbst, über das Werkzeug camera_image, das nur sie sieht, in Stücken von höchstens 100.000 Zeichen und
// 960 Pixel breit. show_camera gibt ihr dazu nur, welche Kamera gemeint ist.

$r = zeige(['name' => 'Hof']);
pruefe(($r['structuredContent'] ?? null) === ['kind' => 'camera', 'images' => [['device' => '34336', 'room' => 'Außen', 'name' => 'Einfahrt', 'taken' => 1794303897]], 'timeZone' => 'Europe/Berlin',
    'labels' => ['taken' => 'Image from %s', 'loading' => 'Loading the image …', 'missing' => 'The image cannot be shown here.']],
    'show_camera nennt der Seite Kamera, Aufnahmezeit, Zeitzone und Texte, ohne das Bild zu wiederholen: ' . json_encode($r['structuredContent'] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(strlen((string)json_encode($r['structuredContent'] ?? null)) < 1000, 'die Angaben für die Seite bleiben klein');
$r = zeige(['room' => 'Außen']);
pruefe(!isset($r['structuredContent']), 'ohne Bild auch keine Angaben für die Ansicht');

pruefe(DeviceIndex::CAMERA_PART === 100000 && DeviceIndex::CAMERA_VIEW_WIDTH === 960, 'Stücke von 100.000 Zeichen, Ansicht 960 Pixel breit');
function stueck(array $argumente): array
{
    global $index;
    zeige(['name' => 'nichts']); // frischer Index und frische Anlage
    try {
        return $index->cameraPart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}
$erstes = stueck(['device' => '34336']);
$teil = $erstes['structuredContent'] ?? [];
pruefe(!$erstes['isError'] && ($teil['kind'] ?? '') === 'camera_part' && ($teil['device'] ?? '') === '34336' && ($teil['name'] ?? '') === 'Einfahrt' && ($teil['room'] ?? '') === 'Außen' && ($teil['taken'] ?? 0) === 1794303897
    && ($teil['mimeType'] ?? '') === 'image/jpeg' && ($teil['offset'] ?? -1) === 0 && ($teil['timeZone'] ?? '') === 'Europe/Berlin' && isset($teil['labels']['taken']),
    'erstes Stück mit allen Angaben zur Kamera: ' . json_encode(array_diff_key($teil, ['data' => 0]), JSON_UNESCAPED_UNICODE));
pruefe(($teil['total'] ?? 0) > 100000 && strlen((string)($teil['data'] ?? '')) === 100000, 'das 4K-Bild braucht mehrere Stücke, das erste ist voll: ' . json_encode([$teil['total'] ?? null, strlen((string)($teil['data'] ?? ''))]));
pruefe(strlen((string)json_encode($erstes)) < 150000, 'ein Stück bleibt samt Hülle unter 150.000 Zeichen: ' . strlen((string)json_encode($erstes)));
pruefe(!isset($erstes['image']) && preg_match('/^Image of camera "Einfahrt": part 1 of \d+\.$/', $erstes['text']) === 1, 'kein Bildblock, nur eine kurze Zeile: ' . $erstes['text']);
$base64 = (string)($teil['data'] ?? '');
$total = (int)($teil['total'] ?? 0);
for ($offset = 100000; $offset < $total && $offset < 2000000; $offset += 100000) {
    $weiter = stueck(['device' => '34336', 'offset' => $offset]);
    $base64 .= (string)($weiter['structuredContent']['data'] ?? '');
    if (($weiter['structuredContent']['offset'] ?? -1) !== $offset || ($weiter['structuredContent']['total'] ?? -1) !== $total) {
        $base64 = '';
        break;
    }
}
$zusammen = base64_decode($base64, true);
$masse = is_string($zusammen) && $zusammen !== '' ? getimagesizefromstring($zusammen) : false;
pruefe(strlen($base64) === $total && $masse !== false && $masse[0] === 960 && $masse[1] === 540 && $masse['mime'] === 'image/jpeg', 'die Stücke ergeben zusammen ein JPEG von 960 x 540: ' . json_encode($masse === false ? [strlen($base64), $total] : [$masse[0], $masse[1]]));
// Die Seite kennt die Kamera notfalls nur aus den Argumenten des Aufrufs (ein Host, der ihr das Ergebnis nicht gibt)
$r = stueck(['name' => 'Hof']);
pruefe(!$r['isError'] && ($r['structuredContent']['device'] ?? '') === '34336' && ($r['structuredContent']['offset'] ?? -1) === 0, 'auch über Name oder Raum wie bei show_camera: ' . $r['text']);
$r = stueck(['room' => 'Außen']);
pruefe($r['isError'] && !isset($r['structuredContent']) && str_starts_with($r['text'], '2 cameras match'), 'mehrere Treffer: Absage wie bei show_camera: ' . $r['text']);
// Fehler
$r = stueck(['device' => '11941']);
pruefe($r['isError'] && !isset($r['structuredContent']) && $r['text'] === 'No released camera has the key 11941.', 'ein Schlüssel, der keine Kamera ist: ' . $r['text']);
$r = stueck(['device' => '34336', 'offset' => 99999999]);
pruefe($r['isError'] && !isset($r['structuredContent']), 'ein Stück hinter dem Ende gibt es nicht: ' . $r['text']);
$r = stueck(['device' => '69991']);
pruefe($r['isError'] && $r['text'] === 'Camera "Leere Kamera" has no image yet.', 'leeres Medienobjekt: ' . $r['text']);
// Das kleine echte PNG kommt in einem Stück, unverändert
$r = stueck(['device' => '27512']);
pruefe(!$r['isError'] && base64_decode((string)($r['structuredContent']['data'] ?? ''), true) === $cover && ($r['structuredContent']['total'] ?? 0) === strlen(base64_encode($cover)) && ($r['structuredContent']['mimeType'] ?? '') === 'image/png',
    'kleines PNG in einem Stück: ' . json_encode(array_diff_key($r['structuredContent'] ?? [], ['data' => 0])));
// Verkleinern auf eine andere Breite
$r = CameraImage::prepare($gross, null, null, 960);
pruefe(($r['width'] ?? 0) === 960 && ($r['height'] ?? 0) === 540, 'prepare nimmt die Zielbreite als Angabe: ' . json_encode(array_diff_key($r, ['data' => 0])));

// Zu wenig Speicher zum Entpacken: Absage mit den Maßen statt eines abgebrochenen Threads
$limit = (string)ini_get('memory_limit');
ini_set('memory_limit', '40M');
$r = zeige(['name' => 'Hof']);
ini_set('memory_limit', $limit);
pruefe($r['isError'] && !isset($r['image']) && $r['text'] === 'The image of camera "Einfahrt" is too large to be prepared here (3840 x 2160); choose a smaller image at the camera or its grabber.' && $index->problem() === 'failed',
    'bei 40 MB memory_limit: Absage mit Maßen und Rat: ' . $r['text'] . ' / ' . json_encode($index->problem()));

// Übersetzung
$de =json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
foreach ([['name' => 'Hof'], ['room' => 'Außen'], ['name' => 'Garage'], ['name' => 'Leere Kamera'], ['name' => 'Gelöschte Kamera']] as $argumente) {
    $r = zeige($argumente, $de);
    $index->cameraPart(['device' => '34336']);
    $index->cameraPart(['device' => '1']);
    $index->cameraPart(['device' => '34336', 'offset' => 99999999]);
    pruefe($anlage->missing === [], 'übersetzt ' . json_encode($argumente, JSON_UNESCAPED_UNICODE) . ': ' . json_encode($anlage->missing, JSON_UNESCAPED_UNICODE));
}
$r = zeige(['room' => 'Außen'], $de);
pruefe(str_starts_with($r['text'], '2 Kameras passen zu room=Außen, type=camera, es wurde kein Bild geholt. Frag nach, welche gemeint ist:'), 'deutsch: ' . $r['text']);

ende('Kamerabild');
