<?php

declare(strict_types=1);

/*
 * Ringe für Autarkie, Eigenverbrauch und Leistung (Anregung pitti, Forum t/144633 #5, Burkhard 13.10.2026: „lässt
 * sich soetwas auch umsetzen?“). show_chart fürs ganze Haus mit Zeitraum gibt zu den Stapeln zwei Ringe mit:
 * Autarkie (Hausverbrauch aufgeteilt in PV direkt, Batterie und Netz) und Eigenverbrauch (PV aufgeteilt in direkt,
 * in die Batterie geladen und eingespeist). Mit period=now kommen die Ringe aus den Leistungen, dazu ein Ring der
 * Hausleistung. Die Prozentzahl in der Mitte ist dieselbe, die das Modell im Text bekommt.
 * Mitschnitte nuc_2026-10-12_verbrauch.json (Zähler) und nuc_2026-10-09_leistung.json (Leistungen, abends, PV 0 W),
 * beide echt. Die Erwartungen sind aus denselben Werten im Test gerechnet.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

$verbrauch = fixture('nuc_2026-10-12_verbrauch.json');
$leistung = fixture('nuc_2026-10-09_leistung.json');
date_default_timezone_set($verbrauch['zeitzone']);

function diagrammZaehler(array $argumente, ?array $uebersetzungen = null): array
{
    global $verbrauch;
    $anlage = new FakeBackend($verbrauch['variablen'], $uebersetzungen);
    $anlage->jetzt = $verbrauch['jetzt'];
    $index = new DeviceIndex($verbrauch['geraete'], $anlage, null, '', $verbrauch['bilanz']);
    try {
        return $index->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

function diagrammLeistung(array $argumente, ?array $bilanzLeistung = null, ?array $uebersetzungen = null): array
{
    global $leistung;
    $index = new DeviceIndex($leistung['geraete'], new FakeBackend($leistung['variablen'], $uebersetzungen), null, '', [], $bilanzLeistung ?? $leistung['bilanzLeistung']);
    try {
        return $index->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

/** Ring nach Schlüssel. */
function ring(array $r, string $schluessel): array
{
    foreach ($r['structuredContent']['rings'] ?? [] as $ring) {
        if (($ring['key'] ?? null) === $schluessel) {
            return $ring;
        }
    }
    return [];
}

/** Teile eines Rings als Schlüssel => Wert. */
function teile(array $ring): array
{
    return array_column($ring['parts'] ?? [], 'value', 'key');
}

$nahe = static fn (mixed $a, float $b, float $toleranz = 0.01): bool => (is_int($a) || is_float($a)) && abs($a - $b) < $toleranz;

/* --- Gestern aus den Zählern ------------------------------------------------------------------- */

// Summen gestern in kWh aus den Stundenaggregaten des Mitschnitts
$kwh = static fn (array $v, float $s): float => strtolower((string)$v['einheit']) === 'wh' ? $s / 1000 : $s;
$summe = [];
$beginn = mktime(0, 0, 0, 10, 11, 2026);
foreach ($verbrauch['bilanz'] as $teil => $id) {
    $v = $verbrauch['variablen'][(string)$id];
    $summe[$teil] = 0.0;
    foreach ($v['aggregate']['0'] as $a) {
        if ($a['zeit'] >= $beginn && $a['zeit'] < $beginn + 86400) {
            $summe[$teil] += $kwh($v, (float)$a['summe']);
        }
    }
}
$haus = $summe['import'] + $summe['solar'] + $summe['discharge'] - $summe['export'] - $summe['charge'];
$direkt = max(0.0, $summe['solar'] - $summe['export'] - $summe['charge']);
$geladenAusPv = max(0.0, $summe['solar'] - $summe['export'] - $direkt);

$r = diagrammZaehler(['period' => 'yesterday']);
pruefe(!$r['isError'] && ($r['structuredContent']['kind'] ?? null) === 'consumption' && ($r['structuredContent']['series'] ?? []) !== [], 'Haus gestern bleibt ein Stapeldiagramm: ' . strtok($r['text'], "\n"));
pruefe(array_column($r['structuredContent']['rings'] ?? [], 'key') === ['autarky', 'self_use'], 'dazu zwei Ringe, Autarkie und Eigenverbrauch: ' . json_encode(array_column($r['structuredContent']['rings'] ?? [], 'key')));

