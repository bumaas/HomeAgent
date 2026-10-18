<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/DeviceBackend.php';

/**
 * Anlage aus einem Mitschnitt: Werte, Aktionen, Bereiche und das beobachtete Verhalten der
 * Geräte (sofort, träge, fahrend, blockiert, stumm, Fehler). Zeichnet jede ausgelöste Aktion und
 * die Wartezeit auf.
 */
final class FakeBackend implements DeviceBackend
{
    /** @var list<array{0: int, 1: mixed}> jede ausgelöste Aktion */
    public array $requests = [];

    /** Summe aller Wartezeiten in Millisekunden */
    public int $slept = 0;

    /** @var list<string> Texte, für die die Übersetzung fehlte */
    public array $missing = [];

    /** @var list<int> gestartete Skripte */
    public array $started = [];

    /** @var array<int, array<string, mixed>> */
    private array $variables;

    /** @var list<array{at: int, id: int, value: mixed}> noch ausstehende Rückmeldungen träger Geräte */
    private array $pending = [];

    /** @var array<int, array{target: int|float, next: int}> laufende Fahrten von Geräten mit Zwischenmeldungen */
    private array $drives = [];

    /** @var array<int, string> Fehlertext der letzten Aktion je Variable */
    private array $failures = [];

    /**
     * Fahrten von Aktoren, die nur beim Losfahren und am Ende melden (Homematic, Mitschnitt nuc
     * 08.10.2026), und der Laufstatus blockierender Aktoren (Velux).
     *
     * @var array<int, array{target: mixed, startAt: int, endAt: int, started: bool}>
     */
    private array $startEndDrives = [];

    /** @var list<array{at: int, id: int, value: mixed}> Fahrstatus-Meldungen, die noch ausstehen */
    private array $statusChanges = [];

    /** @var array<int, array<string, mixed>> Fahrtmerker je Positionsvariable */
    private array $travels = [];

    /** @var array<int, int> pausierte Wiedergabevariable => Zeitpunkt in ms */
    private array $paused = [];

    /** @var array<int, array<string, mixed>> */
    private array $scripts = [];

    /** @var null|array<string, string> */
    private ?array $translations;

    /** @var array<int, mixed> Werte beim Anlegen, für Module, die einen alten Wert zurückschreiben */
    private array $initial = [];

    /**
     * @param array<string, array<string, mixed>> $variables id => [wert, formatiert?, formate?, aktion?, verhalten?, verzoegerungMs?, blockiertMs?, schritt?, schrittMs?, bereich?, fehlertext?]
     * @param null|array<string, string> $translations null = englische Schlüssel unverändert
     * @param array<string, array<string, mixed>> $scripts id => [name, fehlertext?]
     */
    public function __construct(array $variables, ?array $translations = null, array $scripts = [])
    {
        foreach ($variables as $id => $v) {
            $this->variables[(int)$id] = $v;
            $this->initial[(int)$id] = $v['wert'] ?? null;
        }
        foreach ($scripts as $id => $s) {
            $this->scripts[(int)$id] = $s;
        }
        $this->translations = $translations;
    }

    public function read(int $variableId): ?array
    {
        $v = $this->variables[$variableId] ?? null;
        return $v === null ? null : ['value' => $v['wert'], 'formatted' => $this->format($v)];
    }

    public function hasAction(int $variableId): bool
    {
        return (bool)($this->variables[$variableId]['aktion'] ?? false);
    }

    /** Einträge aus dem Mitschnitt: „optionen“ als [Wert, Beschriftung]. */
    public function options(int $variableId): ?array
    {
        $options = $this->variables[$variableId]['optionen'] ?? null;
        return is_array($options) && $options !== [] ? array_map(static fn (array $o): array => [$o[0], (string)$o[1]], $options) : null;
    }

