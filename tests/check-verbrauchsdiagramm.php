<?php

declare(strict_types=1);

/*
 * Verbrauch als Stapeldiagramm (Burkhard 12.10.2026: „wie sieht es mit verbrauchsdiagrammen aus, also
 * stapeldiagramme?“). show_chart ohne Raum, Etage, Typ und Name zeichnet den Hausverbrauch je Stunde, Tag oder Monat,
 * gestapelt nach Herkunft: Netz, PV direkt (PV − Einspeisung − Batterie geladen) und Batterie (entladen); die
 * Einspeisung steht unter der Nulllinie. Zusammen ergibt ein Balken genau den Hausverbrauch der Bilanz. Lädt die
 * Batterie aus dem Netz, ist PV direkt 0 und der Rest kommt aus dem Netz.
 * Mitschnitt nuc_2026-10-12_verbrauch.json (echt). Die Erwartungen je Stunde sind aus denselben Aggregaten von Hand
 * gerechnet, September 2026 ist wie im Mitschnitt vom 09.10.2026 774,8 kWh.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-12_verbrauch.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function stapel(array $argumente, ?array $bilanz = null, ?array $uebersetzungen = null): array
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($mitschnitt['geraete'], $anlage, null, '', $bilanz ?? $mitschnitt['bilanz']);
    try {
        return $index->chart($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

/** Werte einer Reihe nach Schlüssel, je Zeitpunkt. */
function reihe(array $daten, string $schluessel): array
{
    foreach ($daten['series'] ?? [] as $s) {
        if (($s['key'] ?? null) === $schluessel) {
            return array_combine($daten['times'], $s['values']);
        }
    }
    return [];
}

$nahe = static fn (?float $a, float $b): bool => $a !== null && abs($a - $b) < 0.01;

/* --- Gestern stündlich -------------------------------------------------------------------------- */

$r = stapel(['period' => 'yesterday']);
$daten = $r['structuredContent'] ?? [];
$zeilen = explode("\n", $r['text']);
pruefe(!$r['isError'] && ($daten['kind'] ?? null) === 'consumption' && ($daten['per'] ?? null) === 'hour' && ($daten['unit'] ?? null) === 'kWh', 'Haus gestern: Verbrauch je Stunde in kWh: ' . json_encode(array_diff_key($daten, ['series' => 0, 'times' => 0])) . ' / ' . $r['text']);
pruefe(count($daten['times'] ?? []) === 24 && ($daten['times'][0] ?? null) === mktime(0, 0, 0, 10, 11, 2026), '24 Stunden ab Mitternacht: ' . count($daten['times'] ?? []));
pruefe(array_column($daten['series'] ?? [], 'key') === ['grid', 'solar', 'battery', 'export'], 'Stapel Netz, PV direkt, Batterie, darunter die Einspeisung: ' . json_encode(array_column($daten['series'] ?? [], 'key')));
$t14 = mktime(14, 0, 0, 10, 11, 2026);
$t15 = mktime(15, 0, 0, 10, 11, 2026);
// 14 Uhr: Bezug 3,61, PV 3,75, Einspeisung 0,56, Batterie geladen 4,60, Haus 2,21. Die Batterie lud aus dem Netz,
// PV direkt ist 0, der ganze Verbrauch kam aus dem Netz.
pruefe($nahe(reihe($daten, 'grid')[$t14] ?? null, 2.21) && $nahe(reihe($daten, 'solar')[$t14] ?? null, 0.0) && $nahe(reihe($daten, 'battery')[$t14] ?? null, 0.0) && $nahe(reihe($daten, 'export')[$t14] ?? null, -0.56), '14 Uhr: Batterie lädt aus dem Netz: ' . json_encode([reihe($daten, 'grid')[$t14] ?? null, reihe($daten, 'solar')[$t14] ?? null, reihe($daten, 'battery')[$t14] ?? null, reihe($daten, 'export')[$t14] ?? null]));
// 15 Uhr: PV 8,60 − Einspeisung 4,20 − geladen 3,27 = 1,13 direkt, Haus 1,79, Netz 0,66
pruefe($nahe(reihe($daten, 'solar')[$t15] ?? null, 1.13) && $nahe(reihe($daten, 'grid')[$t15] ?? null, 0.66) && $nahe(reihe($daten, 'export')[$t15] ?? null, -4.20), '15 Uhr: PV direkt und der Rest aus dem Netz: ' . json_encode([reihe($daten, 'solar')[$t15] ?? null, reihe($daten, 'grid')[$t15] ?? null]));
pruefe(str_starts_with($zeilen[0], 'House consumption ') && str_contains($zeilen[0], 'period=yesterday') && end($zeilen) === 'The chart is shown in the chat.', 'das Modell bekommt die Summe wie bei get_state und die Schlusszeile: ' . $r['text']);
pruefe(!array_any($zeilen, static fn (string $z): bool => preg_match('/^\d{4}-\d\d-\d\d /', $z) === 1), 'keine Stundenzeilen für das Modell');
pruefe(($daten['title'] ?? null) === $zeilen[0], 'Titel ist die Kopfzeile');

