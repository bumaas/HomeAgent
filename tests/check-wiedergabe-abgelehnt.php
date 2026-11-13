<?php

declare(strict_types=1);

/*
 * B110: Ein Wiedergabebefehl, den das Gerät ablehnt, gilt nicht als bestätigt. Das Sonos-Modul setzt seine
 * Wiedergabevariable schon beim Senden; lehnt das Sonos den Befehl ab (leere Warteschlange, UPnP-Fehler 701), steht
 * sie für Sekunden auf „Play“, obwohl nichts spielt. Im Vergleichslauf vom 13.11.2026 meldete set_state dreimal
 * „spielt“. Der Fehlschlag der Aktion kommt kurz nach dem Zielwert; er geht der Variable vor, und eine Bestätigung
 * zählt erst, wenn seit dem Senden eine kurze Frist ohne Fehlschlag vergangen ist.
 * Mitschnitt nuc_2026-11-13_wiedergabe_abgelehnt.json (was daran gemessen und was angenommen ist, steht in _quelle);
 * die Gegenprobe mit einem Befehl, den das Sonos annimmt, kommt aus nuc_2026-10-10_wiedergabe.json.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-13_wiedergabe_abgelehnt.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function rufe(array $mitschnitt, string $werkzeug, array $argumente, ?array $uebersetzungen = null): array
{
    global $anlage;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    try {
        $idx = new DeviceIndex($mitschnitt['geraete'], $anlage);
        return $werkzeug === 'get' ? $idx->getState($argumente) : $idx->setState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';

/* --- Abgelehntes Play ----------------------------------------------------------------------------- */

$r = rufe($mitschnitt, 'get', ['name' => 'Sonos']);
pruefe($zeile($r, 1) === 'Wintergarten | Sonos | media | stopped (volume 20 %)', 'Ausgang: das Sonos steht auf Stop, ohne Titel: ' . $r['text']);

$r = rufe($mitschnitt, 'set', ['name' => 'Sonos', 'command' => 'play']);
pruefe($anlage->requests === [[27341, 2]], 'Play geht als 2 an das Sonos-Modul: ' . json_encode($anlage->requests));
pruefe(str_starts_with($zeile($r, 0), '0 of 1 devices are now playing back'), 'die Kopfzeile zählt das Sonos nicht als in Wiedergabe: ' . $r['text']);
pruefe(str_starts_with($zeile($r, 1), 'Wintergarten | Sonos | media | failed: ') && str_contains($zeile($r, 1), 'UPnPError s:Client 701'), 'die Zeile nennt den Fehlschlag mit dem Text des Sonos-Moduls: ' . $r['text']);
pruefe(!str_contains($r['text'], 'media | playing'), 'nirgends „playing“, obwohl die Variable auf Play steht: ' . $r['text']);
pruefe($anlage->value(27341) === 2, 'die Variable zeigt bei der Antwort noch den Zielwert (das Modul glaubt ihr nicht): ' . json_encode($anlage->value(27341)));
pruefe($anlage->slept <= 250, 'die Antwort wartet nicht bis zum Rückfall der Variable: ' . $anlage->slept . ' ms');

$r = rufe($mitschnitt, 'set', ['name' => 'Sonos', 'command' => 'play'], $de);
pruefe(str_starts_with($zeile($r, 0), '0 von 1 Geräten') && str_contains($zeile($r, 1), 'fehlgeschlagen: '), 'deutsch ebenso: ' . $r['text']);

// on mit type=media setzt die Wiedergabe fort (B86) und läuft über denselben Weg
$r = rufe($mitschnitt, 'set', ['name' => 'Sonos', 'type' => 'media', 'command' => 'on']);
pruefe($anlage->requests === [[27341, 2]] && str_contains($zeile($r, 1), 'failed: ') && !str_contains($r['text'], 'media | playing'), 'on mit type=media meldet den Fehlschlag ebenso: ' . $r['text']);

// Lautstärke zusammen mit Play: die Lautstärke ist gestellt, die Wiedergabe nicht
$r = rufe($mitschnitt, 'set', ['name' => 'Sonos', 'command' => 'play', 'volume' => 30]);
pruefe(str_contains($r['text'], 'failed: ') && !str_contains($r['text'], 'media | playing'), 'mit Lautstärke zusammen bleibt der Fehlschlag stehen: ' . $r['text']);

/* --- Gegenprobe: angenommenes Play ----------------------------------------------------------------- */

$angenommen = fixture('nuc_2026-10-10_wiedergabe.json');
$r = rufe($angenommen, 'set', ['name' => 'Sonos', 'command' => 'play']);
pruefe($zeile($r, 0) === '1 of 1 devices are now playing back (name=Sonos).' && $zeile($r, 1) === 'Wintergarten | Sonos | media | playing (volume 20 %)', 'ein angenommenes Play bleibt bestätigt: ' . $r['text']);
pruefe($anlage->slept >= 200 && $anlage->slept <= 250, 'bestätigt wird erst nach der Frist für einen Fehlschlag, und nicht später: ' . $anlage->slept . ' ms');

// Über das HomeAssistant-Modul kommt der Beleg vom Gerät (Statustext nach 900 ms), dort kostet die Frist nichts
$r = rufe($angenommen, 'set', ['name' => 'Sonos über HA', 'command' => 'play']);
pruefe($zeile($r, 0) === '1 of 1 devices are now playing back (name=Sonos über HA).' && $anlage->slept <= 1000, 'über HA wie bisher nach 900 ms: ' . $anlage->slept . ' ms, ' . $r['text']);

ende('Wiedergabe abgelehnt');