$a = ring($r, 'autarky');
preg_match('/Self-sufficiency (\d+) %, self-consumed solar (\d+) %/', $r['text'], $text);
pruefe(isset($text[1]) && ($a['share'] ?? null) === (int)$text[1], 'Autarkie in der Mitte wie im Text: ' . json_encode($a['share'] ?? null) . ' / ' . ($text[0] ?? $r['text']));
pruefe(array_keys(teile($a)) === ['solar', 'battery', 'grid'], 'Autarkie teilt den Hausverbrauch in PV direkt, Batterie und Netz: ' . json_encode(array_keys(teile($a))));
pruefe($nahe(teile($a)['solar'] ?? null, $direkt) && $nahe(teile($a)['battery'] ?? null, $summe['discharge']) && $nahe(teile($a)['grid'] ?? null, $summe['import']), 'Teile in kWh: ' . json_encode(teile($a)) . ' erwartet ' . json_encode([round($direkt, 2), round($summe['discharge'], 2), round($summe['import'], 2)]));
pruefe($nahe($a['total'] ?? null, $haus) && ($a['unit'] ?? null) === 'kWh', 'Bezug des Rings ist der Hausverbrauch: ' . json_encode($a['total'] ?? null) . ' / ' . round($haus, 2));

$e = ring($r, 'self_use');
pruefe(isset($text[2]) && ($e['share'] ?? null) === (int)$text[2], 'Eigenverbrauch in der Mitte wie im Text: ' . json_encode($e['share'] ?? null));
pruefe(array_keys(teile($e)) === ['solar', 'battery', 'export'], 'Eigenverbrauch teilt die PV in direkt, geladen und eingespeist: ' . json_encode(array_keys(teile($e))));
pruefe($nahe(teile($e)['solar'] ?? null, $direkt) && $nahe(teile($e)['battery'] ?? null, $geladenAusPv) && $nahe(teile($e)['export'] ?? null, $summe['export']), 'Teile in kWh: ' . json_encode(teile($e)));
pruefe($nahe($e['total'] ?? null, $summe['solar']), 'Bezug des Rings ist die PV-Erzeugung: ' . json_encode($e['total'] ?? null) . ' / ' . round($summe['solar'], 2));
pruefe($nahe(array_sum(teile($e)), $summe['solar']), 'die Teile ergeben zusammen die PV-Erzeugung');

// Nur Netzbezug: keine Autarkie, kein Eigenverbrauch, also keine Ringe
$anlage = new FakeBackend($verbrauch['variablen']);
$anlage->jetzt = $verbrauch['jetzt'];
$nurNetz = (new DeviceIndex($verbrauch['geraete'], $anlage, null, '', ['import' => $verbrauch['bilanz']['import']]))->chart(['period' => 'yesterday']);
pruefe(!$nurNetz['isError'] && !isset($nurNetz['structuredContent']['rings']), 'nur Netzbezug: keine Ringe: ' . json_encode(array_column($nurNetz['structuredContent']['rings'] ?? [], 'key')));
// Ein Raum: Verbrauch je Gerät, keine Ringe
$r = diagrammZaehler(['room' => 'Keller', 'period' => 'yesterday']);
pruefe(!isset($r['structuredContent']['rings']), 'Verbrauch eines Raums: keine Ringe');

/* --- Jetzt aus den Leistungen ------------------------------------------------------------------ */

// Abends am nuc: Bezug 8 W, PV 0, Batterie entladen 1888 W, Haus 1896 W
$r = diagrammLeistung(['period' => 'now']);
pruefe(!$r['isError'] && ($r['structuredContent']['kind'] ?? null) === 'rings', 'period=now fürs ganze Haus: nur Ringe: ' . $r['text']);
pruefe(is_int($r['structuredContent']['at'] ?? null) && $r['structuredContent']['at'] > 1700000000, 'Leistung jetzt mit Zeitpunkt für die Titelzeile: ' . json_encode($r['structuredContent']['at'] ?? null));
pruefe(array_column($r['structuredContent']['rings'] ?? [], 'key') === ['power', 'autarky'], 'Leistung und Autarkie, ohne PV kein Eigenverbrauch: ' . json_encode(array_column($r['structuredContent']['rings'] ?? [], 'key')));
pruefe(str_starts_with($r['text'], 'House power 1896 W (all devices, period=now)') && str_contains($r['text'], "\nSelf-sufficiency 100 %.") && str_ends_with($r['text'], "\nThe chart is shown in the chat."), 'das Modell bekommt die Hausleistung, die Autarkie und die Schlusszeile: ' . $r['text']);
$p = ring($r, 'power');
pruefe(($p['unit'] ?? null) === 'W' && $nahe($p['total'] ?? null, 1896.0, 1.0) && array_keys(teile($p)) === ['solar', 'battery', 'grid'], 'Leistungsring: Haus 1896 W aus PV, Batterie und Netz: ' . json_encode($p));
pruefe($nahe(teile($p)['battery'] ?? null, 1888.0, 1.0) && $nahe(teile($p)['grid'] ?? null, 8.0, 1.0) && $nahe(teile($p)['solar'] ?? null, 0.0), 'Teile in W: ' . json_encode(teile($p)));
pruefe(($p['max'] ?? 0) >= 1896 && ($p['max'] ?? 0) <= 5000 && fmod((float)($p['max'] ?? 1), 1000.0) === 0.0, 'Skala in ganzen kW über der größten Leistung: ' . json_encode($p['max'] ?? null));
pruefe((ring($r, 'autarky')['share'] ?? null) === 100 && (ring($r, 'autarky')['unit'] ?? null) === 'W', 'Autarkie jetzt 100 %: ' . json_encode(ring($r, 'autarky')));

