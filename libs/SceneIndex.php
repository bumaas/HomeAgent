<?php

declare(strict_types=1);

require_once __DIR__ . '/DeviceBackend.php';
require_once __DIR__ . '/DeviceIndex.php';

/**
 * Szenenliste des Home Agent: findet eine Szene nach Name und Raum und startet ihr Skript.
 * Eine Szene ist ein Skript der Anlage; was es tut, weiß nur der Anwender.
 */
final class SceneIndex
{
    /** @var list<array{ScriptID: int, Name: string, Aliases: string, Room: string}> */
    private array $entries;

    private DeviceBackend $backend;

    private RoomIndex $rooms;

    /**
     * @param list<array<string, mixed>> $entries Zeilen der Formularliste „Scenes“
     * @param null|RoomIndex $rooms Raumliste, damit eine Szene auch über ein Alias ihres Raums gefunden wird
     */
    public function __construct(array $entries, DeviceBackend $backend, ?RoomIndex $rooms = null)
    {
        $this->rooms = $rooms ?? new RoomIndex([]);
        $this->entries = array_map(static fn (array $e): array => [
            'ScriptID' => (int)($e['ScriptID'] ?? 0),
            'Name'     => trim((string)($e['Name'] ?? '')),
            'Aliases'  => (string)($e['Aliases'] ?? ''),
            'Room'     => trim((string)($e['Room'] ?? '')),
        ], $entries);
        $this->backend = $backend;
    }

    /** @return list<string> Szenennamen für die Werkzeugbeschreibung, mit Raum, wo einer eingetragen ist */
    public function labels(): array
    {
        return array_map(static fn (array $e): string => $e['Name'] . ($e['Room'] !== '' ? ' (' . $e['Room'] . ')' : ''), $this->entries);
    }

    /**
     * Werkzeug „run_scene“: startet genau eine Szene. Ohne Namen wird nichts gestartet und die
     * Antwort nennt die Szenen.
     *
     * @return array{text: string, isError: bool}
     */
    public function runScene(array $arguments): array
    {
        $room = trim((string)($arguments['room'] ?? ''));
        $name = trim((string)($arguments['name'] ?? ''));

        if ($this->entries === []) {
            return $this->fail($this->t('No scene is released.'));
        }
        if ($name === '') {
            $head = sprintf($this->t('%d scene(s) available, none was started. Give the name of one scene:'), count($this->entries));
            return ['text' => implode("\n", array_merge([$head], $this->lines($this->entries))), 'isError' => false];
        }

        $filter = ($room !== '' ? 'room=' . $room . ', ' : '') . 'name=' . $name;
        $matches = $this->find($room, $name);
        if ($matches === []) {
            return $this->fail(sprintf($this->t('Nothing was started: no released scene matches %s. Known scenes:'), $filter) . "\n" . implode("\n", $this->lines($this->entries)));
        }
        if (count($matches) > 1) {
            return $this->fail(sprintf($this->t('Nothing was started: %d scenes match %s. Ask which one is meant:'), count($matches), $filter) . "\n" . implode("\n", $this->lines($matches)));
        }

        $scene = $matches[0];
        if (!$this->backend->scriptExists($scene['ScriptID'])) {
            return $this->fail(sprintf($this->t('Scene "%s" could not be started: script %d is missing.'), $scene['Name'], $scene['ScriptID']));
        }
        $error = $this->backend->runScript($scene['ScriptID']);
        if ($error !== null) {
            return $this->fail(sprintf($this->t('Scene "%s" could not be started: %s'), $scene['Name'], $error));
        }
        return ['text' => sprintf($this->t('Scene "%s" was started.'), $scene['Name']), 'isError' => false];
    }

    /** @return list<array{ScriptID: int, Name: string, Aliases: string, Room: string}> nur die beste Trefferstufe */
    private function find(string $room, string $name): array
    {
        $nameKey = DeviceIndex::normalize($name);
        $byQuality = [];
        foreach ($this->entries as $entry) {
            // Wie bei den Geräten: „-“ als Raum meint Szenen ohne Raum, ein Name aus Satzzeichen trifft nichts.
            if (trim($room) !== '' && !$this->rooms->matches($room, $entry['Room'])) {
                continue;
            }
            $quality = DeviceIndex::nameMatchQuality($entry['Name'], $entry['Aliases'], $nameKey);
            if ($quality > 0) {
                $byQuality[$quality][] = $entry;
            }
        }
        return $byQuality === [] ? [] : $byQuality[max(array_keys($byQuality))];
    }

    /** @return list<string> je Szene „Raum | Name“ */
    private function lines(array $entries): array
    {
        return array_map(static fn (array $e): string => ($e['Room'] !== '' ? $e['Room'] : '-') . ' | ' . $e['Name'], $entries);
    }

    /** @return array{text: string, isError: bool} */
    private function fail(string $text): array
    {
        return ['text' => $text, 'isError' => true];
    }

    private function t(string $text): string
    {
        return $this->backend->translate($text);
    }
}
