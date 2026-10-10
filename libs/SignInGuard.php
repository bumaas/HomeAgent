<?php

declare(strict_types=1);

/**
 * Bremse gegen das Durchprobieren von Tokens, ohne Symcon-Abhängigkeit. Der Zustand liegt beim
 * Aufrufer (im Modul ein Buffer) und kommt mit der Uhrzeit herein, damit die Regeln testbar sind.
 *
 * Gezählt wird je Minute ab dem ersten Fehlversuch. Gemeldet wird der erste Fehlversuch einer
 * Minute und, beim ersten Fehlversuch der nächsten, die Zahl der vorigen; so steht höchstens
 * zweimal je Minute etwas im Log. Nach MAX_FAILURES Fehlversuchen in einer Minute ist der
 * Endpunkt BLOCK_SECONDS lang für alle zu, auch für richtige Tokens: Sonst ginge das Raten
 * weiter, und ein Treffer wäre an der Antwort zu erkennen. Eine Bremse je Absender greift nicht,
 * weil über Symcon Connect alle Anfragen von derselben Adresse kommen.
 */
final class SignInGuard
{
    public const WINDOW_SECONDS = 60;
    public const MAX_FAILURES = 20;
    public const BLOCK_SECONDS = 60;

    /** @return int Sekunden, die der Endpunkt noch gesperrt ist; 0 = offen */
    public static function blockedFor(array $state, int $now): int
    {
        return max(0, (int)($state['blockedUntil'] ?? 0) - $now);
    }

    /**
     * Zählt einen Fehlversuch.
     *
     * @return array{state: array{start: int, count: int, blockedUntil: int}, events: list<array{0: string, 1?: int, 2?: int}>}
     *         Ereignisse: ['first'], ['summary', Anzahl, Beginn der vorigen Minute], ['blocked']
     */
    public static function failure(array $state, int $now): array
    {
        $events = [];
        $start = (int)($state['start'] ?? 0);
        $count = (int)($state['count'] ?? 0);
        $blockedUntil = (int)($state['blockedUntil'] ?? 0);
        if ($now - $start >= self::WINDOW_SECONDS) {
            if ($count > 1) {
                $events[] = ['summary', $count, $start];
            }
            $start = $now;
            $count = 0;
        }
        $count++;
        if ($count === 1) {
            $events[] = ['first'];
        }
        if ($count >= self::MAX_FAILURES && $blockedUntil <= $now) {
            $blockedUntil = $now + self::BLOCK_SECONDS;
            $events[] = ['blocked'];
        }
        return ['state' => ['start' => $start, 'count' => $count, 'blockedUntil' => $blockedUntil], 'events' => $events];
    }
}
