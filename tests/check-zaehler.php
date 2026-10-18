<?php

declare(strict_types=1);

/*
 * Zähler mit „gemessen von“ (Backlog B82, Wunsch Micha87 im Forum, 16.10.2026: Unterverteilungen und PV-Anlagen als
 * Zähler). Ein Zähler (Typ meter) zählt nie in einer Gerätesumme; jedes Gerät und jeder Zähler kann über MeteredBy
 * (Energievariable des Zählers) an einem Zähler hängen, beliebig tief. Je Zähler steht sein Wert, was darunter gemessen
 * ist, und der Rest „nicht erfasst“; fürs Haus zählt die oberste Ebene. Vorbild ist included_in_stat von Home Assistant.
 *
 * Mitschnitt nuc_2026-10-17_zaehler.json: echt sind alle Werte, angenommen ist die Verschachtelung (alle Geräte am
 * Haushaltszähler, die Steckdose des Fernsehers an der Schalterleiste); rechnerisch passt sie, siehe _quelle. Die
 * Erwartungen rechnet der Test selbst aus den Aggregaten der Fixture.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-17_zaehler.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$alle = $mitschnitt['geraete'];
$nurGeraete = array_values(array_map(static fn (array $g): array => array_diff_key($g, ['MeteredBy' => 0]), array_filter($alle, static fn (array $g): bool => $g['Type'] !== 'meter')));

function index(array $liste, bool $bilanz = true): DeviceIndex
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    return new DeviceIndex($liste, $anlage, null, '', $bilanz ? $mitschnitt['bilanz'] : [], $bilanz ? $mitschnitt['leistungsbilanz'] : []);
}

function frage(array $liste, array $argumente): string
{
    try {
        return index($liste)->getState($argumente)['text'];
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
}

/** kWh eines Zählers gestern aus den Tagesaggregaten der Fixture. */
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

function watt(int $id): float
{
    global $mitschnitt;
    return (float)$mitschnitt['variablen'][(string)$id]['wert'];
}

/* --- Typ ------------------------------------------------------------------------------------------ */

pruefe(array_key_exists('meter', DeviceIndex::TYPES) && DeviceIndex::TYPES['meter'] === null, 'es gibt den Typ meter ohne Wörter für an/aus');
pruefe((DeviceIndex::TYPE_ROLES['meter'] ?? null) === ['roles' => ['energy', 'power'], 'required' => ['energy']], 'ein Zähler hat Energie (Pflicht) und Leistung: ' . json_encode(DeviceIndex::TYPE_ROLES['meter'] ?? null));

/* --- Werte aus der Fixture ------------------------------------------------------------------------ */

$haushalt = gestern(40308);
$wallbox = gestern(36579);
$leiste = gestern(16183);
$fernseher = gestern(37729);
// Am Haushalt hängen die Geräte im Keller und in der Küche (Entfeuchter und Luftentfeuchter teilen sich #49570, einmal)
// und die Schalterleiste, an der der Fernseher hängt.
$unterHaushalt = $leiste;
foreach ([49570, 57576, 17393, 39246, 41402, 36893, 42446, 45489] as $id) {
    $unterHaushalt += gestern($id);
}
$haus = gestern(43989) + gestern(10639) + gestern(13436) - gestern(47491) - gestern(17418);
pruefe($haushalt > 0 && $leiste > $fernseher && $haushalt > $unterHaushalt, 'Fixture: Haushalt und Leiste messen mehr als darunter (' . zahl($haushalt) . ', ' . zahl($leiste) . ' kWh)');

/* --- Haus gestern --------------------------------------------------------------------------------- */

$alt = frage($nurGeraete, ['period' => 'yesterday']);
$neu = frage($alle, ['period' => 'yesterday']);
$altZeilen = explode("\n", $alt);
$neuZeilen = explode("\n", $neu);
pruefe(array_slice($neuZeilen, 0, 2) === array_slice($altZeilen, 0, 2), 'Hausverbrauch und Autarkie bleiben gleich: ' . $neuZeilen[0]);
pruefe(str_contains($alt, 'by 9 of 9 metered device(s)'), 'ohne Zähler bleibt der Satz zu den Geräten wie bisher: ' . ($altZeilen[2] ?? ''));
$oben = $haushalt + $wallbox;
pruefe(in_array(sprintf('Of this, %s kWh by 2 meter(s) and 0 device(s) at the top level, %s kWh not metered.', zahl($oben), zahl(max(0.0, $haus - $oben))), $neuZeilen, true),
    'mit Zählern zählt fürs Haus die oberste Ebene: ' . ($neuZeilen[2] ?? ''));
