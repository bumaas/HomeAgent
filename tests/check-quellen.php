<?php

declare(strict_types=1);

/*
 * Weitere Quellen der Energiebilanz (Backlog B82 Stufe b, Wunsch Micha87 im Forum 17.10.2026: drei PV-Anlagen mit je
 * einem Zähler). Neben den Feldern der Bilanz nimmt eine Liste weitere Netzanschlüsse, PV-Anlagen und Batterien auf,
 * wie die Quellen im Energie-Dashboard von Home Assistant; die Bilanz zählt je Art alle zusammen.
 *
 * Orakel 1: Ein Zähler in der Liste statt im Feld ändert nichts (nuc_2026-10-17_zaehler.json, echte Werte und echte
 * Anordnung), bei der Leistung ebenso mit Vorzeichen und Umkehr (betreut_2026-10-14_batterie.json).
 * Orakel 2: Zwei Quellen derselben Art zählen zusammen. Dafür steht der Tibber Pulse (#30012, #44499) als zweiter
 * Netzanschluss in der Liste. Die Werte sind echt, die Anordnung ist angenommen: Er misst denselben Anschluss wie der
 * SMA Home Manager. Echte Daten mehrerer PV-Anlagen gibt es noch nicht.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-17_zaehler.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$bilanz = $mitschnitt['bilanz'];
$geraete = array_values(array_filter($mitschnitt['geraete'], static fn (array $g): bool => $g['Type'] !== 'meter'));

/** Antwort und Diagramm mit Bilanzfeldern und weiteren Quellen. */
function frage(array $felder, array $quellen, array $argumente, bool $diagramm = false): array
{
    global $mitschnitt, $geraete;
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($geraete, $anlage, null, '', $felder);
        if ($quellen !== []) {
            if (!method_exists($index, 'addSources')) {
                return ['text' => 'FEHLT: addSources', 'chart' => null];
            }
            $index->addSources($quellen);
        }
        if ($diagramm) {
            $r = $index->chart($argumente);
            return ['text' => $r['text'], 'chart' => $r['structuredContent'] ?? null];
        }
        return ['text' => $index->getState($argumente)['text'], 'chart' => null];
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'chart' => null];
    }
}

function gestern(int $id): float
{
    global $mitschnitt;
    $heute = (int)strtotime('today', $mitschnitt['jetzt']);
    $v = $mitschnitt['variablen'][(string)$id];
    $summe = 0.0;
    foreach ($v['aggregate']['1'] as $a) {
        if ($a['zeit'] >= (int)strtotime('-1 day', $heute) && $a['zeit'] <= $heute - 1) {
            $summe += $a['summe'];
        }
    }
    return strtolower($v['einheit']) === 'wh' ? $summe / 1000 : $summe;
}

function zahl(float $wert): string
{
    return rtrim(rtrim(number_format($wert, 1, '.', ''), '0'), '.');
}

$ohne = static fn (array $schluessel): array => array_diff_key($GLOBALS['bilanz'], array_flip($schluessel));

/* --- Orakel 1: ein Zähler in der Liste statt im Feld ---------------------------------------------- */

foreach ([['period' => 'yesterday'], ['period' => 'last_month'], ['from' => '2026-10-16 08:00', 'to' => '2026-10-16 12:00']] as $argumente) {
    $soll = frage($bilanz, [], $argumente)['text'];
    $faelle = [
        'PV'       => [$ohne(['solar']), [['Type' => 'solar', 'Name' => 'Dach', 'EnergyIn' => $bilanz['solar']]]],
        'Batterie' => [$ohne(['charge', 'discharge']), [['Type' => 'battery', 'Name' => 'Keller', 'EnergyIn' => $bilanz['discharge'], 'EnergyOut' => $bilanz['charge']]]],
        'Netz'     => [$ohne(['import', 'export']), [['Type' => 'grid', 'Name' => 'Hausanschluss', 'EnergyIn' => $bilanz['import'], 'EnergyOut' => $bilanz['export']]]],
        'alles'    => [[], [['Type' => 'grid', 'Name' => 'Hausanschluss', 'EnergyIn' => $bilanz['import'], 'EnergyOut' => $bilanz['export']],
            ['Type' => 'solar', 'Name' => 'Dach', 'EnergyIn' => $bilanz['solar']], ['Type' => 'battery', 'Name' => 'Keller', 'EnergyIn' => $bilanz['discharge'], 'EnergyOut' => $bilanz['charge']]]],
    ];
    foreach ($faelle as $was => [$felder, $quellen]) {
        $ist = frage($felder, $quellen, $argumente)['text'];
        pruefe($ist === $soll, $was . ' in der Liste, ' . json_encode($argumente) . ': ' . strtok($ist, "\n") . ' ‖ erwartet ' . strtok($soll, "\n"));
    }
}
$soll = frage($bilanz, [], ['period' => 'yesterday'], true)['chart'];
$ist = frage($ohne(['solar']), [['Type' => 'solar', 'Name' => 'Dach', 'EnergyIn' => $bilanz['solar']]], ['period' => 'yesterday'], true)['chart'];
pruefe($soll !== null && $ist === $soll, 'das Diagramm des Hausverbrauchs bleibt gleich, PV in der Liste');

