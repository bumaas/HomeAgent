<?php

declare(strict_types=1);

/*
 * Verlauf der Innentemperatur (Burkhard 13.11.2026): Hängt der Fühler als InsideVariableID am Gerät, gab es für ihn
 * nur den Wert jetzt, kein „Wie kalt war die Kühltruhe gestern?“ und kein Diagramm. Jetzt antwortet ein Gerät, das mit
 * Namen und type=temperature (oder type=measurement) gefragt wird, mit seiner Innentemperatur als Messwert. Damit laufen
 * Zustand, Verlauf, Diagramm und Heatmap über die Wege, die es für Messwerte schon gibt. Ohne Namen bleibt alles wie
 * bisher: type=temperature meint das Klima des Raums, eine eingetragene Zeile geht der gedachten vor.
 * Mitschnitt nuc_2026-11-13_innentemperatur_verlauf.json (was daran angenommen ist, steht in _quelle).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once dirname(__DIR__) . '/libs/RoomIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-11-13_innentemperatur_verlauf.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

function frage(array $argumente, string $werkzeug = 'get', ?array $uebersetzungen = null, ?array $zeilen = null): array
{
    global $mitschnitt;
    $anlage = new FakeBackend($mitschnitt['variablen'], $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        $index = new DeviceIndex($zeilen ?? $mitschnitt['geraete'], $anlage);
        return match ($werkzeug) {
            'chart' => $index->chart($argumente),
            'set'   => $index->setState($argumente),
            default => $index->getState($argumente),
        };
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'isError' => true];
    }
}

$zeile = static fn (array $r, int $n): string => explode("\n", $r['text'])[$n] ?? '';
$hinweis = 'For the inside temperature over time ask with name and type=temperature.';

/* --- Wert jetzt ------------------------------------------------------------------------------------ */

foreach (['temperature', 'measurement'] as $typ) {
    $r = frage(['name' => 'Kühltruhe', 'type' => $typ]);
    pruefe(!$r['isError'] && $zeile($r, 0) === '1 device(s) found (type=' . $typ . ', name=Kühltruhe).' && $zeile($r, 1) === 'Keller | Kühltruhe | measurement | -19.0 °C', 'mit Namen und type=' . $typ . ' antwortet die Kühltruhe mit ihrer Innentemperatur: ' . $r['text']);
}
$r = frage(['name' => 'Gefriertruhe', 'type' => 'temperature']);
pruefe(str_contains($zeile($r, 1), '| measurement | -19.0 °C'), 'auch über den Alias des Geräts: ' . $r['text']);
$r = frage(['name' => 'Kühl', 'type' => 'temperature']);
pruefe(str_starts_with($zeile($r, 0), '2 device(s) found') && str_contains($r['text'], 'Kühlschrank | measurement | 11.9 °C') && str_contains($r['text'], 'Kühltruhe | measurement | -19.0 °C'), 'ein Teilname trifft beide Kühlgeräte: ' . $r['text']);

/* --- Verlauf --------------------------------------------------------------------------------------- */

$r = frage(['name' => 'Kühltruhe', 'type' => 'temperature', 'period' => 'yesterday']);
pruefe(!$r['isError'] && $zeile($r, 0) === 'History of 1 measured value(s) (type=temperature, name=Kühltruhe, period=yesterday), per hour: minimum / average / maximum.', 'gestern je Stunde: ' . $zeile($r, 0));
pruefe(str_starts_with($zeile($r, 1), 'Keller | Kühltruhe | measurement | minimum -21.') && str_contains($zeile($r, 1), 'maximum -15.'), 'die Zeile nennt Minimum und Maximum des Tages: ' . $zeile($r, 1));
pruefe(substr_count($r['text'], "\n2026-11-12 ") === 24 && str_contains($r['text'], "\n2026-11-12 00:00 -21.1 / -17.5 / -15.4"), '24 Stunden mit den Werten des Archivs: ' . substr($r['text'], 0, 420));
$r = frage(['name' => 'Kühltruhe', 'type' => 'measurement', 'from' => '2026-11-06', 'to' => '2026-11-12']);
pruefe(!$r['isError'] && str_contains($zeile($r, 0), 'per day') && substr_count($r['text'], "\n2026-11-") === 7, 'eine Woche je Tag, auch mit type=measurement: ' . substr($r['text'], 0, 420));
$r = frage(['name' => 'Kühltruhe', 'type' => 'temperature', 'period' => 'yesterday'], 'get', $de);
pruefe(str_starts_with($zeile($r, 0), 'Verlauf von 1 Messwert(en)') && str_starts_with($zeile($r, 1), 'Keller | Kühltruhe | measurement | Minimum -21.'), 'deutsch ebenso: ' . substr($r['text'], 0, 300));
$r = frage(['name' => 'Kühlschrank', 'type' => 'temperature', 'period' => 'yesterday']);
pruefe(str_contains($r['text'], 'Keller | Kühlschrank | measurement | no history archived'), 'ein Fühler ohne Archiv sagt das: ' . $r['text']);

