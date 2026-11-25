<?php

declare(strict_types=1);

/*
 * Hausgeräte mit Betriebsstatus (B117 Stufe 0, Burkhard 25.11.2026): Waschmaschine, Trockner und Spülmaschine hängen an
 * Homematic-Steckdosen mit Leistung; ihr Betriebsstatus und die Restlaufzeit kommen vom Modul Home Connect. Am Hausgerät
 * (appliance) gibt es dafür die wahlweisen Rollen operation (OperationVariableID) und remaining (RemainingVariableID).
 * Dann nennt die Zeile Status und, solange ein Programm läuft, die Restlaufzeit; „aktiv“ heißt „ein Programm läuft“;
 * off schaltet ein Gerät mit laufendem Programm nur mit seinem Namen oder von der Karte aus, sonst wäre „Keller aus“ das
 * Ende der Wäsche. Die Zeitleiste kommt aus dem Betriebsstatus, wenn er archiviert ist.
 *
 * Mitschnitt nuc_2026-11-25_hausgeraete.json (nur gelesen, 25.11.2026 13:07): ECHT sind Werte, Texte, Einträge,
 * Aktion ja/nein, Archivstatus und Rohwerte der letzten sieben Tage. Die Waschmaschine lief (an der Maschine gestartet),
 * die Spülmaschine stand auf „Bereit“ und meldete trotzdem 2:30:00 Restlaufzeit. ANGENOMMEN sind die Zeilen der
 * Freigabeliste (an der Anlage stehen Steckdose und Betriebsstatus getrennt) und, wo es dabeisteht, einzelne Werte.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-25_hausgeraete.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

const WASCH_STECKDOSE = 41006;
const WASCH_STATUS = 54360;
const TROCKNER_STECKDOSE = 40179;
const TROCKNER_STATUS = 52800;
const SPUEL_STECKDOSE = 23567;

/** @var FakeBackend $anlage */
$anlage = null;