$zeileHaushalt = sprintf('- | Haushalt | meter | %s kWh, of this %s kWh measured below it by 9 meter(s) or device(s), %s kWh not metered', zahl($haushalt), zahl($unterHaushalt), zahl($haushalt - $unterHaushalt));
pruefe(in_array($zeileHaushalt, $neuZeilen, true), 'die Zeile des Haushalts nennt den Rest: erwartet ' . $zeileHaushalt . ' ‖ ' . $neu);
$zeileLeiste = sprintf('Wohnzimmer | Schalterleiste Multimedia | meter | %s kWh, of this %s kWh measured below it by 1 meter(s) or device(s), %s kWh not metered', zahl($leiste), zahl($fernseher), zahl($leiste - $fernseher));
pruefe(in_array($zeileLeiste, $neuZeilen, true), 'die Leiste zählt nur den Fernseher darunter: erwartet ' . $zeileLeiste);
pruefe(in_array(sprintf('- | Wallbox | meter | %s kWh', zahl($wallbox)), $neuZeilen, true), 'ein Zähler ohne etwas darunter nennt nur seinen Wert');
pruefe(array_diff(array_slice($altZeilen, 3), $neuZeilen) === [], 'die Geräte stehen wie ohne Zähler da, kein Zähler unter den größten: ' . json_encode(array_values(array_diff(array_slice($altZeilen, 3), $neuZeilen)), JSON_UNESCAPED_UNICODE));
pruefe(count(array_filter($neuZeilen, static fn (string $z): bool => str_contains($z, '| meter |'))) === 3, 'genau drei Zeilen für Zähler');

/* --- Raum gestern --------------------------------------------------------------------------------- */

$raum = explode("\n", frage($alle, ['room' => 'Wohnzimmer', 'period' => 'yesterday']));
pruefe($raum[0] === sprintf('%s kWh consumed by 1 of 1 metered device(s) (room=Wohnzimmer, period=yesterday).', zahl($fernseher)), 'im Raum zählt der Zähler nicht in der Summe: ' . $raum[0]);
pruefe(in_array($zeileLeiste, $raum, true), 'der Zähler im Raum steht mit Rest da: ' . implode(' ‖ ', $raum));

/* --- Nur Zähler ----------------------------------------------------------------------------------- */

$zaehler = explode("\n", frage($alle, ['type' => 'meter', 'period' => 'yesterday']));
pruefe($zaehler[0] === '3 meter(s) (type=meter, period=yesterday). Meters are not added up, one can contain another.', 'nur Zähler: keine Summe: ' . $zaehler[0]);
pruefe(in_array($zeileHaushalt, $zaehler, true) && in_array($zeileLeiste, $zaehler, true), 'nur Zähler: die Zeilen mit Rest');
$name = explode("\n", frage($alle, ['name' => 'Haushalt', 'period' => 'yesterday']));
pruefe($name[0] === '1 meter(s) (name=Haushalt, period=yesterday). Meters are not added up, one can contain another.' && ($name[1] ?? '') === $zeileHaushalt, 'nach Namen: ' . implode(' ‖ ', $name));

/* --- Leistung jetzt ------------------------------------------------------------------------------- */

