<?php

declare(strict_types=1);

/*
 * Verlauf von Messwerten (Mitschnitt nuc 10.10.2026, 15:30): get_state mit period oder from/to liefert
 * für Temperatur, Feuchte, Luftqualität, sonstige Messwerte und die Ist-Temperatur von Thermostaten je
 * Stunde, Tag oder Monat Minimum, Mittel und Maximum aus den Aggregaten des Archivs. Anlass: In der
 * Claude-App fragte Burkhard nach einem Diagramm der Temperaturen im Gästezimmer der letzten 30 Tage.
 * Verbrauch bleibt, wo Zähler sind und fürs ganze Haus.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$mitschnitt = fixture('nuc_2026-10-10_verlauf.json');
date_default_timezone_set($mitschnitt['zeitzone']);
$werte = $mitschnitt['variablen'];
$liste = $mitschnitt['geraete'];
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @var FakeBackend */
$anlage = null;

function frage(array $argumente, ?array $uebersetzungen = null): array
{
    global $anlage, $liste, $werte, $mitschnitt;
    $anlage = new FakeBackend($werte, $uebersetzungen);
    $anlage->jetzt = $mitschnitt['jetzt'];
    try {
        return (new DeviceIndex($liste, $anlage))->getState($argumente);
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

/** Zeile $n der Antwort, '' wenn es sie nicht gibt; -1 ist die letzte. */
$zeile = static function (array $r, int $n): string {
    $z = explode("\n", $r['text']);
    return $n < 0 ? (string)end($z) : ($z[$n] ?? '');
};
$anzahl = static fn (array $r): int => count(explode("\n", $r['text']));
$zahl = static fn (float $x): string => rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.');
/** erwartete Zeile aus der Fixture: Zeitpunkt im Format $format, dann Min / Mittel / Max */
$erwartet = static function (int $variable, string $stufe, string $wann, string $format) use ($werte, $zahl): string {
    foreach ($werte[(string)$variable]['verlauf'][$stufe] as [$ts, $min, $avg, $max]) {
        if (date($format, $ts) === $wann) {
            return $wann . ' ' . $zahl($min) . ' / ' . $zahl($avg) . ' / ' . $zahl($max);
        }
    }
    return 'FEHLT IN DER FIXTURE: ' . $wann;
};

/**
 * Minimum und Maximum über den ganzen Zeitraum, wie sie in der Gerätezeile stehen sollen (Bedienungs-Blindtest
 * 12.10.2026: Der Agent las „am kältesten / am wärmsten“ selbst aus den Stundenzeilen ab). Gerechnet aus den
 * Tabellenzeilen unter der Gerätezeile $i: das kleinste Minimum und das größte Maximum, jeweils das erste.
 */
$spanne = static function (array $r, int $i, string $einheit): string {
    $z = explode("\n", $r['text']);
    $min = $max = null;
    for ($n = $i + 1; $n < count($z) && preg_match('/^(\d{4}-\d\d(?:-\d\d)?(?: \d\d:\d\d)?) (-?[\d.]+) \/ (-?[\d.]+) \/ (-?[\d.]+)$/', $z[$n], $m) === 1; $n++) {
        if ($min === null || (float)$m[2] < (float)$min[1]) {
            $min = [$m[1], $m[2]];
        }
        if ($max === null || (float)$m[4] > (float)$max[1]) {
            $max = [$m[1], $m[4]];
        }
    }
    return $min === null ? 'KEINE ZEILEN' : sprintf('minimum %s %s (%s), maximum %s %s (%s)', $min[1], $einheit, $min[0], $max[1], $einheit, $max[0]);
};
$zeileMit = static function (array $r, string $anfang): int {
    foreach (explode("\n", $r['text']) as $n => $z) {
        if (str_starts_with($z, $anfang)) {
            return $n;
        }
    }
    return -1;
};

/* --- Tage: letzte Woche im Gästezimmer --------------------------------------------------------- */

$r = frage(['room' => 'Gast', 'type' => 'temperature', 'period' => 'last_week']);
pruefe(!$r['isError'] && $zeile($r, 0) === 'History of 1 measured value(s) (room=Gast, type=temperature, period=last_week), per day: minimum / average / maximum.', 'Kopfzeile: ' . $zeile($r, 0));
pruefe($zeile($r, 1) === 'Gast | Temperatur | temperature | ' . $spanne($r, 1, '°C'), 'Gerätezeile mit Minimum und Maximum samt Tag: ' . $zeile($r, 1));
// Unabhängig von der Tabelle aus der Fixture: Tagesaggregate 28.09. bis 04.10. des Raumfühlers im Gästezimmer
$tage = array_values(array_filter($werte['41351']['verlauf']['1'], static fn (array $t): bool => date('Y-m-d', $t[0]) >= '2026-09-28' && date('Y-m-d', $t[0]) <= '2026-10-04'));
$kalt = array_reduce($tage, static fn (?array $c, array $t): array => $c === null || $t[1] < $c[1] ? $t : $c);
$warm = array_reduce($tage, static fn (?array $c, array $t): array => $c === null || $t[3] > $c[3] ? $t : $c);
pruefe(str_contains($zeile($r, 1), 'minimum ' . $zahl($kalt[1]) . ' °C (' . date('Y-m-d', $kalt[0]) . '), maximum ' . $zahl($warm[3]) . ' °C (' . date('Y-m-d', $warm[0]) . ')'), 'Werte wie in der Fixture: ' . $zeile($r, 1));
$r = frage(['room' => 'Gast', 'type' => 'temperature', 'period' => 'last_week'], $de);
pruefe(str_starts_with($zeile($r, 1), 'Gast | Temperatur | temperature | Minimum ') && str_contains($zeile($r, 1), ', Maximum '), 'deutsch: ' . $zeile($r, 1));
$r = frage(['room' => 'Gast', 'type' => 'temperature', 'period' => 'last_week']);
pruefe($anzahl($r) === 9 && $zeile($r, 2) === $erwartet(41351, '1', '2026-09-28', 'Y-m-d') && $zeile($r, 8) === $erwartet(41351, '1', '2026-10-04', 'Y-m-d'), 'Montag bis Sonntag, je Tag eine Zeile, aufsteigend: ' . $r['text']);
pruefe($zeile($r, 2) === '2026-09-28 22.2 / 22.6 / 23.1', 'gerundet auf eine Stelle, ohne überflüssige Null: ' . $zeile($r, 2));
pruefe(($anlage->historyRequests[0] ?? null) === [41351, 1], 'Tageswerte aus Stufe 1: ' . json_encode($anlage->historyRequests));

/* --- Stunden: heute und gestern ---------------------------------------------------------------- */

$r = frage(['type' => 'thermostat', 'period' => 'yesterday']);
pruefe(!$r['isError'] && str_contains($zeile($r, 0), 'per hour') && $zeile($r, 1) === 'Wintergarten | Heizung | thermostat | ' . $spanne($r, 1, '°C'), 'beim Thermostat der Verlauf der Ist-Temperatur, stündlich: ' . $zeile($r, 0) . ' / ' . $zeile($r, 1));
pruefe($anzahl($r) === 26 && $zeile($r, 2) === $erwartet(19960, '0', '2026-10-09 00:00', 'Y-m-d H:i') && $zeile($r, 25) === $erwartet(19960, '0', '2026-10-09 23:00', 'Y-m-d H:i'), 'gestern: 24 Stunden von 0 bis 23 Uhr: ' . $anzahl($r));
$r = frage(['name' => 'Außentemperatur', 'period' => 'today']);
pruefe(!$r['isError'] && $zeile($r, 1) === 'Garten | Temperatur (Außentemperatur) | temperature | ' . $spanne($r, 1, '°C') && $anzahl($r) === 18 && $zeile($r, 17) === $erwartet(53076, '0', '2026-10-10 15:00', 'Y-m-d H:i'), 'heute über einen Alias, stündlich bis jetzt: ' . $r['text']);

/* --- freier Zeitraum, Jahr ----------------------------------------------------------------------- */

$r = frage(['room' => 'Gast', 'type' => 'temperature', 'from' => '2026-09-10', 'to' => '2026-10-09']);
pruefe(!$r['isError'] && $anzahl($r) === 32 && str_contains($zeile($r, 0), 'per day') && $zeile($r, 2) === $erwartet(41351, '1', '2026-09-10', 'Y-m-d') && $zeile($r, 31) === $erwartet(41351, '1', '2026-10-09', 'Y-m-d'), '30 Tage, die Frage aus der Claude-App: ' . $anzahl($r));
$r = frage(['room' => 'Keller', 'type' => 'temperature', 'period' => 'this_year']);
pruefe(!$r['isError'] && str_contains($zeile($r, 0), 'per month') && ($anlage->historyRequests[0] ?? null) === [26722, 3], 'ein Jahr monatlich aus Stufe 3: ' . json_encode($anlage->historyRequests));

/* --- Gruppen, Auslassungen, Grenzen ------------------------------------------------------------ */

$r = frage(['room' => 'Gast', 'period' => 'last_week']);
pruefe(!$r['isError'] && str_starts_with($zeile($r, 0), 'History of 2 measured value(s) (room=Gast, period=last_week)') && (explode("\n", $r['text'])[$zeileMit($r, 'Gast | Luftfeuchte | humidity | ')] ?? '') === 'Gast | Luftfeuchte | humidity | ' . $spanne($r, $zeileMit($r, 'Gast | Luftfeuchte | humidity | '), '%'), 'ohne Typ: Temperatur und Feuchte: ' . $zeile($r, 0));
pruefe($zeile($r, -1) === '1 device(s) without measured value were left out.', 'das Fenster wird ausgelassen und gezählt: ' . $zeile($r, -1));
$r = frage(['name' => 'Heizkörper', 'period' => 'today']);
pruefe(!$r['isError'] && $zeile($r, 1) === 'Wintergarten | Heizkörper | temperature | no history archived', 'nicht archiviert: ' . $r['text']);
$r = frage(['type' => 'temperature', 'period' => 'last_week']);
pruefe($r['isError'] && $r['text'] === 'Too many measured values (6) for a history (type=temperature, period=last_week). Give a room or a name, at most 5 at a time.', 'mehr als fünf Messwerte: Absage: ' . $r['text']);
$r = frage(['period' => 'last_week']);
pruefe(!str_starts_with($r['text'], 'History'), 'fürs ganze Haus bleibt es beim Verbrauch: ' . $zeile($r, 0));

/* --- Dieselbe Variable nur einmal (nuc 10.10.2026: Thermostat „Heizung“ im Gästezimmer liest den Raumfühler #41351) --- */

$listeVorher = $liste;
$liste[] = ['Room' => 'Gast', 'Name' => 'Heizung', 'Aliases' => '', 'Type' => 'thermostat', 'SetpointVariableID' => 10028, 'StateVariableID' => 41351];
$r = frage(['room' => 'Gast', 'type' => 'temperature', 'from' => '2026-09-10', 'to' => '2026-10-09']);
pruefe(!$r['isError'] && str_starts_with($zeile($r, 0), 'History of 1 measured value(s)') && $anzahl($r) === 32 && $zeile($r, 1) === 'Gast | Temperatur | temperature | ' . $spanne($r, 1, '°C'), 'Raumfühler und Ist-Temperatur des Thermostats sind eine Variable: nur einmal: ' . $zeile($r, 0) . ' / ' . $anzahl($r));
pruefe($anlage->historyRequests === [[41351, 1]], 'das Archiv wird nur einmal gefragt: ' . json_encode($anlage->historyRequests));
$r = frage(['room' => 'Gast', 'period' => 'last_week']);
pruefe(str_starts_with($zeile($r, 0), 'History of 2 measured value(s)') && $zeile($r, -1) === '1 device(s) without measured value were left out.', 'ohne Typ: die Dublette zählt weder als Messwert noch als ausgelassen: ' . $zeile($r, 0) . ' / ' . $zeile($r, -1));
$r = frage(['room' => 'Gast', 'name' => 'Heizung', 'period' => 'last_week']);
pruefe(!$r['isError'] && $zeile($r, 1) === 'Gast | Heizung | thermostat | ' . $spanne($r, 1, '°C'), 'nach Namen gefragt kommt das Thermostat selbst: ' . $zeile($r, 1));
$liste = $listeVorher;

/* --- Deutsch ----------------------------------------------------------------------------------- */

$texte = [];
foreach ([['room' => 'Gast', 'period' => 'last_week'], ['type' => 'thermostat', 'period' => 'yesterday'], ['room' => 'Keller', 'type' => 'temperature', 'period' => 'this_year'],
    ['name' => 'Heizkörper', 'period' => 'today'], ['type' => 'temperature', 'period' => 'last_week']] as $argumente) {
    $texte[] = frage($argumente, $de)['text'];
    pruefe($anlage->missing === [], 'deutsche Übersetzungen vollständig für ' . json_encode($argumente) . ': ' . implode(' | ', array_unique($anlage->missing)));
}
pruefe(str_starts_with($texte[0], 'Verlauf von 2 Messwert(en) (room=Gast, period=last_week), je Tag: Minimum / Mittel / Maximum.'), 'deutsche Kopfzeile: ' . explode("\n", $texte[0])[0]);

ende('Verlauf von Messwerten');
