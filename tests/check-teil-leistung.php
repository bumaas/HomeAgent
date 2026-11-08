<?php

declare(strict_types=1);

/*
 * Verlauf der Leistung eines Teils der Energiebilanz: show_chart mit part=pv|grid|feed_in|battery_charge|battery_discharge
 * und type=power zeichnet die Leistungssensoren dieses Teils als Linie mit Minimum und Maximum. Anlass: Die Claude-App fragte
 * am 05.11.2026 am nuc nach der PV-Leistung von heute (name=PV type=power, dann part=pv type=power), beides wurde abgewiesen.
 *
 * Mitschnitte, alle Werte echt: nuc_2026-10-18_leistung.json (getrennte Sensoren je Richtung) und
 * betreut_2026-11-08_leistung.json (Netz und Batterie als ein Sensor mit Vorzeichen, die Batterie mit Invert, zwei
 * PV-Anlagen). Die Erwartungen rechnet der Test aus den Aggregaten der Fixture nach.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$nuc = fixture('nuc_2026-10-18_leistung.json');
$betreut = fixture('betreut_2026-11-08_leistung.json');
date_default_timezone_set($nuc['zeitzone']);

/** @return array{0: DeviceIndex, 1: FakeBackend} */
function aufbau(array $mitschnitt, ?array $quellen = null): array
{
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'] ?? [], $anlage);
    $index->addSources($quellen ?? $mitschnitt['quellen']);
    return [$index, $anlage];
}

