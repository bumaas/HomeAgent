<?php

declare(strict_types=1);

/*
 * Lüftung (Typ fan): Ein/Aus, Lüfterstufe und Betriebsmodus per Name, über denselben Weg wie beim Luftentfeuchter
 * (runOption, DeviceBackend::options), hier mit Ganzzahlwerten aus den Profilzuordnungen.
 * Mitschnitt nuc_2026-10-12_lueftung.json: Ambientika im Büro. Werte, Zuordnungen und Aktionen sind echt, ebenso
 * der Sprung des Modus auf „Smart“ nach dem Stellen der Lüfterstufe (Fehler im Ambientika-Modul, Backlog B56). Dass
 * der neue Wert nach 100 ms in der Variable steht, ist aus dem Quelltext des Ambientika-Moduls angenommen; die
 * Messung konnte erst nach dem Ende von RequestAction (2,2 bis 2,4 s) lesen. Ein/Aus ist nicht gemessen.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-12_lueftung.json');
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
        return $werkzeug === 'get' ? $idx->getState($argumente) : $idx->setState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$gesendet = static function (int $variable): array {
    global $anlage;
    return array_values(array_map(static fn (array $q): mixed => $q[1], array_filter($anlage->requests, static fn (array $q): bool => $q[0] === $variable)));
};
$buero = ['room' => 'Büro', 'type' => 'fan'];

/* --- Typ und Angebot ------------------------------------------------------------------------------ */

pruefe(array_key_exists('fan', DeviceIndex::TYPES) && in_array('fan', DeviceIndex::CONTROLLABLE, true), 'Typ fan, steuerbar');
pruefe((DeviceIndex::TYPE_ROLES['fan']['roles'] ?? null) === ['state', 'mode', 'fan', 'power', 'energy'] && (DeviceIndex::TYPE_ROLES['fan']['required'] ?? null) === ['state', 'fan'], 'Fähigkeiten der Lüftung, Ein/Aus oder Lüfterstufe ist Pflicht: ' . json_encode(DeviceIndex::TYPE_ROLES['fan'] ?? null));
pruefe(DeviceIndex::abilitiesOf($liste[0]) === ['on', 'off', 'mode', 'fan_speed'], 'versteht on, off, mode, fan_speed: ' . json_encode(DeviceIndex::abilitiesOf($liste[0])));
pruefe(array_slice(DeviceIndex::offeredAbilities($liste), -2) === ['mode', 'fan_speed'], 'das Schema bietet mode und fan_speed an: ' . json_encode(DeviceIndex::offeredAbilities($liste)));

/* --- Abfrage -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'get', $buero);
pruefe($zeile($r, 1) === 'Büro | Lüftung | fan | on (Manuelle Wärmerückgewinnung, fan niedrig)', 'Zeile mit Modus und Lüfterstufe: ' . $r['text']);
$r = rufe($werte, 'get', $buero, $de);
pruefe($zeile($r, 1) === 'Büro | Lüftung | fan | an (Manuelle Wärmerückgewinnung, Lüfter niedrig)', 'auf Deutsch: ' . $r['text']);
$r = rufe($werte, 'get', $buero + ['active_only' => true]);
pruefe(str_starts_with($r['text'], '1 of 1 checked devices active'), 'eingeschaltet zählt als aktiv: ' . $r['text']);
$r = rufe($werte, 'get', ['name' => 'Ambientika']);
pruefe($zeile($r, 1) === 'Büro | Lüftung (Ambientika) | fan | on (Manuelle Wärmerückgewinnung, fan niedrig)', 'über den Alias: ' . $r['text']);

/* --- Stellen -------------------------------------------------------------------------------------- */

$r = rufe($werte, 'set', $buero + ['fan_speed' => 'mittel'], $de);
pruefe($gesendet(20833) === [2] && $zeile($r, 0) === '1 von 1 Geräten auf mittel gestellt (room=Büro, type=fan).', 'Lüfterstufe mittel: gesendet wird der Wert 2 aus dem Profil: ' . $r['text']);
pruefe($zeile($r, 1) === 'Büro | Lüftung | fan | an (Manuelle Wärmerückgewinnung, Lüfter mittel)', 'die Zeile nennt die neue Stufe; der Modussprung des Ambientika-Moduls kommt erst nach 2,35 s: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['fan_speed' => 'high']);
pruefe($anlage->requests === [] && str_contains($zeile($r, 1), 'unknown entry "high", known: niedrig, mittel, hoch'), 'englische Wörter treffen die deutschen Einträge nicht, die Zeile nennt sie: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['mode' => 'Nacht'], $de);
pruefe($gesendet(31702) === [3] && $zeile($r, 0) === '1 von 1 Geräten auf Nacht gestellt (room=Büro, type=fan).', 'Betriebsmodus Nacht: Wert 3: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['mode' => 'Manuelle Wärmerückgewinnung'], $de);
pruefe($anlage->requests === [] && str_contains($zeile($r, 1), 'stand schon auf Manuelle Wärmerückgewinnung'), 'schon in diesem Modus, nichts gesendet: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['fan_speed' => 'Nacht']);
pruefe($anlage->requests === [] && $zeile($r, 1) === 'Büro | Lüftung | fan | "Nacht" is a mode, give it as mode', 'Modus im Feld der Lüfterstufe: Hinweis: ' . $r['text']);
$r = rufe($werte, 'set', $buero + ['command' => 'off'], $de);
pruefe($gesendet(46282) === [false] && str_contains($zeile($r, 1), 'aus'), 'ausschalten über den Schaltzustand: ' . $r['text']);
$r = rufe($werte, 'set', ['type' => 'fan', 'command' => 'on']);
pruefe($anlage->requests === [] && str_contains($zeile($r, 1), 'already on'), 'schon an: nichts gesendet: ' . $r['text']);

foreach (['Ventilation/fan'] as $text) {
    pruefe(isset($de[$text]), 'deutsche Übersetzung für „' . $text . '“');
}

ende('Lüftung');
