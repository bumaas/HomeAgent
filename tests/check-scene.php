<?php

declare(strict_types=1);

/*
 * Werkzeug „run_scene“ gegen die Testszenen der Testbox (Mitschnitt 06.10.2026): zwei Skripte
 * unter _Testbereich und der Fehlertext von IPS_RunScript für ein fehlendes Skript. Geprüft
 * wird auf Deutsch über die locale.json; die an der Anlage beobachteten Antworten müssen
 * wortgleich herauskommen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/SceneIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$fx = fixture('testbox_2026-10-06_schalten.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
$fehlend = [];

/** @return array{0: SceneIndex, 1: FakeBackend} */
function szenen(?array $liste = null, ?array $skripte = null): array
{
    global $fx, $de;
    $backend = new FakeBackend([], $de, $skripte ?? $fx['skripte']);
    return [new SceneIndex($liste ?? $fx['szenen'], $backend), $backend];
}

function merke(FakeBackend $backend): void
{
    global $fehlend;
    $fehlend = array_merge($fehlend, $backend->missing);
}

/* --- An der Anlage beobachtete Antworten, wortgleich ------------------------------------- */

[$idx, $b] = szenen();
$r = $idx->runScene(['name' => 'Leselicht']);
pruefe($r['text'] === $fx['beobachtet']['run_scene name=Leselicht']['text'], 'Szene starten wie beobachtet: ' . $r['text']);
pruefe(!$r['isError'] && $b->started === [37149], 'genau das eine Skript wird gestartet: ' . json_encode($b->started));
pruefe($b->slept === 0, 'auf das Ende der Szene wird nicht gewartet');
merke($b);

[$idx, $b] = szenen();
$r = $idx->runScene([]);
pruefe($r['text'] === $fx['beobachtet']['run_scene']['text'], 'ohne Namen: Liste wie beobachtet: ' . $r['text']);
pruefe(!$r['isError'] && $b->started === [], 'ohne Namen wird nichts gestartet');
merke($b);

[$idx, $b] = szenen();
$r = $idx->runScene(['name' => 'Kino']);
pruefe($r['text'] === $fx['beobachtet']['run_scene name=Kino']['text'], 'unbekannte Szene wie beobachtet: ' . $r['text']);
pruefe($r['isError'] && $b->started === [], 'unbekannte Szene startet nichts');
merke($b);

/* --- Namenssuche -------------------------------------------------------------------------- */

[$idx, $b] = szenen();
$idx->runScene(['name' => 'feierabend']);
$idx->runScene(['name' => 'Lese']);
$idx->runScene(['name' => 'ALLES AUS', 'room' => 'testraum']);
pruefe($b->started === [52232, 37149, 52232], 'Alias, Teilwort und Großschreibung mit Raum finden die Szene: ' . json_encode($b->started));
merke($b);

$doppelt = array_merge($fx['szenen'], [['Room' => 'Küche', 'Name' => 'Alles aus', 'Aliases' => '', 'ScriptID' => 37149]]);
[$idx, $b] = szenen($doppelt);
$r = $idx->runScene(['name' => 'Alles aus']);
pruefe($r['isError'] && $b->started === [], 'gleicher Name in zwei Räumen startet nichts');
pruefe(str_starts_with($r['text'], 'Nichts gestartet: 2 Szenen passen zu name=Alles aus.') && str_contains($r['text'], "\nKüche | Alles aus"), 'und nennt die Kandidaten: ' . $r['text']);
$r = $idx->runScene(['name' => 'Alles aus', 'room' => 'Küche']);
pruefe(!$r['isError'] && $b->started === [37149], 'mit Raum ist es eindeutig: ' . json_encode($b->started));
$r = $idx->runScene(['name' => 'Alles aus', 'room' => 'Keller']);
pruefe($r['isError'] && str_contains($r['text'], 'room=Keller, name=Alles aus'), 'falscher Raum: kein Treffer, Filter wird genannt: ' . $r['text']);
merke($b);

/* --- Fehler der Anlage -------------------------------------------------------------------- */