function diagramm(array $mitschnitt, array $argumente, ?array $quellen = null): array
{
    try {
        return aufbau($mitschnitt, $quellen)[0]->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

/** Punkte [Zeit, Min, Mittel, Max] in kW aus den Aggregaten; $gedreht wie „Invert“: Vorzeichen um, Min und Max tauschen. */
function punkte(array $mitschnitt, int $id, int $stufe, int $von, int $bis, bool $gedreht = false): array
{
    $r = [];
    foreach ($mitschnitt['variablen'][(string)$id]['aggregate'][(string)$stufe] as $a) {
        if ($a['zeit'] >= $von && $a['zeit'] < $bis) {
            $r[] = $gedreht ? [$a['zeit'], -$a['max'] / 1000, -$a['mittel'] / 1000, -$a['min'] / 1000] : [$a['zeit'], $a['min'] / 1000, $a['mittel'] / 1000, $a['max'] / 1000];
        }
    }
    return $r;
}

/** Nur eine Richtung der Punkte, positiv gezählt: Was in die andere Richtung geht, zählt 0. */
function richtung(array $punkte, bool $positiv): array
{
    return array_map(static fn (array $p): array => $positiv
        ? [$p[0], max(0.0, $p[1]), max(0.0, $p[2]), max(0.0, $p[3])]
        : [$p[0], max(0.0, -$p[3]), max(0.0, -$p[2]), max(0.0, -$p[1])], $punkte);
}

function gleich(array $ist, array $soll): bool
{
    if (count($ist) !== count($soll) || $soll === []) {
        return false;
    }
    foreach ($soll as $i => $zeile) {
        foreach ($zeile as $j => $wert) {
            if (abs((float)($ist[$i][$j] ?? PHP_FLOAT_MAX) - (float)$wert) > 0.0001) {
                return false;
            }
        }
    }
    return true;
}

$kurz = static fn (array $r): string => substr((string)$r['text'], 0, 200);

/* --- getrennte Sensoren je Richtung (nuc) --------------------------------------------------------- */

$gestern = (int)strtotime('yesterday', $nuc['jetzt']);
$heute = (int)strtotime('today', $nuc['jetzt']);
foreach (['pv' => 14114, 'grid' => 24942, 'feed_in' => 35568, 'battery_discharge' => 25414, 'battery_charge' => 55001] as $part => $id) {
    $r = diagramm($nuc, ['part' => $part, 'type' => 'power', 'period' => 'yesterday']);
    $d = $r['structuredContent'] ?? [];
    $s = $d['series'][0] ?? [];
    pruefe(!$r['isError'] && ($d['kind'] ?? '') === 'history' && count($d['series'] ?? []) === 1 && ($s['unit'] ?? '') === 'kW', "part=$part type=power: eine Linie in kW: " . $kurz($r));
    pruefe(gleich($s['points'] ?? [], punkte($nuc, $id, 0, $gestern, $heute)), "part=$part: Minimum, Mittel und Maximum je Stunde aus #$id");
    pruefe(str_contains($r['text'], '1 source') && str_contains($r['text'], "part=$part"), "part=$part: die Kopfzeile nennt Anzahl und Teil: " . $kurz($r));
    pruefe(!str_contains($r['text'], 'kWh'), "part=$part: keine Summe in kWh aus einer Linie: " . $r['text']);
}

// Nur der Sensor dieses Teils, keine Hausleistung und keine Netzleistung dazu
[$index, $anlage] = aufbau($nuc);
$anlage->historyRequests = [];
$r = $index->chart(['part' => 'pv', 'type' => 'power', 'period' => 'yesterday']);
pruefe(!str_contains($r['text'], 'house power') && !str_contains($r['text'], 'grid'), 'part=pv: ohne Hausleistung und Netz: ' . $r['text']);
pruefe($anlage->historyRequests === [[14114, 0]], 'part=pv: genau eine Abfrage, die der PV je Stunde: ' . json_encode($anlage->historyRequests));

// Monat je Tag, wie jeder Verlauf; Zeitraum und Zeitzone trägt die Seite
$monat = (int)strtotime(date('Y-m-01', $nuc['jetzt']));
$r = diagramm($nuc, ['part' => 'pv', 'type' => 'power', 'period' => 'this_month']);
$d = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($d['per'] ?? '') === 'day' && gleich($d['series'][0]['points'] ?? [], punkte($nuc, 14114, 1, $monat, PHP_INT_MAX)), 'this_month: je Tag aus den Tagesaggregaten: ' . $kurz($r));
pruefe(count($d['range'] ?? []) === 2 && ($d['timeZone'] ?? '') === $nuc['zeitzone'], 'Zeitraum und Zeitzone sind dabei');

// Der Aufruf vom 05.11. geht jetzt, auch ohne period
$r = diagramm($nuc, ['part' => 'pv', 'type' => 'power', 'period' => 'today']);
pruefe(!$r['isError'] && gleich($r['structuredContent']['series'][0]['points'] ?? [], punkte($nuc, 14114, 0, $heute, PHP_INT_MAX)), 'part=pv period=today type=power wie am 05.11.: ' . $kurz($r));
$r2 = diagramm($nuc, ['part' => 'pv', 'type' => 'power']);
pruefe(!$r2['isError'] && count($r2['structuredContent']['series'] ?? []) === 1, 'ohne Zeitraum gilt heute: ' . $kurz($r2));

/* --- Sensoren mit Vorzeichen, mehrere Anlagen (betreute Anlage) ------------------------------------ */

$bHeute = (int)strtotime('today', $betreut['jetzt']);
$r = diagramm($betreut, ['part' => 'pv', 'type' => 'power', 'period' => 'today']);
$reihen = $r['structuredContent']['series'] ?? [];
pruefe(!$r['isError'] && count($reihen) === 2 && str_contains($r['text'], '2 source'), 'zwei PV-Anlagen, zwei Linien: ' . $kurz($r));
pruefe(str_contains($reihen[0]['name'] ?? '', 'Huawei') && str_contains($reihen[1]['name'] ?? '', 'Hoymiles'), 'jede Linie heißt nach ihrer Anlage: ' . json_encode(array_column($reihen, 'name'), JSON_UNESCAPED_UNICODE));
pruefe(gleich($reihen[0]['points'] ?? [], punkte($betreut, 10327, 0, $bHeute, PHP_INT_MAX)) && gleich($reihen[1]['points'] ?? [], punkte($betreut, 50420, 0, $bHeute, PHP_INT_MAX)), 'Werte je Anlage aus ihrem Sensor');

// Ein Sensor mit Vorzeichen wird nach Richtung getrennt, jeder Teil zeigt nur seine, positiv (Claude-App 08.11.2026: im
// Entlade-Diagramm standen mittags −2,5 kW). Batterie mit Invert: nach dem Drehen ist Entladen positiv.
foreach (['battery_discharge' => [true, 'battery discharge'], 'battery_charge' => [false, 'battery charge']] as $part => [$positiv, $wort]) {
    $r = diagramm($betreut, ['part' => $part, 'type' => 'power', 'period' => 'today']);
    $s = $r['structuredContent']['series'][0] ?? [];
    pruefe(!$r['isError'] && count($r['structuredContent']['series'] ?? []) === 1 && gleich($s['points'] ?? [], richtung(punkte($betreut, 31051, 0, $bHeute, PHP_INT_MAX, true), $positiv)), "$part: nur diese Richtung, positiv: " . $kurz($r));
    pruefe(str_contains($s['name'] ?? '', $wort) && !str_contains($s['name'] ?? '', 'negative'), "$part: die Linie heißt nach dem Teil, ohne „negativ“: " . ($s['name'] ?? ''));
}

// Netz als ein Sensor ohne Invert: Bezug positiv, Einspeisung ist die negative Seite
foreach (['grid' => [true, 'grid import'], 'feed_in' => [false, 'grid export']] as $part => [$positiv, $wort]) {
    $r = diagramm($betreut, ['part' => $part, 'type' => 'power', 'period' => 'today']);
    $s = $r['structuredContent']['series'][0] ?? [];
    pruefe(!$r['isError'] && gleich($s['points'] ?? [], richtung(punkte($betreut, 16895, 0, $bHeute, PHP_INT_MAX), $positiv)) && str_contains($s['name'] ?? '', $wort) && !str_contains($s['name'] ?? '', 'negative'), "$part: Sensor mit Vorzeichen nach Richtung: " . $kurz($r));
}

// Ohne part bleibt die Netzleistung ein Sensor mit Vorzeichen (Kontrolle)
$r = diagramm($betreut, ['type' => 'power', 'period' => 'today'], null);
pruefe(str_contains($r['text'], 'export negative'), 'ohne part weiter „Einspeisung negativ“: ' . $kurz($r));

/* --- Sensor ohne Einheit ---------------------------------------------------------------------------- */

// nuc 08.11.2026: Die zweite Batterie (#14525, ohne Profil und Suffix, Invert) kam live ohne Einheit und mit 2482 als „kW“.
// Ohne Einheit gilt W wie bei period=now (powerFactor).
$ohneEinheit = fixture('nuc_2026-11-08_leistung_ohne_einheit.json');
$oGestern = (int)strtotime('yesterday', $ohneEinheit['jetzt']);
$oHeute = (int)strtotime('today', $ohneEinheit['jetzt']);
$r = diagramm($ohneEinheit, ['part' => 'battery_charge', 'type' => 'power', 'period' => 'yesterday']);
$s = $r['structuredContent']['series'][0] ?? [];
pruefe(!$r['isError'] && ($s['unit'] ?? '') === 'kW', 'Sensor ohne Einheit: die Linie steht in kW: ' . json_encode($s['unit'] ?? null));
pruefe(gleich($s['points'] ?? [], richtung(punkte($ohneEinheit, 14525, 0, $oGestern, $oHeute, true), false)), 'Sensor ohne Einheit: Werte als W gelesen, nur das Laden');

// Stunden mit beiden Richtungen, feste Werte aus dem Mitschnitt. Der Sensor zählt Laden positiv (Invert). Die Spitzen
// bleiben exakt, das Mittel ist der Saldo und zählt nur für die Richtung, in die er zeigt.
$zeile = static function (int $zeit) use ($ohneEinheit): array {
    foreach ($ohneEinheit['variablen']['14525']['aggregate']['0'] as $a) {
        if ($a['zeit'] === $zeit) {
            return $a;
        }
    }
    return [];
};
$stunde = static function (array $s, int $zeit): array {
    foreach ($s['points'] ?? [] as $p) {
        if ($p[0] === $zeit) {
            return $p;
        }
    }
    return [];
};
$saldoLaden = (int)strtotime('2026-11-07 12:00');
$saldoEntladen = (int)strtotime('2026-11-07 13:00');
$a11 = $zeile($saldoLaden);
$a12 = $zeile($saldoEntladen);
pruefe($a11['min'] < -50 && $a11['max'] > 50 && $a11['mittel'] > 0 && $a12['min'] < -50 && $a12['max'] > 50 && $a12['mittel'] < 0, 'Orakel: beide Stunden enthalten beide Richtungen, die eine lädt im Saldo, die andere entlädt');
$laden = diagramm($ohneEinheit, ['part' => 'battery_charge', 'type' => 'power', 'period' => 'yesterday']);
$entladen = diagramm($ohneEinheit, ['part' => 'battery_discharge', 'type' => 'power', 'period' => 'yesterday']);
$sl = $laden['structuredContent']['series'][0] ?? [];
$se = $entladen['structuredContent']['series'][0] ?? [];
pruefe(gleich([$stunde($sl, $saldoLaden)], [[$saldoLaden, 0.0, $a11['mittel'] / 1000, $a11['max'] / 1000]]), '12 Uhr, Saldo Laden: Ladelinie mit Mittel und Spitze: ' . json_encode($stunde($sl, $saldoLaden)));
pruefe(gleich([$stunde($se, $saldoLaden)], [[$saldoLaden, 0.0, 0.0, -$a11['min'] / 1000]]), '12 Uhr: Entladelinie nur mit ihrer Spitze: ' . json_encode($stunde($se, $saldoLaden)));
pruefe(gleich([$stunde($sl, $saldoEntladen)], [[$saldoEntladen, 0.0, 0.0, $a12['max'] / 1000]]), '13 Uhr, Saldo Entladen: Ladelinie nur mit ihrer Spitze: ' . json_encode($stunde($sl, $saldoEntladen)));
pruefe(gleich([$stunde($se, $saldoEntladen)], [[$saldoEntladen, 0.0, -$a12['mittel'] / 1000, -$a12['min'] / 1000]]), '13 Uhr: Entladelinie mit Mittel und Spitze: ' . json_encode($stunde($se, $saldoEntladen)));
$werte = array_merge(...array_map(static fn (array $p): array => array_slice($p, 1), array_merge($sl['points'] ?? [[0, -1]], $se['points'] ?? [[0, -1]])));
pruefe(min($werte) >= 0.0, 'keine negativen Werte in einer Richtung: ' . min($werte));

// Der Zeitpunkt der Spitze ist der des Rohwerts, in der Ladelinie also der des Maximums des Sensors
$spitze = null;
foreach ($ohneEinheit['variablen']['14525']['aggregate']['0'] as $a) {
    if ($a['zeit'] >= $oGestern && $a['zeit'] < $oHeute && ($spitze === null || $a['max'] > $spitze['max'])) {
        $spitze = $a;
    }
}
pruefe(str_contains($laden['text'], 'maximum ' . rtrim(rtrim(number_format($spitze['max'] / 1000, 1, '.', ''), '0'), '.') . ' kW (' . date('Y-m-d H:i', $spitze['maxzeit']) . ')'), 'Die Antwort nennt Spitze und Zeitpunkt des Ladens: ' . $laden['text']);

/* --- Absagen -------------------------------------------------------------------------------------- */

foreach (['cumulative' => true, 'heatmap' => true, 'compare' => 'previous'] as $feld => $wert) {
    $r = diagramm($nuc, ['part' => 'pv', 'type' => 'power', 'period' => 'this_month', $feld => $wert]);
    pruefe($r['isError'] && str_contains($r['text'], 'only a period'), "type=power mit $feld wird abgewiesen: " . $r['text']);
}
$r = diagramm($nuc, ['part' => 'grid', 'type' => 'power', 'period' => 'this_month', 'costs' => true]);
pruefe($r['isError'] && str_contains($r['text'], 'only a period'), 'type=power mit costs wird abgewiesen: ' . $r['text']);
$r = diagramm($nuc, ['part' => 'pv', 'type' => 'temperature', 'period' => 'today']);
pruefe($r['isError'] && str_contains($r['text'], 'whole house'), 'ein anderer Typ bleibt abgewiesen: ' . $r['text']);
$r = diagramm($nuc, ['part' => 'pv', 'type' => 'power', 'room' => 'Keller', 'period' => 'today']);
pruefe($r['isError'] && str_contains($r['text'], 'whole house'), 'mit Raum bleibt es abgewiesen: ' . $r['text']);
$r = diagramm($nuc, ['part' => 'pv', 'type' => 'power', 'period' => 'now']);
pruefe($r['isError'] && str_contains($r['text'], 'now has no history'), 'period=now hat keinen Verlauf: ' . $r['text']);

// Quelle ohne Leistungssensor: die Absage nennt, was fehlt
$ohne = array_map(static fn (array $q): array => $q['Type'] === 'solar' ? ['PowerIn' => 0] + $q : $q, $nuc['quellen']);
[$index] = aufbau($nuc, $ohne);
$r = $index->chart(['part' => 'pv', 'type' => 'power', 'period' => 'today']);
pruefe($r['isError'] && str_contains($r['text'], 'power variable') && $index->problem() === 'invalid', 'ohne Sensor kein Verlauf: ' . $r['text']);

// Ohne type=power bleibt der Teil beim Zähler in kWh (Kontrolle)
$r = diagramm($nuc, ['part' => 'pv', 'period' => 'today']);
pruefe(($r['isError'] && str_contains($r['text'], 'FEHLT') === false) || ($r['structuredContent']['kind'] ?? '') === 'consumption', 'ohne type=power weiter die Balken: ' . $kurz($r));

ende('Leistung eines Teils der Bilanz');
