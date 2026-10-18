<?php

declare(strict_types=1);

/*
 * Klimagerät als Thermostat mit Betriebsart und Lüfterstufe (Backlog B19, 13.10.2026). Ein Klimagerät ist ein
 * Thermostat, der wahlweise Modus und Lüfterstufe hat; beides geht per Name über denselben Weg wie beim
 * Luftentfeuchter (runOption, DeviceBackend::options) und steht auf der Karte als Auswahl.
 * Mitschnitt betreut_2026-10-13_klima.json: Panasonic-Klimagerät über das HomeAssistant-Modul. Werte, Einträge,
 * Aktionen und Zeiten sind echt; die Lüfterstufe kam erst nach 2,7 bis 3,2 s zurück, also nach der Wartezeit von 2 s.
 * Die Zeile der Freigabeliste ist angenommen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('betreut_2026-10-13_klima.json');
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function rufe(array $werte, string $werkzeug, array $argumente, ?array $uebersetzungen = null): array
{
    global $anlage, $liste;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    try {
        $idx = new DeviceIndex($liste, $anlage);
        return match ($werkzeug) {
            'get' => $idx->getState($argumente),
            'karten' => $idx->cards($argumente, false),
            default => $idx->setState($argumente),
        };
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$gesendet = static function (int $variable): array {
    global $anlage;
    return array_values(array_map(static fn (array $q): mixed => $q[1], array_filter($anlage->requests, static fn (array $q): bool => $q[0] === $variable)));
};
$buero = ['room' => 'Büro'];

/* --- Typ und Angebot ------------------------------------------------------------------------------ */

