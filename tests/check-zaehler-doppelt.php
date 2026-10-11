<?php

declare(strict_types=1);

/*
 * Ein Zähler oder eine Leistung an zwei Geräten zählt nur einmal (Backlog B47). Anlass 11.10.2026: Der
 * Luftentfeuchter im Keller und seine Messsteckdose „Entfeuchter“ sind dasselbe Gerät; mit Leistung und Zähler
 * an beiden Zeilen wäre der Keller doppelt gezählt worden. Dazu kann der Luftentfeuchter Leistung und Zähler tragen.
 * Mitschnitte nuc_2026-10-09_energie.json (Zähler #49570 mit Archiv-Aggregaten) und nuc_2026-10-09_leistung.json
 * (Leistung #40189); die Zeile „Luftentfeuchter“ mit denselben Variablen ist die Freigabe vom 11.10. ohne die
 * übrigen Fähigkeiten, deren Variablen in diesen Mitschnitten fehlen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$energie = fixture('nuc_2026-10-09_energie.json');
$leistung = fixture('nuc_2026-10-09_leistung.json');
$zweite = ['Room' => 'Keller', 'Name' => 'Luftentfeuchter', 'Aliases' => '', 'Type' => 'humidifier', 'Invert' => false, 'PowerVariableID' => 40189, 'EnergyVariableID' => 49570];

function frage(array $mitschnitt, array $liste, array $argumente): string
{
    $anlage = new FakeBackend($mitschnitt['variablen']);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        return (new DeviceIndex($liste, $anlage))->getState($argumente)['text'];
    } catch (Throwable $e) {
        return 'FEHLT: ' . $e->getMessage();
    }
}

pruefe(array_intersect(['power', 'energy'], DeviceIndex::TYPE_ROLES['humidifier']['roles'] ?? []) === ['power', 'energy'], 'der Luftentfeuchter kann Leistung und Zähler tragen: ' . json_encode(DeviceIndex::TYPE_ROLES['humidifier'] ?? null));

/* --- Verbrauch ------------------------------------------------------------------------------------ */

$ohne = frage($energie, $energie['geraete'], ['room' => 'Keller', 'period' => 'yesterday']);
$mit = frage($energie, array_merge($energie['geraete'], [$zweite]), ['room' => 'Keller', 'period' => 'yesterday']);
pruefe($mit === $ohne, 'der Keller zählt den Zähler einmal, unter dem ersten Gerät: ' . $mit . ' ‖ ohne zweite Zeile: ' . $ohne);
$allein = frage($energie, array_merge($energie['geraete'], [$zweite]), ['name' => 'Luftentfeuchter', 'period' => 'yesterday']);
$steckdose = frage($energie, $energie['geraete'], ['name' => 'Entfeuchter', 'period' => 'yesterday']);
pruefe(strtok($allein, ' ') === strtok($steckdose, ' ') && str_contains($allein, 'Keller | Luftentfeuchter | humidifier |'), 'nach Namen gefragt, zählt der Luftentfeuchter selbst: ' . $allein);
$haus = frage($energie, array_merge($energie['geraete'], [$zweite]), ['period' => 'yesterday']);
$hausOhne = frage($energie, $energie['geraete'], ['period' => 'yesterday']);
pruefe($haus === $hausOhne, 'fürs ganze Haus ebenso: ' . $haus);

/* --- Leistung ------------------------------------------------------------------------------------- */

$ohne = frage($leistung, $leistung['geraete'], ['room' => 'Keller', 'period' => 'now']);
$mit = frage($leistung, array_merge($leistung['geraete'], [$zweite]), ['room' => 'Keller', 'period' => 'now']);
pruefe($mit === $ohne, 'die Leistung zählt ebenfalls einmal: ' . $mit . ' ‖ ' . $ohne);

ende('Zähler an zwei Geräten');
