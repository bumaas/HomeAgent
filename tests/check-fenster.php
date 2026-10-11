<?php

declare(strict_types=1);

/*
 * Fenster mit Antrieb in der Abfrage kenntlich machen. Anlass (11.10.2026): Die Claude-App konnte auf
 * „Ist ein manuelles Fenster offen?“ und „Welche Fenster kannst du öffnen?“ nicht antworten, weil alle
 * 21 Fensterzeilen gleich aussahen. Fenster, die set_state fahren kann, tragen jetzt „with drive“.
 * Mitschnitt nuc_2026-10-11_fenster.json: alle Fensterzeilen des nuc, 15 nur mit Kontakt, 6 Kippfenster
 * mit Antrieb (Küche links und Schlafen West gekippt).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-11_fenster.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function frage(array $liste, array $werte, array $argumente, ?array $uebersetzungen = null, bool $schalten = false): array
{
    try {
        $idx = new DeviceIndex($liste, new FakeBackend($werte, $uebersetzungen));
        return $schalten ? $idx->setState($argumente) : $idx->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeilen = static fn (array $r): array => array_slice(explode("\n", $r['text']), 1);

$r = frage($liste, $werte, ['type' => 'window']);
$mitAntrieb = array_values(array_filter($zeilen($r), static fn (string $z): bool => str_contains($z, 'with drive')));
pruefe(count($mitAntrieb) === 6, 'sechs Fenster mit Antrieb gekennzeichnet: ' . $r['text']);
pruefe(in_array('Küche | Fenster links | window | tilted (with drive)', $zeilen($r), true), 'gekippt mit Antrieb: ' . $r['text']);
pruefe(in_array('Flur | Fenster | window | closed (with drive)', $zeilen($r), true), 'geschlossen mit Antrieb: ' . $r['text']);
pruefe(in_array('Dachboden | Giebelfenster | window | closed', $zeilen($r), true), 'nur Kontakt bleibt ohne Zusatz: ' . $r['text']);

$r = frage($liste, $werte, ['type' => 'window', 'active_only' => true], $de);
pruefe(str_starts_with($r['text'], '2 von 21 geprüften Geräten aktiv') && in_array('Schlafen | Westfenster | window | gekippt (mit Antrieb)', $zeilen($r), true), 'offene Fenster auf Deutsch: ' . $r['text']);

// Nur abfragen: Das Werkzeug kann es nicht fahren, also kein Hinweis.
$gesperrt = array_map(static fn (array $g): array => ['ReadOnly' => true] + $g, $liste);
$r = frage($gesperrt, $werte, ['room' => 'Küche', 'type' => 'window']);
pruefe(!str_contains($r['text'], 'with drive'), 'mit „nur abfragen“ kein Hinweis: ' . $r['text']);

// Die Antwort auf einen Fahrbefehl bleibt ohne Zusatz, dort ist der Antrieb selbstverständlich.
$r = frage($liste, $werte, ['room' => 'Flur', 'type' => 'window', 'command' => 'close'], null, true);
pruefe(!str_contains($r['text'], 'with drive'), 'set_state ohne Hinweis: ' . $r['text']);

/* --- Zählzeile (build 68, Backlog B09) ------------------------------------------------------------ */
// Anlass: Die Claude-App schrieb im neuen Chat erneut „Fünf Fenster haben einen Antrieb“ und zählte dann sechs auf.

$letzte = static fn (array $r): string => (string)array_last(explode("\n", $r['text']));
$r = frage($liste, $werte, ['type' => 'window']);
pruefe($letzte($r) === '6 of 21 windows have a drive.', 'das Werkzeug zählt die Fenster mit Antrieb: ' . $letzte($r));
$r = frage($liste, $werte, ['type' => 'window'], $de);
pruefe($letzte($r) === '6 von 21 Fenstern haben einen Antrieb.', 'auf Deutsch: ' . $letzte($r));
$r = frage($liste, $werte, ['room' => 'Küche', 'type' => 'window']);
pruefe($letzte($r) === '1 of 2 windows have a drive.', 'in der Küche eins von zwei: ' . $r['text']);
$r = frage($liste, $werte, ['room' => 'Flur', 'type' => 'window']);
pruefe(!str_contains($r['text'], 'have a drive'), 'nur Fenster mit Antrieb: keine Zählzeile: ' . $r['text']);
$r = frage($liste, $werte, ['room' => 'Gast', 'type' => 'window']);
pruefe(!str_contains($r['text'], 'have a drive'), 'nur Kontakte: keine Zählzeile: ' . $r['text']);
$r = frage($liste, $werte, ['type' => 'window', 'active_only' => true]);
pruefe(!str_contains($r['text'], 'have a drive'), 'die beiden offenen haben beide einen Antrieb: keine Zählzeile: ' . $r['text']);
$r = frage($gesperrt, $werte, ['type' => 'window']);
pruefe(!str_contains($r['text'], 'have a drive'), 'mit „nur abfragen“ keine Zählzeile: ' . $r['text']);

pruefe(isset($de['with drive']), 'deutsche Übersetzung für „with drive“');
pruefe(isset($de['%d of %d windows have a drive.']), 'deutsche Übersetzung der Zählzeile');

ende('Fenster mit Antrieb');
