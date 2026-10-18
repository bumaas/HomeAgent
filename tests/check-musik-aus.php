<?php

declare(strict_types=1);

/*
 * „Musik aus“ an einem Mediaplayer ohne Ein/Aus (B86): off pausiert, on setzt fort. Anlass Vergleichslauf B04
 * (18.10.2026, Satz „Musik im Wintergarten aus und Licht im Wohnzimmer aus“): set_state command=off am Sonos
 * Wintergarten wurde abgewiesen, das Modell brauchte dreimal von drei eine zweite Runde mit stop.
 * Mitschnitt nuc_2026-10-10_wiedergabe.json: Sonos-Modul (nur Wiedergabe) und HomeAssistant-Modul (Statustext ohne
 * Aktion, Befehlsvariable -1). Ein Player mit schaltbarem Ein/Aus (Denon, check-receiver.php) schaltet weiter aus.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-10_wiedergabe.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$receiver = fixture('nuc_2026-10-11_receiver.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function rufe(array $liste, array $werte, array $argumente, ?array $uebersetzungen = null): array
{
    global $anlage;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    try {
        return (new DeviceIndex($liste, $anlage))->setState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$gesendet = static function (int $variable): array {
    global $anlage;
    return array_values(array_map(static fn (array $q): mixed => $q[1], array_filter($anlage->requests, static fn (array $q): bool => $q[0] === $variable)));
};
// Beide spielen: Sonos-Modul Status 2, HA-Statustext „playing“.
$spielen = $werte;
$spielen['27341']['wert'] = 2;
$spielen['45169']['wert'] = 'playing';

/* --- Angebot -------------------------------------------------------------------------------------- */

pruefe(DeviceIndex::abilitiesOf($liste[0]) === ['on', 'off', 'stop', 'play', 'pause', 'next', 'previous', 'volume', 'volume_change', 'mute'], 'Sonos ohne Ein/Aus versteht on und off: ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));

/* --- off pausiert --------------------------------------------------------------------------------- */

$r = rufe($liste, $spielen, ['name' => 'Sonos', 'command' => 'off']);
pruefe(!$r['isError'] && $gesendet(27341) === [3], 'off schickt Pause (3) an das Sonos-Modul: ' . json_encode($anlage->requests) . ' ' . $r['text']);
pruefe($zeile($r, 0) === '1 of 1 devices are now paused (name=Sonos).', 'die Antwort sagt pausiert: ' . $r['text']);
pruefe(array_keys($anlage->rememberedPaused()) === [27341], 'gemerkt für play ohne Raum: ' . json_encode($anlage->rememberedPaused()));
$r = rufe($liste, $spielen, ['name' => 'Sonos', 'command' => 'off'], $de);
pruefe($zeile($r, 0) === '1 von 1 Geräten sind jetzt pausiert (name=Sonos).', 'auf Deutsch: ' . $r['text']);
$r = rufe($liste, $werte, ['name' => 'Sonos', 'command' => 'off']);
pruefe(!$r['isError'] && $anlage->requests === [] && $zeile($r, 1) === 'Wintergarten | Sonos | media | already paused', 'schon pausiert, nichts gesendet: ' . $r['text']);

/* --- on setzt fort -------------------------------------------------------------------------------- */

$r = rufe($liste, $werte, ['name' => 'Sonos', 'type' => 'media', 'command' => 'on']);
pruefe(!$r['isError'] && $gesendet(27341) === [2] && $zeile($r, 0) === '1 of 1 devices are now playing back (type=media, name=Sonos).', 'on mit type=media schickt Play (2): ' . $r['text']);

// Nur mit type=media (Burkhard 18.10.2026): „Wintergarten an“ soll keine Musik starten (Review 18.10.2026, Frage 1).
$r = rufe($liste, $werte, ['name' => 'Sonos', 'command' => 'on']);
pruefe($r['isError'] && $anlage->requests === [] && str_contains($r['text'], 'type=media'), 'on ohne type setzt nicht fort und nennt type=media: ' . $r['text']);
$r = rufe($liste, $werte, ['name' => 'Sonos', 'command' => 'on'], $de);
pruefe($r['isError'] && str_contains($r['text'], 'type=media') && !str_contains($r['text'], 'Nothing'), 'auf Deutsch: ' . $r['text']);
$raum = array_merge($liste, $receiver['geraete']);
$raum[count($raum) - 1]['Room'] = 'Wintergarten';
$r = rufe($raum, array_replace($werte, $receiver['variablen']), ['room' => 'Wintergarten', 'command' => 'on']);
pruefe($gesendet(27341) === [] && $gesendet(11085) === [] && $gesendet(35575) === [true], 'room=Wintergarten command=on schaltet den Receiver ein, die Player ohne Ein/Aus spielen nicht: ' . json_encode($anlage->requests) . ' ' . $r['text']);
pruefe(str_contains($r['text'], '2 device(s) of the selection do not understand "on" and were left out.'), 'die zwei Player sind ausgelassen und gezählt: ' . $r['text']);
$r = rufe($liste, $spielen, ['room' => 'Wintergarten', 'command' => 'off']);
pruefe($gesendet(27341) === [3] && $gesendet(11085) === [3], 'off ohne type pausiert weiter: ' . json_encode($anlage->requests));

/* --- Statustext des HomeAssistant-Moduls ---------------------------------------------------------- */

// Der Statustext ist kein Schalter (check-receiver.php); mit Wiedergabe pausiert off über die Befehlsvariable.
$r = rufe($liste, $spielen, ['name' => 'Sonos über HA', 'command' => 'off']);
pruefe($gesendet(11085) === [3] && $gesendet(45169) === [], 'off über HA: Pause an die Befehlsvariable, der Statustext bleibt: ' . json_encode($anlage->requests));
pruefe($zeile($r, 0) === '0 of 1 devices are now paused, 1 not confirmed yet (name=Sonos über HA).', 'über HA kam „paused“ in 3 s nicht: ' . $r['text']);

/* --- Gruppe und Satz aus dem Vergleichslauf ------------------------------------------------------- */

$r = rufe($liste, $spielen, ['room' => 'Wintergarten', 'type' => 'media', 'command' => 'off']);
pruefe($gesendet(27341) === [3] && $gesendet(11085) === [3], 'room=Wintergarten type=media command=off pausiert beide in einem Aufruf: ' . json_encode($anlage->requests) . ' ' . $r['text']);

/* --- Player mit Ein/Aus und Wiedergabe schaltet weiter aus ---------------------------------------- */

$an = $receiver['variablen'];
$an['35575']['wert'] = true;
unset($an['35575']['formatiert']);
$r = rufe($receiver['geraete'], $an, ['name' => 'Receiver', 'command' => 'off']);
pruefe($anlage->requests === [[35575, false]] && $zeile($r, 0) === '1 of 1 devices are now off (name=Receiver).', 'Receiver mit Schaltzustand: weiter aus: ' . $r['text']);

ende('Musik aus');
