<?php

declare(strict_types=1);

/*
 * Namenssuche bei zusammengesetzten Wörtern. Anlass am nuc (10.10.2026): name=Außentemperatur traf
 * 14 Geräte „Temperatur“, weil „temperatur“ irgendwo in der Anfrage stand. Ein Gerätename zählt in
 * der Anfrage nur, wenn er an einem Wortanfang steht: „Heizkörperthermostat“ trifft „Heizkörper“,
 * „Außentemperatur“ trifft „Temperatur“ nicht. Geräte und Werte aus dem Mitschnitt vom nuc
 * (nuc_2026-10-10_verlauf.json); der Eintrag „Garten“ mit Alias „Außentemperatur“ ist dort
 * Testkonfiguration auf einer echten Variable und wird für den Fall am nuc weggelassen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-10_verlauf.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$alle = $mitschnitt['geraete'];
$ohneGarten = array_values(array_filter($alle, static fn (array $g): bool => $g['Room'] !== 'Garten'));

function frage(array $liste, array $argumente): array
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    return (new DeviceIndex($liste, $anlage))->getState($argumente);
}

$kopf = static fn (array $r): string => explode("\n", $r['text'])[0];

/* --- der Fall vom nuc: kein Gerät heißt so -------------------------------------------------------- */

$r = frage($ohneGarten, ['name' => 'Außentemperatur']);
pruefe($r['isError'] && str_starts_with($r['text'], 'No released device matches name=Außentemperatur.'), 'Außentemperatur trifft kein Gerät „Temperatur“: ' . $r['text']);
$r = frage($ohneGarten, ['name' => 'Aussentemperatur']);
pruefe($r['isError'] && str_starts_with($r['text'], 'No released device matches name=Aussentemperatur.'), 'ebenso ohne ß: ' . $kopf($r));
$r = frage($ohneGarten, ['name' => 'Raumtemperatur']);
pruefe($r['isError'], 'Raumtemperatur trifft kein Gerät „Temperatur“: ' . $kopf($r));

/* --- mit einem Gerät, das so heißt, bleibt es beim genauen Treffer ---------------------------------- */

$r = frage($alle, ['name' => 'Außentemperatur']);
pruefe(!$r['isError'] && $kopf($r) === '1 device(s) found (name=Außentemperatur).', 'mit Alias genau ein Gerät: ' . $r['text']);

/* --- was weiter treffen muss ------------------------------------------------------------------------ */

$r = frage($ohneGarten, ['name' => 'Heizkörperthermostat']);
pruefe(!$r['isError'] && $kopf($r) === '1 device(s) found (name=Heizkörperthermostat).' && str_contains($r['text'], 'Wintergarten | Heizkörper |'), 'Name am Wortanfang einer Zusammensetzung trifft: ' . $r['text']);
$r = frage($ohneGarten, ['name' => 'Temperatur Keller']);
pruefe(!$r['isError'] && str_starts_with($kopf($r), '3 device(s) found'), 'Name als ganzes Wort in der Anfrage trifft weiter: ' . $kopf($r));
$r = frage($ohneGarten, ['name' => 'Temp']);
pruefe(!$r['isError'] && str_starts_with($kopf($r), '3 device(s) found'), 'Teilwort des Namens trifft weiter: ' . $kopf($r));
$r = frage($ohneGarten, ['name' => 'Wandthermostat']);
pruefe(!$r['isError'] && str_contains($r['text'], 'Heizung (Wandthermostat)'), 'Alias trifft weiter: ' . $r['text']);

/* --- dieselbe Regel für die Trefferstufe selbst ----------------------------------------------------- */

pruefe(DeviceIndex::nameMatchQuality('Temperatur', '', DeviceIndex::normalize('Außentemperatur')) === 0, 'Stufe 0 für „Temperatur“ in „Außentemperatur“');
pruefe(DeviceIndex::nameMatchQuality('Esstisch', '', DeviceIndex::normalize('Esstischlampe')) === 1, 'Stufe 1 für „Esstisch“ in „Esstischlampe“');
pruefe(DeviceIndex::nameMatchQuality('Deckenlicht', '', DeviceIndex::normalize('Deckenlicht Küche')) === 1, 'Stufe 1 für „Deckenlicht“ in „Deckenlicht Küche“');
pruefe(DeviceIndex::nameMatchQuality('Licht', '', DeviceIndex::normalize('Küchen-Licht')) === 1, 'Stufe 1, wenn ein Bindestrich die Wörter trennt');

ende('Namenssuche');
