<?php

declare(strict_types=1);

/*
 * MCP-Spezifikation 2026-07-28 neben den älteren Versionen (Backlog B62, Burkhard 12.10.2026): ohne initialize,
 * jede Anfrage trägt Version, Client und Fähigkeiten in _meta und als Kopfzeilen. Claude Code fragt zuerst
 * server/discover; beantwortet der Server das, folgt nur noch tools/list (2 statt 5 Anfragen beim Verbinden,
 * Leitbild „schnell vor günstig“). Ältere Clients (initialize) bleiben unverändert.
 * Mitschnitt claude_code_2026-10-12_mcp_2026-07-28.json (server/discover und tools/list von Claude Code 2.1.296).
 * Angenommen nach dem Beispiel der Spezifikation: tools/call mit Mcp-Name, die Fehlerfälle.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/McpProtocol.php';

$mitschnitt = fixture('claude_code_2026-10-12_mcp_2026-07-28.json')['anfragen'];
$info = ['name' => 'Symcon Home Agent', 'title' => 'Home Agent', 'version' => '0.3', 'instructions' => 'Anleitung'];
$aufrufe = [];
$werkzeuge = [['name' => 'get_state', 'inputSchema' => ['type' => 'object', 'properties' => ['room' => ['type' => 'string']]]]];

/** Eine Anfrage wie im Mitschnitt: Körper und Kopfzeilen (Namen klein). Vor dem Umbau kennt handleHttp keine Kopfzeilen. */
function sende(array|string $koerper, array $kopf, bool $angemeldet = true): array
{
    global $info, $aufrufe, $werkzeuge;
    $body = is_string($koerper) ? $koerper : (string)json_encode($koerper, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $parameter = ['POST', $angemeldet, $body, static fn (): array => $werkzeuge, static function (string $name, array $argumente) use (&$aufrufe): array {
        $aufrufe[] = [$name, $argumente];
        return ['text' => '1 device(s) found.', 'isError' => false];
    }, $info];
    try {
        $antwort = (new ReflectionMethod(McpProtocol::class, 'handleHttp'))->getNumberOfParameters() > 6
            ? McpProtocol::handleHttp(...array_merge($parameter, [$kopf]))
            : McpProtocol::handleHttp(...$parameter);
    } catch (Throwable $e) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'json' => ['fehler' => $e->getMessage()]];
    }
    return $antwort + ['json' => json_decode($antwort['body'], true) ?? []];
}

[$discover, $liste] = $mitschnitt;
$meta = $discover['koerper']['params']['_meta'];

/* --- server/discover ------------------------------------------------------------------------- */

$a = sende($discover['koerper'], $discover['header']);
$r = $a['json']['result'] ?? [];
pruefe($a['status'] === 200 && ($a['json']['id'] ?? null) === 'server-discover-probe-1', 'server/discover wird beantwortet: ' . $a['status'] . ' ' . $a['body']);
pruefe(($r['resultType'] ?? null) === 'complete', 'resultType complete');
pruefe(($r['supportedVersions'] ?? null) === ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], 'unterstützte Versionen, neueste zuerst: ' . json_encode($r['supportedVersions'] ?? null));
pruefe(isset($r['capabilities']['tools']) && ($r['_meta']['io.modelcontextprotocol/serverInfo'] ?? null) === ['name' => 'Symcon Home Agent', 'title' => 'Home Agent', 'version' => '0.3'], 'Fähigkeiten und Serverinfo: ' . json_encode($r));
pruefe(($r['instructions'] ?? null) === 'Anleitung', 'die Anleitung des Zugangs steht dabei');
pruefe(($r['ttlMs'] ?? null) === 300000 && ($r['cacheScope'] ?? null) === 'private', 'darf 5 Minuten zwischengespeichert werden, nur für diesen Client: ' . json_encode([$r['ttlMs'] ?? null, $r['cacheScope'] ?? null]));

/* --- tools/list ohne initialize ------------------------------------------------------------- */

