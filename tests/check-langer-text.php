<?php

declare(strict_types=1);

/*
 * Lange Texte in get_state (t/144611 #79, 03.11.2026): Eine Wettervorhersage mit 216 Zeichen als „sonstiger
 * Messwert“ endete nach 200 Zeichen mit „…“. Trifft die Abfrage genau ein Gerät, kommt der Text jetzt ganz,
 * höchstens FULL_TEXT_LENGTH Zeichen; in Listen bleibt die Grenze von 200 Zeichen je Zeile.
 * Echt sind beide Textvariablen (Mitschnitt nuc 03.11.2026: Reinigungen des Roborock, 329 Zeichen, und die
 * letzten Änderungen der Matter Diagnose, 224 Zeichen). Angenommen ist nur der überlange Text am Ende, der den
 * echten Roborock-Text wiederholt.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$werte = fixture('nuc_2026-11-03_langer-text.json')['variablen'];
$liste = [
    ['Room' => 'Keller', 'Name' => 'Saugroboter Reinigungen', 'Aliases' => '', 'Type' => 'measurement', 'StateVariableID' => 34365],
    ['Room' => 'Keller', 'Name' => 'Matter Änderungen', 'Aliases' => '', 'Type' => 'measurement', 'StateVariableID' => 43624],
];
$einzeilig = static fn (string $text): string => (string)preg_replace('/ {2,}/', ' ', trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text)));
$roborock = $einzeilig($werte['34365']['formatiert']);
$matter = $einzeilig($werte['43624']['formatiert']);

$idx = new DeviceIndex($liste, new FakeBackend($werte));

// Genau ein Gerät: der ganze Text
$r = $idx->getState(['name' => 'Saugroboter Reinigungen']);
pruefe(!$r['isError'], 'Abfrage nach Namen ist kein Fehler: ' . $r['text']);
pruefe(str_contains($r['text'], 'Keller | Saugroboter Reinigungen | measurement | ' . $roborock), 'ein Gerät: Text mit ' . mb_strlen($roborock) . ' Zeichen kommt ganz: ' . $r['text']);
pruefe(!str_contains($r['text'], '…'), 'ein Gerät: kein „…“ am Ende: ' . $r['text']);

$r = $idx->getState(['name' => 'Matter Änderungen']);
pruefe(str_contains($r['text'], '| ' . $matter), 'ein Gerät: Text mit ' . mb_strlen($matter) . ' Zeichen kommt ganz: ' . $r['text']);

// Mehrere Geräte: je Zeile höchstens 200 Zeichen Text
$r = $idx->getState(['room' => 'Keller']);
pruefe(!$r['isError'] && substr_count($r['text'], ' | measurement | ') === 2, 'Raum liefert beide Geräte: ' . $r['text']);
pruefe(!str_contains($r['text'], $roborock) && !str_contains($r['text'], $matter), 'Liste: keiner der Texte kommt ganz: ' . $r['text']);
foreach (explode("\n", $r['text']) as $zeile) {
    if (str_contains($zeile, ' | measurement | ')) {
        $text = substr($zeile, strpos($zeile, ' | measurement | ') + strlen(' | measurement | '));
        pruefe(mb_strlen($text) <= 200 && str_ends_with($text, '…'), 'Liste: Text gekürzt auf höchstens 200 Zeichen: ' . $zeile);
    }
}

// Auch ein einzelnes Gerät hat eine Obergrenze (angenommener Text: der echte zehnmal hintereinander)
$lang = $werte;
$lang['34365']['formatiert'] = $lang['34365']['wert'] = implode("\n", array_fill(0, 10, $werte['34365']['wert']));
$r = (new DeviceIndex($liste, new FakeBackend($lang)))->getState(['name' => 'Saugroboter Reinigungen']);
$text = substr($r['text'], strpos($r['text'], ' | measurement | ') + strlen(' | measurement | '));
$text = (string)strtok($text, "\n");
pruefe(mb_strlen($text) > 200 && mb_strlen($text) <= 2000 && str_ends_with($text, '…'), 'ein Gerät mit überlangem Text: gekürzt auf höchstens 2000 Zeichen, nicht 200 (' . mb_strlen($text) . ')');

ende('Lange Texte');
