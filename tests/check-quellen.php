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
 * SMA Home Manager.
 * Orakel 3 (Stufe c, betreut_2026-10-17_quellen.json, echte Werte und echte Anordnung: zwei Wechselrichter, ein
 * ausgebauter und ein neuer Netzzähler nacheinander): Text, Stapel und Teile rechnet der Test selbst aus den Aggregaten.
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
// Dieselbe Variable in zwei Zeilen zählte doppelt, ohne Hinweis (Review 18.10.2026, M4: Hausverbrauch 1231,8 statt 441,1 kWh).
// Gemeldet wird die spätere Zeile; ebenso zwei Felder einer Zeile mit derselben Variable.
pruefe($pruefe([['Type' => 'solar', 'Name' => 'Dach', 'EnergyIn' => 10639], $tibber, ['Type' => 'solar', 'Name' => 'Dach nochmal', 'EnergyIn' => 10639]]) === [2 => 'duplicate'], 'dieselbe PV in zwei Zeilen: ' . json_encode($pruefe([['Type' => 'solar', 'Name' => 'Dach', 'EnergyIn' => 10639], $tibber, ['Type' => 'solar', 'Name' => 'Dach nochmal', 'EnergyIn' => 10639]])));
pruefe($pruefe([['Type' => 'grid', 'Name' => 'Netz', 'EnergyIn' => 43989, 'EnergyOut' => 43989]]) === [0 => 'duplicate'], 'Bezug und Einspeisung mit derselben Variable');
pruefe($pruefe([['Type' => 'grid', 'Name' => 'Netz', 'EnergyIn' => 43989, 'PowerIn' => 24942], ['Type' => 'battery', 'Name' => 'Akku', 'PowerIn' => 24942]]) === [1 => 'duplicate'], 'dieselbe Leistung an Netz und Batterie');

/* --- Mehrere PV-Anlagen und zwei Netzzähler nacheinander (betreute Anlage, Stufe c) -------------- */

// Echte Werte und echte Anordnung: zwei Wechselrichter, ein ausgebauter und ein neuer Netzzähler, eine Batterie
$zwei = fixture('betreut_2026-10-17_quellen.json');

function zweiIndex(): DeviceIndex
{
    global $zwei;
    $anlage = new FakeBackend($zwei['variablen']);
    $anlage->jetzt = $zwei['jetzt'];
    $index = new DeviceIndex([], $anlage, null, '');
    $index->addSources($zwei['quellen']);
    return $index;
}

/** kWh je Abschnitt eines Zählers der Fixture (Stufe, Beginn ab, Beginn bis einschließlich). */
function abschnitte(int $id, string $stufe, int $von, int $bis): array
{
    global $zwei;
    $werte = [];
    foreach ($zwei['variablen'][(string)$id]['aggregate'][$stufe] as $a) {
        if ($a['zeit'] >= $von && $a['zeit'] <= $bis) {
            $werte[$a['zeit']] = (float)$a['summe'];
        }
    }
    return $werte;
}

$september = [(int)strtotime('2026-09-01'), (int)strtotime('2026-09-30')];
$summe = static fn (int ...$ids): float => array_sum(array_map(static fn (int $id): float => array_sum(abschnitte($id, '1', ...$GLOBALS['september'])), $ids));
$huawei = $summe(25173);
$hoymiles = $summe(53548);
$bezug = $summe(11557, 51590);
$einspeisung = $summe(11314, 18681);
$haus = $bezug + $huawei + $hoymiles + $summe(53433) - $einspeisung - $summe(42220);
$soll = sprintf('House consumption %s kWh (all devices, from=2026-09-01, to=2026-09-30): grid import %s (of this Netz %s, Netz bis 05/2026 %s), solar %s (of this Huawei %s, Hoymiles %s), battery discharge %s, grid export %s (of this Netz %s, Netz bis 05/2026 %s), battery charge %s kWh.',
    zahl($haus), zahl($bezug), zahl($summe(11557)), zahl($summe(51590)), zahl($huawei + $hoymiles), zahl($huawei), zahl($hoymiles), zahl($summe(53433)),
    zahl($einspeisung), zahl($summe(11314)), zahl($summe(18681)), zahl($summe(42220)));
$ist = strtok(zweiIndex()->getState(['from' => '2026-09-01', 'to' => '2026-09-30'])['text'], "\n");
pruefe($ist === $soll, 'zwei PV-Anlagen und zwei Netzzähler im Text: ' . $ist . ' ‖ erwartet ' . $soll);