// period=now mit Raum oder ohne Leistungsbilanz bleibt eine Absage
$r = diagrammLeistung(['period' => 'now', 'room' => 'Keller']);
pruefe($r['isError'] && !isset($r['structuredContent']), 'period=now mit Raum: kein Diagramm: ' . $r['text']);
$r = diagrammLeistung(['period' => 'now'], []);
pruefe($r['isError'] && !isset($r['structuredContent']), 'period=now ohne Leistungsbilanz: kein Diagramm: ' . $r['text']);

/* --- Ohne Batterie ----------------------------------------------------------------------------- */

// Neustadt (15.10.2026, Hardcopy Burkhard aus der Claude-App): Die Bilanz hat Netz, Einspeisung und PV, aber keinen
// Batteriezähler (der Marstek steht nur als Leistungssensor drin). Die Ringe zeigten trotzdem „Batterie 0 kWh“. Ohne
// Batteriezähler gibt es die Zeile nicht, wie im Stapel. Werte vom nuc, nur die Batteriezähler weggelassen.
$anlage = new FakeBackend($verbrauch['variablen']);
$anlage->jetzt = $verbrauch['jetzt'];
$ohneBatterie = (new DeviceIndex($verbrauch['geraete'], $anlage, null, '', array_diff_key($verbrauch['bilanz'], ['charge' => 0, 'discharge' => 0])))->chart(['period' => 'yesterday']);
pruefe(array_keys(teile(ring($ohneBatterie, 'autarky'))) === ['solar', 'grid'], 'ohne Batteriezähler: Autarkie nur aus PV direkt und Netz: ' . json_encode(teile(ring($ohneBatterie, 'autarky'))));
pruefe(array_keys(teile(ring($ohneBatterie, 'self_use'))) === ['solar', 'export'], 'ohne Batteriezähler: Eigenverbrauch nur aus PV direkt und Einspeisung: ' . json_encode(teile(ring($ohneBatterie, 'self_use'))));
// Ebenso jetzt ohne Batterieleistung
$r = diagrammLeistung(['period' => 'now'], array_diff_key($leistung['bilanzLeistung'], ['charge' => 0, 'discharge' => 0]));
pruefe(array_keys(teile(ring($r, 'power'))) === ['solar', 'grid'] && array_keys(teile(ring($r, 'autarky'))) === ['solar', 'grid'], 'ohne Batterieleistung: Leistung und Autarkie ohne Batterie: ' . json_encode([teile(ring($r, 'power')), teile(ring($r, 'autarky'))]));
// Mit Batterie bleibt die Zeile, auch wenn sie 0 ist (am nuc nachts geladen, tagsüber oft 0)
pruefe(array_keys(teile(ring(diagrammZaehler(['period' => 'yesterday']), 'autarky'))) === ['solar', 'battery', 'grid'], 'mit Batteriezähler bleibt die Zeile');

/* --- Mit Vergleich ----------------------------------------------------------------------------- */

// Hardcopy 15.10.2026: Titel „2026 · Vergleich 2025“, die Ringe zeigten aber nur 2026. Mit compare trägt jeder Ring den
// Anteil des Vergleichszeitraums (compare.share), solange der Zeitraum läuft bis zur selben Zeit wie „By the same time“.
// Orakel: die Ringe des Vergleichszeitraums selbst bzw. die Stundenaggregate von gestern bis 17:19.
$r = diagrammZaehler(['from' => '2026-10-05', 'to' => '2026-10-10', 'compare' => 'previous']);
$alt = diagrammZaehler(['from' => '2026-09-29', 'to' => '2026-10-04']);
pruefe(!$r['isError'] && (ring($r, 'autarky')['compare']['share'] ?? null) === (ring($alt, 'autarky')['share'] ?? 'fehlt')
    && (ring($r, 'self_use')['compare']['share'] ?? null) === (ring($alt, 'self_use')['share'] ?? 'fehlt'), 'abgeschlossener Zeitraum: der Vergleich hat die Anteile der Woche davor: ' . json_encode([ring($r, 'autarky')['compare'] ?? null, ring($alt, 'autarky')['share'] ?? null, ring($r, 'self_use')['compare'] ?? null, ring($alt, 'self_use')['share'] ?? null]));

