<?php

declare(strict_types=1);

require_once __DIR__ . '/DeviceIndex.php';
require_once __DIR__ . '/RoomIndex.php';

/**
 * Vorschlag der Freigabeliste aus Symcons Alexa-Instanz. Wer Sprachsteuerung nutzt, hat dort seine
 * Geräte schon nach Art sortiert und mit sprechbaren Namen gepflegt. Ohne Symcon-Abhängigkeit: die
 * Konfiguration der Alexa-Instanz, der Zugang zu den Variablen und der Übersetzer kommen von außen,
 * damit der Test mit dem Mitschnitt vom nuc arbeiten kann.
 */
final class AlexaImport
{
    /** Modul-GUID der Alexa-Instanz von Symcon (am nuc #10110, gelesen 10.10.2026). */
    public const MODULE_ID = '{CC759EB6-7821-4AA5-9267-EF08C6A6A5B3}';

    /** Alexa-Liste => Gerätetyp und je Fähigkeit der Schlüssel der Variable im Alexa-Eintrag. */
    private const KINDS = [
        'DeviceLightSwitch'       => ['type' => 'light', 'roles' => ['state' => 'PowerControllerID']],
        'DeviceLightDimmer'       => ['type' => 'light', 'roles' => ['level' => 'BrightnessControllerID']],
        'DeviceLightExpert'       => ['type' => 'light', 'roles' => ['state' => 'PowerControllerID', 'level' => 'BrightnessOnlyControllerID']],
        'DeviceGenericSwitch'     => ['type' => 'switch', 'roles' => ['state' => 'PowerControllerID']],
        'DeviceShutter'           => ['type' => 'blind', 'roles' => ['level' => 'RangeControllerShutterID']],
        'DeviceThermostat'        => ['type' => 'thermostat', 'roles' => ['setpoint' => 'ThermostatControllerID']],
        'DeviceTemperatureSensor' => ['type' => 'temperature', 'roles' => ['state' => 'TemperatureSensorID']],
    ];

    /** Alexa-Listen ohne passenden Typ, mit dem Wort für die Meldung. */
    private const UNSUPPORTED = [
        'DeviceLightColor'      => 'color light',
        'DeviceLock'            => 'lock',
        'DeviceTelevision'      => 'television',
        'DeviceSpeaker'         => 'speaker',
        'DeviceSpeakerMuteable' => 'speaker',
        'DeviceMediaPlayer'     => 'media player',
        'DeviceGenericSlider'   => 'slider',
    ];

    /**
     * Profile eines Rollladens, der nur Fahrbefehle kennt (Alexa-Doku „Rollladen“). Als Position
     * gelesen hieße „auf 50 %“ der Wert 2, also Stop (am nuc: 0 Öffnen, 2 Stop, 4 Schließen).
     */
    private const MOVE_PROFILES = ['~shuttermovestop', '~shuttermovestep'];

    /** Szenen sind bei Alexa Aktionen, keine Skripte; die Szenenliste braucht ein Skript. */
    private const SCENES = ['DeviceSimpleScene', 'DeviceDeactivatableScene'];

