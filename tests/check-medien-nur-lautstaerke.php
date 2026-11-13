<?php

declare(strict_types=1);

/*
 * Mediaplayer nur mit Lautstärke: Die Zeile lautete „Bad | Echo | media | (Lautstärke 40 %)“, ein Zustand aus einer
 * leeren Stelle und einer Klammer. Im Vergleichslauf vom 13.11.2026 sagte das Modell dazu „der Echo hat keinen Status
 * angegeben“ (Blindtest 12.11.2026, Befund 2). Hat ein Mediaplayer sonst nichts, ist die Lautstärke sein Zustand, so
 * wie die Leistung bei einem Gerät, das nur sie hat.
 * Mitschnitt nuc_2026-11-13_echo_nur_lautstaerke.json (was daran angenommen ist, steht in _quelle).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-13_echo_nur_lautstaerke.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function frage(array $mitschnitt, array $argumente, ?array $uebersetzungen = null): array
{
    try {
        return (new DeviceIndex($mitschnitt['geraete'], new FakeBackend($mitschnitt['variablen'], $uebersetzungen)))->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';

$r = frage($mitschnitt, ['room' => 'Bad', 'type' => 'media']);
pruefe($zeile($r, 1) === 'Bad | Echo (Echo Dot, Alexa) | media | volume 40 %' || $zeile($r, 1) === 'Bad | Echo | media | volume 40 %', 'nur Lautstärke: sie ist der Zustand, ohne Klammer: ' . $r['text']);
pruefe(!str_contains($r['text'], '| (') && !str_contains($r['text'], '| -'), 'kein leerer Zustand vor der Klammer und kein Strich: ' . $r['text']);
$r = frage($mitschnitt, ['room' => 'Bad', 'type' => 'media'], $de);
pruefe(str_ends_with($zeile($r, 1), '| media | Lautstärke 40 %'), 'deutsch ebenso: ' . $r['text']);
$r = frage($mitschnitt, ['room' => 'Gast', 'type' => 'media']);
pruefe(str_ends_with($zeile($r, 1), '| media | volume 40 %, muted'), 'mit Stummschaltung beides ohne Klammer: ' . $r['text']);

// an/aus kennt so ein Player weiter nicht
$r = frage($mitschnitt, ['room' => 'Bad', 'active_only' => true]);
pruefe(!str_contains($r['text'], '1 of 1'), 'bei active_only zählt er nicht als aktiv: ' . $r['text']);

// Gegenprobe: Ein Player mit Wiedergabe behält die Klammer (Mitschnitt vom 10.10.2026)
$sonos = fixture('nuc_2026-10-10_wiedergabe.json');
$r = frage($sonos, ['name' => 'Sonos']);
pruefe($zeile($r, 1) === 'Wintergarten | Sonos | media | paused (volume 20 %)', 'mit Wiedergabe steht die Lautstärke weiter in der Klammer: ' . $r['text']);

ende('Mediaplayer nur mit Lautstärke');