    /**
     * Wie an der Anlage läuft die Aktion in einem eigenen Skript: Der Aufruf kehrt sofort zurück,
     * ein Fehlschlag steht danach in failure().
     */
    public function request(int $variableId, mixed $value): ?string
    {
        $this->requests[] = [$variableId, $value];
        unset($this->failures[$variableId]);
        $v = $this->variables[$variableId] ?? null;
        if ($v === null) {
            $this->failures[$variableId] = 'Variable #' . $variableId . ' existiert nicht';
            return null;
        }
        // Denon-Modul (Testbox 12.10.2026): einen Wert außerhalb des Rasters lehnt es ab, RequestAction liefert false.
        if (isset($v['raster']) && is_numeric($value)) {
            $schritte = ((float)$value - (float)$v['bereich']['min']) / (float)$v['raster'];
            if (abs($schritte - round($schritte)) > 1e-6) {
                $this->failures[$variableId] = (string)$v['fehlertext'];
                return null;
            }
        }
        switch ($v['verhalten'] ?? 'sofort') {
            case 'fehler':
                $this->failures[$variableId] = (string)$v['fehlertext'];
                break;
            case 'nichtAusgeloest':
                // Die Aktion ließ sich gar nicht auslösen (SymconDeviceBackend: IPS_RunScriptTextEx scheiterte);
                // dann gibt request() den Fehlertext zurück, und failure() bleibt leer.
                return (string)$v['fehlertext'];
            case 'traege':
                $this->pending[] = ['at' => $this->slept + (int)$v['verzoegerungMs'], 'id' => $variableId, 'value' => $value];
                break;
            case 'blockiert':
                // RequestAction kehrt erst zurück, wenn das Gerät am Ziel ist (Velux mit
                // WaitForFinishSession); bis dahin bleibt die Variable stehen.
                $this->pending[] = ['at' => $this->slept + (int)$v['blockiertMs'], 'id' => $variableId, 'value' => $value];
                break;
            case 'fahrend':
                $this->drives[$variableId] = ['target' => $value, 'next' => $this->slept + (int)$v['schrittMs']];
                break;
            case 'stumm':
                break;
            case 'reaktion':
                // Gemessene Folge je Befehlswert (Mediaplayer am nuc, 10.10.2026): [ms, Variable, Wert].
                // Die Befehlsvariable selbst behält ihren Wert, solange die Folge sie nicht setzt.
                foreach ($v['reaktion'][(string)$value] ?? [] as [$ms, $id, $reaction]) {
                    $this->pending[] = ['at' => $this->slept + (int)$ms, 'id' => (int)$id, 'value' => $reaction];
                }
                break;
            case 'startEnde':
                // Meldung beim Losfahren nach startMs, Zielwert nach der Fahrzeit, die anteilig zur
                // Strecke aus vollfahrtMs folgt; Fahrstatus dazwischen true.
                $range = $v['bereich'];
                $share = abs((float)$value - (float)$v['wert']) / ((float)$range['max'] - (float)$range['min']);
                $startAt = $this->slept + (int)$v['startMs'];
                $this->startEndDrives[$variableId] = ['target' => $value, 'startAt' => $startAt, 'endAt' => $startAt + (int)round($share * (int)$v['vollfahrtMs']), 'started' => false];
                break;
            case 'stumm':
                break;
            default:
                $this->write($variableId, $value);
        }
        // IPS-868 am nuc (10.10.2026): Ein Befehl an den Weißkanal schreibt die Farbe mit, die das Modul
        // vorher kannte; ein gerade gesendetes Schwarz wird so wieder zum alten Wert.
        if (isset($v['schreibtAltwert'])) {
            $this->write((int)$v['schreibtAltwert'], $this->initial[(int)$v['schreibtAltwert']] ?? null);
        }
        if (isset($v['fahrstatus'], $v['fahrstatusMs']) && ($v['verhalten'] ?? '') === 'blockiert') {
            $this->statusChanges[] = ['at' => $this->slept + (int)$v['fahrstatusMs'], 'id' => (int)$v['fahrstatus'], 'value' => true];
            $this->statusChanges[] = ['at' => $this->slept + (int)$v['blockiertMs'], 'id' => (int)$v['fahrstatus'], 'value' => false];
        }
        return null;
    }

    /** Zeitpunkt der letzten Aktualisierung in ms, wie an der Anlage nur auf die Sekunde genau. */
    public function updated(int $variableId): int
    {
        $ms = (int)($this->variables[$variableId]['aktualisiert'] ?? -3600000);
        return intdiv($ms - (($ms % 1000 + 1000) % 1000), 1000) * 1000;
    }

