<?php

declare(strict_types=1);

/*
 * Leistung und Zähler an Thermostat und Mediaplayer (Backlog B85, Burkhard 18.10.2026). Ein Klimagerät und ein
 * AV-Receiver nehmen Strom auf; ohne Leistung und Zähler fehlten sie in Gerätesummen, „nicht erfasst“ und Diagrammen.
 *
 * Echt sind alle Werte: das Panasonic-Klimagerät aus betreut_2026-10-13_klima.json, der Denon-Receiver aus
 * nuc_2026-10-11_receiver.json, Leistung #32163 und Zähler #37729 der Steckdose am Fernseher aus nuc_2026-10-18_leistung.json.
 * Angenommen ist, dass diese Leistung und dieser Zähler am Klimagerät bzw. am Receiver hängen: An keiner der Anlagen ist
 * die Leistung eines Klimageräts archiviert (nuc und betreute Anlage am 18.10.2026 durchsucht).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$leistung = fixture('nuc_2026-10-18_leistung.json');
$klima = fixture('betreut_2026-10-13_klima.json');
$receiver = fixture('nuc_2026-10-11_receiver.json');
date_default_timezone_set($leistung['zeitzone']);
$mess = ['32163' => $leistung['variablen']['32163'], '37729' => $leistung['variablen']['37729']];
$messung = ['PowerVariableID' => 32163, 'EnergyVariableID' => 37729];

function frage(array $liste, array $werte, string $werkzeug, array $argumente): array
{
    global $leistung;
    $anlage = new FakeBackend($werte);
    $anlage->jetzt = $leistung['jetzt'];
    try {
        $idx = new DeviceIndex($liste, $anlage);
        return $werkzeug === 'karten' ? $idx->cards($argumente, false) : $idx->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

/** kWh des Zählers gestern aus den Tagesaggregaten der Fixture. */
function gestern(int $id): float
{
    global $leistung;
    $heute = (int)strtotime('today', $leistung['jetzt']);
    $summe = 0.0;
    foreach ($leistung['variablen'][(string)$id]['aggregate']['1'] as $a) {
        if ($a['zeit'] >= (int)strtotime('-1 day', $heute) && $a['zeit'] < $heute) {
            $summe += $a['summe'];
        }
    }
    return strtolower($leistung['variablen'][(string)$id]['einheit']) === 'wh' ? $summe / 1000 : $summe;
}

$watt = $leistung['variablen']['32163']['formatiert'];

/* --- Typen ---------------------------------------------------------------------------------------- */

foreach (['thermostat', 'media'] as $typ) {
    $rollen = DeviceIndex::TYPE_ROLES[$typ]['roles'];
    pruefe(in_array('power', $rollen, true) && in_array('energy', $rollen, true), $typ . ': Leistung und Zähler wahlweise: ' . json_encode($rollen));
}
pruefe(DeviceIndex::TYPE_ROLES['thermostat']['required'] === ['setpoint'] && DeviceIndex::TYPE_ROLES['media']['required'] === ['state', 'playback', 'volume'], 'die Pflichtfelder bleiben');

/* --- Klimagerät ----------------------------------------------------------------------------------- */

$klimaListe = [$klima['geraete'][0] + $messung];
$klimaWerte = $klima['variablen'] + $mess;
$r = frage($klimaListe, $klimaWerte, 'get', ['room' => 'Büro']);
pruefe((explode("\n", $r['text'])[1] ?? '') === 'Büro | Klimagerät | thermostat | setpoint 24 °C (currently 20.0 °C), Aus, fan Auto (' . $watt . ')', 'die Zeile nennt die Leistung hinter Modus und Lüfter: ' . $r['text']);

// Ohne Ist-Temperatur bleibt der Sollwert der Zustand, die Leistung kommt dazu (vorher war die Leistung allein der Zustand)
$ohneIst = [array_diff_key($klimaListe[0], ['StateVariableID' => 0])];
$r = frage($ohneIst, $klimaWerte, 'get', ['room' => 'Büro']);
$zeile = explode("\n", $r['text'])[1] ?? '';
pruefe(str_starts_with($zeile, 'Büro | Klimagerät | thermostat | setpoint 24 °C') && str_contains($zeile, $watt), 'ohne Ist-Temperatur: Sollwert mit Leistung: ' . $zeile);

$r = frage($klimaListe, $klimaWerte, 'get', ['room' => 'Büro', 'period' => 'now']);
// Die Leistung jetzt rundet auf ganze Watt wie bei jedem Gerät
pruefe(!$r['isError'] && (explode("\n", $r['text'])[1] ?? '') === 'Büro | Klimagerät | thermostat | ' . round((float)$leistung['variablen']['32163']['wert']) . ' W', 'Leistung jetzt: ' . $r['text']);

$r = frage($klimaListe, $klimaWerte, 'get', ['room' => 'Büro', 'period' => 'yesterday']);
$kwh = rtrim(rtrim(number_format(gestern(37729), 2, '.', ''), '0'), '.');
pruefe(!$r['isError'] && str_contains($r['text'], $kwh . ' kWh'), 'Verbrauch gestern aus dem Zähler (' . $kwh . ' kWh): ' . $r['text']);

// Die Karte zeigt weiter die Ist-Temperatur als Hauptwert, nicht die Leistung
$r = frage($klimaListe, $klimaWerte, 'karten', ['room' => 'Büro']);
$figur = $r['structuredContent']['cards'][0]['figure'] ?? null;
pruefe(($figur['unit'] ?? '') === '°C', 'Karte: Hauptwert bleibt die Ist-Temperatur: ' . json_encode($figur, JSON_UNESCAPED_UNICODE));

/* --- Mediaplayer ---------------------------------------------------------------------------------- */

$receiverListe = [$receiver['geraete'][0] + $messung];
$receiverWerte = $receiver['variablen'] + $mess;
$r = frage($receiverListe, $receiverWerte, 'get', ['room' => 'Wohnzimmer']);
pruefe((explode("\n", $r['text'])[1] ?? '') === 'Wohnzimmer | Receiver | media | off (' . $watt . ')', 'die Zeile nennt die Leistung: ' . $r['text']);
$r = frage($receiverListe, $receiverWerte, 'get', ['room' => 'Wohnzimmer', 'period' => 'yesterday']);
pruefe(!$r['isError'] && str_contains($r['text'], $kwh . ' kWh'), 'Verbrauch gestern: ' . $r['text']);
$r = frage($receiverListe, $receiverWerte, 'karten', ['room' => 'Wohnzimmer']);
pruefe(($r['structuredContent']['cards'][0]['figure'] ?? null) === null, 'Karte: kein Hauptwert aus der Leistung: ' . json_encode($r['structuredContent']['cards'][0]['figure'] ?? 'fehlt'));

ende('Leistung an Thermostat und Mediaplayer');