// Skript nach dem Eintragen gelöscht
[$idx, $b] = szenen(null, ['52232' => $fx['skripte']['52232']]);
$r = $idx->runScene(['name' => 'Leselicht']);
pruefe($r['isError'] && $b->started === [] && str_contains($r['text'], 'Das Skript 37149 fehlt'), 'gelöschtes Skript wird benannt: ' . $r['text']);
merke($b);

// IPS_RunScript lehnt ab: der Text der Anlage geht in die Antwort
[$idx, $b] = szenen(null, ['37149' => ['name' => 'Testszene Leselicht', 'fehlertext' => $fx['fehlertexte']['skriptFehlt']]]);
$r = $idx->runScene(['name' => 'Leselicht']);
pruefe($r['isError'] && str_ends_with($r['text'], 'ließ sich nicht starten: ' . $fx['fehlertexte']['skriptFehlt']), 'Fehlschlag mit dem Text der Anlage: ' . $r['text']);
merke($b);

[$idx, $b] = szenen([]);
$r = $idx->runScene(['name' => 'Leselicht']);
pruefe($r['isError'] && $r['text'] === 'Es ist keine Szene freigegeben.', 'leere Szenenliste: ' . $r['text']);
pruefe($idx->labels() === [], 'ohne Szenen keine Namen für die Werkzeugbeschreibung');
merke($b);

[$idx] = szenen(array_merge($fx['szenen'], [['Room' => '', 'Name' => 'Gute Nacht', 'Aliases' => '', 'ScriptID' => 37149]]));
pruefe($idx->labels() === ['Leselicht (Testraum)', 'Alles aus (Testraum)', 'Gute Nacht'], 'Namen für die Werkzeugbeschreibung, Raum nur wo eingetragen: ' . json_encode($idx->labels(), JSON_UNESCAPED_UNICODE));

// Namen und Räume, von denen nach dem Normalisieren nichts übrig bleibt (Sicherheitsreview
// 06.10.2026): Vorher traf name=„-“ jede Szene, und die einzige Szene eines Raums startete.
foreach (['-', '.', ' _ ', "\u{00A0}"] as $leer) {
    $zeigen = json_encode($leer);
    [$idx, $b] = szenen([$fx['szenen'][0]]);
    $r = $idx->runScene(['name' => $leer]);
    pruefe($r['isError'] && $b->started === [], "name=$zeigen startet nicht die einzige Szene: " . json_encode($b->started));
    [$idx, $b] = szenen();
    $r = $idx->runScene(['name' => 'Leselicht', 'room' => $leer]);
    pruefe($r['isError'] && $b->started === [], "room=$zeigen hebt den Raumfilter nicht auf: " . json_encode($b->started));
    merke($b);
}
[$idx, $b] = szenen(array_merge($fx['szenen'], [['Room' => '', 'Name' => 'Leselicht', 'Aliases' => '', 'ScriptID' => 52232]]));
$r = $idx->runScene(['name' => 'Leselicht', 'room' => '-']);
pruefe(!$r['isError'] && $b->started === [52232], 'room=- meint die Szene ohne Raum: ' . json_encode($b->started) . ' ' . $r['text']);
merke($b);

// Raumliste: Eine Szene wird auch über ein Alias ihres Raums gefunden
require_once dirname(__DIR__) . '/libs/RoomIndex.php';
$b = new FakeBackend([], $de, $fx['skripte']);
$idx = new SceneIndex($doppelt, $b, new RoomIndex([['Name' => 'Küche', 'Aliases' => 'Kochbereich', 'Floor' => 'Erdgeschoss']]));
$r = $idx->runScene(['name' => 'Alles aus', 'room' => 'Kochbereich']);
pruefe(!$r['isError'] && $b->started === [37149], 'Raum-Alias wählt die Szene der Küche: ' . json_encode($b->started) . ' ' . $r['text']);
$r = $idx->runScene(['name' => 'Alles aus', 'room' => 'Speisekammer']);
pruefe($r['isError'] && $b->started === [37149], 'ein unbekannter Raum startet nichts');
merke($b);

pruefe($fehlend === [], 'jeder verwendete Text hat eine deutsche Übersetzung, es fehlen: ' . implode(' / ', array_unique($fehlend)));

ende('run_scene');