    /** Uhr des Mitschnitts in ms: die Summe aller Wartezeiten. */
    public function now(): int
    {
        return $this->slept;
    }

    /** @return null|array<string, mixed> */
    public function rememberedTravel(int $levelVariable): ?array
    {
        return $this->travels[$levelVariable] ?? null;
    }

    public function rememberPaused(array $playbackVariables): void
    {
        foreach ($playbackVariables as $variable) {
            $this->paused[$variable] = $this->slept;
        }
    }

    public function rememberedPaused(): array
    {
        return $this->paused;
    }

    public function rememberTravel(int $levelVariable, ?array $travel): void
    {
        if ($travel === null) {
            unset($this->travels[$levelVariable]);
        } else {
            $this->travels[$levelVariable] = $travel;
        }
    }

    /** @var list<array{0: int, 1: string}> jeder ausgelöste Stopp: Positionsvariable und Weg */
    public array $stops = [];

    /**
     * Stoppweg wie an der Anlage: eine eigene Stoppvariable mit Aktion (Wahrheitswert oder ein
     * Eintrag „Stop“/„Stopp“), sonst der mitgeschnittene Weg über das Modul des Geräts.
     */
    public function stopMethod(int $levelVariable, int $stopVariable): string
    {
        if ($stopVariable > 0) {
            return $this->stopValue($stopVariable) === null ? '' : 'variable';
        }
        return (string)($this->variables[$levelVariable]['stoppweg'] ?? '');
    }

    /**
     * Hält die Fahrt an. Was danach geschieht, steht im Mitschnitt unter „stopp“ → „folge“: je
     * Schritt Verzögerung in ms, „position“ oder „fahrstatus“ und der gemeldete Wert. Eine laufende
     * Fahrt endet damit, ihr Zielwert kommt nicht mehr.
     */
    public function stop(int $levelVariable, int $stopVariable): ?string
    {
        $method = $this->stopMethod($levelVariable, $stopVariable);
        if ($method === '') {
            return 'no way to stop';
        }
        $this->stops[] = [$levelVariable, $method];
        if ($method === 'variable') {
            $this->requests[] = [$stopVariable, $this->stopValue($stopVariable)];
        }
        $v = $this->variables[$levelVariable];
        unset($this->startEndDrives[$levelVariable], $this->drives[$levelVariable]);
        $this->pending = array_values(array_filter($this->pending, static fn (array $p): bool => $p['id'] !== $levelVariable));
        $status = (int)($v['fahrstatus'] ?? 0);
        $this->statusChanges = array_values(array_filter($this->statusChanges, static fn (array $c): bool => $c['id'] !== $status));
        foreach ($v['stopp']['folge'] ?? [] as [$ms, $what, $value]) {
            $id = $what === 'position' ? $levelVariable : $status;
            if ($id > 0) {
                $this->statusChanges[] = ['at' => $this->slept + (int)$ms, 'id' => $id, 'value' => $value];
            }
        }
        return null;
    }

    /** Wert, der eine Stoppvariable auslöst; null = sie taugt nicht dafür. */
    private function stopValue(int $variableId): mixed
    {
        $v = $this->variables[$variableId] ?? null;
        if ($v === null || !($v['aktion'] ?? false)) {
            return null;
        }
        if (is_bool($v['wert'])) {
            return true;
        }
        foreach ($v['formate'] ?? [] as $value => $caption) {
            if (in_array(mb_strtolower(trim((string)$caption)), ['stop', 'stopp'], true)) {
                return is_int($v['wert']) ? (int)$value : $value;
            }
        }
        return null;
    }

    /** Hält eine Fahrt von außen an (Wandtaster): Position bleibt stehen, Fahrstatus wird false. */
    public function halt(int $variableId, mixed $position): void
    {
        unset($this->startEndDrives[$variableId]);
        $this->write($variableId, $position);
        if (isset($this->variables[$variableId]['fahrstatus'])) {
            $this->write((int)$this->variables[$variableId]['fahrstatus'], false);
        }
    }

    private function write(int $variableId, mixed $value): void
    {
        $this->variables[$variableId]['wert'] = $value;
        $this->variables[$variableId]['aktualisiert'] = $this->slept;
        unset($this->variables[$variableId]['formatiert']);
    }