// Jeder Balken ergibt den Hausverbrauch der Bilanz
$kwh = static fn (array $v, float $s): float => strtolower((string)$v['einheit']) === 'wh' ? $s / 1000 : $s;
$haus = [];
foreach (['import' => 1, 'solar' => 1, 'discharge' => 1, 'export' => -1, 'charge' => -1] as $teil => $vorzeichen) {
    $v = $mitschnitt['variablen'][(string)$mitschnitt['bilanz'][$teil]];
    foreach ($v['aggregate']['0'] as $a) {
        $haus[$a['zeit']] = ($haus[$a['zeit']] ?? 0) + $vorzeichen * $kwh($v, (float)$a['summe']);
    }
}
$falsch = [];
foreach ($daten['times'] ?? [] as $t) {
    $summe = (reihe($daten, 'grid')[$t] ?? 0) + (reihe($daten, 'solar')[$t] ?? 0) + (reihe($daten, 'battery')[$t] ?? 0);
    if (abs($summe - max(0.0, $haus[$t])) > 0.002) {
        $falsch[] = date('H:i', $t) . ' ' . round($summe, 3) . ' statt ' . round($haus[$t], 3);
    }
}
pruefe(($daten['times'] ?? []) !== [] && $falsch === [], 'jeder Balken ist der Hausverbrauch: ' . implode(', ', $falsch));

/* --- Monat täglich, Jahr monatlich, freier Zeitraum ------------------------------------------------ */

$r = stapel(['period' => 'last_month']);
$daten = $r['structuredContent'] ?? [];
$summe = array_sum(array_merge(...array_map(static fn (array $s): array => $s['key'] === 'export' ? [] : $s['values'], $daten['series'] ?? [])));
pruefe(($daten['per'] ?? null) === 'day' && count($daten['times'] ?? []) === 30 && abs($summe - 774.8) < 0.05, 'September je Tag, zusammen 774,8 kWh: ' . ($daten['per'] ?? '') . ' ' . count($daten['times'] ?? []) . ' ' . round($summe, 2));
$r = stapel(['period' => 'this_year']);
pruefe(($r['structuredContent']['per'] ?? null) === 'month' && count($r['structuredContent']['times'] ?? []) === 10, 'dieses Jahr je Monat, Januar bis Oktober: ' . count($r['structuredContent']['times'] ?? []));
$r = stapel(['from' => '2026-10-05', 'to' => '2026-10-11']);
pruefe(($r['structuredContent']['per'] ?? null) === 'day' && count($r['structuredContent']['times'] ?? []) === 7, 'freier Zeitraum über eine Woche je Tag: ' . count($r['structuredContent']['times'] ?? []));
$r = stapel([]);
pruefe(($r['structuredContent']['per'] ?? null) === 'hour' && str_contains(explode("\n", $r['text'])[0], 'period=today'), 'ohne Zeitraum heute, stündlich: ' . explode("\n", $r['text'])[0]);