    /**
     * Schlägt Zeilen für die Freigabeliste vor. Schon freigegebene Variablen, doppelte Einträge und
     * Arten ohne passenden Typ fallen weg und werden gezählt.
     *
     * @param array<string, mixed> $alexaConfig Konfiguration der Alexa-Instanz (Listen als JSON-Text oder Array)
     * @param callable(int): ?array{parent: int, path: string, profile?: string} $objectInfo null = Variable fehlt
     * @param list<array<string, mixed>> $devices bisherige Zeilen der Freigabeliste
     * @param callable(string): string $translate
     * @return array{rows: list<array<string, mixed>>, report: array<string, mixed>, message: string}
     */
    public static function propose(array $alexaConfig, callable $objectInfo, array $devices, RoomIndex $rooms, callable $translate): array
    {
        $report = ['proposed' => 0, 'released' => 0, 'duplicates' => 0, 'missing' => 0, 'scenes' => 0, 'merged' => 0, 'unsupported' => []];
        $released = [];
        foreach ($devices as $row) {
            foreach (DeviceIndex::ROLES as $key) {
                if ((int)($row[$key] ?? 0) > 1) {
                    $released[(int)$row[$key]] = true;
                }
            }
        }

        $candidates = [];
        $used = [];
        foreach ($alexaConfig as $list => $value) {
            $entries = is_string($value) ? json_decode($value, true) : $value;
            if (!str_starts_with((string)$list, 'Device') || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (in_array($list, self::SCENES, true)) {
                    $report['scenes']++;
                    continue;
                }
                if (!isset(self::KINDS[$list])) {
                    $word = self::UNSUPPORTED[$list] ?? 'other';
                    $report['unsupported'][$word] = ($report['unsupported'][$word] ?? 0) + 1;
                    continue;
                }
                $roles = [];
                foreach (self::KINDS[$list]['roles'] as $role => $field) {
                    if ((int)($entry[$field] ?? 0) > 1) {
                        $roles[$role] = (int)$entry[$field];
                    }
                }
                $infos = array_map($objectInfo, $roles);
                if ($roles === [] || in_array(null, $infos, true)) {
                    $report['missing']++;
                    continue;
                }
                if ($list === 'DeviceShutter' && in_array(strtolower((string)($infos['level']['profile'] ?? '')), self::MOVE_PROFILES, true)) {
                    $report['unsupported']['blind without position'] = ($report['unsupported']['blind without position'] ?? 0) + 1;
                    continue;
                }
                if (array_intersect_key($released, array_flip($roles)) !== []) {
                    $report['released']++;
                    continue;
                }
                if (array_intersect_key($used, array_flip($roles)) !== []) {
                    $report['duplicates']++;
                    continue;
                }
                foreach ($roles as $id) {
                    $used[$id] = true;
                }
                $name = trim((string)($entry['Name'] ?? ''));
                $type = self::KINDS[$list]['type'];
                // Fensterantriebe stehen bei Alexa unter den Rollläden („Fenster Bad“).
                if ($type === 'blind' && (self::tokens($name)[0] ?? '') === 'fenster') {
                    $type = 'window';
                }
                $pathTokens = [];
                foreach ($infos as $info) {
                    $pathTokens = array_merge($pathTokens, self::tokens((string)$info['path']));
                }
                $candidates[] = ['type' => $type, 'name' => $name, 'roles' => $roles, 'parent' => (int)array_first($infos)['parent'],
                    'paths' => array_values(array_map(static fn (array $info): string => (string)$info['path'], $infos)),
                    'match' => $rooms->bestMatch(self::tokens($name), $pathTokens)];
            }
        }

        self::proposeRooms($candidates);
        $report['merged'] = self::mergeThermostats($candidates);
        $rows = array_map(static fn (array $c): array => self::row($c), array_values($candidates));
        $report['proposed'] = count($rows);
        return ['rows' => $rows, 'report' => $report, 'message' => self::message($report, $translate)];
    }

    /**
     * Raum für Vorschläge, zu denen die Raumliste nichts weiß (betreute Anlage, 10.10.2026: ohne Raumliste
     * blieben alle Räume leer). Zuerst die Kategorien im eigenen Pfad, von der Wurzel her: die erste,
     * die ein Wort mit dem Alexa-Namen teilt („Hardware\Shelly\EG\Küche\…“ bei „Küche links“). Sonst
     * ein Wort des Namens, das bei irgendeinem Vorschlag als eigene Kategorie vorkommt. Kategorien
     * aus höchstens zwei Buchstaben (EG, OG, DG) sind Etagen und zählen nicht.
     *
     * @param list<array<string, mixed>> $candidates
     */
    private static function proposeRooms(array &$candidates): void
    {
        // Ohne die letzten beiden Stufen: die Variable und ihre Instanz (das Gerät selbst).
        $categories = static function (array $paths): array {
            $result = [];
            foreach ($paths as $path) {
                $segments = explode('\\', $path);
                foreach (array_slice($segments, 0, max(0, count($segments) - 2)) as $segment) {
                    if (mb_strlen((string)preg_replace('/[^\p{L}]/u', '', $segment)) > 2) {
                        $result[] = $segment;
                    }
                }
            }
            return $result;
        };
        $known = [];
        foreach ($candidates as $candidate) {
            foreach ($categories($candidate['paths']) as $segment) {
                $tokens = self::tokens($segment);
                if (count($tokens) === 1) {
                    $known[$tokens[0]] ??= $segment;
                }
            }
        }
        foreach ($candidates as &$candidate) {
            if ($candidate['match'] !== null) {
                continue;
            }
            $nameTokens = self::tokens($candidate['name']);
            $room = array_find($categories($candidate['paths']), static fn (string $segment): bool => array_intersect(self::tokens($segment), $nameTokens) !== []);
            if ($room === null) {
                $word = array_find($nameTokens, static fn (string $token): bool => isset($known[$token]));
                $room = $word === null ? null : $known[$word];
            }
            if ($room !== null) {
                $room = trim((string)preg_replace('/[_\s]+/u', ' ', $room));
                $candidate['match'] = ['room' => $room, 'tokens' => self::tokens($room)];
            }
        }
        unset($candidate);
    }