    public function failure(int $variableId): ?string
    {
        return $this->failures[$variableId] ?? null;
    }

    public function range(int $variableId): ?array
    {
        $range = $this->variables[$variableId]['bereich'] ?? null;
        if ($range === null) {
            return null;
        }
        // Ohne eigene Angabe gilt wie an der Anlage: kleinster Wert offen (~Shutter, KLF200).
        return [
            'min'     => (float)$range['min'],
            'max'     => (float)$range['max'],
            'open'    => (float)($range['offen'] ?? $range['min']),
            'closed'  => (float)($range['geschlossen'] ?? $range['max']),
            'source'  => (string)($range['herkunft'] ?? 'default'),
            'profile' => (string)($range['profil'] ?? ''),
            'step'    => (float)($range['schritt'] ?? 0),
        ];
    }

    /** @var list<array{0: int, 1: int}> jede Verlaufsabfrage: Variable und Aggregationsstufe */
    public array $historyRequests = [];

    /** Verlauf aus dem Mitschnitt: Zeilen [TimeStamp, Min, Avg, Max] je Stufe, wie das Archiv sie lieferte. */
    /** Rohwerte aus dem Mitschnitt (`rohwerte`, aufsteigend, der erste darf vor $from liegen). */
    public function stateSeries(int $variableId, int $from, int $to): ?array
    {
        $v = $this->variables[$variableId] ?? null;
        if ($v === null || ($v['geloggt'] ?? false) !== true || !isset($v['rohwerte'])) {
            return null;
        }
        $start = null;
        $changes = [];
        foreach ($v['rohwerte'] as [$time, $value]) {
            if ($time < $from) {
                $start = $value;
            } elseif ($to === 0 || $time <= $to) {
                $changes[] = [(int)$time, $value];
            }
        }
        return ['start' => $start, 'changes' => $changes];
    }