/* --- Nur ein Teil der Bilanz -------------------------------------------------------------------- */

$r = stapel(['period' => 'yesterday'], ['import' => 43989]);
pruefe(!$r['isError'] && array_column($r['structuredContent']['series'] ?? [], 'key') === ['grid'], 'nur Netzbezug: ein Stapel: ' . json_encode(array_column($r['structuredContent']['series'] ?? [], 'key')));

/* --- Beschriftungen ---------------------------------------------------------------------------- */

$r = stapel(['period' => 'yesterday']);
pruefe(array_column($r['structuredContent']['series'] ?? [], 'name') === ['grid', 'solar (used directly)', 'battery', 'grid export'], 'Namen der Stapel: ' . json_encode(array_column($r['structuredContent']['series'] ?? [], 'name')));
$r = stapel(['period' => 'yesterday'], null, $de);
pruefe(array_column($r['structuredContent']['series'] ?? [], 'name') === ['Netz', 'PV direkt', 'Batterie', 'Einspeisung'] && ($r['structuredContent']['labels']['per'] ?? null) === 'Stunde', 'deutsch: ' . json_encode(array_column($r['structuredContent']['series'] ?? [], 'name')) . ' ' . json_encode($r['structuredContent']['labels'] ?? null));

/* --- Verbrauch je Gerät (Burkhard 12.10.2026, zweiter Schritt) ------------------------------------ */

// Mit Raum oder Name und nur Zählern unter den Treffern: je Gerät ein Stapel, die fünf größten über den Zeitraum zuerst,
// der Rest als „übrige Geräte“. Keller gestern (Tageswerte im Mitschnitt): Wärmepumpe 9 kWh, Entfeuchter 2,76,
// Kühltruhe 0,77, Kühlschrank 0,30, Weinkühlschrank 0,16, Trockner und Waschmaschine je 0,0004. Der Luftentfeuchter
// teilt den Zähler des Entfeuchters und zählt nicht doppelt.
$r = stapel(['room' => 'Keller', 'period' => 'yesterday']);
$daten = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($daten['kind'] ?? null) === 'consumption' && ($daten['per'] ?? null) === 'hour' && count($daten['times'] ?? []) === 24, 'Keller gestern: Verbrauch je Stunde: ' . json_encode(array_diff_key($daten, ['series' => 0, 'times' => 0])) . ' / ' . strtok($r['text'], "\n"));
pruefe(array_column($daten['series'] ?? [], 'name') === ['Keller · Wärmepumpe', 'Keller · Entfeuchter', 'Keller · Kühltruhe', 'Keller · Kühlschrank', 'Keller · Weinkühlschrank', 'other devices'], 'fünf größte Geräte, dann die übrigen: ' . json_encode(array_column($daten['series'] ?? [], 'name'), JSON_UNESCAPED_UNICODE));
pruefe(array_column($daten['series'] ?? [], 'key') === ['device', 'device', 'device', 'device', 'device', 'other'], 'Schlüssel für die Farben: ' . json_encode(array_column($daten['series'] ?? [], 'key')));
$summe = static fn (array $daten, int $i): float => abs(array_sum(array_map(static fn (array $s): float => (float)array_sum($s['values']), [$daten['series'][$i] ?? ['values' => []]])));
pruefe(abs($summe($daten, 0) - 9.0) < 0.01 && abs($summe($daten, 1) - 2.7646) < 0.002, 'Wärmepumpe in kWh, Entfeuchter von Wh in kWh: ' . $summe($daten, 0) . ' / ' . $summe($daten, 1));
pruefe(str_starts_with(strtok($r['text'], "\n"), 'kWh consumed') || str_contains(strtok($r['text'], "\n"), 'kWh consumed by'), 'das Modell bekommt die Summe wie bei get_state: ' . strtok($r['text'], "\n"));