/* --- Diagramme ------------------------------------------------------------------------------------- */

$r = frage(['name' => 'Kühltruhe', 'type' => 'temperature', 'period' => 'yesterday'], 'chart');
$daten = $r['structuredContent'] ?? [];
pruefe(!$r['isError'] && ($daten['kind'] ?? '') === 'history' && count($daten['series'] ?? []) === 1, 'show_chart zeichnet den Verlauf als eine Reihe: ' . substr($r['text'], 0, 300) . ' ' . json_encode(array_keys($daten)));
$r = frage(['name' => 'Kühltruhe', 'type' => 'temperature', 'heatmap' => true, 'from' => '2026-11-06', 'to' => '2026-11-12'], 'chart');
pruefe(!$r['isError'] && ($r['structuredContent']['kind'] ?? '') === 'heatmap', 'auch die Heatmap geht: ' . substr($r['text'], 0, 300));

/* --- Was bleibt, wie es war ------------------------------------------------------------------------ */

$r = frage(['room' => 'Keller', 'type' => 'temperature']);
pruefe($zeile($r, 0) === '1 device(s) found (room=Keller, type=temperature).' && str_contains($zeile($r, 1), 'Keller | Temperatur | temperature |'), 'ohne Namen meint type=temperature weiter nur das Klima des Raums: ' . $r['text']);
$r = frage(['room' => 'Keller', 'type' => 'measurement']);
pruefe(!str_contains($r['text'], 'Kühltruhe') && !str_contains($r['text'], 'Kühlschrank'), 'ohne Namen tauchen die Geräte auch unter type=measurement nicht auf: ' . $r['text']);
$r = frage(['name' => 'Kühltruhe']);
pruefe($zeile($r, 1) === 'Keller | Kühltruhe | switch | off (0.0 W, inside -19.0 °C)', 'ohne Typ bleibt die Zeile des Geräts: ' . $r['text']);
$r = frage(['name' => 'Kühltruhe', 'type' => 'humidity']);
pruefe(!str_contains($r['text'], '| measurement |'), 'ein anderer Typ bekommt die Innentemperatur nicht: ' . $r['text']);
// Eine eingetragene Zeile geht vor: Steht der Fühler (auch) als Messwert in der Liste, antwortet sie.
$mitZeile = array_merge($mitschnitt['geraete'], [['Room' => 'Keller', 'Name' => 'Temperatur Kühltruhe', 'Aliases' => '', 'Type' => 'measurement', 'StateVariableID' => 10645, 'ReadOnly' => true]]);
$r = frage(['name' => 'Kühltruhe', 'type' => 'measurement'], 'get', null, $mitZeile);
pruefe(str_starts_with($zeile($r, 0), '1 device(s) found') && $zeile($r, 1) === 'Keller | Temperatur Kühltruhe | measurement | -19.0 °C', 'die eingetragene Messwertzeile geht der gedachten vor: ' . $r['text']);
// Schalten sieht die gedachte Zeile nie
$r = frage(['name' => 'Kühltruhe', 'type' => 'measurement', 'command' => 'on'], 'set');
pruefe($r['isError'] && !str_contains($r['text'], '| measurement |'), 'set_state kennt die gedachte Zeile nicht: ' . $r['text']);

/* --- Hinweis auf den Weg --------------------------------------------------------------------------- */

$r = frage(['name' => 'Kühltruhe', 'period' => 'yesterday']);
pruefe(str_contains($r['text'], $hinweis), 'ein Zeitraum ohne Typ nennt den Weg zum Verlauf der Innentemperatur: ' . $r['text']);
$r = frage(['name' => 'Kühltruhe', 'period' => 'yesterday'], 'get', $de);
pruefe(isset($de[$hinweis]) && str_contains($r['text'], (string)($de[$hinweis] ?? 'FEHLT')), 'deutsch ebenso: ' . $r['text']);
$r = frage(['name' => 'Kühltruhe']);
pruefe(!str_contains($r['text'], $hinweis), 'ohne Zeitraum kein Hinweis: ' . $r['text']);
$r = frage(['name' => 'Kühltruhe', 'type' => 'temperature', 'period' => 'yesterday']);
pruefe(!str_contains($r['text'], $hinweis), 'beim Verlauf selbst kein Hinweis: ' . substr($r['text'], 0, 200));
$ohne = array_map(static fn (array $g): array => array_diff_key($g, ['InsideVariableID' => 0]), $mitschnitt['geraete']);
$r = frage(['name' => 'Kühltruhe', 'period' => 'yesterday'], 'get', null, $ohne);
pruefe(!str_contains($r['text'], $hinweis), 'ohne Fühler am Gerät kein Hinweis: ' . $r['text']);

ende('Verlauf der Innentemperatur');