    public function history(int $variableId, int $level, int $from, int $to): ?array
    {
        $this->historyRequests[] = [$variableId, $level];
        $v = $this->variables[$variableId] ?? null;
        // Neuere Mitschnitte wie bei den Zählern: „archiviert“, „aggregationstyp“ 0 und „aggregate“ je Stufe mit min,
        // mittel, max und dem Zeitpunkt von Minimum und Maximum (B84)
        if ($v !== null && isset($v['aggregate']) && ($v['archiviert'] ?? false) === true && ($v['aggregationstyp'] ?? 0) === 0) {
            $rows = array_values(array_filter($v['aggregate'][(string)$level] ?? [], static fn (array $a): bool => $a['zeit'] >= $from && ($to === 0 || $a['zeit'] <= $to)));
            return [
                'rows' => array_map(static fn (array $a): array => ['time' => (int)$a['zeit'], 'min' => (float)$a['min'], 'avg' => (float)$a['mittel'], 'max' => (float)$a['max'],
                    'minTime' => (int)$a['minzeit'], 'maxTime' => (int)$a['maxzeit']], $rows),
                'unit' => (string)($v['einheit'] ?? ''),
            ];
        }
        if ($v === null || ($v['geloggt'] ?? false) !== true || ($v['aggregation'] ?? 0) === 1) {
            return null;
        }
        $rows = array_values(array_filter($v['verlauf'][(string)$level] ?? [], static fn (array $r): bool => $r[0] >= $from && ($to === 0 || $r[0] <= $to)));
        usort($rows, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        return [
            'rows' => array_map(static fn (array $r): array => ['time' => (int)$r[0], 'min' => (float)$r[1], 'avg' => (float)$r[2], 'max' => (float)$r[3]], $rows),
            'unit' => (string)($v['einheit'] ?? ''),
        ];
    }

    public function setpointRange(int $variableId): ?array
    {
        $range = $this->variables[$variableId]['sollbereich'] ?? null;
        return $range === null ? null : ['min' => (float)$range['min'], 'max' => (float)$range['max'], 'step' => (float)($range['schritt'] ?? 0)];
    }

    /** Farbmodus aus dem Mitschnitt: „farbmodus“ als [ident, wert] an der Farb- oder Farbtemperaturvariable. */
    public function colorMode(int $variableId): ?array
    {
        $mode = $this->variables[$variableId]['farbmodus'] ?? null;
        return is_array($mode) ? ['ident' => (string)$mode['ident'], 'value' => $mode['wert']] : null;
    }

    public function sleep(int $milliseconds): void
    {
        $this->slept += $milliseconds;
        foreach ($this->pending as $key => $p) {
            if ($p['at'] <= $this->slept) {
                $this->write($p['id'], $p['value']);
                unset($this->pending[$key]);
            }
        }
        // In der Reihenfolge der Zeitpunkte, damit eine Folge (false, true, false) richtig endet.
        uasort($this->statusChanges, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        foreach ($this->statusChanges as $key => $c) {
            if ($c['at'] <= $this->slept) {
                $this->write($c['id'], $c['value']);
                unset($this->statusChanges[$key]);
            }
        }
        foreach ($this->startEndDrives as $id => $drive) {
            $v = $this->variables[$id];
            if (!$drive['started'] && $drive['startAt'] <= $this->slept) {
                // Startmeldung: aus einer Endlage der unveränderte Wert, sonst ein Hauch Richtung Ziel
                $position = (float)$v['wert'];
                $atEnd = $position <= (float)$v['bereich']['min'] || $position >= (float)$v['bereich']['max'];
                $this->write($id, $atEnd ? $v['wert'] : $position + ($drive['target'] > $position ? 0.005 : -0.005));
                if (isset($v['fahrstatus'])) {
                    $this->write((int)$v['fahrstatus'], true);
                }
                $this->startEndDrives[$id]['started'] = true;
            }
            if ($drive['endAt'] <= $this->slept && $this->startEndDrives[$id]['started']) {
                $this->write($id, $drive['target']);
                if (isset($v['fahrstatus'])) {
                    $this->write((int)$v['fahrstatus'], false);
                }
                unset($this->startEndDrives[$id]);
            }
        }
        foreach ($this->drives as $id => $drive) {
            $step = (int)$this->variables[$id]['schritt'];
            while (isset($this->drives[$id]) && $drive['next'] <= $this->slept) {
                $position = $this->variables[$id]['wert'];
                $position += max(-$step, min($step, $drive['target'] - $position));
                $this->write($id, $position);
                $drive['next'] += (int)$this->variables[$id]['schrittMs'];
                $this->drives[$id] = $drive;
                if ($position == $drive['target']) {
                    unset($this->drives[$id]);
                }
            }
        }
    }

    public function scriptExists(int $scriptId): bool
    {
        return isset($this->scripts[$scriptId]);
    }

    public function runScript(int $scriptId): ?string
    {
        $this->started[] = $scriptId;
        if (!isset($this->scripts[$scriptId])) {
            return 'Skript #' . $scriptId . ' exisitert nicht'; // Schreibweise der Anlage
        }
        return $this->scripts[$scriptId]['fehlertext'] ?? null;
    }

    public function translate(string $text): string
    {
        if ($this->translations === null) {
            return $text;
        }
        if (!isset($this->translations[$text])) {
            $this->missing[] = $text;
        }
        return $this->translations[$text] ?? $text;
    }

    public function value(int $variableId): mixed
    {
        return $this->variables[$variableId]['wert'] ?? null;
    }

    /**
     * Summe eines als Zähler archivierten Werts aus den mitgeschnittenen Aggregaten (Stufe => Liste
     * mit Beginn „zeit“ und „summe“). Wie AC_GetAggregatedValues zählt ein Zeitraum, dessen Beginn
     * zwischen $from und $to liegt; $to = 0 heißt bis jetzt, also einschließlich des laufenden.
     */
    public function counterSum(int $variableId, int $level, int $from, int $to): ?array
    {
        $v = $this->variables[$variableId] ?? null;
        if ($v === null || ($v['archiviert'] ?? false) !== true || ($v['aggregationstyp'] ?? 0) !== 1) {
            return null;
        }
        $to = $to === 0 ? $this->timestamp() : $to;
        $sum = 0.0;
        foreach ($v['aggregate'][(string)$level] ?? [] as $a) {
            if ($a['zeit'] >= $from && $a['zeit'] <= $to) {
                $sum += (float)$a['summe'];
            }
        }
        return ['sum' => $sum, 'unit' => (string)($v['einheit'] ?? '')];
    }

    /** @var list<array{0: int, 1: int}> abgefragte Zählerreihen als [Variable, Stufe], für Prüfungen der Laufzeit */
    public array $seriesRequests = [];

    public function counterSeries(int $variableId, int $level, int $from, int $to): ?array
    {
        $this->seriesRequests[] = [$variableId, $level];
        $v = $this->variables[$variableId] ?? null;
        if ($v === null || ($v['archiviert'] ?? false) !== true || ($v['aggregationstyp'] ?? 0) !== 1) {
            return null;
        }
        $to = $to === 0 ? $this->timestamp() : $to;
        $rows = [];
        foreach ($v['aggregate'][(string)$level] ?? [] as $a) {
            if ($a['zeit'] >= $from && $a['zeit'] <= $to) {
                $rows[] = ['time' => (int)$a['zeit'], 'sum' => (float)$a['summe']];
            }
        }
        usort($rows, static fn (array $x, array $y): int => $x['time'] <=> $y['time']);
        return ['rows' => $rows, 'unit' => (string)($v['einheit'] ?? '')];
    }

    /** @var array<int, int> abgefragte Zeitpunkte je Variable (counterReadings), für Prüfungen der Laufzeit */
    public array $readingRequests = [];

    /** @var list<int> alle abgefragten Zeitpunkte (counterReadings) */
    public array $readingTimes = [];

    /** Zählerstand vor jedem Zeitpunkt aus den mitgeschnittenen Rohwerten („ablesungen“ als [zeit, wert]), Nullen übergangen. */
    public function counterReadings(int $variableId, array $times): ?array
    {
        $this->readingRequests[$variableId] = ($this->readingRequests[$variableId] ?? 0) + count($times);
        $this->readingTimes = array_merge($this->readingTimes, $times);
        $v = $this->variables[$variableId] ?? null;
        if ($v === null || ($v['archiviert'] ?? false) !== true || ($v['aggregationstyp'] ?? 0) !== 1) {
            return null;
        }
        $readings = [];
        foreach ($times as $time) {
            $readings[$time] = null;
            foreach ($v['ablesungen'] ?? [] as [$at, $value]) {
                if ($at < $time && (float)$value > 0) {
                    $readings[$time] = (float)$value;
                }
            }
        }
        return $readings;
    }

    /** Einheit der Variable laut Mitschnitt; '' = keine. */
    public function unit(int $variableId): string
    {
        return (string)($this->variables[$variableId]['einheit'] ?? '');
    }

    /** Uhrzeit der Anlage: die des Mitschnitts, wenn der Test sie setzt. */
    public ?int $jetzt = null;

    public function timestamp(): int
    {
        return $this->jetzt ?? time();
    }

    /** Anzeigetext: der mitgeschnittene, sonst wie die Anlage ihn für Schalter und Prozentregler bildet. */
    private function format(array $v): string
    {
        if (isset($v['formatiert'])) {
            return (string)$v['formatiert'];
        }
        if (is_bool($v['wert'])) {
            return $v['wert'] ? 'An' : 'Aus';
        }
        if (isset($v['formate'][(string)$v['wert']])) {
            return (string)$v['formate'][(string)$v['wert']];
        }
        // Auswahl: die Beschriftung des Eintrags, wie an der Anlage („High“ zeigt „Hoch“).
        foreach ($v['optionen'] ?? [] as [$value, $caption]) {
            if ((string)$value === (string)$v['wert']) {
                return (string)$caption;
            }
        }
        // Darstellung mit Nachkommastellen und Suffix (Denon: DIGITS 1, SUFFIX „  dB“, wie GetValueFormatted es zeigte).
        if (isset($v['suffix'])) {
            return number_format((float)$v['wert'], (int)($v['stellen'] ?? 0), '.', '') . $v['suffix'];
        }
        if (isset($v['bereich'])) {
            $span = $v['bereich']['max'] - $v['bereich']['min'];
            return round(($v['wert'] - $v['bereich']['min']) / $span * 100) . ' %';
        }
        return (string)$v['wert'];
    }
}