function rufe(string $werkzeug, array $argumente, array $werte, ?array $zeilen = null, ?array $uebersetzungen = null, ?string $karte = null): array
{
    global $anlage, $liste, $mitschnitt;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($zeilen ?? $liste, $anlage);
        return match ($werkzeug) {
            'get'   => $index->getState($argumente),
            'set'   => $karte === null ? $index->setState($argumente) : $index->controlDevice(['device' => $karte] + $argumente),
            'karten' => $index->cards($argumente, false),
        };
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

$zeilen = static fn (array $r): array => explode("\n", (string)$r['text']);
$hat = static fn (array $r, string $zeile): bool => in_array($zeile, explode("\n", (string)$r['text']), true);
$gesendet = static function (): array {
    global $anlage;
    return $anlage->requests;
};

/* --- Rollen ---------------------------------------------------------------------------------------- */
pruefe((DeviceIndex::ROLES['operation'] ?? null) === 'OperationVariableID' && (DeviceIndex::ROLES['remaining'] ?? null) === 'RemainingVariableID',
    'die Rollen heißen in der Zeile OperationVariableID und RemainingVariableID');
pruefe(array_intersect(['operation', 'remaining'], DeviceIndex::TYPE_ROLES['appliance']['roles']) === ['operation', 'remaining']
    && array_intersect(['operation', 'remaining'], DeviceIndex::TYPE_ROLES['appliance']['required']) === [], 'das Hausgerät hat beide wahlweise');
foreach (array_keys(DeviceIndex::TYPE_ROLES) as $typ) {
    if ($typ !== 'appliance') {
        pruefe(array_intersect(['operation', 'remaining'], DeviceIndex::TYPE_ROLES[$typ]['roles']) === [], $typ . ' hat keinen Betriebsstatus');
    }
}
pruefe(DeviceIndex::rolesOf($liste[0]) === ['state' => WASCH_STECKDOSE, 'power' => 29351, 'operation' => WASCH_STATUS, 'remaining' => 17384], 'rolesOf liest beide aus der Zeile: ' . json_encode(DeviceIndex::rolesOf($liste[0])));

/* --- Zeile ----------------------------------------------------------------------------------------- */
$r = rufe('get', ['room' => 'Keller'], $werte);
pruefe($hat($r, 'Keller | Waschmaschine | appliance | on, Läuft, 2:50:00 left (4.7 W)'), 'läuft: Status und Restlaufzeit vor der Leistung: ' . $r['text']);
pruefe($hat($r, 'Keller | Trockner | appliance | on, Inaktiv (0.0 W)'), 'inaktiv: nur der Status: ' . $r['text']);
$r = rufe('get', ['room' => 'Küche'], $werte);
pruefe($hat($r, 'Küche | Spülmaschine | appliance | on, Bereit (1.2 W)'), 'echter Fall: „Bereit“ mit 2:30:00 Restlaufzeit nennt keine Restlaufzeit: ' . $r['text']);
$r = rufe('get', ['room' => 'Keller'], $werte, null, $de);
pruefe($hat($r, 'Keller | Waschmaschine | appliance | an, Läuft, noch 2:50:00 (4.7 W)'), 'auf Deutsch: ' . $r['text']);
// ANGENOMMEN: Steckdose des Trockners aus. Der Status ist dann alt, die Zeile nennt nur „off“.
$aus = $werte;
$aus[TROCKNER_STECKDOSE]['wert'] = false;
$aus[TROCKNER_STECKDOSE]['formatiert'] = 'Aus';
$r = rufe('get', ['room' => 'Keller'], $aus);
pruefe($hat($r, 'Keller | Trockner | appliance | off (0.0 W)'), 'Steckdose aus: kein Status: ' . $r['text']);
// Ohne die neuen Felder bleibt die Zeile, wie sie war
$ohne = array_map(static fn (array $g): array => array_diff_key($g, ['OperationVariableID' => 0, 'RemainingVariableID' => 0]), $liste);
$r = rufe('get', ['room' => 'Keller'], $werte, $ohne);
pruefe($hat($r, 'Keller | Waschmaschine | appliance | on (4.7 W)'), 'ohne Betriebsstatus wie bisher (schon vorher richtig): ' . $r['text']);

/* --- aktiv heißt: ein Programm läuft --------------------------------------------------------------- */
$r = rufe('get', ['type' => 'appliance', 'active_only' => true], $werte);
pruefe(str_starts_with($r['text'], '1 ') && str_contains($r['text'], '| Waschmaschine |') && !str_contains($r['text'], '| Trockner |') && !str_contains($r['text'], '| Spülmaschine |'),
    'nur die laufende Waschmaschine ist aktiv, die Steckdosen der anderen sind nur an: ' . $r['text']);
// Alle Status-Einträge des Mitschnitts: laufend sind Run, Pause, DelayedStart, ActionRequired, Aborting
$laufend = [];
foreach ($werte[WASCH_STATUS]['optionen'] as [$wert, $text]) {
    $probe = $werte;
    $probe[WASCH_STATUS]['wert'] = $wert;
    $probe[WASCH_STATUS]['formatiert'] = $text;
    $r = rufe('get', ['name' => 'Waschmaschine', 'active_only' => true], $probe);
    if (str_starts_with($r['text'], '1 ')) {
        $laufend[] = $text;
    }
}
pruefe($laufend === ['Läuft', 'Pause', 'Abbrechen', 'Verzögerter Start', 'Aktion erforderlich'], 'laufend sind genau diese Einträge: ' . json_encode($laufend, JSON_UNESCAPED_UNICODE));

/* --- off nur mit Namen, solange ein Programm läuft ------------------------------------------------- */
$r = rufe('set', ['room' => 'Keller', 'command' => 'off'], $werte);
pruefe(!$r['isError'] && $gesendet() === [[TROCKNER_STECKDOSE, false]] && $hat($r, '1 device(s) left out: a program is running (Waschmaschine). "off" switches such a device off only with its name.'),
    '„Keller aus“ schaltet den Trockner, die laufende Waschmaschine bleibt an: ' . $r['text'] . ' ' . json_encode($gesendet()));
$r = rufe('set', ['type' => 'appliance', 'command' => 'off'], $werte, [$liste[0]]);
pruefe($r['isError'] && $gesendet() === [] && $r['text'] === 'Nothing was changed: a program is running (Waschmaschine). "off" switches such a device off only with its name.',
    'nur die laufende Waschmaschine: Absage ohne Befehl: ' . $r['text']);
$r = rufe('set', ['type' => 'appliance', 'command' => 'off'], $werte, [$liste[0]], $de);
pruefe($r['text'] === 'Nichts geändert: Ein Programm läuft (Waschmaschine). „off“ schaltet ein solches Gerät nur mit seinem Namen aus.', 'Absage auf Deutsch: ' . $r['text']);
$r = rufe('set', ['name' => 'Waschmaschine', 'command' => 'off'], $werte);
pruefe(!$r['isError'] && $gesendet() === [[WASCH_STECKDOSE, false]], 'mit Namen schaltet off die Steckdose: ' . $r['text'] . ' ' . json_encode($gesendet()));
$karte = rufe('karten', ['name' => 'Waschmaschine'], $werte)['structuredContent']['cards'][0]['key'] ?? '';
$r = rufe('set', ['command' => 'off'], $werte, null, null, (string)$karte);
pruefe(!$r['isError'] && $gesendet() === [[WASCH_STECKDOSE, false]], 'von der Karte ebenso: ' . ($r['text'] ?? '') . ' ' . json_encode($gesendet()));
// Ohne die neuen Felder wie bisher
$r = rufe('set', ['room' => 'Keller', 'command' => 'off'], $werte, $ohne);
pruefe(!$r['isError'] && count($gesendet()) === 2, 'ohne Betriebsstatus schaltet off beide Steckdosen (schon vorher richtig): ' . $r['text']);
// on bleibt, wie es war
$r = rufe('set', ['room' => 'Keller', 'command' => 'on'], $aus);
pruefe(!$r['isError'] && $gesendet() === [[TROCKNER_STECKDOSE, true]], 'on schaltet wie bisher: ' . $r['text'] . ' ' . json_encode($gesendet()));

/* --- Zeitleiste aus dem Betriebsstatus ------------------------------------------------------------- */
$heute = date('Y-m-d', $mitschnitt['jetzt']);
$r = rufe('get', ['name' => 'Waschmaschine', 'from' => $heute, 'to' => $heute], $werte);
pruefe(!$r['isError'] && str_contains($r['text'], 'Waschmaschine') && str_contains($r['text'], 'Läuft') && !str_contains($r['text'], 'not archived'),
    'die Steckdose ist nicht archiviert, die Zeitleiste kommt aus dem Betriebsstatus: ' . $r['text']);
$r = rufe('get', ['name' => 'Spülmaschine', 'from' => date('Y-m-d', $mitschnitt['jetzt'] - 6 * 86400), 'to' => $heute], $werte);
pruefe(!$r['isError'] && str_contains($r['text'], 'Bereit') && str_contains($r['text'], 'Läuft'), 'auch neben einer archivierten Steckdose zählt der Betriebsstatus: ' . $r['text']);

ende('Hausgeräte mit Betriebsstatus');
