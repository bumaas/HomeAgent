<?php

declare(strict_types=1);

require_once __DIR__ . '/DeviceBackend.php';

/** Anbindung der Freigabeliste an die laufende Symcon-Anlage. */
final class SymconDeviceBackend implements DeviceBackend
{
    /**
     * Skript, in dem eine Aktion läuft; %s ist der Aufruf. RequestAction meldet einen Fehlschlag
     * als PHP-Warnung plus false; der Text der Warnung ist die einzige Auskunft über die Ursache
     * und geht deshalb über die Aktion „ActionFailed“ an die Instanz zurück.
     */
    private const ACTION_SCRIPT = <<<'PHP'
        $error = null;
        set_error_handler(static function (int $number, string $text) use (&$error): bool {
            $error = $text;
            return true;
        });
        try {
            $ok = (bool)(%s);
        } catch (Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }
        restore_error_handler();
        if (!$ok && $_IPS['HAGT_INSTANCE'] > 0) {
            IPS_RequestAction($_IPS['HAGT_INSTANCE'], 'ActionFailed', json_encode([
                'key'      => $_IPS['HAGT_KEY'],
                'variable' => $_IPS['HAGT_VARIABLE'],
                'error'    => $error ?? 'RequestAction returned false',
            ], JSON_INVALID_UTF8_SUBSTITUTE));
        }
        PHP;

    private const REQUEST_CALL = 'RequestAction($_IPS[\'HAGT_VARIABLE\'], $_IPS[\'HAGT_VALUE\'])';

    /**
     * Module, deren Geräte sich nur über eine Modulfunktion anhalten lassen (keine Variable STOP an
     * der Instanz, nuc 09.10.2026): Modul-GUID => Name und Aufruf mit der Instanz der Position.
     * Signaturen: bool HM_WriteValueBoolean(int $InstanzID, string $Parameter, bool $Wert) laut
     * Befehlsreferenz, bool KLF200_ShutterMoveStop(int $InstanzeID) laut README des Moduls.
     */
    private const STOP_MODULES = [
        '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}' => ['HomeMatic', 'HM_WriteValueBoolean(%d, \'STOP\', true)'],
        '{4EBD07B1-2962-4531-AC5F-7944789A9CE5}' => ['KLF200', 'KLF200_ShutterMoveStop(%d)'],
    ];

    /**
     * Module, deren Rollladenposition ohne Darstellung Rollladen den größten Wert als offen zählt:
     * Modul-GUID => Name und Anfang des Idents der Positionsvariable. Home Assistant zählt die Position
     * eines Covers 0 = zu, 100 = offen; das HomeAssistant-Modul legt sie als Schieberegler 0 bis 100 %
     * an (Ident `cover_status`, Testbox 11.10.2026).
     */
    private const OPEN_AT_MAX_MODULES = [
        '{72D6A284-1870-4E11-92D8-0402C8233C29}' => ['Home Assistant', 'cover_'],
    ];

    /** Einträge einer Stoppvariable, die „anhalten“ bedeuten (Groß- und Kleinschreibung egal). */
    private const STOP_CAPTIONS = ['stop', 'stopp'];

    /** Modul „Archive Control“. */
    private const ARCHIVE_CONTROL = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    /** Aggregationstyp „Zähler“ laut AC_GetAggregationType (0 Standard, 1 Zähler). */
    private const AGGREGATION_COUNTER = 1;

    /** VARIABLE_PRESENTATION_SHUTTER; als GUID, weil es die Konstante erst ab Symcon 8.0 gibt. */
    private const PRESENTATION_SHUTTER = '{6075FC22-69AF-B110-3749-C24138883082}';

    /** @var callable(string): string */
    private $translator;

    private int $instanceId;

    /** @var null|callable(string): ?string */
    private $readFailure;

    /** @var array<int, string> Auftragsnummer der zuletzt ausgelösten Aktion je Variable */
    private array $started = [];

    /** @var null|callable(int): ?array liest den Fahrtmerker einer Positionsvariable */
    private $readTravel;

    /** @var null|callable(int, ?array): void schreibt oder löscht den Fahrtmerker */
    private $storeTravel;

    /** @var null|callable(): array liest die pausierten Mediaplayer (Variable => ms) */
    private $readPaused;