$r = stapel(['name' => 'Waschmaschine', 'period' => 'yesterday']);
pruefe(!$r['isError'] && array_column($r['structuredContent']['series'] ?? [], 'name') === ['Keller · Waschmaschine'], 'ein Gerät mit Zähler: ein Stapel: ' . json_encode(array_column($r['structuredContent']['series'] ?? [], 'name'), JSON_UNESCAPED_UNICODE) . ' ' . $r['text']);

// Fürs ganze Haus mit type=energy: die größten Geräte, die übrigen und „nicht erfasst“ (Haus aus der Bilanz minus
// Geräte, je Stunde, nicht unter 0). Zusammen mindestens der Hausverbrauch.
$r = stapel(['type' => 'energy', 'period' => 'yesterday']);
$daten = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && array_column($daten['series'] ?? [], 'key') === ['device', 'device', 'device', 'device', 'device', 'other', 'unmetered'], 'ganzes Haus je Gerät mit „nicht erfasst“: ' . json_encode(array_column($daten['series'] ?? [], 'key')) . ' / ' . strtok($r['text'], "\n"));
pruefe(array_slice(array_column($daten['series'] ?? [], 'name'), 0, 3) === ['Keller · Wärmepumpe', 'Keller · Entfeuchter', 'Küche · Spülmaschine'], 'die größten zuerst: ' . json_encode(array_column($daten['series'] ?? [], 'name'), JSON_UNESCAPED_UNICODE));
$falsch = [];
foreach ($daten['times'] ?? [] as $i => $t) {
    $gesamt = array_sum(array_map(static fn (array $s): float => $s['values'][$i], $daten['series']));
    if ($gesamt + 0.002 < max(0.0, $haus[$t])) {
        $falsch[] = date('H:i', $t);
    }
}
pruefe(($daten['times'] ?? []) !== [] && $falsch === [], 'je Stunde mindestens der Hausverbrauch: ' . implode(', ', $falsch));
$r = stapel(['type' => 'energy', 'period' => 'yesterday'], []);
pruefe(!$r['isError'] && !in_array('unmetered', array_column($r['structuredContent']['series'] ?? [], 'key'), true), 'ohne Bilanz kein „nicht erfasst“');

$r = stapel(['room' => 'Keller, Küche', 'type' => 'energy', 'period' => 'yesterday']);
pruefe($r['isError'] && !isset($r['structuredContent']), 'Verbrauch nur für einen Raum je Diagramm: ' . $r['text']);
$r = stapel(['room' => 'Flur', 'type' => 'energy', 'period' => 'yesterday']);
pruefe($r['isError'] && !isset($r['structuredContent']), 'Zähler ohne Archiv: kein Diagramm: ' . $r['text']);

$r = stapel(['room' => 'Keller', 'period' => 'yesterday'], null, $GLOBALS['de']);
pruefe((array_slice($r['structuredContent']['series'] ?? [], -1)[0]['name'] ?? null) === 'übrige Geräte', 'deutsch: ' . json_encode((array_slice($r['structuredContent']['series'] ?? [], -1)[0]['name'] ?? null) ?? null, JSON_UNESCAPED_UNICODE));
$r = stapel(['type' => 'energy', 'period' => 'yesterday'], null, $GLOBALS['de']);
pruefe((array_slice($r['structuredContent']['series'] ?? [], -1)[0]['name'] ?? null) === 'nicht erfasst', 'deutsch: ' . json_encode((array_slice($r['structuredContent']['series'] ?? [], -1)[0]['name'] ?? null) ?? null, JSON_UNESCAPED_UNICODE));

ende('Verbrauchsdiagramm');
