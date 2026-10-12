<?php

declare(strict_types=1);

/**
 * Zustandsloser MCP-Server über Streamable HTTP (JSON-RPC 2.0), ohne Symcon-Abhängigkeit.
 *
 * Jede HTTP-Anfrage wird für sich beantwortet: POST mit einer JSON-RPC-Nachricht (oder einem
 * Stapel), Antwort als application/json. Einen Datenstrom (GET/SSE) und Sitzungen bietet der
 * Server nicht an; die Spezifikation erlaubt das, GET wird mit 405 abgewiesen.
 *
 * Zwei Generationen an einem Endpunkt („dual-era“, Spezifikation 2026-07-28, Backlog B62): Eine Anfrage mit
 * der Protokollversion in params._meta folgt 2026-07-28 (kein initialize, Kopfzeilen MCP-Protocol-Version,
 * Mcp-Method und Mcp-Name werden geprüft, server/discover, resultType, Zwischenspeichern von tools/list). Ohne
 * _meta bleibt alles wie in 2025-11-25 und davor. Claude Code verbindet so mit 2 statt 5 Anfragen.
 *
 * Die Werkzeuge kommen von außen: $listTools liefert die Liste für tools/list, $callTool führt
 * ein Werkzeug aus und gibt ['text' => string, 'isError' => bool] zurück.
 */
final class McpProtocol
{
    /** Unterstützte Protokollversionen mit initialize, neueste zuerst. */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** Versionen ohne initialize, mit Version und Client je Anfrage in _meta. */
    public const MODERN_VERSIONS = ['2026-07-28'];

    /** So lange darf ein Client Werkzeugliste und Discover-Antwort behalten (Burkhard 12.10.2026: 5 Minuten). */
    public const LIST_TTL_MS = 300000;

    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_SERVER = 'io.modelcontextprotocol/serverInfo';

    /** Inhaltstyp der Seiten von MCP Apps (Erweiterung io.modelcontextprotocol/ui). */
    public const APP_MIME_TYPE = 'text/html;profile=mcp-app';

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
     * @param array<string, string> $headers Kopfzeilen der Anfrage, Namen klein (mcp-protocol-version, mcp-method, mcp-name)
     * @param null|callable(string): ?string $readResource HTML einer ui://-Ressource (MCP Apps), null = unbekannt
     * @param null|callable(?string): ?array $prompts Vorlagen: mit null die Liste, mit einem Namen Beschreibung und
     *                                                Nachrichten, null = gibt es nicht
     * @return array{status: int, headers: array<string, string>, body: string, methods?: list<string>} methods = aufgerufene JSON-RPC-Methoden, fürs Debug
     */
    public static function handleHttp(string $method, bool $authorized, string|Closure $body, callable $listTools, callable $callTool, array $info, array $headers = [], ?callable $readResource = null, ?callable $prompts = null): array
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

        // Name, Version und Fähigkeiten des Clients fürs Protokoll, nur beim Verbinden (B63)
        $client = self::clientLabel($message);
        $withClient = static fn (array $response): array => $client === null ? $response : $response + ['client' => $client];

        // Protokoll 2026-07-28: erkennbar an der Version in _meta (bei einem Stapel an irgendeiner Nachricht)
        if (self::isModern($message)) {
            return $withClient(self::handleModern($message, $headers, $listTools, $callTool, $info, $readResource, $prompts));
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
            $reply = self::handleMessage($single, $listTools, $callTool, $info, ['read' => $readResource, 'prompts' => $prompts]);
            if ($reply !== null) {
                $replies[] = $reply;
            }
        }

