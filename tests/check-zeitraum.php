<?php

declare(strict_types=1);

/*
 * Freie Zeiträume für den Verbrauch (09.10.2026, aus dem Vergleich mit Home Assistant): get_state
 * nimmt statt „period“ auch „from“ und „to“ (erster und letzter Tag, JJJJ-MM-TT, beide
 * eingeschlossen; ohne „to“ nur der eine Tag). Das Werkzeug nimmt die gröbste passende Stufe des
 * Archivs: ganze Jahre, ganze Monate, sonst Tage; reicht der Zeitraum bis heute, zählt er bis jetzt.
 * Mitschnitt wie check-bilanz.php. Die Archivdaten des Netzbezugs am nuc sind vor Februar 2026
 * unbrauchbar (SMA #43989: 2025 und Januar 0, Februar 29.873 kWh); geprüft wird der Hausverbrauch
 * deshalb nur mit Oktober und September, Januar bis März und 2025 nur an der Wärmepumpe.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-09_energiebilanz.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$bilanz = $mitschnitt['bilanz'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var list<FakeBackend> */
$anlagen = [];

function index(array $liste, array $werte, array $bilanz, ?array $uebersetzungen = null): DeviceIndex
{
    global $anlagen, $mitschnitt;
    $anlagen[] = $backend = new FakeBackend($werte, $uebersetzungen);
    $backend->jetzt = $mitschnitt['jetzt'];
    return new DeviceIndex($liste, $backend, null, '', $bilanz);
}

function frage(DeviceIndex $idx, array $argumente): array
{
    try {
        return $idx->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$idx = index($liste, $werte, $bilanz);

/* --- Tage ------------------------------------------------------------------------------------- */

$r = frage($idx, ['from' => '2026-10-01', 'to' => '2026-10-05']);
pruefe(!$r['isError'] && $zeile($r, 0) === 'House consumption 118.8 kWh (all devices, from=2026-10-01, to=2026-10-05): grid import 16.5, solar 285.2, battery discharge 43.5, grid export 178.2, battery charge 48.1 kWh.', '1. bis 5. Oktober aus Tageswerten: ' . $r['text']);
pruefe($zeile($r, 1) === 'Self-sufficiency 86 %, self-consumed solar 37 %.' && $zeile($r, 2) === 'Of this, 27.2 kWh by 4 of 4 metered device(s), 91.6 kWh not metered.', 'mit Autarkie und Geräteanteil: ' . $r['text']);
$r = frage($idx, ['from' => '2026-10-03']);
pruefe(str_starts_with($zeile($r, 0), 'House consumption 21.3 kWh (all devices, from=2026-10-03, to=2026-10-03): grid import 1.2,'), 'ohne „to“ nur der eine Tag: ' . $r['text']);
$r = frage($idx, ['from' => '2026-10-09']);
$heute = frage($idx, ['period' => 'today']);
pruefe(str_replace('from=2026-10-09, to=2026-10-09', 'period=today', $zeile($r, 0)) === $zeile($heute, 0), 'heute als Datum zählt bis jetzt wie period=today: ' . $r['text']);

/* --- Monate und Jahre ------------------------------------------------------------------------- */

$r = frage($idx, ['from' => '2026-09-01', 'to' => '2026-09-30']);
$september = frage($idx, ['period' => 'last_month']);
pruefe(str_replace('from=2026-09-01, to=2026-09-30', 'period=last_month', $r['text']) === $september['text'], 'der ganze September wie period=last_month: ' . $r['text']);
$r = frage($idx, ['name' => 'Wärmepumpe', 'from' => '2026-01-01', 'to' => '2026-03-31']);
pruefe($r['text'] === "3009 kWh consumed by 1 of 1 metered device(s) (name=Wärmepumpe, from=2026-01-01, to=2026-03-31).\nTechnik | Wärmepumpe | appliance | 3009 kWh", 'Januar bis März aus Monatswerten: ' . $r['text']);
$r = frage($idx, ['name' => 'Wärmepumpe', 'from' => '2025-01-01', 'to' => '2025-12-31']);
pruefe(str_ends_with($r['text'], '| 5631 kWh') && str_replace('from=2025-01-01, to=2025-12-31', 'period=last_year', $r['text']) === frage($idx, ['name' => 'Wärmepumpe', 'period' => 'last_year'])['text'], 'das Jahr 2025 wie period=last_year: ' . $r['text']);
$r = frage($idx, ['name' => 'Wärmepumpe', 'from' => '2026-10-01', 'to' => '2026-10-31']);
pruefe(str_replace('from=2026-10-01, to=2026-10-31', 'period=this_month', $r['text']) === frage($idx, ['name' => 'Wärmepumpe', 'period' => 'this_month'])['text'], 'der laufende Monat zählt bis jetzt: ' . $r['text']);
$r = frage($idx, ['name' => 'Wärmepumpe', 'from' => '2026-09-28', 'to' => '2026-10-02']);
pruefe(str_ends_with($r['text'], '| 9 kWh'), 'über die Monatsgrenze aus Tageswerten (28.9. bis 2.10.: 2+2+2+1+2): ' . $r['text']);

/* --- Fehler ------------------------------------------------------------------------------------ */

$r = frage($idx, ['from' => '2026-13-01']);
pruefe($r['isError'] && $r['text'] === 'Parameter "from" must be a date like 2026-01-31.', 'kein gültiges Datum: ' . $r['text']);
$r = frage($idx, ['from' => '2026-10-05', 'to' => '2026-10-01']);
pruefe($r['isError'] && $r['text'] === 'Parameter "to" must not be before "from".', '„to“ vor „from“: ' . $r['text']);
$r = frage($idx, ['period' => 'last_month', 'from' => '2026-09-01']);
pruefe($r['isError'] && $r['text'] === 'Give either period or from and to, not both.', 'period und from zugleich: ' . $r['text']);
$r = frage($idx, ['to' => '2026-09-30']);
pruefe($r['isError'] && $r['text'] === 'Parameter "to" needs "from".', '„to“ ohne „from“: ' . $r['text']);
$r = frage($idx, ['from' => 'Januar']);
pruefe($r['isError'] && str_starts_with($r['text'], 'Parameter "from" must be a date'), 'ein Wort statt eines Datums: ' . $r['text']);

/* --- Deutsch ---------------------------------------------------------------------------------- */

$r = frage(index($liste, $werte, $bilanz, $de), ['from' => '2026-10-01', 'to' => '2026-10-05']);
pruefe(str_starts_with($zeile($r, 0), 'Hausverbrauch 118.8 kWh (alle Geräte, from=2026-10-01, to=2026-10-05):'), 'deutsche Kopfzeile: ' . $r['text']);
foreach ([['from' => '2026-13-01'], ['from' => '2026-10-05', 'to' => '2026-10-01'], ['period' => 'today', 'from' => '2026-10-01'], ['to' => '2026-10-01']] as $argumente) {
    frage(index($liste, $werte, $bilanz, $de), $argumente);
}

$fehlend = [];
foreach ($anlagen as $anlage) {
    $fehlend = array_merge($fehlend, $anlage->missing);
}
pruefe($fehlend === [], 'jeder neue Text hat eine deutsche Übersetzung, es fehlen: ' . implode(' / ', array_unique($fehlend)));

ende('Freie Zeiträume');
