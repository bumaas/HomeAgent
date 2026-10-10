<?php

declare(strict_types=1);

require_once __DIR__ . '/DeviceIndex.php';

/**
 * Raumliste des Home Agent: gibt Räumen Aliase und eine Etage. Der Raum eines Geräts oder
 * einer Szene bleibt freier Text; ein Raum, der hier nicht steht, wird weiter über seinen Namen
 * gefunden, hat aber weder Alias noch Etage.
 */
final class RoomIndex
{
    /** @var list<array{name: string, key: string, keys: list<string>, floor: string}> */
    private array $rooms = [];

    /** @param list<array<string, mixed>> $rows Zeilen der Formularliste „Rooms“ */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            $name = trim((string)($row['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $keys = [DeviceIndex::normalize($name)];
            foreach (explode(',', (string)($row['Aliases'] ?? '')) as $alias) {
                $key = DeviceIndex::normalize($alias);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
            $this->rooms[] = ['name' => $name, 'key' => $keys[0], 'keys' => $keys, 'floor' => trim((string)($row['Floor'] ?? ''))];
        }
    }

    /**
     * Meint die Raumangabe den Raum eines Geräts? Entweder heißt der Raum so, oder die Angabe ist
     * Name bzw. Alias eines Raums der Liste, der so heißt wie der Raum des Geräts.
     */
    public function matches(string $query, string $deviceRoom): bool
    {
        $queryKey = DeviceIndex::normalize($query);
        $roomKey = DeviceIndex::normalize($deviceRoom);
        if ($queryKey === $roomKey) {
            return true;
        }
        foreach ($this->rooms as $room) {
            if ($room['key'] === $roomKey && in_array($queryKey, $room['keys'], true)) {
                return true;
            }
        }
        return false;
    }

    /** Steht der Raum eines Geräts unter diesem Namen in der Raumliste? Nur dann hat er Aliase und Etage. */
    public function isListed(string $deviceRoom): bool
    {
        return in_array(DeviceIndex::normalize($deviceRoom), array_column($this->rooms, 'key'), true);
    }

    /** Name des Raums der Liste, der so heißt oder diesen Alias hat; null = nicht in der Liste. */
    public function nameOf(string $query): ?string
    {
        $queryKey = DeviceIndex::normalize($query);
        foreach ($this->rooms as $room) {
            if ($queryKey !== '' && in_array($queryKey, $room['keys'], true)) {
                return $room['name'];
            }
        }
        return null;
    }

    /** Steht die Angabe als Name oder Alias in der Raumliste? */
    public function isKnown(string $query): bool
    {
        $queryKey = DeviceIndex::normalize($query);
        foreach ($this->rooms as $room) {
            if (in_array($queryKey, $room['keys'], true)) {
                return true;
            }
        }
        return false;
    }

    /** Liegt der Raum eines Geräts laut Raumliste auf dieser Etage? */
    public function onFloor(string $floor, string $deviceRoom): bool
    {
        $floorKey = DeviceIndex::normalize($floor);
        $roomKey = DeviceIndex::normalize($deviceRoom);
        foreach ($this->rooms as $room) {
            if ($room['key'] === $roomKey && $floorKey !== '' && DeviceIndex::normalize($room['floor']) === $floorKey) {
                return true;
            }
        }
        return false;
    }

    public function knowsFloor(string $floor): bool
    {
        $floorKey = DeviceIndex::normalize($floor);
        foreach ($this->floors() as $known) {
            if (DeviceIndex::normalize($known) === $floorKey) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> Etagen in der Reihenfolge der Raumliste, jede einmal */
    public function floors(): array
    {
        $floors = [];
        foreach ($this->rooms as $room) {
            if ($room['floor'] !== '') {
                $floors[DeviceIndex::normalize($room['floor'])] ??= $room['floor'];
            }
        }
        return array_values($floors);
    }

    /**
     * Welcher Raum der Liste steckt in diesen Wörtern (aus einem Gerätenamen und dem Pfad seiner
     * Variable)? Ein Name oder Alias passt, wenn alle seine Wörter vorkommen; es gewinnt der mit den
     * meisten Wörtern, dann der mit den meisten Wörtern im Namen, dann der längste. So trifft
     * „Thermostat DG Küche“ mit dem Pfad „DG_Küche“ den Alias „Küche_DG“ und nicht die Küche unten.
     * „tokens“ sind alle Wörter des Raums samt Aliasen und Etage, die aus dem Namen fallen dürfen.
     *
     * @param list<string> $nameTokens normalisierte Wörter des Namens
     * @param list<string> $pathTokens normalisierte Wörter des Pfads
     * @return null|array{room: string, tokens: list<string>}
     */
    public function bestMatch(array $nameTokens, array $pathTokens): ?array
    {
        $words = static fn (string $text): array => preg_split('/[^\p{L}\p{N}]+/u', DeviceIndex::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $all = array_merge($nameTokens, $pathTokens);
        $best = null;
        $bestScore = null;
        foreach ($this->rooms as $room) {
            foreach ($room['keys'] as $key) {
                $tokens = $words($key);
                if ($tokens === [] || array_diff($tokens, $all) !== []) {
                    continue;
                }
                $score = [count($tokens), count(array_intersect($tokens, $nameTokens)), strlen($key)];
                if ($bestScore === null || $score > $bestScore) {
                    $bestScore = $score;
                    $best = $room;
                }
            }
        }
        if ($best === null) {
            return null;
        }
        $tokens = $words($best['floor']);
        foreach ($best['keys'] as $key) {
            $tokens = array_merge($tokens, $words($key));
        }
        return ['room' => $best['name'], 'tokens' => array_values(array_unique($tokens))];
    }

    /** @return list<string> Namen der Räume, wie sie in der Liste stehen */
    public function names(): array
    {
        return array_column($this->rooms, 'name');
    }
}