$jetzt = explode("\n", frage($alle, ['room' => 'Wohnzimmer', 'period' => 'now']));
pruefe($jetzt[0] === sprintf('%d W used by 1 of 1 device(s) with power measurement (room=Wohnzimmer, period=now).', round(watt(32163))), 'Leistung im Raum ohne Zähler in der Summe: ' . $jetzt[0]);
$zeileLeisteW = sprintf('Wohnzimmer | Schalterleiste Multimedia | meter | %d W, of this %d W measured below it by 1 meter(s) or device(s), %d W not metered', round(watt(55941)), round(watt(32163)), round(watt(55941) - watt(32163)));
pruefe(in_array($zeileLeisteW, $jetzt, true), 'Leistung der Leiste mit Rest: erwartet ' . $zeileLeisteW . ' ‖ ' . implode(' ‖ ', $jetzt));
// Der Haushalt hat keine Leistung: Er zählt mit dem, was darunter gemessen ist, und hat keine eigene Zeile.
$untenW = watt(55941);
foreach ([40189, 53389, 29008, 46928, 29351, 25376, 38565, 42418] as $id) {
    $untenW += watt($id);
}
$hausW = explode("\n", frage($alle, ['period' => 'now']));
$hausLeistung = watt(24942) + watt(14114) + watt(25414) - watt(35568) - watt(55001);
pruefe(in_array(sprintf('Of this, %d W by 1 meter(s) and 0 device(s) at the top level, %d W not metered.', round($untenW), round(max(0.0, $hausLeistung - $untenW))), $hausW, true), 'Hausleistung: oberste Ebene ohne eigene Leistung aus dem darunter: ' . ($hausW[1] ?? ''));
pruefe(!array_any($hausW, static fn (string $z): bool => str_contains($z, '| Haushalt |') || str_contains($z, '| Wallbox |')), 'Zähler ohne Leistung haben jetzt keine Zeile');

/* --- Zustand ohne Zeitraum ------------------------------------------------------------------------ */

$zustand = explode("\n", frage($alle, ['type' => 'meter']));
pruefe(in_array('Wohnzimmer | Schalterleiste Multimedia | meter | ' . $mitschnitt['variablen']['55941']['formatiert'], $zustand, true), 'ein Zähler mit Leistung zeigt sie als Zustand: ' . implode(' ‖ ', $zustand));
pruefe(in_array('- | Haushalt | meter | ' . $mitschnitt['variablen']['40308']['formatiert'], $zustand, true), 'ein Zähler ohne Leistung zeigt seinen Stand');

/* --- Karten --------------------------------------------------------------------------------------- */