    /** @var null|callable(list<int>, int): void merkt pausierte Mediaplayer mit der Uhrzeit in ms */
    private $storePaused;

    /**
     * @param callable(string): string $translator
     * @param int $instanceId Instanz, an die ein Aktionsskript seinen Fehlschlag meldet (0 = keine)
     * @param null|callable(string): ?string $readFailure liest den gemeldeten Fehlertext zu einer Auftragsnummer
     * @param null|callable(int): ?array $readTravel liest den Fahrtmerker (null = ohne Merker)
     * @param null|callable(int, ?array): void $storeTravel schreibt den Fahrtmerker
     * @param null|callable(): array $readPaused liest die pausierten Mediaplayer
     * @param null|callable(list<int>, int): void $storePaused merkt pausierte Mediaplayer
     */
    public function __construct(callable $translator, int $instanceId = 0, ?callable $readFailure = null, ?callable $readTravel = null, ?callable $storeTravel = null, ?callable $readPaused = null, ?callable $storePaused = null)
    {
        $this->translator = $translator;
        $this->instanceId = $instanceId;
        $this->readFailure = $readFailure;
        $this->readTravel = $readTravel;
        $this->storeTravel = $storeTravel;
        $this->readPaused = $readPaused;
        $this->storePaused = $storePaused;
    }