$a = sende($liste['koerper'], $liste['header']);
$r = $a['json']['result'] ?? [];
pruefe($a['status'] === 200 && ($a['json']['id'] ?? null) === 0 && ($r['tools'][0]['name'] ?? null) === 'get_state', 'tools/list wie im Mitschnitt: ' . $a['body']);
pruefe(($r['resultType'] ?? null) === 'complete' && ($r['ttlMs'] ?? null) === 300000 && ($r['cacheScope'] ?? null) === 'private', 'mit resultType, ttlMs und cacheScope: ' . json_encode($r));
pruefe(($r['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? null) === 'Symcon Home Agent', 'Serverinfo in jedem Ergebnis');

/* --- tools/call ---------------------------------------------------------------------------------- */

$aufruf = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'get_state', 'arguments' => ['room' => 'Küche'], '_meta' => $meta]];
$kopf = ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => 'get_state'];
$aufrufe = [];
$a = sende($aufruf, $kopf);
$r = $a['json']['result'] ?? [];
pruefe($a['status'] === 200 && $aufrufe === [['get_state', ['room' => 'Küche']]] && ($r['content'][0]['text'] ?? null) === '1 device(s) found.' && ($r['isError'] ?? null) === false, 'Werkzeugaufruf: ' . $a['body']);
pruefe(($r['resultType'] ?? null) === 'complete' && !isset($r['ttlMs']), 'resultType, aber kein Zwischenspeichern beim Aufruf');
$a = sende($aufruf, ['mcp-name' => '=?base64?' . base64_encode('get_state') . '?='] + $kopf);
pruefe($a['status'] === 200 && isset($a['json']['result']), 'Mcp-Name in der Base64-Form wird dekodiert: ' . $a['body']);

/* --- Prüfung der Kopfzeilen (400, HeaderMismatch -32020) ---------------------------------------- */

foreach ([
    'ohne MCP-Protocol-Version'      => array_diff_key($kopf, ['mcp-protocol-version' => 0]),
    'ohne Mcp-Method'                => array_diff_key($kopf, ['mcp-method' => 0]),
    'ohne Mcp-Name bei tools/call'   => array_diff_key($kopf, ['mcp-name' => 0]),
    'Mcp-Method passt nicht'         => ['mcp-method' => 'tools/list'] + $kopf,
    'Mcp-Name passt nicht'           => ['mcp-name' => 'set_state'] + $kopf,
    'Version passt nicht zu _meta'   => ['mcp-protocol-version' => '2025-11-25'] + $kopf,
] as $fall => $falsch) {
    $aufrufe = [];
    $a = sende($aufruf, $falsch);
    pruefe($a['status'] === 400 && ($a['json']['error']['code'] ?? null) === -32020 && ($a['json']['id'] ?? null) === 7 && $aufrufe === [], $fall . ': ' . $a['status'] . ' ' . $a['body']);
}

/* --- Version, Methode, Stapel ------------------------------------------------------------------ */

$alt = $aufruf;
$alt['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '1900-01-01';
$a = sende($alt, ['mcp-protocol-version' => '1900-01-01'] + $kopf);
pruefe($a['status'] === 400 && ($a['json']['error']['code'] ?? null) === -32022 && ($a['json']['error']['data'] ?? null) === ['supported' => ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], 'requested' => '1900-01-01'], 'unbekannte Version: UnsupportedProtocolVersion mit der Liste: ' . $a['body']);
$ping = ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'ping', 'params' => ['_meta' => $meta]];
$a = sende($ping, ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'ping']);
pruefe($a['status'] === 404 && ($a['json']['error']['code'] ?? null) === -32601, 'ping gibt es in 2026-07-28 nicht mehr, unbekannte Methode 404: ' . $a['status'] . ' ' . $a['body']);
$a = sende([$liste['koerper'], $liste['koerper']], $liste['header']);
pruefe($a['status'] === 400 && ($a['json']['error']['code'] ?? null) === -32600, 'Stapel gibt es mit _meta nicht: ' . $a['status'] . ' ' . $a['body']);
pruefe(sende($discover['koerper'], $discover['header'], false)['status'] === 401, 'ohne Anmeldung weiter 401');

/* --- Ältere Clients bleiben, wie sie sind ------------------------------------------------------- */

$a = sende(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], ['mcp-protocol-version' => '2025-06-18']);
pruefe($a['status'] === 200 && ($a['json']['result']['protocolVersion'] ?? null) === '2025-06-18' && !isset($a['json']['result']['resultType']), 'initialize wie bisher, ohne die Felder der neuen Version: ' . $a['body']);
$a = sende(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['mcp-protocol-version' => '2025-06-18']);
pruefe($a['status'] === 200 && !isset($a['json']['result']['ttlMs']) && ($a['json']['result']['tools'][0]['name'] ?? null) === 'get_state', 'tools/list ohne _meta wie bisher: ' . $a['body']);
$a = sende(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], []);
pruefe($a['status'] === 200 && isset($a['json']['result']), 'ping ohne _meta wie bisher');

ende('Protokoll 2026-07-28');
