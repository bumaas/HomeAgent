<?php

declare(strict_types=1);

/*
 * Vorzeichen eines Leistungssensors der Bilanz umkehren (B77, Burkhard 14.10.2026). Das Modul folgt bei einem
 * einzelnen Sensor mit Vorzeichen Home Assistant: Netzbezug negativ heißt Einspeisung, Batterie entladen negativ
 * heißt laden. Der Marstek Venus E einer betreuten Anlage meldet umgekehrt, positiv heißt laden. Mitschnitt
 * 14.10.2026 mit drei Zeitpunkten (laden, entladen, einspeisen); das Orakel ist der Verbrauch, den ein Skript des
 * Anwenders als Netz + PV − Batterie rechnet und der an diesen Zeitpunkten ohne Rest aufgeht.
 *
 * Echt: alle Werte und die Richtung der Batterie. Angenommen: ein Netzsensor, der Einspeisung positiv meldet; dafür
 * ist der echte Netzwert mit umgekehrtem Vorzeichen eingesetzt.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('betreut_2026-10-14_batterie.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$bilanz = $mitschnitt['bilanzLeistung'];

/** Variablen des Mitschnitts zu einem Zeitpunkt, wahlweise mit geänderten Werten. */
function werte(array $mitschnitt, string $zeitpunkt, array $aenderung = []): array
{
    $variablen = [];
    foreach ($mitschnitt['zeitpunkte'][$zeitpunkt]['werte'] as $id => $wert) {
        $variablen[(string)$id] = ['name' => $mitschnitt['namen'][$id], 'wert' => (float)($aenderung[$id] ?? $wert), 'einheit' => $mitschnitt['einheiten'][$id]];
    }
    return $variablen;
}

function index(array $variablen, array $bilanz, array $umgekehrt): DeviceIndex
{
    $index = new DeviceIndex([], new FakeBackend($variablen), null, '', [], $bilanz);
    if (method_exists($index, 'setInvertedPower')) {
        $index->setInvertedPower($umgekehrt);
    }
    return $index;
}

function erste(DeviceIndex $index): string
{
    try {
        return explode("\n", $index->getState(['period' => 'now'])['text'])[0];
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage();
    }
}

function richtung(DeviceIndex $index): mixed
{
    try {
        return method_exists($index, 'signedPowers') ? $index->signedPowers() : null;
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage();
    }
}

$orakel = static fn (string $z): string => (string)(int)round($mitschnitt['zeitpunkte'][$z]['werte']['45303']);

/* --- Batterie mit umgekehrtem Vorzeichen: die Hausleistung trifft den Verbrauch des Skripts ------------------- */

$text = erste(index(werte($mitschnitt, 'laden'), $bilanz, ['discharge']));
pruefe($text === 'House power ' . $orakel('laden') . ' W (all devices, period=now): grid import 1, solar 1504, battery discharge 0, battery charge 1112 W.', 'laden (Marstek +1112 W) zählt als Batterie geladen, 393 W wie das Skript: ' . $text);
$text = erste(index(werte($mitschnitt, 'entladen'), $bilanz, ['discharge']));
pruefe($text === 'House power ' . $orakel('entladen') . ' W (all devices, period=now): grid import 9, solar 631, battery discharge 637 W.', 'entladen (Marstek −637 W) zählt als Batterie entladen, 1277 W wie das Skript: ' . $text);
$text = erste(index(werte($mitschnitt, 'einspeisen'), $bilanz, ['discharge']));
pruefe($text === 'House power ' . $orakel('einspeisen') . ' W (all devices, period=now): grid import 0, solar 734, battery discharge 5, grid export 475 W.', 'Einspeisung über den Netzsensor bleibt, die Batterie dreht trotzdem: ' . $text);

/* --- Ohne Haken bleibt es bei der Konvention (so stand es vor B77) ---------------------------------------------- */

$text = erste(index(werte($mitschnitt, 'laden'), $bilanz, []));
pruefe($text === 'House power 2617 W (all devices, period=now): grid import 1, solar 1504, battery discharge 1112 W.', 'ohne Haken zählt +1112 W als Entladen: ' . $text);

/* --- Netzsensor mit umgekehrtem Vorzeichen (angenommen: Einspeisung positiv) ---------------------------------- */

$umgedreht = werte($mitschnitt, 'einspeisen', ['16895' => 474.801]);
$text = erste(index($umgedreht, $bilanz, ['import', 'discharge']));
pruefe($text === 'House power ' . $orakel('einspeisen') . ' W (all devices, period=now): grid import 0, solar 734, battery discharge 5, grid export 475 W.', 'Netz umgekehrt: +475 W zählen als Einspeisung: ' . $text);

/* --- Nur Sensoren mit Vorzeichen lassen sich umkehren ----------------------------------------------------------- */

// Getrennte Sensoren für Laden und Entladen melden beide positiv; ein Haken änderte dort nur Unsinn.
$getrennt = werte($mitschnitt, 'laden', ['31051' => 0.0]);
$getrennt['45303']['wert'] = 1112.0; // als Ladesensor eingesetzt (angenommen)
$text = erste(index($getrennt, ['import' => 16895, 'solar' => 49040, 'discharge' => 31051, 'charge' => 45303], ['discharge']));
pruefe($text === 'House power 393 W (all devices, period=now): grid import 1, solar 1504, battery discharge 0, battery charge 1112 W.', 'mit eigenem Ladefeld wirkt der Haken nicht: ' . $text);
$text = erste(index(werte($mitschnitt, 'laden'), $bilanz, ['solar', 'export', 'charge', 'unbekannt']));
pruefe($text === 'House power 2617 W (all devices, period=now): grid import 1, solar 1504, battery discharge 1112 W.', 'andere Schlüssel werden ignoriert: ' . $text);

/* --- Für den Selbsttest: Rohwert und wie er zählt --------------------------------------------------------------- */

$r = richtung(index(werte($mitschnitt, 'laden'), $bilanz, ['discharge']));
pruefe($r === [
    ['key' => 'import', 'variable' => 16895, 'raw' => 1.077, 'counted' => 'import', 'watts' => 1.077, 'inverted' => false],
    ['key' => 'discharge', 'variable' => 31051, 'raw' => 1112.0, 'counted' => 'charge', 'watts' => 1112.0, 'inverted' => true],
], 'signedPowers nennt je Sensor mit Vorzeichen den Rohwert und wie er zählt: ' . json_encode($r));
$r = richtung(index(werte($mitschnitt, 'entladen'), $bilanz, ['discharge']));
pruefe(($r[1]['counted'] ?? null) === 'discharge' && ($r[1]['watts'] ?? null) === 637.0, 'entladen: zählt als discharge 637 W: ' . json_encode($r));
$r = richtung(index($getrennt, ['import' => 16895, 'discharge' => 31051, 'charge' => 45303], ['discharge']));
pruefe(is_array($r) && array_column($r, 'key') === ['import'], 'mit eigenem Ladefeld ist die Batterie kein Sensor mit Vorzeichen: ' . json_encode($r));

ende('Vorzeichen der Leistungsbilanz');