$bis = [];
$jetzt = $verbrauch['jetzt'];
$heuteBeginn = mktime(0, 0, 0, 10, 12, 2026);
foreach ($verbrauch['bilanz'] as $teil => $id) {
    $v = $verbrauch['variablen'][(string)$id];
    $bis[$teil] = 0.0;
    foreach ($v['aggregate']['0'] as $a) {
        $gleicheZeit = $a['zeit'] + 86400;
        if ($a['zeit'] >= $beginn && $gleicheZeit < $jetzt) {
            $bis[$teil] += $kwh($v, (float)$a['summe']) * min(1.0, ($jetzt - $gleicheZeit) / 3600);
        }
    }
}
$hausBis = $bis['import'] + $bis['solar'] + $bis['discharge'] - $bis['export'] - $bis['charge'];
$autarkieBis = (int)round(max(0.0, min(1.0, 1 - $bis['import'] / $hausBis)) * 100);
$eigenBis = (int)round(max(0.0, min(1.0, ($bis['solar'] - $bis['export']) / $bis['solar'])) * 100);
$r = diagrammZaehler(['period' => 'today', 'compare' => 'previous']);
pruefe((ring($r, 'autarky')['compare']['share'] ?? null) === $autarkieBis && (ring($r, 'self_use')['compare']['share'] ?? null) === $eigenBis,
    'laufender Tag: der Vergleich bis zur selben Zeit: ' . json_encode([ring($r, 'autarky')['compare'] ?? null, $autarkieBis, ring($r, 'self_use')['compare'] ?? null, $eigenBis]));
pruefe(str_contains($r['text'], sprintf("\nBy the same time: Self-sufficiency %d %%, self-consumed solar %d %%.", $autarkieBis, $eigenBis)), 'das Modell bekommt dieselben Anteile: ' . $r['text']);
// Ohne Vergleich kein compare am Ring
pruefe(!isset(ring(diagrammZaehler(['period' => 'yesterday']), 'autarky')['compare']), 'ohne Vergleich kein compare am Ring');

/* --- Beschriftungen ---------------------------------------------------------------------------- */

$r = diagrammZaehler(['period' => 'yesterday'], $de);
pruefe((ring($r, 'autarky')['title'] ?? null) === 'Autarkie' && (ring($r, 'autarky')['label'] ?? null) === 'Autarkie'
    && (ring($r, 'self_use')['title'] ?? null) === 'Eigenverbrauch' && (ring($r, 'self_use')['label'] ?? null) === 'Eigenverbrauch', 'deutsche Titel: ' . json_encode([ring($r, 'autarky')['title'] ?? null, ring($r, 'self_use')['title'] ?? null], JSON_UNESCAPED_UNICODE));
pruefe(array_column(ring($r, 'autarky')['parts'] ?? [], 'name') === ['PV direkt', 'Batterie', 'Netz'] && array_column(ring($r, 'self_use')['parts'] ?? [], 'name') === ['PV direkt', 'Batterie', 'Einspeisung'], 'deutsche Teile: ' . json_encode([array_column(ring($r, 'autarky')['parts'] ?? [], 'name'), array_column(ring($r, 'self_use')['parts'] ?? [], 'name')], JSON_UNESCAPED_UNICODE));
$r = diagrammLeistung(['period' => 'now'], null, $de);
pruefe((ring($r, 'power')['title'] ?? null) === 'Aktuelle Leistung' && (ring($r, 'power')['label'] ?? null) === 'Haus', 'Leistungsring deutsch: ' . json_encode(ring($r, 'power'), JSON_UNESCAPED_UNICODE));
// Kurze Namen wie in den Ringen daneben (Layoutvergleich der Diagramme 13.10.2026: dort „PV direkt, Batterie, Netz“)
pruefe(array_column(ring($r, 'power')['parts'] ?? [], 'name') === ['PV', 'Batterie', 'Netz'], 'Leistungsring: PV, Batterie, Netz: ' . json_encode(array_column(ring($r, 'power')['parts'] ?? [], 'name'), JSON_UNESCAPED_UNICODE));

ende('Ringe');
