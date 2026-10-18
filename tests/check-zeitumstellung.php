<?php

declare(strict_types=1);

/*
 * Stunden am Tag der Umstellung auf Winterzeit (Review 18.10.2026, M1): Das Archiv liefert die Stunde 02:00 zweimal
 * (02:00 Sommerzeit und 02:00 Winterzeit). Seit build 195 setzt sectionStart jeden Zeitpunkt auf den Beginn seines
 * Abschnitts; mit mktime() fiel die zweite Stunde 02:00 auf die erste. Der Stapel des Hauses zählte dann zwei Stunden
 * in einer Säule, der Verlauf eines Messwerts verlor eine Stunde.
 *
 * Mitschnitt nuc_2025-10-26_zeitumstellung.json, Werte echt (siehe _quelle); die Bilanz mit nur einer Netzzeile und der
 * Netzbezug als Messwert sind angenommen. Orakel sind die 25 Zeilen der Fixture selbst.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2025-10-26_zeitumstellung.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$v = $mitschnitt['variablen'];
$zaehler = $v['43989']['aggregate']['0'];
$leistung = $v['24942']['aggregate']['0'];

function index(array $geraete = []): DeviceIndex
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    $index = new DeviceIndex($geraete, $anlage);
    $index->addSources([['Type' => 'grid', 'Name' => 'Netz', 'EnergyIn' => 43989, 'EnergyOut' => 0, 'PowerIn' => 24942, 'PowerOut' => 0, 'Invert' => false]]);
    return $index;
}

$doppelt = count($zaehler) === 25 && date('H', $zaehler[2]['zeit']) === '02' && date('H', $zaehler[3]['zeit']) === '02';
pruefe($doppelt, 'Fixture hat 25 Stunden, 02:00 zweimal');

// Stapel des Hauses je Stunde
$c = index()->chart(['from' => '2025-10-26', 'to' => '2025-10-26'])['structuredContent'] ?? [];
$zeiten = $c['times'] ?? [];
pruefe(count($zeiten) === 25, 'Hausstapel: 25 Säulen, nicht ' . count($zeiten));
pruefe($zeiten === array_column($zaehler, 'zeit'), 'Hausstapel: Zeitpunkte sind die Stundenbeginne des Archivs');
$netz = [];
foreach ($c['series'] ?? [] as $reihe) {
    $netz = ($reihe['key'] ?? '') === 'grid' ? $reihe['values'] : $netz;
}
$soll = array_map(static fn (array $a): float => round($a['summe'], 3), $zaehler);
pruefe(($netz[3] ?? null) === $soll[3], 'Hausstapel: zweite Stunde 02:00 hat ihren eigenen Netzbezug, ' . json_encode(array_slice($netz, 2, 2)) . ' statt ' . json_encode(array_slice($soll, 2, 2)));
pruefe($netz === $soll, 'Hausstapel: Netzbezug je Stunde wie im Archiv');

// Verlauf eines Messwerts je Stunde
$geraet = ['Room' => 'Keller', 'Name' => 'Netzbezug', 'Aliases' => '', 'Type' => 'measurement', 'StateVariableID' => 24942, 'ReadOnly' => true];
$verlauf = index([$geraet])->chart(['name' => 'Netzbezug', 'from' => '2025-10-26', 'to' => '2025-10-26'])['structuredContent'] ?? [];
$punkte = $verlauf['series'][0]['points'] ?? [];
pruefe(count($punkte) === 25, 'Verlauf: 25 Stunden, nicht ' . count($punkte));
pruefe(array_column($punkte, 0) === array_column($leistung, 'zeit'), 'Verlauf: Zeitpunkte sind die Stundenbeginne des Archivs');
$mittel = array_map(static fn (array $p): float => round((float)$p[2], 1), $punkte);
foreach ([2, 3] as $n) {
    $sollMittel = round($leistung[$n]['mittel'], 1);
    pruefe(in_array($sollMittel, $mittel, true), 'Verlauf: ' . ($n - 1) . '. Stunde 02:00 behält ihr Mittel ' . $sollMittel);
}

ende('Zeitumstellung');