/* --- Orakel 2: zwei Netzanschlüsse ---------------------------------------------------------------- */

$tibber = ['Type' => 'grid', 'Name' => 'Tibber', 'EnergyIn' => 30012, 'EnergyOut' => 44499];
$zwei = explode("\n", frage($bilanz, [$tibber], ['period' => 'yesterday'])['text']);
$bezug = gestern(43989) + gestern(30012);
$einspeisung = gestern(47491) + gestern(44499);
$haus = $bezug + gestern(10639) + gestern(13436) - $einspeisung - gestern(17418);
$soll = sprintf('House consumption %s kWh (all devices, period=yesterday): grid import %s (of this Tibber %s), solar %s, battery discharge %s, grid export %s (of this Tibber %s), battery charge %s kWh.',
    zahl($haus), zahl($bezug), zahl(gestern(30012)), zahl(gestern(10639)), zahl(gestern(13436)), zahl($einspeisung), zahl(gestern(44499)), zahl(gestern(17418)));
pruefe($zwei[0] === $soll, 'zwei Netzanschlüsse zählen zusammen, die Zeile nennt den weiteren: ' . $zwei[0] . ' ‖ erwartet ' . $soll);

/* --- Leistung mit Vorzeichen (betreute Anlage) ---------------------------------------------------- */

$betreut = fixture('betreut_2026-10-14_batterie.json');
$leistung = $betreut['bilanzLeistung'];
foreach ($betreut['zeitpunkte'] as $zeitpunkt => $daten) {
    $variablen = [];
    foreach ($daten['werte'] as $id => $wert) {
        $variablen[(string)$id] = ['name' => $betreut['namen'][$id], 'wert' => (float)$wert, 'einheit' => $betreut['einheiten'][$id]];
    }
    $mitFeld = new DeviceIndex([], new FakeBackend($variablen), null, '', [], $leistung);
    $mitFeld->setInvertedPower(['discharge']);
    $soll = explode("\n", $mitFeld->getState(['period' => 'now'])['text'])[0];
    try {
        $mitListe = new DeviceIndex([], new FakeBackend($variablen), null, '', [], array_diff_key($leistung, ['discharge' => 0]));
        $mitListe->addSources([['Type' => 'battery', 'Name' => 'Marstek', 'PowerIn' => $leistung['discharge'], 'Invert' => true]]);
        $ist = explode("\n", $mitListe->getState(['period' => 'now'])['text'])[0];
        $richtung = $mitListe->signedPowers();
    } catch (Throwable $e) {
        $ist = 'FEHLT: ' . $e->getMessage();
        $richtung = [];
    }
    pruefe($ist === $soll, "Batterie mit Vorzeichen in der Liste, umgekehrt ($zeitpunkt): $ist ‖ erwartet $soll");
    pruefe(count($richtung) === 2 && ($richtung[1]['source'] ?? '') === 'Marstek' && $richtung[1]['inverted'] === true && $richtung[1]['variable'] === $leistung['discharge'], "der Selbsttest nennt den Sensor der Liste mit Namen ($zeitpunkt): " . json_encode($richtung));
}

/* --- Prüfung der Liste ---------------------------------------------------------------------------- */

$pruefe = static fn (array $quellen): array => method_exists(DeviceIndex::class, 'sourceProblems') ? DeviceIndex::sourceProblems($quellen) : ['fehlt'];
pruefe($pruefe([$tibber, ['Type' => 'solar', 'Name' => 'Garage', 'EnergyIn' => 10639]]) === [], 'gültige Quellen: keine Fehler');
pruefe($pruefe([['Type' => 'solar', 'Name' => 'Garage', 'EnergyIn' => 10639, 'EnergyOut' => 47491]]) === [0 => 'solar_out'], 'PV hat keinen zweiten Zähler');
pruefe($pruefe([['Type' => 'solar', 'Name' => 'Garage', 'PowerIn' => 14114, 'PowerOut' => 35568]]) === [0 => 'solar_out'], 'PV hat keine zweite Leistung');
pruefe($pruefe([['Type' => 'grid', 'Name' => 'leer']]) === [0 => 'empty'], 'eine Quelle ohne Variable');
pruefe($pruefe([['Type' => 'wind', 'Name' => 'Rad', 'EnergyIn' => 10639]]) === [0 => 'unknown_type'], 'unbekannte Art');

ende('Weitere Quellen der Energiebilanz');