        if ($replies === []) {
            return $withClient(['status' => 202, 'headers' => ['Cache-Control' => 'no-store'], 'body' => '', 'methods' => $methods]);
        }
        return $withClient(self::response(200, [], $isBatch ? $replies : $replies[0]) + ['methods' => $methods]);
    }

    /**
     * Client bei initialize und server/discover: „Name Version (Fähigkeiten)“, bei Rückfragen mit ihren Arten und mit
     * den Erweiterungen; null bei allen anderen Anfragen. Damit zeigt das Protokoll, was ein Client kann (B63).
     */
    private static function clientLabel(mixed $message): ?string
    {
        if (!is_array($message) || array_is_list($message) || !in_array($message['method'] ?? null, ['initialize', 'server/discover'], true)) {
            return null;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $info = $params['clientInfo'] ?? $meta['io.modelcontextprotocol/clientInfo'] ?? [];
        $capabilities = $params['capabilities'] ?? $meta['io.modelcontextprotocol/clientCapabilities'] ?? [];
        $parts = [];
        foreach (is_array($capabilities) ? $capabilities : [] as $name => $value) {
            if ($name === 'extensions' && is_array($value)) {
                foreach (array_keys($value) as $extension) {
                    $parts[] = 'extension ' . $extension;
                }
            } elseif ($name === 'elicitation' && is_array($value) && $value !== [] && !array_is_list($value)) {
                $parts[] = 'elicitation: ' . implode(', ', array_keys($value));
            } else {
                $parts[] = (string)$name;
            }
        }
        $label = trim((is_array($info) ? (string)($info['name'] ?? '?') . ' ' . (string)($info['version'] ?? '') : '?'));
        return mb_substr($label . ($parts === [] ? '' : ' (' . implode(', ', $parts) . ')'), 0, 300);
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
    /**
     * @param array{ui?: bool, read?: ?callable} $context ui = der Client zeigt MCP Apps (nur nach 2026-07-28 erkennbar),
     *                                              read = liefert das HTML einer ui://-Ressource
     */
    private static function handleMessage(mixed $message, callable $listTools, callable $callTool, array $info, array $context = []): ?array
    {
        $apps = ($context['ui'] ?? false) === true;
        $read = $context['read'] ?? null;
        $prompts = $context['prompts'] ?? null;
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
                    'capabilities'    => self::capabilities($read !== null, $prompts !== null),
                    'serverInfo'      => ['name' => $info['name'], 'title' => $info['title'], 'version' => $info['version']],
                    'instructions'    => $info['instructions'],
                ]);
            case 'ping':
                return self::result($id, new stdClass());
            case 'server/discover':
                // Pflicht für Server nach 2026-07-28; auch ohne _meta beantwortet, wie ein Client es zur Probe schickt
                return self::result($id, self::discoverResult($info, $read !== null, $prompts !== null));
            case 'prompts/list':
            case 'prompts/get':
                if ($prompts === null) {
                    return self::error($id, -32601, 'Method not found: ' . $message['method']);
                }
                if ($message['method'] === 'prompts/list') {
                    return self::result($id, ['prompts' => $prompts(null) ?? []]);
                }
                $prompt = is_string($params['name'] ?? null) ? $prompts($params['name']) : null;
                return $prompt === null
                    ? self::error($id, -32602, 'Unknown prompt: ' . (is_string($params['name'] ?? null) ? $params['name'] : '?'))
                    : self::result($id, $prompt);
            case 'tools/list':
                return self::result($id, ['tools' => $listTools($apps)]);
            case 'resources/list':
                if ($read === null) {
                    return self::error($id, -32601, 'Method not found: resources/list');
                }
                // Die Seite eines Diagramms finden Clients über das Werkzeug; die Spezifikation erlaubt, sie hier wegzulassen.
                return self::result($id, ['resources' => []]);
            case 'resources/read':
                if ($read === null) {
                    return self::error($id, -32601, 'Method not found: resources/read');
                }
                $uri = is_string($params['uri'] ?? null) ? $params['uri'] : '';
                $html = $read === null || $uri === '' ? null : $read($uri);
                if ($html === null) {
                    return self::error($id, -32602, 'Resource not found: ' . $uri);
                }
                return self::result($id, ['contents' => [['uri' => $uri, 'mimeType' => self::APP_MIME_TYPE, 'text' => $html]]]);
            case 'tools/call':
                $name = $params['name'] ?? null;
                $arguments = $params['arguments'] ?? [];
                $tools = $listTools($apps);
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
                // structuredContent sieht nur die Seite eines Diagramms, das Modell liest den Text
                return self::result($id, ['content' => [['type' => 'text', 'text' => $outcome['text']]], 'isError' => $outcome['isError']]
                    + (isset($outcome['structuredContent']) ? ['structuredContent' => $outcome['structuredContent']] : []));
            default:
                return self::error($id, -32601, 'Method not found: ' . $message['method']);
        }
    }

    /** Trägt die Nachricht (oder eine Nachricht des Stapels) eine Protokollversion in params._meta? */
    private static function isModern(mixed $message): bool
    {
        $messages = is_array($message) && array_is_list($message) ? $message : [$message];
        foreach ($messages as $single) {
            if (is_array($single) && is_array($single['params'] ?? null) && is_array($single['params']['_meta'] ?? null)
                && array_key_exists(self::META_VERSION, $single['params']['_meta'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Eine Anfrage nach 2026-07-28: genau eine Nachricht, Kopfzeilen passend zum Körper, Version unterstützt.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, methods: list<string>}
     */
    private static function handleModern(array $message, array $headers, callable $listTools, callable $callTool, array $info, ?callable $readResource = null, ?callable $prompts = null): array
    {
        if (array_is_list($message)) {
            return self::response(400, [], self::error(null, -32600, 'Invalid Request: protocol version 2026-07-28 takes one message per request')) + ['methods' => ['(batch)']];
        }
        $id = $message['id'] ?? null;
        $rpc = is_string($message['method'] ?? null) ? $message['method'] : '';
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $version = $params['_meta'][self::META_VERSION];
        $methods = [$rpc === '' ? '?' : $rpc];
        if (($message['jsonrpc'] ?? null) !== '2.0' || $rpc === '') {
            return self::response(400, [], self::error($id, -32600, 'Invalid Request')) + ['methods' => $methods];
        }
        // Kopfzeilen und Körper müssen übereinstimmen (Server Validation, HeaderMismatch -32020)
        $mismatch = match (true) {
            !isset($headers['mcp-protocol-version'])                 => 'MCP-Protocol-Version header is missing',
            $headers['mcp-protocol-version'] !== $version            => 'MCP-Protocol-Version header does not match _meta',
            !isset($headers['mcp-method'])                           => 'Mcp-Method header is missing',
            $headers['mcp-method'] !== $rpc                          => 'Mcp-Method header does not match the method',
            in_array($rpc, ['tools/call', 'prompts/get', 'resources/read'], true) && !isset($headers['mcp-name'])                    => 'Mcp-Name header is missing',
            in_array($rpc, ['tools/call', 'prompts/get'], true) && self::headerValue($headers['mcp-name']) !== ($params['name'] ?? null) => 'Mcp-Name header does not match params.name',
            $rpc === 'resources/read' && self::headerValue($headers['mcp-name']) !== ($params['uri'] ?? null)                        => 'Mcp-Name header does not match params.uri',
            default                                                  => null,
        };
        if ($mismatch !== null) {
            return self::response(400, [], self::error($id, -32020, 'Header mismatch: ' . $mismatch)) + ['methods' => $methods];
        }
        if (!in_array($version, self::MODERN_VERSIONS, true)) {
            return self::response(400, [], self::error($id, -32022, 'Unsupported protocol version', ['supported' => array_merge(self::MODERN_VERSIONS, self::VERSIONS), 'requested' => is_scalar($version) ? (string)$version : '?'])) + ['methods' => $methods];
        }
        if (!array_key_exists('id', $message)) {
            return ['status' => 202, 'headers' => ['Cache-Control' => 'no-store'], 'body' => '', 'methods' => $methods];
        }
        $serverInfo = [self::META_SERVER => self::serverInfo($info)];
        $context = ['ui' => self::showsApps($params), 'read' => $readResource, 'prompts' => $prompts];
        switch ($rpc) {
            case 'server/discover':
                return self::response(200, [], self::result($id, self::discoverResult($info, $readResource !== null, $prompts !== null))) + ['methods' => $methods];
            case 'prompts/list':
            case 'prompts/get':
                if ($prompts === null) {
                    return self::response(404, [], self::error($id, -32601, 'Method not found: ' . $rpc)) + ['methods' => $methods];
                }
                $reply = self::handleMessage($message, $listTools, $callTool, $info, $context);
                if (isset($reply['result'])) {
                    $reply['result'] = ['resultType' => 'complete'] + $reply['result'] + ($rpc === 'prompts/list' ? ['ttlMs' => self::LIST_TTL_MS, 'cacheScope' => 'private'] : []) + ['_meta' => $serverInfo];
                }
                return self::response(200, [], $reply) + ['methods' => $methods];
            case 'tools/list':
                return self::response(200, [], self::result($id, ['resultType' => 'complete', 'tools' => $listTools($context['ui']), 'ttlMs' => self::LIST_TTL_MS, 'cacheScope' => 'private', '_meta' => $serverInfo])) + ['methods' => $methods];
            case 'resources/list':
            case 'resources/read':
                if ($readResource === null) {
                    return self::response(404, [], self::error($id, -32601, 'Method not found: ' . $rpc)) + ['methods' => $methods];
                }
                $reply = self::handleMessage($message, $listTools, $callTool, $info, $context);
                if (isset($reply['result'])) {
                    $reply['result'] = ['resultType' => 'complete'] + $reply['result'] + ['_meta' => $serverInfo];
                }
                return self::response(200, [], $reply) + ['methods' => $methods];
            case 'tools/call':
                $reply = self::handleMessage($message, $listTools, $callTool, $info, $context);
                if (isset($reply['result'])) {
                    $reply['result'] = ['resultType' => 'complete'] + $reply['result'] + ['_meta' => $serverInfo];
                }
                return self::response(200, [], $reply) + ['methods' => $methods];
            default:
                // Unbekannt, auch initialize und ping, die es in 2026-07-28 nicht mehr gibt
                return self::response(404, [], self::error($id, -32601, 'Method not found: ' . $rpc)) + ['methods' => $methods];
        }
    }

    /** Kopfzeile in der Base64-Form =?base64?…?= dekodiert, sonst unverändert. */
    private static function headerValue(string $value): string
    {
        if (preg_match('/^=\?base64\?(.*)\?=$/', $value, $parts) === 1) {
            $decoded = base64_decode($parts[1], true);
            return $decoded === false ? '' : $decoded;
        }
        return $value;
    }

    /** @return array{name: string, title: string, version: string} */
    private static function serverInfo(array $info): array
    {
        return ['name' => $info['name'], 'title' => $info['title'], 'version' => $info['version']];
    }

    /** Antwort auf server/discover: Versionen, Fähigkeiten, Serverinfo, Anleitung, Zwischenspeichern. */
    /**
     * Zeigt der Client MCP Apps? Steht nach 2026-07-28 in jeder Anfrage (clientCapabilities.extensions); gemessen am nuc
     * meldet die Claude-App (iOS) „io.modelcontextprotocol/ui“ an, Claude Code und Home Assistant nicht (12.10.2026).
     */
    private static function showsApps(array $params): bool
    {
        $mimeTypes = $params['_meta']['io.modelcontextprotocol/clientCapabilities']['extensions']['io.modelcontextprotocol/ui']['mimeTypes'] ?? [];
        return is_array($mimeTypes) && in_array(self::APP_MIME_TYPE, $mimeTypes, true);
    }

    /** Fähigkeiten des Servers: Werkzeuge immer, Resources (Seiten der Apps) und Prompts (Vorlagen), wenn es sie gibt. */
    private static function capabilities(bool $resources, bool $prompts): array
    {
        return ['tools' => ['listChanged' => false]]
            + ($resources ? ['resources' => ['listChanged' => false]] : [])
            + ($prompts ? ['prompts' => ['listChanged' => false]] : []);
    }

    private static function discoverResult(array $info, bool $resources = false, bool $prompts = false): array
    {
        return [
            'resultType'        => 'complete',
            'supportedVersions' => array_merge(self::MODERN_VERSIONS, self::VERSIONS),
            'capabilities'      => self::capabilities($resources, $prompts),
            '_meta'             => [self::META_SERVER => self::serverInfo($info)],
            'instructions'      => $info['instructions'],
            'ttlMs'             => self::LIST_TTL_MS,
            'cacheScope'        => 'private',
        ];
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

    private static function error(mixed $id, int $code, string $text, ?array $data = null): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $text] + ($data === null ? [] : ['data' => $data])];
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