try {
    $karten = index($alle)->cards(['room' => 'Wohnzimmer'], true)['structuredContent']['cards'] ?? [];
    $karte = array_values(array_filter($karten, static fn (array $k): bool => ($k['name'] ?? '') === 'Schalterleiste Multimedia'))[0] ?? null;
    pruefe($karte !== null && $karte['group'] === 'measure', 'ein Zähler bekommt eine Karte unter den Messwerten: ' . json_encode($karte, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    pruefe(false, 'Karten: ' . $e->getMessage());
}

/* --- Prüfung der Liste ---------------------------------------------------------------------------- */

$pruefeListe = static fn (array $liste): array => method_exists(DeviceIndex::class, 'meterProblems') ? DeviceIndex::meterProblems($liste) : ['fehlt'];
pruefe($pruefeListe($alle) === [], 'die Liste der Fixture ist fehlerfrei: ' . json_encode($pruefeListe($alle)));
$falsch = $alle;
$falsch[3]['MeteredBy'] = 99999;          // Entfeuchter an einem Zähler, den es nicht gibt
$falsch[4]['MeteredBy'] = 41402;          // Kühlschrank an der Waschmaschine, die kein Zähler ist
$falsch[0]['MeteredBy'] = 16183;          // Haushalt unter der Leiste, die unter dem Haushalt hängt
$falsch[1]['MeteredBy'] = 36579;          // Wallbox unter sich selbst
$probleme = $pruefeListe($falsch);
pruefe($probleme === [0 => 'cycle', 1 => 'cycle', 2 => 'cycle', 3 => 'unknown_meter', 4 => 'unknown_meter'], 'Verweis ins Leere und Zyklus: ' . json_encode($probleme));

/* --- Selbsttest: darunter mehr als der Zähler ----------------------------------------------------- */

$monat = [(int)strtotime('first day of last month 00:00', $mitschnitt['jetzt']), (int)strtotime('first day of this month 00:00', $mitschnitt['jetzt']) - 1];
$ueber = static fn (array $liste): array => method_exists(DeviceIndex::class, 'meterOverruns') ? index($liste)->meterOverruns(...$GLOBALS['monat']) : ['fehlt'];
pruefe($ueber($alle) === [], 'richtig verschachtelt: keine Warnung');
$verkehrt = $alle;
$verkehrt[0]['MeteredBy'] = 0;
$verkehrt[2]['MeteredBy'] = 0;
$verkehrt[12]['MeteredBy'] = 40308;       // Fernseher am Haushalt
foreach ([3, 4, 5, 6, 7, 8, 9, 10, 11] as $i) {
    $verkehrt[$i]['MeteredBy'] = 16183;   // alle Geräte an der Leiste
}
$warnung = $ueber($verkehrt);
pruefe(count($warnung) === 1 && ($warnung[0]['name'] ?? '') === 'Schalterleiste Multimedia' && $warnung[0]['below'] > 1.05 * $warnung[0]['meter'], 'verkehrt verschachtelt: Warnung für die Leiste: ' . json_encode($warnung, JSON_UNESCAPED_UNICODE));

/* --- Diagramm eines Zählers: Stapel aus dem, was darunter hängt, und dem Rest (Stufe c) ------------- */

/** kWh je Stunde gestern eines Zählers der Fixture, Schlüssel ist der Beginn der Stunde. */
function stunden(int $id): array
{
    global $mitschnitt;
    $heute = (int)strtotime('today', $mitschnitt['jetzt']);
    $v = $mitschnitt['variablen'][(string)$id];
    $werte = [];
    foreach ($v['aggregate']['0'] as $a) {
        if ($a['zeit'] >= $heute - 86400 && $a['zeit'] < $heute) {
            $werte[$a['zeit']] = strtolower($v['einheit']) === 'wh' ? $a['summe'] / 1000 : $a['summe'];
        }
    }
    return $werte;
}

function zaehlerDiagramm(array $argumente): array
{
    global $alle;
    try {
        return index($alle)->chart($argumente)['structuredContent'] ?? [];
    } catch (Throwable $e) {
        return ['fehler' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
    }
}

/** Prüft einen Stapel gegen Zähler und Kinder je Stunde; liefert die größte Abweichung in kWh. */
function stapelAbweichung(array $diagramm, array $oben, array $kinder): float
{
    $abweichung = 0.0;
    foreach ($diagramm['times'] ?? [] as $i => $zeit) {
        if (!isset($oben[$zeit])) {
            continue;
        }
        $gemessen = array_sum(array_map(static fn (array $k): float => $k[$zeit] ?? 0.0, $kinder));
        $teile = 0.0;
        $rest = null;
        foreach ($diagramm['series'] as $reihe) {
            if ($reihe['key'] === 'unmetered') {
                $rest = (float)$reihe['values'][$i];
            } else {
                $teile += (float)$reihe['values'][$i];
            }
        }
        $abweichung = max($abweichung, abs($teile - $gemessen), abs(($rest ?? -1.0) - max(0.0, $oben[$zeit] - $gemessen)));
    }
    return ($diagramm['times'] ?? []) === [] ? INF : $abweichung;
}

// Haushalt: alle Geräte hängen an ihm, jede Variable einmal (Entfeuchter und Luftentfeuchter teilen sich #49570)
$kinder = [];
foreach ($alle as $g) {
    if (($g['MeteredBy'] ?? 0) === 40308 && !isset($kinder[$g['EnergyVariableID']])) {
        $kinder[$g['EnergyVariableID']] = stunden($g['EnergyVariableID']);
    }
}
$diagramm = zaehlerDiagramm(['name' => 'Haushalt', 'period' => 'yesterday']);
$schluessel = array_column($diagramm['series'] ?? [], 'key');
pruefe(($diagramm['kind'] ?? '') === 'consumption' && end($schluessel) === 'unmetered' && in_array('other', $schluessel, true) && count($schluessel) === 7,
    'Zähler Haushalt: fünf größte darunter, die übrigen, der Rest: ' . json_encode($diagramm['fehler'] ?? $schluessel));
pruefe(stapelAbweichung($diagramm, stunden(40308), $kinder) <= 0.002, 'Zähler Haushalt: Stapel und Rest je Stunde aus den Zählern, größte Abweichung ' . stapelAbweichung($diagramm, stunden(40308), $kinder));

// Schalterleiste: ein Gerät darunter und der Rest
$diagramm = zaehlerDiagramm(['name' => 'Schalterleiste', 'period' => 'yesterday']);
pruefe(array_column($diagramm['series'] ?? [], 'name') === ['Wohnzimmer · Steckdose Fernseher', 'not metered']
    && stapelAbweichung($diagramm, stunden(16183), [stunden(37729)]) <= 0.002, 'Zähler Schalterleiste: Fernseher und Rest: ' . json_encode($diagramm['fehler'] ?? array_column($diagramm['series'] ?? [], 'name'), JSON_UNESCAPED_UNICODE));

// Haus je Gerät mit Zählern: die oberste Ebene (Haushalt, Wallbox) und der Rest der Bilanz
$hausStunden = [];
foreach (['import' => 1, 'solar' => 1, 'discharge' => 1, 'export' => -1, 'charge' => -1] as $schl => $vorzeichen) {
    foreach (stunden($mitschnitt['bilanz'][$schl]) as $zeit => $kwh) {
        $hausStunden[$zeit] = ($hausStunden[$zeit] ?? 0.0) + $vorzeichen * $kwh;
    }
}
$diagramm = zaehlerDiagramm(['type' => 'energy', 'period' => 'yesterday']);
pruefe(array_column($diagramm['series'] ?? [], 'name') === ['Haushalt', 'Wallbox', 'not metered']
    && stapelAbweichung($diagramm, array_map(static fn (float $v): float => max(0.0, $v), $hausStunden), [stunden(40308), stunden(36579)]) <= 0.002,
    'Haus je Gerät mit Zählern: oberste Ebene und Rest der Bilanz: ' . json_encode($diagramm['fehler'] ?? array_column($diagramm['series'] ?? [], 'name'), JSON_UNESCAPED_UNICODE));

/* --- Gerät mit der Energievariable eines Zählers (Review 18.10.2026, M3) ------------------------- */

// Die Wallbox #36579 ist Zähler; dieselbe Variable an einem Gerät „Auto laden“ (angenommen, Messsteckdose und Gerät
// dahinter) zählte doppelt, wenn das Gerät vor dem Zähler stand. Die Variable zählt einmal, beim Zähler, in jeder Reihenfolge.
$auto = ['Room' => 'Garage', 'Name' => 'Auto laden', 'Aliases' => '', 'Type' => 'socket', 'EnergyVariableID' => 36579, 'PowerVariableID' => 0, 'MeteredBy' => 0, 'Invert' => false];
$oben = static fn (string $text): string => array_values(array_filter(explode("\n", $text), static fn (string $z): bool => str_contains($z, 'at the top level')))[0] ?? '';
$ohne = $oben(frage($alle, ['period' => 'this_month']));
foreach (['vor' => array_merge([$auto], $alle), 'nach' => array_merge($alle, [$auto])] as $wo => $liste) {
    $mit = $oben(frage($liste, ['period' => 'this_month']));
    pruefe($mit === $ohne, 'Gerät mit der Variable der Wallbox ' . $wo . ' dem Zähler: oberste Ebene unverändert, ' . $mit . ' statt ' . $ohne);
}// Ein Typ ohne Energie und Leistung hat kein „gemessen von“; ein alter Wert aus der Zeit als Steckdose sperrte die Tür,
// und das Feld war im Dialog unsichtbar (Review 18.10.2026, M5)
$tuer = ['Room' => 'Flur', 'Name' => 'Haustür', 'Aliases' => '', 'Type' => 'door', 'StateVariableID' => 51561, 'MeteredBy' => 99999, 'Invert' => false];
pruefe($pruefeListe(array_merge($alle, [$tuer])) === [], 'Tür mit altem „gemessen von“ auf einen fehlenden Zähler ist nicht gesperrt: ' . json_encode($pruefeListe(array_merge($alle, [$tuer]))));
$tuer['MeteredBy'] = 40308;
pruefe($oben(frage(array_merge($alle, [$tuer]), ['period' => 'this_month'])) === $ohne, 'Tür mit „gemessen von“ auf den Haushalt ändert die oberste Ebene nicht');

ende('Zähler mit „gemessen von“');
