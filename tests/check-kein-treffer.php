<?php

declare(strict_types=1);

/*
 * „Kein Treffer“ ist bei einer Abfrage eine Auskunft, kein Fehlschlag (Claude-App 10.11.2026: „Gibt es eine Kamera?“
 * ergab die richtige Antwort „Nein“, die App zeigte aber „Fehlgeschlagen“ mit Warndreieck, weil get_state isError
 * setzte). Mittelweg (Burkhard 10.11.2026): Ist der Filter in sich gültig, kommt die Zeile ohne Fehlerkennung; ein
 * unbekannter Raum oder eine unbekannte Etage bleibt ein Fehler, dort hilft dem Modell ein zweiter Versuch mit der
 * Raumliste. Schalten und Diagramm bleiben Fehler, dort ist nichts geschehen.
 * Geräteliste und Werte aus dem Mitschnitt nuc_2026-10-13_zeitleiste.json (echt, nur gelesen).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-13_zeitleiste.json');
date_default_timezone_set($mitschnitt['zeitzone']);

/** @var ?DeviceIndex */
$index = null;

function frage(array $argumente, string $werkzeug = 'get'): array
{
    global $mitschnitt, $index;
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage);
    try {
        return match ($werkzeug) {
            'set'   => $index->setState($argumente),
            'chart' => $index->chart($argumente),
            'cards' => $index->cards($argumente, false),
            default => $index->getState($argumente),
        };
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

/* --- gültiger Filter ohne Treffer: Auskunft ------------------------------------------------------ */

// Die Frage aus dem Chat
$r = frage(['name' => 'Kamera']);
pruefe(!$r['isError'], '„Gibt es eine Kamera?“ ist kein Fehlschlag: ' . $r['text']);
pruefe(str_starts_with($r['text'], 'No released device matches name=Kamera. ') && str_contains($r['text'], 'Known rooms: ') && str_contains($r['text'], 'Existing types: '), 'der Text bleibt, mit Räumen und Typen: ' . $r['text']);
pruefe($index->problem() === 'unknown_name', 'die Liste „Was nicht geklappt hat“ führt den Aufruf weiter: ' . json_encode($index->problem()));
// Bekannter Raum, bekannter Typ, nur dort nichts freigegeben (im Keller gibt es im Mitschnitt kein Fenster)
$r = frage(['room' => 'Keller', 'type' => 'window', 'active_only' => true]);
pruefe(!$r['isError'] && str_starts_with($r['text'], 'No released device matches room=Keller, type=window'), 'bekannter Raum ohne solches Gerät: Auskunft: ' . $r['text']);
pruefe($index->problem() === 'no_match', 'als no_match gemerkt: ' . json_encode($index->problem()));

/* --- unbekannter Raum, unbekannte Etage: weiter ein Fehler --------------------------------------- */

$r = frage(['room' => 'Schlafzimmer', 'type' => 'light']);
pruefe($r['isError'] && str_starts_with($r['text'], 'No released device matches room=Schlafzimmer, type=light.') && $index->problem() === 'unknown_room', 'unbekannter Raum bleibt ein Fehler mit Raumliste: ' . $r['text']);
$r = frage(['room' => 'Schlafzimmer', 'name' => 'Kamera']);
pruefe($r['isError'] && $index->problem() === 'unknown_room', 'unbekannter Raum geht vor unbekanntem Namen: ' . $r['text']);
$r = frage(['floor' => 'Mond']);
pruefe($r['isError'] && $index->problem() === 'unknown_floor', 'unbekannte Etage bleibt ein Fehler: ' . $r['text']);

/* --- Schalten und Diagramm: weiter ein Fehler ---------------------------------------------------- */

$r = frage(['name' => 'Kamera', 'command' => 'on'], 'set');
pruefe($r['isError'], 'set_state ohne Treffer bleibt ein Fehler, geschaltet wurde nichts: ' . $r['text']);
$r = frage(['name' => 'Kamera', 'period' => 'yesterday'], 'chart');
pruefe($r['isError'] && !isset($r['structuredContent']), 'show_chart ohne Treffer bleibt ein Fehler, es gibt kein Diagramm: ' . $r['text']);

/* --- Karten: Auskunft ohne Karten ---------------------------------------------------------------- */

$r = frage(['name' => 'Kamera'], 'cards');
pruefe(!$r['isError'] && ($r['structuredContent']['cards'] ?? null) === [], 'show_devices ohne Treffer: kein Fehlschlag, keine Karten: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
pruefe(str_starts_with($r['text'], 'No released device matches name=Kamera.') && !str_contains($r['text'], 'shown as cards'), 'und kein Satz über gezeigte Karten: ' . $r['text']);
$r = frage(['room' => 'Schlafzimmer'], 'cards');
pruefe($r['isError'] && !isset($r['structuredContent']), 'show_devices mit unbekanntem Raum bleibt ein Fehler: ' . $r['text']);

ende('Kein Treffer');