    /**
     * Ein Temperatursensor wird die Ist-Temperatur eines Thermostats, wenn beide in derselben
     * Instanz liegen oder im selben Raum genau ein Thermostat und genau ein Sensor stehen.
     *
     * @param list<array<string, mixed>> $candidates
     */
    private static function mergeThermostats(array &$candidates): int
    {
        $merged = 0;
        $join = static function (int $thermostat, int $sensor) use (&$candidates, &$merged): void {
            $candidates[$thermostat]['roles']['state'] = $candidates[$sensor]['roles']['state'];
            unset($candidates[$sensor]);
            $merged++;
        };
        foreach ($candidates as $t => $thermostat) {
            if ($thermostat['type'] !== 'thermostat') {
                continue;
            }
            foreach ($candidates as $s => $sensor) {
                if ($sensor['type'] === 'temperature' && $sensor['parent'] === $thermostat['parent']) {
                    $join($t, $s);
                    break;
                }
            }
        }
        $byRoom = [];
        foreach ($candidates as $index => $candidate) {
            $room = $candidate['match']['room'] ?? '';
            if ($room !== '' && ($candidate['type'] === 'temperature' || ($candidate['type'] === 'thermostat' && !isset($candidate['roles']['state'])))) {
                $byRoom[$room][$candidate['type']][] = $index;
            }
        }
        foreach ($byRoom as $types) {
            if (count($types['thermostat'] ?? []) === 1 && count($types['temperature'] ?? []) === 1) {
                $join($types['thermostat'][0], $types['temperature'][0]);
            }
        }
        return $merged;
    }

    /** Zeile im Format der Property „Devices“; ungesetzte Fähigkeiten stehen auf 1 wie im Formular. */
    private static function row(array $candidate): array
    {
        $match = $candidate['match'];
        $name = $candidate['name'];
        if ($match !== null) {
            // Wörter, die zum Raum oder seiner Etage gehören, fallen aus dem Namen.
            $kept = array_filter(preg_split('/\s+/u', $name) ?: [], static fn (string $word): bool => array_diff(self::tokens($word), $match['tokens']) !== [] || self::tokens($word) === []);
            $name = $kept === [] ? $name : implode(' ', $kept);
        }
        $row = [
            'Room'     => $match['room'] ?? '',
            'Name'     => $name,
            'Aliases'  => $name !== $candidate['name'] ? $candidate['name'] : '',
            'Type'     => $candidate['type'],
            'Invert'   => false,
            'ReadOnly' => false,
            'Tilt'     => false,
        ];
        foreach (DeviceIndex::ROLES as $role => $key) {
            $row[$key] = $candidate['roles'][$role] ?? 1;
        }
        return $row;
    }

    /** @param array<string, mixed> $report */
    private static function message(array $report, callable $t): string
    {
        $parts = [sprintf($t('%d device(s) proposed'), $report['proposed']), sprintf($t('%d already released'), $report['released'])];
        if ($report['unsupported'] !== []) {
            $kinds = [];
            foreach ($report['unsupported'] as $word => $count) {
                $kinds[] = $t($word) . ' ' . $count;
            }
            $parts[] = sprintf($t('%d without a matching type (%s)'), array_sum($report['unsupported']), implode(', ', $kinds));
        }
        if ($report['scenes'] > 0) {
            $parts[] = sprintf($t('%d scene(s) are Alexa actions and were left out'), $report['scenes']);
        }
        if ($report['duplicates'] > 0) {
            $parts[] = sprintf($t('%d listed twice in Alexa'), $report['duplicates']);
        }
        if ($report['missing'] > 0) {
            $parts[] = sprintf($t('%d with a missing variable'), $report['missing']);
        }
        if ($report['merged'] > 0) {
            $parts[] = sprintf($t('%d temperature sensor(s) became the current temperature of a thermostat'), $report['merged']);
        }
        return implode(', ', $parts) . '. ' . $t('Tick the devices to release and press Release selected.');
    }

    /** @return list<string> Wörter in normalisierter Form; Unterstrich, Punkt und Backslash trennen. */
    public static function tokens(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', DeviceIndex::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
