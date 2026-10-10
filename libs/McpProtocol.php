<?php

declare(strict_types=1);

/**
 * Zustandsloser MCP-Server über Streamable HTTP (JSON-RPC 2.0), ohne Symcon-Abhängigkeit.
 *
 * Jede HTTP-Anfrage wird für sich beantwortet: POST mit einer JSON-RPC-Nachricht (oder einem
 * Stapel), Antwort als application/json. Einen Datenstrom (GET/SSE) und Sitzungen bietet der
 * Server nicht an; die Spezifikation erlaubt das, GET wird mit 405 abgewiesen.
 *
 * Die Werkzeuge kommen von außen: $listTools liefert die Liste für tools/list, $callTool führt
 * ein Werkzeug aus und gibt ['text' => string, 'isError' => bool] zurück.
 */
final class McpProtocol
{
    /** Unterstützte Protokollversionen, neueste zuerst. */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** Gehen mit jeder Antwort hinaus: nichts zwischenspeichern, den Inhaltstyp nicht raten. */
    public const BASE_HEADERS = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

    /** Höchstzahl Nachrichten in einem Stapel. */
    private const MAX_BATCH = 50;

    /**
     * @param bool $authorized Ergebnis der Zugangswahl (findAccess). Werkzeugliste, Werkzeugaufruf und
     *                         Anleitung bindet der Aufrufer an den gefundenen Zugang.
     * @param callable(): list<array<string, mixed>> $listTools
     * @param callable(string, array<string, mixed>): array{text: string, isError: bool} $callTool
     * @param array{name: string, title: string, version: string, instructions: string} $info
     * @return array{status: int, headers: array<string, string>, body: string, methods?: list<string>} methods = aufgerufene JSON-RPC-Methoden, fürs Debug
     */
    public static function handleHttp(string $method, bool $authorized, string|Closure $body, callable $listTools, callable $callTool, array $info): array
    {
        if (!$authorized) {
            return self::response(401, ['WWW-Authenticate' => 'Bearer'], ['error' => 'Missing or invalid bearer token']);
        }
        if ($method !== 'POST') {
            return self::response(405, ['Allow' => 'POST'], ['error' => 'Only POST is supported, this server offers no event stream']);
        }

        // Als Closure übergeben, liest der Aufrufer den Körper erst nach der Anmeldung
        try {
            $message = json_decode($body instanceof Closure ? (string)$body() : $body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::response(400, [], self::error(null, -32700, 'Parse error'));
        }

        // Stapel gibt es nur in der Protokollversion 2025-03-26. Jeder Werkzeugaufruf kann bis zu
        // 2 s warten und Aktionsskripte starten; deshalb höchstens einer je HTTP-Anfrage.
        $isBatch = is_array($message) && array_is_list($message);
        if ($isBatch && count($message) > self::MAX_BATCH) {
            return self::response(400, [], self::error(null, -32600, 'Invalid Request: at most ' . self::MAX_BATCH . ' messages per batch'));
        }
        $replies = [];
        $methods = [];
        $toolCalls = 0;
        foreach ($isBatch ? $message : [$message] as $single) {
            $methods[] = is_array($single) && is_string($single['method'] ?? null) ? $single['method'] : '?';
            if (is_array($single) && ($single['method'] ?? null) === 'tools/call' && array_key_exists('id', $single) && ++$toolCalls > 1) {
                $replies[] = self::error($single['id'], -32600, 'Invalid Request: only one tools/call per HTTP request');
                continue;
            }
            $reply = self::handleMessage($single, $listTools, $callTool, $info);
            if ($reply !== null) {
                $replies[] = $reply;
            }
        }

        if ($replies === []) {
            return ['status' => 202, 'headers' => ['Cache-Control' => 'no-store'], 'body' => '', 'methods' => $methods];
        }
        return self::response(200, [], $isBatch ? $replies : $replies[0]) + ['methods' => $methods];
    }

    /**
     * Liest das Token aus einer Adresse der Form <Hook-Pfad>/<Token>. Für Clients, die keinen
     * Authorization-Header mitschicken können. Alles hinter dem Hook-Pfad gilt als Token, ohne
     * Query-String und ohne Schrägstriche am Rand; '' = die Adresse trägt keines.
     */
    public static function tokenFromPath(string $requestUri, string $hookPath): string
    {
        $path = explode('?', $requestUri, 2)[0];
        $hookPath = rtrim($hookPath, '/');
        if ($hookPath === '' || !str_starts_with($path, $hookPath . '/')) {
            return '';
        }
        return trim(substr($path, strlen($hookPath)), '/');
    }

    /**
     * Bestimmt den Zugang, zu dem die Anmeldung passt. Ein mitgeschickter Authorization-Header
     * entscheidet allein. Nur ohne Header zählt das Token aus der Adresse, und nur bei Zugängen,
     * die das erlauben („InPath“); manche Server liefern den Pfad kodiert, deshalb gilt auch die
     * dekodierte Form. Ein Zugang ohne Token passt nie. Verglichen wird jeder Zugang, damit die
     * Antwortzeit nicht verrät, an welcher Stelle der Liste ein Token steht.
     *
     * @param array<int, array<string, mixed>> $accesses Zugänge mit „Token“ und „InPath“
     * @return ?int Schlüssel des ersten passenden Zugangs, null = keiner
     */
    public static function findAccess(string $authorization, string $pathToken, array $accesses): ?int
    {
        $authorization = trim($authorization);
        // RFC 7235: Das Schema ist unabhängig von Groß- und Kleinschreibung. Ein anderes Schema oder
        // ein leeres Token ergibt '', das zu keinem Zugang passt.
        $given = preg_match('/^Bearer[ \t]+(\S.*)$/i', $authorization, $parts) === 1 ? trim($parts[1]) : '';
        $found = null;
        foreach ($accesses as $index => $access) {
            // Beim Einfügen mitkopierte Leerzeichen am Rand gehören nicht zum Token
            $token = trim((string)($access['Token'] ?? ''));
            if ($token === '') {
                continue;
            }
            if ($authorization !== '') {
                $matches = $given !== '' && hash_equals($token, $given);
            } else {
                $matches = $pathToken !== '' && ($access['InPath'] ?? false) === true
                    && (hash_equals($token, $pathToken) || hash_equals($token, rawurldecode($pathToken)));
            }
            if ($matches && $found === null) {
                $found = $index;
            }
        }
        return $found;
    }

    /**
     * Bearbeitet eine einzelne JSON-RPC-Nachricht. Benachrichtigungen (ohne id) liefern null.
     *
     * @return array<string, mixed>|null
     */
    private static function handleMessage(mixed $message, callable $listTools, callable $callTool, array $info): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return self::error(is_array($message) ? ($message['id'] ?? null) : null, -32600, 'Invalid Request');
        }
        if (!array_key_exists('id', $message)) {
            return null; // Benachrichtigung, z. B. notifications/initialized
        }
        $id = $message['id'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        switch ($message['method']) {
            case 'initialize':
                $requested = $params['protocolVersion'] ?? '';
                return self::result($id, [
                    'protocolVersion' => in_array($requested, self::VERSIONS, true) ? $requested : self::VERSIONS[0],
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => $info['name'], 'title' => $info['title'], 'version' => $info['version']],
                    'instructions'    => $info['instructions'],
                ]);
            case 'ping':
                return self::result($id, new stdClass());
            case 'tools/list':
                return self::result($id, ['tools' => $listTools()]);
            case 'tools/call':
                $name = $params['name'] ?? null;
                $arguments = $params['arguments'] ?? [];
                $tools = $listTools();
                $known = array_column($tools, 'name');
                if (!is_string($name) || !in_array($name, $known, true)) {
                    return self::error($id, -32602, 'Unknown tool: ' . (is_string($name) ? $name : '?') . '. Available: ' . implode(', ', $known));
                }
                if (!is_array($arguments)) {
                    return self::error($id, -32602, 'arguments must be an object');
                }
                // null heißt „nicht angegeben“; manche Clients schicken unbenutzte Felder so mit
                $arguments = array_filter($arguments, static fn (mixed $value): bool => $value !== null);
                $problem = self::typeProblem($arguments, $tools[array_search($name, $known, true)]['inputSchema'] ?? []);
                if ($problem !== null) {
                    return self::error($id, -32602, $problem);
                }
                $outcome = $callTool($name, $arguments);
                return self::result($id, ['content' => [['type' => 'text', 'text' => $outcome['text']]], 'isError' => $outcome['isError']]);
            default:
                return self::error($id, -32601, 'Method not found: ' . $message['method']);
        }
    }

    /**
     * Prüft die Argumente gegen die Typen im Schema des Werkzeugs. Die Werkzeuge erwarten Text,
     * wo das Schema Text sagt; ein Array dort ergab vorher eine PHP-Warnung im Log und in der Antwort.
     */
    private static function typeProblem(array $arguments, array $schema): ?string
    {
        foreach ($schema['properties'] ?? [] as $key => $property) {
            if (!array_key_exists($key, $arguments) || !is_string($property['type'] ?? null)) {
                continue;
            }
            $value = $arguments[$key];
            $ok = match ($property['type']) {
                'string'  => is_string($value),
                'number'  => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'object'  => is_array($value) && ($value === [] || !array_is_list($value)),
                'array'   => is_array($value) && array_is_list($value),
                default   => true,
            };
            if (!$ok) {
                return sprintf('Parameter "%s" must be of type %s, got %s', $key, $property['type'], get_debug_type($value));
            }
        }
        return null;
    }

    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $text): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $text]];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function response(int $status, array $headers, mixed $content): array
    {
        return [
            'status'  => $status,
            'headers' => $headers + self::BASE_HEADERS,
            // Texte aus der Anlage können ungültiges UTF-8 tragen (Fremdmodule, CP850 unter Windows);
            // ersetzt statt geworfen, sonst endet der Hook ohne Antwort.
            'body'    => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        ];
    }
}
