<?php

declare(strict_types=1);

/*
 * Was nicht geklappt hat (Burkhard 12.10.2026): Jede Antwort, bei der der Assistent nicht alles erreicht hat,
 * trägt eine Art (problem()). Das Modul zeigt daraus im Formular eine Liste mit Hinweis, was der Anwender tun
 * kann. Ein fahrender Rollladen ist kein Problem, er ist nur noch nicht am Ziel.
 * Mitschnitt testbox_2026-10-06_schalten.json: stumme Steckdose, Lampe ohne Aktion, fahrender Rollladen.
 * Angenommen: der Raum „Bad“ in der Raumliste ohne Gerät und der Raum „Badezimmer“, den es nicht gibt.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once dirname(__DIR__) . '/libs/SceneIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$fx = fixture('testbox_2026-10-06_schalten.json');
$raeume = new RoomIndex([['Name' => 'Bad', 'Aliases' => '', 'Floor' => '']]);

/** Art des Problems nach einem Aufruf; 'FEHLT', solange es problem() nicht gibt. */
function art(string $methode, array $argumente): array
{
    global $fx, $raeume;
    $index = new DeviceIndex($fx['geraete'], new FakeBackend($fx['variablen']), $raeume);
    $antwort = $index->$methode($argumente);
    return [method_exists($index, 'problem') ? $index->problem() : 'FEHLT', $antwort];
}

$fall = static function (string $methode, array $argumente, ?string $erwartet, string $text): void {
    [$art, $antwort] = art($methode, $argumente);
    pruefe($art === $erwartet, $text . ': ' . var_export($art, true) . ' – ' . strtok($antwort['text'], "\n"));
};

/* --- Nicht gefunden ------------------------------------------------------------------------------ */

$fall('getState', ['room' => 'Badezimmer'], 'unknown_room', 'Raum, den es nicht gibt');
$fall('setState', ['room' => 'Badezimmer', 'type' => 'light', 'command' => 'on'], 'unknown_room', 'Raum, den es nicht gibt, beim Schalten');
$fall('setState', ['room' => 'Bad', 'type' => 'light', 'command' => 'on'], 'no_match', 'Raum aus der Raumliste ohne passendes Gerät');
$fall('setState', ['name' => 'Stehlampe', 'command' => 'on'], 'unknown_name', 'Name, den es nicht gibt');
$fall('getState', ['name' => 'Stehlampe'], 'unknown_name', 'Name, den es nicht gibt, beim Abfragen');
$fall('setState', ['room' => 'Küche', 'name' => 'Esstisch', 'command' => 'on'], 'no_match', 'Name gibt es, aber nicht in diesem Raum');
$fall('setState', ['name' => 'Lampe', 'command' => 'on'], 'ambiguous', 'Name trifft mehrere Geräte');
$fall('setState', ['floor' => 'Dachgeschoss', 'command' => 'on'], 'unknown_floor', 'Etage, die es nicht gibt');

/* --- Gefunden, aber nicht alles erreicht --------------------------------------------------------- */

$fall('setState', ['name' => 'Testlampe', 'command' => 'open'], 'not_understood', 'Befehl, den das Gerät nicht kann');
$fall('setState', ['room' => 'Testraum', 'command' => 'open'], 'left_out', 'Lampen und Steckdose im Testraum können nicht „öffnen“ und werden ausgelassen, die Rollläden fahren');
$fall('setState', ['name' => 'Stumme Steckdose', 'command' => 'on'], 'not_confirmed', 'Gerät bestätigt nicht');
$fall('setState', ['name' => 'Lampe ohne Aktion', 'command' => 'on'], 'failed', 'Aktion schlägt fehl');
$fall('setState', ['room' => 'Testraum', 'type' => 'light', 'command' => 'on'], 'failed', 'in der Gruppe zählt der Fehlschlag vor dem Rest');
$fall('setState', ['command' => 'on'], 'invalid', 'Anfrage ohne Auswahl');

/* --- Kein Problem -------------------------------------------------------------------------------- */

$fall('setState', ['name' => 'Testlampe', 'command' => 'on'], null, 'bestätigtes Einschalten');
$fall('setState', ['name' => 'Fahrender Rollladen', 'percent' => 50], null, 'ein fahrender Rollladen ist nur noch nicht am Ziel');
$fall('getState', ['room' => 'Testraum'], null, 'Abfrage mit Treffern');

/* --- Szenen -------------------------------------------------------------------------------------- */

$szene = static function (array $argumente) use ($fx): ?string {
    $index = new SceneIndex($fx['szenen'], new FakeBackend([], null, $fx['skripte']));
    $index->runScene($argumente);
    return method_exists($index, 'problem') ? $index->problem() : 'FEHLT';
};
pruefe($szene(['name' => 'Kino']) === 'unknown_scene', 'Szene, die es nicht gibt: ' . var_export($szene(['name' => 'Kino']), true));
pruefe($szene(['name' => 'Leselicht']) === null, 'gestartete Szene ist kein Problem: ' . var_export($szene(['name' => 'Leselicht']), true));

ende('Was nicht geklappt hat');