    public function read(int $variableId): ?array
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        // Profile mit leerem Suffix hängen ein Leerzeichen an („Geöffnet “ beim Velux KLF200)
        return ['value' => GetValue($variableId), 'formatted' => trim(GetValueFormatted($variableId))];
    }

    public function hasAction(int $variableId): bool
    {
        return $variableId > 0 && IPS_VariableExists($variableId) && HasAction($variableId);
    }

    public function request(int $variableId, mixed $value): ?string
    {
        // Die Aktion läuft in einem eigenen Skript: Bei manchen Modulen kehrt RequestAction erst
        // zurück, wenn das Gerät am Ziel ist (Velux KLF200 mit WaitForFinishSession: 31 Sekunden
        // für eine Fahrt). Die Antwort an den Assistenten darf darauf nicht warten.
        return $this->runAction($variableId, self::REQUEST_CALL, $value);
    }

    public function stopMethod(int $levelVariable, int $stopVariable): string
    {
        if ($stopVariable > 0) {
            return self::stopValue($stopVariable) === null ? '' : 'variable';
        }
        return self::stopModule($levelVariable)[0] ?? '';
    }

    public function stop(int $levelVariable, int $stopVariable): ?string
    {
        if ($stopVariable > 0) {
            $value = self::stopValue($stopVariable);
            if ($value === null) {
                return $this->translate('cannot be stopped');
            }
            // Ein Fehlschlag gehört zur Position: Nach ihr fragt das wartende Werkzeug.
            return $this->runAction($stopVariable, self::REQUEST_CALL, $value, $levelVariable);
        }
        $module = self::stopModule($levelVariable);
        // Auch die Modulfunktion läuft im eigenen Skript: KLF200_ShutterMoveStop kehrte erst nach 2,4 s zurück.
        return $module === null ? $this->translate('cannot be stopped') : $this->runAction($levelVariable, $module[1], null);
    }

    /**
     * Startet den Aufruf im Aktionsskript. Der Fehlschlag wird unter $reportFor abgelegt, sonst
     * unter der Variable selbst.
     */
    private function runAction(int $variableId, string $call, mixed $value, int $reportFor = 0): ?string
    {
        $key = bin2hex(random_bytes(6));
        $this->started[$reportFor > 0 ? $reportFor : $variableId] = $key;
        return self::guarded(fn (): bool => IPS_RunScriptTextEx(sprintf(self::ACTION_SCRIPT, $call), self::actionParameters($variableId, $value, $this->instanceId, $key)), 'IPS_RunScriptTextEx returned false');
    }

    /**
     * Parameter des Aktionsskripts. Ohne Wert (Stopp über eine Modulfunktion) fehlt HAGT_VALUE:
     * IPS_RunScriptTextEx nimmt null nicht an („Cannot auto-convert value for parameter Parameters
     * (Type is not supported)“, nuc 09.10.2026).
     *
     * @return array<string, bool|int|float|string>
     */
    private static function actionParameters(int $variableId, mixed $value, int $instanceId, string $key): array
    {
        $parameters = ['HAGT_VARIABLE' => $variableId, 'HAGT_INSTANCE' => $instanceId, 'HAGT_KEY' => $key];
        if ($value !== null) {
            $parameters['HAGT_VALUE'] = $value;
        }
        return $parameters;
    }

    public function options(int $variableId): ?array
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $entries = self::entriesOf($variableId);
        return $entries === [] ? null : $entries;
    }

    /**
     * Einträge aus der Darstellung (OPTIONS, etwa beim HomeAssistant-Modul), sonst aus dem Profil.
     *
     * @return list<array{0: mixed, 1: string}>
     */
    private static function entriesOf(int $variableId): array
    {
        $presentation = IPS_GetVariablePresentation($variableId);
        $options = json_decode((string)($presentation['OPTIONS'] ?? ''), true);
        $entries = [];
        foreach (is_array($options) ? $options : [] as $option) {
            if (is_array($option) && array_key_exists('Value', $option)) {
                $entries[] = [$option['Value'], (string)($option['Caption'] ?? '')];
            }
        }
        $profile = (string)($presentation['PROFILE'] ?? '');
        if ($entries === [] && $profile !== '' && IPS_VariableProfileExists($profile)) {
            foreach (IPS_GetVariableProfile($profile)['Associations'] as $association) {
                $entries[] = [$association['Value'], (string)$association['Name']];
            }
        }
        return $entries;
    }

    /** Wert, der eine Stoppvariable auslöst: true, oder der Wert ihres Eintrags „Stop“; null = taugt nicht. */
    private static function stopValue(int $variableId): mixed
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId) || !HasAction($variableId)) {
            return null;
        }
        $type = IPS_GetVariable($variableId)['VariableType'];
        if ($type === VARIABLETYPE_BOOLEAN) {
            return true;
        }
        foreach (self::entriesOf($variableId) as [$value, $caption]) {
            if (in_array(mb_strtolower(trim($caption)), self::STOP_CAPTIONS, true)) {
                return match ($type) {
                    VARIABLETYPE_INTEGER => (int)$value,
                    VARIABLETYPE_FLOAT   => (float)$value,
                    default              => (string)$value,
                };
            }
        }
        return null;
    }

    /**
     * Modul der Instanz, zu der die Positionsvariable gehört, wenn es sich über eine Funktion
     * anhalten lässt.
     *
     * @return null|array{0: string, 1: string} Name und fertiger Aufruf
     */
    private static function stopModule(int $levelVariable): ?array
    {
        if ($levelVariable <= 0 || !IPS_VariableExists($levelVariable)) {
            return null;
        }
        $instance = IPS_GetParent($levelVariable);
        if ($instance <= 0 || !IPS_InstanceExists($instance)) {
            return null;
        }
        $module = self::STOP_MODULES[IPS_GetInstance($instance)['ModuleInfo']['ModuleID']] ?? null;
        return $module === null ? null : [$module[0], sprintf($module[1], $instance)];
    }

    /** Name des Moduls, wenn die Variable eine Rollladenposition aus OPEN_AT_MAX_MODULES ist; sonst null. */
    private static function openAtMaxModule(int $variableId): ?string
    {
        $instance = IPS_GetParent($variableId);
        if ($instance <= 0 || !IPS_InstanceExists($instance)) {
            return null;
        }
        $module = self::OPEN_AT_MAX_MODULES[IPS_GetInstance($instance)['ModuleInfo']['ModuleID']] ?? null;
        if ($module === null || !str_starts_with(IPS_GetObject($variableId)['ObjectIdent'], $module[1])) {
            return null;
        }
        return $module[0];
    }

    public function failure(int $variableId): ?string
    {
        $key = $this->started[$variableId] ?? null;
        return $key === null || $this->readFailure === null ? null : ($this->readFailure)($key);
    }

    public function scriptExists(int $scriptId): bool
    {
        return $scriptId > 0 && IPS_ScriptExists($scriptId);
    }

    public function runScript(int $scriptId): ?string
    {
        return self::guarded(static fn (): bool => IPS_RunScript($scriptId), 'IPS_RunScript returned false');
    }

    public function range(int $variableId): ?array
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $presentation = IPS_GetVariablePresentation($variableId);
        // Die Darstellung Rollladen (ab 8.0) kennt kein MIN/MAX. Sie nennt den Rohwert für „offen“
        // und für „geschlossen“ und damit Richtung und Bereich zugleich.
        if (($presentation['PRESENTATION'] ?? '') === self::PRESENTATION_SHUTTER) {
            if (!isset($presentation['OPEN_OUTSIDE_VALUE'], $presentation['CLOSE_INSIDE_VALUE'])) {
                return null;
            }
            $rotation = (int)($presentation['USAGE_TYPE'] ?? 0) === 1;
            return self::travel((float)$presentation['OPEN_OUTSIDE_VALUE'], (float)$presentation['CLOSE_INSIDE_VALUE'], $rotation ? 'rotation' : 'presentation');
        }
        if (isset($presentation['MIN'], $presentation['MAX'])) {
            $range = self::validRange((float)$presentation['MIN'], (float)$presentation['MAX']);
            $module = self::openAtMaxModule($variableId);
            if ($range !== null && $module !== null) {
                return self::travel($range['max'], $range['min'], 'module') + ['module' => $module];
            }
            return $range === null ? null : $range + ['step' => (float)($presentation['STEP_SIZE'] ?? 0)];
        }
        // Legacy-Darstellung: der Bereich steht im Profil. Trägt der Profilname „.Reversed“, zählt
        // Symcon ihn als 100 % bis 0 % (Doku „Legacy-Icons“); bei Homematic ist LEVEL 0 geschlossen.
        $profile = (string)($presentation['PROFILE'] ?? '');
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            $data = IPS_GetVariableProfile($profile);
            $range = self::validRange((float)$data['MinValue'], (float)$data['MaxValue'], $profile);
            if ($range !== null && str_contains(strtolower($profile), '.reversed')) {
                return self::travel($range['max'], $range['min'], 'reversed', $profile);
            }
            return $range === null ? null : $range + ['step' => (float)$data['StepSize']];
        }
        return null;
    }

    public function setpointRange(int $variableId): ?array
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $presentation = IPS_GetVariablePresentation($variableId);
        if (($presentation['PRESENTATION'] ?? '') !== self::PRESENTATION_SHUTTER && isset($presentation['MIN'], $presentation['MAX'])) {
            $min = (float)$presentation['MIN'];
            $max = (float)$presentation['MAX'];
            return $max > $min ? ['min' => $min, 'max' => $max, 'step' => (float)($presentation['STEP_SIZE'] ?? 0)] : null;
        }
        $profile = (string)($presentation['PROFILE'] ?? '');
        if ($profile === '') {
            $variable = IPS_GetVariable($variableId);
            $profile = $variable['VariableCustomProfile'] !== '' ? $variable['VariableCustomProfile'] : $variable['VariableProfile'];
        }
        if ($profile === '' || !IPS_VariableProfileExists($profile)) {
            return null;
        }
        $data = IPS_GetVariableProfile($profile);
        $min = (float)$data['MinValue'];
        $max = (float)$data['MaxValue'];
        $step = (float)$data['StepSize'];
        // Ohne Schrittweite gilt der kleinste Abstand der Einträge mit Zahlen (B81, betreute Anlage: Homematic-IP-Profil
        // mit Einträgen 15,0 bis 24,0 im Abstand von 0,5; der Selbsttest meldete „in Schritten von 0“, set_state rundete
        // nicht, die Karte nahm 0,5)
        if ($step <= 0) {
            $step = self::associationStep($data['Associations']);
        }
        // Ein Rand mit Text statt Zahl ist ein Sonderwert (Homematic: 30,5 „On“, 4,5 „Off“). Ohne Schrittweite
        // zählt das Raster der Karte (0,5); vorher blieb der Sonderwert dann im Bereich (betreute Anlage 16.10.2026).
        $edge = $step > 0 ? $step : 0.5;
        foreach ($data['Associations'] as $association) {
            if (is_numeric(str_replace(',', '.', trim((string)$association['Name'])))) {
                continue;
            }
            if (abs((float)$association['Value'] - $max) < 0.001) {
                $max -= $edge;
            } elseif (abs((float)$association['Value'] - $min) < 0.001) {
                $min += $edge;
            }
        }
        return $max > $min ? ['min' => $min, 'max' => $max, 'step' => $step] : null;
    }

    /**
     * Raster aus den Einträgen mit Zahlen: der kleinste Abstand, aber nur bei dichten Einträgen (mindestens drei Abstände,
     * jeder ein Vielfaches des kleinsten, höchstens 1). Grobe Einträge sind Beschriftungen, kein Raster: zwei Zahlen an den
     * Rändern ergaben 24 (21 °C wurde 30 °C), Farbbänder bei 5, 18, 22, 26 ergaben 4 (Review 18.10.2026, M2).
     */
    private static function associationStep(array $associations): float
    {
        $values = [];
        foreach ($associations as $association) {
            if (is_numeric(str_replace(',', '.', trim((string)$association['Name'])))) {
                $values[] = round((float)$association['Value'], 3);
            }
        }
        $values = array_values(array_unique($values));
        sort($values);
        $gaps = [];
        for ($i = 1; $i < count($values); $i++) {
            $gaps[] = round($values[$i] - $values[$i - 1], 3);
        }
        if (count($gaps) < 3 || min($gaps) <= 0 || min($gaps) > 1) {
            return 0.0;
        }
        $step = min($gaps);
        foreach ($gaps as $gap) {
            $multiple = $gap / $step;
            if (abs($multiple - round($multiple)) > 0.001) {
                return 0.0;
            }
        }
        return $step;
    }

    public function colorMode(int $variableId): ?array
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        foreach (IPS_GetChildrenIDs(IPS_GetParent($variableId)) as $child) {
            $ident = IPS_GetObject($child)['ObjectIdent'];
            if (IPS_VariableExists($child) && preg_match('/(^|_)colou?r_?mode$/i', $ident) === 1) {
                return ['ident' => $ident, 'value' => GetValue($child)];
            }
        }
        return null;
    }

    public function sleep(int $milliseconds): void
    {
        IPS_Sleep($milliseconds);
    }

    public function updated(int $variableId): int
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return 0;
        }
        return (int)IPS_GetVariable($variableId)['VariableUpdated'] * 1000;
    }

    public function now(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    public function rememberedTravel(int $levelVariable): ?array
    {
        return $this->readTravel === null ? null : ($this->readTravel)($levelVariable);
    }

    public function rememberPaused(array $playbackVariables): void
    {
        if ($this->storePaused !== null) {
            ($this->storePaused)($playbackVariables, $this->now());
        }
    }

    public function rememberedPaused(): array
    {
        return $this->readPaused === null ? [] : ($this->readPaused)();
    }

    public function rememberTravel(int $levelVariable, ?array $travel): void
    {
        if ($this->storeTravel !== null) {
            ($this->storeTravel)($levelVariable, $travel);
        }
    }

    public function counterSum(int $variableId, int $level, int $from, int $to): ?array
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_CONTROL);
        if ($archives === [] || $variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $archive = $archives[0];
        try {
            if (!AC_GetLoggingStatus($archive, $variableId) || AC_GetAggregationType($archive, $variableId) !== self::AGGREGATION_COUNTER) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }
        // Beim Zähler ist Avg die Summe der positiven Deltas des Zeitraums (Doku AC_GetAggregatedValues).
        // Ohne Aggregate (Zähler frisch archiviert) ist die Summe 0, archiviert ist er trotzdem.
        $sum = 0.0;
        try {
            foreach (self::allRows(static fn (int $until): array => AC_GetAggregatedValues($archive, $variableId, $level, $from, $until, 0), $from, $to) as $row) {
                $sum += (float)$row['Avg'];
            }
        } catch (Throwable) {
            $sum = 0.0;
        }
        return ['sum' => $sum, 'unit' => self::unitOf($variableId)];
    }

    /** Datensätze je Abfrage des Archivs, laut Doku das harte Limit, das immer greift. */
    private const ARCHIVE_LIMIT = 10000;

    /** Höchstens so viele Abfragen je Reihe (100000 Datensätze); bremst den Speicher auf der SymBox (64 MB). */
    private const ARCHIVE_SLICES = 10;

    /**
     * Alle Datensätze von $from bis $to, die neuesten zuerst wie im Archiv. Eine Abfrage liefert höchstens 10000 (Doku
     * AC_GetAggregatedValues und AC_GetLoggedValues); kommen so viele, fragt die nächste bis vor den ältesten erhaltenen
     * (Review 14.10.2026: Kosten über from=2025-01-01 rechneten nur mit den neuesten 10000 Stunden, eine Zeitleiste
     * verlor die ältesten Änderungen, beides ohne Hinweis).
     *
     * @param callable(int): array $query Abfrage mit dem Ende des Zeitraums
     */
    private static function allRows(callable $query, int $from, int $to): array
    {
        $rows = [];
        for ($slice = 0; $slice < self::ARCHIVE_SLICES; $slice++) {
            $batch = $query($to);
            array_push($rows, ...$batch);
            if (count($batch) < self::ARCHIVE_LIMIT) {
                break;
            }
            $to = (int)end($batch)['TimeStamp'] - 1;
            if ($to < $from) {
                break;
            }
        }
        return $rows;
    }

    public function counterSeries(int $variableId, int $level, int $from, int $to): ?array
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_CONTROL);
        if ($archives === [] || $variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $archive = $archives[0];
        try {
            if (!AC_GetLoggingStatus($archive, $variableId) || AC_GetAggregationType($archive, $variableId) !== self::AGGREGATION_COUNTER) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }
        // Wie bei counterSum ist Avg die Summe der positiven Deltas; das Archiv liefert die neuesten zuerst.
        try {
            $rows = array_reverse(self::allRows(static fn (int $until): array => AC_GetAggregatedValues($archive, $variableId, $level, $from, $until, 0), $from, $to));
        } catch (Throwable) {
            $rows = [];
        }
        return [
            'rows' => array_map(static fn (array $r): array => ['time' => (int)$r['TimeStamp'], 'sum' => (float)$r['Avg']], $rows),
            'unit' => self::unitOf($variableId),
        ];
    }

    /** So viele Rohwerte vor einem Zeitpunkt werden nach einem Wert über 0 durchsucht. */
    private const READING_DEPTH = 20;

    /** Eine Variable, die so lange nicht aktualisiert wurde, gilt beim Zählerstand nach jetzt als stillgelegt. */
    private const FRESH_SECONDS = 86400;

    public function counterReadings(int $variableId, array $times): ?array
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_CONTROL);
        if ($archives === [] || $variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $archive = $archives[0];
        try {
            if (!AC_GetLoggingStatus($archive, $variableId) || AC_GetAggregationType($archive, $variableId) !== self::AGGREGATION_COUNTER) {
                return null;
            }
            // Laut Doku AC_GetLoggedValues die neuesten zuerst; Startzeit 0 heißt von Anfang an.
            $readings = [];
            $now = time();
            foreach ($times as $time) {
                $readings[$time] = null;
                // Nach jetzt ist der letzte Satz der Wert der Variable. Aus dem Archiv kostete er in einer betreuten Anlage
                // bei Netzzählern, die alle paar Sekunden schreiben, 0,9 bis 1,4 s (Selbsttest 8,2 s, 18.10.2026). Nur
                // bei einer Variable, die im letzten Tag aktualisiert wurde: Ein stillgelegter Zähler kann nach einer
                // Reparatur neuere Sätze im Archiv haben als in der Variable, und bei ihm ist die Abfrage billig.
                $value = $time > $now && IPS_GetVariable($variableId)['VariableUpdated'] >= $now - self::FRESH_SECONDS ? GetValue($variableId) : null;
                if ((is_int($value) || is_float($value)) && $value > 0) {
                    $readings[$time] = (float)$value;
                    continue;
                }
                // LATEST (jetzt) als Ende der Abfrage wäre PHP_INT_MAX; die eigene Uhr reicht
                foreach (AC_GetLoggedValues($archive, $variableId, 0, min($time, $now + 1) - 1, self::READING_DEPTH) as $row) {
                    if ((float)$row['Value'] > 0) {
                        $readings[$time] = (float)$row['Value'];
                        break;
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }
        return $readings;
    }

    public function history(int $variableId, int $level, int $from, int $to): ?array
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_CONTROL);
        if ($archives === [] || $variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $archive = $archives[0];
        try {
            if (!AC_GetLoggingStatus($archive, $variableId) || AC_GetAggregationType($archive, $variableId) === self::AGGREGATION_COUNTER) {
                return null;
            }
            // Das Archiv liefert die neuesten zuerst (Doku AC_GetAggregatedValues).
            $rows = array_reverse(self::allRows(static fn (int $until): array => AC_GetAggregatedValues($archive, $variableId, $level, $from, $until, 0), $from, $to));
        } catch (Throwable) {
            return null;
        }
        return [
            // MinTime/MaxTime: Zeitpunkt von Minimum und Maximum der Rohwerte (Doku AC_GetAggregatedValues, B84)
            'rows' => array_map(static fn (array $r): array => ['time' => (int)$r['TimeStamp'], 'min' => (float)$r['Min'], 'avg' => (float)$r['Avg'], 'max' => (float)$r['Max']]
                + (isset($r['MinTime'], $r['MaxTime']) ? ['minTime' => (int)$r['MinTime'], 'maxTime' => (int)$r['MaxTime']] : []), $rows),
            'unit' => self::unitOf($variableId),
        ];
    }

    public function stateSeries(int $variableId, int $from, int $to): ?array
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_CONTROL);
        if ($archives === [] || $variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $archive = $archives[0];
        try {
            if (!AC_GetLoggingStatus($archive, $variableId) || AC_GetAggregationType($archive, $variableId) === self::AGGREGATION_COUNTER) {
                return null;
            }
            // Laut Doku AC_GetLoggedValues: neueste zuerst, höchstens 10000 Datensätze, nur Änderungen. Der Wert davor
            // ist der neueste bis $from - 1.
            $before = AC_GetLoggedValues($archive, $variableId, 0, $from - 1, 1);
            $rows = array_reverse(self::allRows(static fn (int $until): array => AC_GetLoggedValues($archive, $variableId, $from, $until, 0), $from, $to));
        } catch (Throwable) {
            return null;
        }
        return [
            'start'   => $before === [] ? null : $before[0]['Value'],
            'changes' => array_map(static fn (array $r): array => [(int)$r['TimeStamp'], $r['Value']], $rows),
        ];
    }

    public function timestamp(): int
    {
        return time();
    }

    public function unit(int $variableId): string
    {
        return $variableId > 0 && IPS_VariableExists($variableId) ? self::unitOf($variableId) : '';
    }

    public function translate(string $text): string
    {
        return ($this->translator)($text);
    }

    /**
     * Symcon meldet einen Fehlschlag als PHP-Warnung plus false; der Text der Warnung ist die
     * einzige Auskunft über die Ursache und geht deshalb in die Antwort.
     *
     * @param callable(): bool $call
     */
    private static function guarded(callable $call, string $fallback): ?string
    {
        $error = null;
        set_error_handler(static function (int $number, string $text) use (&$error): bool {
            $error = $text;
            return true;
        });
        try {
            $ok = $call();
        } catch (Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        return $ok ? null : ($error ?? $fallback);
    }

    /** Einheit einer Variable: Suffix des Profils, sonst das der Darstellung. */
    private static function unitOf(int $variableId): string
    {
        $variable = IPS_GetVariable($variableId);
        $profile = $variable['VariableCustomProfile'] !== '' ? $variable['VariableCustomProfile'] : $variable['VariableProfile'];
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            return trim((string)IPS_GetVariableProfile($profile)['Suffix']);
        }
        $presentation = function_exists('IPS_GetVariablePresentation') ? IPS_GetVariablePresentation($variableId) : [];
        return trim((string)($presentation['SUFFIX'] ?? ''));
    }

    /**
     * Bereich ohne eigene Richtungsangabe: der kleinste Wert ist offen (~Shutter, KLF200).
     *
     * @return null|array{min: float, max: float, open: float, closed: float, source: string, profile: string}
     */
    private static function validRange(float $min, float $max, string $profile = ''): ?array
    {
        return $max > $min ? self::travel($min, $max, 'default', $profile) : null;
    }

    /** @return null|array{min: float, max: float, open: float, closed: float, source: string, profile: string} */
    private static function travel(float $open, float $closed, string $source, string $profile = ''): ?array
    {
        if ($open === $closed) {
            return null;
        }
        return ['min' => min($open, $closed), 'max' => max($open, $closed), 'open' => $open, 'closed' => $closed, 'source' => $source, 'profile' => $profile];
    }
}
