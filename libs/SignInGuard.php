<?php

declare(strict_types=1);

/**
 * Bremse gegen das Durchprobieren von Tokens, ohne Symcon-Abhängigkeit. Der Zustand liegt beim
 * Aufrufer (im Modul ein Buffer) und kommt mit der Uhrzeit herein, damit die Regeln testbar sind.
 *
 * Gezählt wird je Absender und Minute ab dem ersten Fehlversuch. Nach MAX_FAILURES Fehlversuchen
 * eines Absenders in einer Minute ist der Endpunkt für ihn BLOCK_SECONDS lang zu, auch für richtige
 * Tokens: Sonst ginge das Raten weiter, und ein Treffer wäre an der Antwort zu erkennen. Über
 * Symcon Connect kommt jede Anfrage von 127.0.0.1, der Absender steht in X-Forwarded-For (sender()).
 * Bis build 98 zählten alle Fehlversuche zusammen, dann sperrten 20 falsche Tokens von irgendwem
 * auch den eigenen Assistenten (Backlog B60). Gegen Raten von vielen Adressen bleibt eine
 * Obergrenze für alle zusammen (MAX_FAILURES_ALL).
 *
 * Gemeldet wird der erste Fehlversuch einer Minute und, beim ersten Fehlversuch der nächsten, die
 * Zahl der vorigen, über alle Absender; so steht höchstens zweimal je Minute etwas im Log.
 */
final class SignInGuard
{
    public const WINDOW_SECONDS = 60;
    public const MAX_FAILURES = 20;
    public const MAX_FAILURES_ALL = 100;
    public const BLOCK_SECONDS = 60;
    /** So viele Absender merkt sich die Bremse höchstens; abgelaufene fallen zuerst heraus. */
    public const MAX_SENDERS = 100;

    /** Absender der Anfrage: über Connect (Verbindung von 127.0.0.1 oder ::1) der letzte Eintrag in X-Forwarded-For. */
    public static function sender(array $server): string
    {
        $remote = (string)($server['REMOTE_ADDR'] ?? '');
        if (in_array($remote, ['127.0.0.1', '::1'], true)) {
            // Den letzten Eintrag hängt der Proxy an, die vorderen kann der Absender selbst mitschicken.
            $forwarded = array_values(array_filter(array_map('trim', explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''))), static fn (string $a): bool => $a !== ''));
            if ($forwarded !== []) {
                return $forwarded[array_key_last($forwarded)];
            }
        }
        return $remote === '' ? '?' : $remote;
    }

    /** @return int Sekunden, die der Endpunkt für diesen Absender noch gesperrt ist; 0 = offen */
    public static function blockedFor(array $state, int $now, string $sender = ''): int
    {
        $own = (int)($state['senders'][$sender]['blockedUntil'] ?? 0);
        $all = (int)($state['all']['blockedUntil'] ?? 0);
        return max(0, max($own, $all) - $now);
    }

    /**
     * Zählt einen Fehlversuch.
     *
     * @return array{state: array{all: array{start: int, count: int, blockedUntil: int}, senders: array<string, array{start: int, count: int, blockedUntil: int}>}, events: list<array{0: string, 1?: int|string, 2?: int}>}
     *         Ereignisse: ['first'], ['summary', Anzahl, Beginn der vorigen Minute], ['blocked', Absender], ['blocked_all']
     */
    public static function failure(array $state, int $now, string $sender = ''): array
    {
        $events = [];
        $all = self::count(is_array($state['all'] ?? null) ? $state['all'] : [], $now);
        if ($all['expired'] > 1) {
            $events[] = ['summary', $all['expired'], $all['previousStart']];
        }
        if ($all['window']['count'] === 1) {
            $events[] = ['first'];
        }
        if ($all['window']['count'] >= self::MAX_FAILURES_ALL && $all['window']['blockedUntil'] <= $now) {
            $all['window']['blockedUntil'] = $now + self::BLOCK_SECONDS;
            $events[] = ['blocked_all'];
        }

        $senders = is_array($state['senders'] ?? null) ? $state['senders'] : [];
        $own = self::count(is_array($senders[$sender] ?? null) ? $senders[$sender] : [], $now)['window'];
        if ($own['count'] >= self::MAX_FAILURES && $own['blockedUntil'] <= $now) {
            $own['blockedUntil'] = $now + self::BLOCK_SECONDS;
            $events[] = ['blocked', $sender];
        }
        unset($senders[$sender]);
        $senders[$sender] = $own;
        return ['state' => ['all' => $all['window'], 'senders' => self::prune($senders, $now)], 'events' => $events];
    }

    /**
     * Ein Zähler je Minute: beginnt neu, wenn die Minute vorbei ist.
     *
     * @return array{window: array{start: int, count: int, blockedUntil: int}, expired: int, previousStart: int}
     */
    private static function count(array $window, int $now): array
    {
        $start = (int)($window['start'] ?? 0);
        $count = (int)($window['count'] ?? 0);
        $expired = 0;
        if ($now - $start >= self::WINDOW_SECONDS) {
            $expired = $count;
            $count = 0;
        }
        return [
            'window'        => ['start' => $expired > 0 || $count === 0 ? $now : $start, 'count' => $count + 1, 'blockedUntil' => (int)($window['blockedUntil'] ?? 0)],
            'expired'       => $expired,
            'previousStart' => $start,
        ];
    }

    /**
     * Höchstens MAX_SENDERS Absender: zuerst fallen die heraus, deren Minute und Sperre vorbei sind, dann die ältesten.
     *
     * @param array<string, array{start: int, count: int, blockedUntil: int}> $senders in der Reihenfolge des letzten Fehlversuchs
     * @return array<string, array{start: int, count: int, blockedUntil: int}>
     */
    private static function prune(array $senders, int $now): array
    {
        if (count($senders) <= self::MAX_SENDERS) {
            return $senders;
        }
        $senders = array_filter($senders, static fn (array $s): bool => $now - $s['start'] < self::WINDOW_SECONDS || $s['blockedUntil'] > $now);
        return array_slice($senders, -self::MAX_SENDERS, null, true);
    }
}
