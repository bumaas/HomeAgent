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

    /** @var array<int, array<string, mixed>> */
    private array $scripts = [];

    /** @var null|array<string, string> */
    private ?array $translations;

    /**
     * @param array<string, array<string, mixed>> $variables id => [wert, formatiert?, formate?, aktion?, verhalten?, verzoegerungMs?, blockiertMs?, schritt?, schrittMs?, bereich?, fehlertext?]
     * @param null|array<string, string> $translations null = englische Schlüssel unverändert
     * @param array<string, array<string, mixed>> $scripts id => [name, fehlertext?]
     */
    public function __construct(array $variables, ?array $translations = null, array $scripts = [])
    {
        foreach ($variables as $id => $v) {
            $this->variables[(int)$id] = $v;
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
        switch ($v['verhalten'] ?? 'sofort') {
            case 'fehler':
                $this->failures[$variableId] = (string)$v['fehlertext'];
                break;
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
        ];
    }

    /** @var list<array{0: int, 1: int}> jede Verlaufsabfrage: Variable und Aggregationsstufe */
    public array $historyRequests = [];

    /** Verlauf aus dem Mitschnitt: Zeilen [TimeStamp, Min, Avg, Max] je Stufe, wie das Archiv sie lieferte. */
    public function history(int $variableId, int $level, int $from, int $to): ?array
    {
        $this->historyRequests[] = [$variableId, $level];
        $v = $this->variables[$variableId] ?? null;
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
        if (isset($v['bereich'])) {
            $span = $v['bereich']['max'] - $v['bereich']['min'];
            return round(($v['wert'] - $v['bereich']['min']) / $span * 100) . ' %';
        }
        return (string)$v['wert'];
    }
}