// Stapel des Hausverbrauchs: PV direkt je Anlage im Verhältnis ihres Ertrags (welche Anlage einspeist, misst niemand)
try {
    $diagramm = zweiIndex()->chart(['from' => '2026-09-01', 'to' => '2026-09-30'])['structuredContent'] ?? [];
} catch (Throwable $e) {
    $diagramm = [];
}
$reihen = $diagramm['series'] ?? [];
$pv = array_values(array_filter($reihen, static fn (array $r): bool => $r['key'] === 'solar'));
pruefe(array_column($reihen, 'key') === ['grid', 'solar', 'solar', 'battery', 'export'], 'Stapel: Netz, PV je Anlage, Batterie, Einspeisung: ' . json_encode(array_column($reihen, 'key')));
pruefe(array_column($pv, 'source') === ['Huawei', 'Hoymiles'] && array_column($pv, 'shade') === [0, 1], 'jede PV-Reihe nennt ihre Anlage und eine Abstufung der Farbe: ' . json_encode(array_map(static fn (array $r): array => array_diff_key($r, ['values' => 0]), $pv), JSON_UNESCAPED_UNICODE));
$abweichung = 0.0;
$zeiten = $diagramm['times'] ?? [];
$teil = static fn (int $id, int $zeit): float => abschnitte($id, '1', $zeit, $zeit)[$zeit] ?? 0.0;
foreach ($zeiten as $i => $zeit) {
    if ($zeit > $september[1]) {
        continue;
    }
    $erzeugt = [$teil(25173, $zeit), $teil(53548, $zeit)];
    $tag = $teil(11557, $zeit) + $teil(51590, $zeit) + array_sum($erzeugt) + $teil(53433, $zeit) - $teil(11314, $zeit) - $teil(18681, $zeit) - $teil(42220, $zeit);
    $direkt = max(0.0, min(max(0.0, $tag), array_sum($erzeugt) - $teil(11314, $zeit) - $teil(18681, $zeit) - $teil(42220, $zeit)));
    foreach ([0, 1] as $j) {
        $erwartet = array_sum($erzeugt) > 0 ? $direkt * $erzeugt[$j] / array_sum($erzeugt) : 0.0;
        $abweichung = max($abweichung, abs((float)($pv[$j]['values'][$i] ?? -1) - $erwartet));
    }
}
pruefe($zeiten !== [] && $abweichung <= 0.002, 'PV direkt je Anlage und Tag im Verhältnis des Ertrags, größte Abweichung ' . $abweichung . ' kWh');

// Teil der Bilanz mit mehreren Quellen: eine Reihe je Quelle, die Summenzeile über alle
try {
    $teilPv = zweiIndex()->chart(['from' => '2026-09-01', 'to' => '2026-09-30', 'part' => 'pv']);
} catch (Throwable $e) {
    $teilPv = ['text' => 'FEHLT: ' . $e->getMessage()];
}
$reihen = $teilPv['structuredContent']['series'] ?? [];
pruefe(array_column($reihen, 'name') === ['Huawei', 'Hoymiles'] && array_column($reihen, 'key') === ['pv', 'pv'] && array_column($reihen, 'shade') === [0, 1], 'part=pv: eine Reihe je Anlage: ' . json_encode(array_map(static fn (array $r): array => array_diff_key($r, ['values' => 0]), $reihen), JSON_UNESCAPED_UNICODE));
pruefe(abs(array_sum($reihen[1]['values'] ?? []) - $hoymiles) < 0.01 && abs(array_sum($reihen[0]['values'] ?? []) - $huawei) < 0.01, 'part=pv: Werte je Anlage aus ihrem Zähler');
pruefe(str_contains($teilPv['text'], 'Solar production ' . zahl($huawei + $hoymiles) . ' kWh.'), 'part=pv: die Summenzeile zählt beide Anlagen: ' . $teilPv['text']);
try {
    $teilNetz = zweiIndex()->chart(['period' => 'this_year', 'part' => 'grid'])['structuredContent']['series'] ?? [];
} catch (Throwable $e) {
    $teilNetz = [];
}
$jahr = (int)strtotime('2026-01-01');
pruefe(array_column($teilNetz, 'name') === ['Netz', 'Netz bis 05/2026']
    && abs(array_sum($teilNetz[1]['values'] ?? []) - array_sum(abschnitte(51590, '3', $jahr, $zwei['jetzt']))) < 0.01
    && abs(array_sum($teilNetz[0]['values'] ?? []) - array_sum(abschnitte(11557, '3', $jahr, $zwei['jetzt']))) < 0.01,
    'part=grid über das Jahr: alter und neuer Netzzähler je als Reihe');
pruefe(str_contains((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/chart.html'), 'SOURCE_SHADES'), 'die Seite kennt Abstufungen der Farbe je Quelle');

// Das erste Aggregat eines Zählers trägt den Zeitpunkt seines ersten Satzes, nicht den Beginn des Abschnitts: beim
// Hoymiles 14.09.2025 11:00 statt 01.09. Ohne Ausgleich bekam der September eine eigene Säule nur für den Hoymiles
// (Hardcopy Burkhard aus der Claude-App 17.10.2026: „Warum fehlt hier der Septemberwert für Huawei?“, 13 Säulen).
try {
    $vorjahr = zweiIndex()->chart(['period' => 'last_year', 'part' => 'pv'])['structuredContent'] ?? [];
} catch (Throwable $e) {
    $vorjahr = [];
}
$sept = array_search((int)strtotime('2025-09-01'), $vorjahr['times'] ?? [], true);
pruefe(count($vorjahr['times'] ?? []) === 12 && $sept !== false && ($vorjahr['series'][0]['values'][$sept] ?? 0) > 300 && ($vorjahr['series'][1]['values'][$sept] ?? 0) > 50,
    'part=pv 2025: zwölf Monate, der September mit beiden Anlagen: ' . json_encode(array_map(static fn (int $t): string => date('Y-m-d H:i', $t), $vorjahr['times'] ?? [])));
try {
    $mai = zweiIndex()->chart(['from' => '2026-05-01', 'to' => '2026-05-31'])['structuredContent'] ?? [];
} catch (Throwable $e) {
    $mai = [];
}
pruefe(count($mai['times'] ?? []) === 31, 'Hausstapel Mai 2026: 31 Tage, auch wenn neuer Netzzähler und Batterie mitten am Tag beginnen: ' . count($mai['times'] ?? []));

ende('Weitere Quellen der Energiebilanz');