pruefe((DeviceIndex::TYPE_ROLES['thermostat']['roles'] ?? null) === ['state', 'setpoint', 'mode', 'fan', 'power', 'energy'] && (DeviceIndex::TYPE_ROLES['thermostat']['required'] ?? null) === ['setpoint'], 'Thermostat mit Modus, Lüfterstufe, Leistung und Zähler (B85), Pflicht bleibt der Sollwert: ' . json_encode(DeviceIndex::TYPE_ROLES['thermostat'] ?? null));
pruefe(DeviceIndex::abilitiesOf($liste[0]) === ['temperature', 'mode', 'fan_speed', 'off'], 'versteht temperature, mode, fan_speed und off (über den Modus): ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));
$ohne = array_diff_key($liste[0], ['ModeVariableID' => 0, 'FanVariableID' => 0]);
pruefe(DeviceIndex::abilitiesOf($ohne) === ['temperature'], 'ein Thermostat ohne Modus bleibt, wie er war: ' . json_encode(DeviceIndex::abilitiesOf($ohne)));

/* --- Abfrage -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'get', $buero);
pruefe($zeile($r, 1) === 'Büro | Klimagerät | thermostat | setpoint 24 °C (currently 20.0 °C), Aus, fan Auto', 'Zeile mit Modus und Lüfterstufe: ' . $r['text']);
$r = rufe($werte, 'get', $buero, $de);
pruefe($zeile($r, 1) === 'Büro | Klimagerät | thermostat | Soll 24 °C (derzeit 20.0 °C), Aus, Lüfter Auto', 'auf Deutsch: ' . $r['text']);

/* --- Stellen -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', $buero + ['mode' => 'Nur Lüfter'], $de);
pruefe(!$r['isError'] && $gesendet(53427) === ['fan_only'] && str_starts_with($r['text'], '1 von 1 Geräten auf Nur Lüfter gestellt'), 'Modus per Beschriftung, gesendet wird der Wert, nach 1,2 s bestätigt: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['mode' => 'off']);
pruefe(!$r['isError'] && $gesendet(53427) === [] && str_contains($r['text'], 'already set to Aus'), 'steht schon auf Aus: nichts gesendet: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['fan_speed' => 'Niedrig'], $de);
pruefe($gesendet(32329) === ['Low'] && str_contains($r['text'], 'noch nicht bestätigt'), 'Lüfterstufe gesendet; das Gerät meldet sie erst nach 2,7 s, die Antwort sagt es ehrlich: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['fan_speed' => 'Kühlen']);
pruefe($gesendet(32329) === [] && str_contains($r['text'], '"Kühlen" is a mode, give it as mode'), 'ein Modus im Feld der Lüfterstufe: Hinweis, nichts gesendet: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['temperature' => 22]);
pruefe(!$r['isError'] && $gesendet(20547) === [22.0], 'Solltemperatur geht weiter: ' . $r['text']);

/* --- Ausschalten über den Modus (Burkhard 13.10.2026) --------------------------------------------- */

// Ausgangszustand „Nur Lüfter“ wie im Mitschnitt nach dem ersten Befehl; „off“ kam dort nach 1,5 s zurück.
$laeuft = $werte;
$laeuft['53427']['wert'] = 'fan_only';
$laeuft['53427']['formatiert'] = 'Nur Lüfter';
$r = rufe($laeuft, 'set', $buero + ['command' => 'off'], $de);
pruefe(!$r['isError'] && $gesendet(53427) === ['off'] && str_starts_with($r['text'], '1 von 1 Geräten sind jetzt aus'), 'command=off setzt den Modus „Aus“, bestätigt: ' . $r['text']);
pruefe($gesendet(43761) === [] && $gesendet(19646) === [], 'die Ist-Temperatur (Rolle state) wird nicht geschaltet: ' . json_encode($anlage->requests));
$r = rufe($werte, 'set', $buero + ['command' => 'off'], $de);
pruefe(!$r['isError'] && $gesendet(53427) === [] && str_contains($r['text'], 'stand schon auf Aus'), 'steht schon auf Aus: nichts gesendet: ' . $r['text']);
$r = rufe($laeuft, 'set', $buero + ['command' => 'on']);
pruefe($r['isError'] && $anlage->requests === [] && str_contains($r['text'], 'mode'), 'command=on bleibt eine Absage, sie nennt mode (Heizen, Kühlen oder Auto ist nicht eindeutig): ' . $r['text']);
$ohneAus = $laeuft;
$ohneAus['53427']['optionen'] = array_values(array_filter($laeuft['53427']['optionen'], static fn (array $o): bool => $o[0] !== 'off'));
$r = rufe($ohneAus, 'set', $buero + ['command' => 'off']);
pruefe($anlage->requests === [] && str_contains($r['text'], 'unknown entry "off"'), 'ohne Eintrag „off“ geht nichts hinaus, die Zeile nennt die Einträge (angenommener Fall): ' . $r['text']);

/* --- Karte ---------------------------------------------------------------------------------------- */

// Ein Klimagerät im Modus Aus ist nicht aktiv, die Karte wird grau wie jedes ausgeschaltete Gerät (Layoutvergleich
// 13.10.2026: „AUS“ stand blau da). In einem anderen Modus ist es aktiv.
$r = rufe($werte, 'karten', $buero, $de);
pruefe(($r['structuredContent']['cards'][0]['active'] ?? 'fehlt') === false, 'Modus Aus: Karte nicht aktiv: ' . json_encode($r['structuredContent']['cards'][0]['active'] ?? 'fehlt'));
$laeuft = $werte;
$laeuft['53427']['wert'] = 'fan_only';
$laeuft['53427']['formatiert'] = 'Nur Lüfter';
$r = rufe($laeuft, 'karten', $buero, $de);
pruefe(($r['structuredContent']['cards'][0]['active'] ?? 'fehlt') === true, 'Modus Nur Lüfter: Karte aktiv: ' . json_encode($r['structuredContent']['cards'][0]['active'] ?? 'fehlt'));

$r = rufe($werte, 'karten', $buero, $de);
$karte = $r['structuredContent']['cards'][0] ?? [];
pruefe(($karte['controls']['setpoint']['field'] ?? null) === 'temperature' && array_column($karte['controls']['options'] ?? [], 'value') === ['Aus', 'Auto'] && count($karte['controls']['options'][0]['entries'] ?? []) === 6 && !isset($karte['controls']['switch']), 'Karte (ohne Schalter, Aus steht in der Auswahl): Sollwert, Auswahl Modus und Lüfterstufe: ' . json_encode($karte['controls'] ?? null, JSON_UNESCAPED_UNICODE));

ende('Klimagerät');
