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
    global $mitApps, $strukturiert;
    $parameter = ['POST', $angemeldet, $body, static function (bool $apps = false) use (&$mitApps, $werkzeuge): array {
        $mitApps = $apps;
        return $werkzeuge;
    }, static function (string $name, array $argumente, ?string $client = null) use (&$aufrufe, &$strukturiert): array {
        $aufrufe[] = [$name, $argumente];
        $GLOBALS['aufrufer'] = $client;
        return ['text' => '1 device(s) found.', 'isError' => false] + ($strukturiert === null ? [] : ['structuredContent' => $strukturiert]);
    }, $info];
    $seite = static fn (string $uri): ?string => $uri === 'ui://homeagent/chart' ? '<!DOCTYPE html><html><body>Diagramm</body></html>' : null;
    try {
        $anzahl = (new ReflectionMethod(McpProtocol::class, 'handleHttp'))->getNumberOfParameters();
        $vorlagen = static fn (?string $name): ?array => $name === null
            ? [['name' => 'leave_home', 'title' => 'Leaving home', 'description' => 'Checks what is still open or on.']]
            : ($name === 'leave_home' ? ['description' => 'Checks what is still open or on.', 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'I am leaving the house.']]]] : null);
        $antwort = match (true) {
            $anzahl > 8 => McpProtocol::handleHttp(...array_merge($parameter, [$kopf, $seite, $vorlagen])),
            $anzahl > 7 => McpProtocol::handleHttp(...array_merge($parameter, [$kopf, $seite])),
            $anzahl > 6 => McpProtocol::handleHttp(...array_merge($parameter, [$kopf])),
            default     => McpProtocol::handleHttp(...$parameter),
        };
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

/* --- Client fürs Protokoll (B63: was kann welcher Client?) --------------------------------------- */

pruefe((sende($discover['koerper'], $discover['header'])['client'] ?? null) === 'claude-code 2.1.296 (roots, elicitation: form, url)', 'server/discover nennt Client und Fähigkeiten aus _meta: ' . json_encode(sende($discover['koerper'], $discover['header'])['client'] ?? null));
$init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'mcp', 'version' => '0.1.0'], 'capabilities' => ['sampling' => (object)[], 'extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]]]]];
pruefe((sende($init, [])['client'] ?? null) === 'mcp 0.1.0 (sampling, extension io.modelcontextprotocol/ui)', 'initialize nennt Client, Fähigkeiten und Erweiterungen: ' . json_encode(sende($init, [])['client'] ?? null));
pruefe(!isset(sende($liste['koerper'], $liste['header'])['client']), 'bei anderen Anfragen kein Client, sonst stünde er in jeder Zeile');

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
// Der Client steht in jeder Anfrage; das Werkzeug bekommt Name und Version fürs Protokoll (Burkhard 12.10.2026: ein Chat
// lief über „claude-code“ statt über die Claude-App, zu sehen war das nur am server/discover Sekunden vorher).
pruefe(($GLOBALS['aufrufer'] ?? null) === 'claude-code 2.1.296', 'der Werkzeugaufruf kennt den Client: ' . json_encode($GLOBALS['aufrufer'] ?? null));
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

/* --- MCP Apps: Diagramm (Backlog B63) ---------------------------------------------------------- */

// Gemessen am nuc (Protokoll 12.10.2026): Die Claude-App meldet „extension io.modelcontextprotocol/ui“ an. Nur dann
// bietet das Modul show_chart an, und nur dann holt der Client die Seite des Diagramms.
$apps = $meta;
$apps['io.modelcontextprotocol/clientCapabilities'] = ['extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]]];
$listeApps = ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => ['_meta' => $apps]];
$mitApps = null;
sende($listeApps, $liste['header']);
pruefe($mitApps === true, 'Client mit Apps: die Werkzeugliste weiß es: ' . var_export($mitApps, true));
$mitApps = null;
sende($liste['koerper'], $liste['header']);
pruefe($mitApps === false, 'Claude Code ohne Apps: nein: ' . var_export($mitApps, true));
$mitApps = null;
sende(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'], ['mcp-protocol-version' => '2025-06-18']);
pruefe($mitApps === false, 'ältere Clients ohne Sitzung: nein');
$a = sende($discover['koerper'], $discover['header']);
pruefe(isset($a['json']['result']['capabilities']['resources']), 'server/discover nennt Resources: ' . json_encode($a['json']['result']['capabilities'] ?? null));
$lesen = ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'resources/read', 'params' => ['uri' => 'ui://homeagent/chart', '_meta' => $apps]];
$a = sende($lesen, ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/read', 'mcp-name' => 'ui://homeagent/chart']);
$inhalt = $a['json']['result']['contents'][0] ?? [];
pruefe($a['status'] === 200 && ($inhalt['uri'] ?? null) === 'ui://homeagent/chart' && ($inhalt['mimeType'] ?? null) === 'text/html;profile=mcp-app' && str_contains((string)($inhalt['text'] ?? ''), 'Diagramm'), 'resources/read liefert die Seite: ' . $a['status'] . ' ' . substr($a['body'], 0, 200));
pruefe(($a['json']['result']['resultType'] ?? null) === 'complete' && ($a['json']['result']['ttlMs'] ?? null) === 300000 && ($a['json']['result']['cacheScope'] ?? null) === 'private', 'mit resultType, ttlMs und cacheScope (2026-07-28 verlangt sie bei resources/read; ohne meldete claude.ai „kann nicht erreicht werden“): ' . json_encode(array_diff_key($a['json']['result'] ?? [], ['contents' => 0])));
$a = sende(['jsonrpc' => '2.0', 'id' => 15, 'method' => 'resources/list', 'params' => ['_meta' => $apps]], ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/list']);
pruefe(($a['json']['result']['ttlMs'] ?? null) === 300000 && ($a['json']['result']['cacheScope'] ?? null) === 'private', 'resources/list ebenso: ' . $a['body']);
$falschUri = $lesen;
$falschUri['params']['uri'] = 'ui://gibt-es-nicht';
$a = sende($falschUri, ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/read', 'mcp-name' => 'ui://homeagent/chart']);
pruefe($a['status'] === 400 && ($a['json']['error']['code'] ?? null) === -32020, 'Mcp-Name muss auch bei resources/read zur URI passen: ' . $a['body']);
$falsch = $lesen;
$falsch['params']['uri'] = 'ui://gibt-es-nicht';
$a = sende($falsch, ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/read', 'mcp-name' => 'ui://gibt-es-nicht']);
pruefe(($a['json']['error']['code'] ?? null) === -32602, 'unbekannte Ressource: -32602 (2026-07-28): ' . $a['body']);
$a = sende(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/read', 'params' => ['uri' => 'ui://homeagent/chart']], []);
pruefe(($a['json']['result']['contents'][0]['mimeType'] ?? null) === 'text/html;profile=mcp-app', 'resources/read auch für ältere Clients: ' . substr($a['body'], 0, 120));
$strukturiert = ['kind' => 'history', 'series' => []];
$a = sende($aufruf, $kopf);
pruefe(($a['json']['result']['structuredContent'] ?? null) === ['kind' => 'history', 'series' => []] && ($a['json']['result']['content'][0]['text'] ?? null) === '1 device(s) found.', 'structuredContent geht mit, der Text für das Modell auch: ' . $a['body']);
$strukturiert = null;

// claude.ai holt die Werkzeugliste für den Chat über „Anthropic/Toolbox 1.0.0“ ohne Apps-Fähigkeit, gezeichnet wird
// aber im Chat, der sich als „Anthropic/ClaudeAI 1.0.0 (extension io.modelcontextprotocol/ui)“ meldet (Protokoll am
// nuc, Neueinrichtung des Connectors 12.10.2026 16:43; in claude.ai stand danach nur „Get state“). Clients von
// Anthropic bekommen show_chart deshalb immer.
$toolbox = $meta;
$toolbox['io.modelcontextprotocol/clientInfo'] = ['name' => 'Anthropic/Toolbox', 'version' => '1.0.0'];
$toolbox['io.modelcontextprotocol/clientCapabilities'] = [];
$mitApps = null;
sende(['jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/list', 'params' => ['_meta' => $toolbox]], $liste['header']);
pruefe($mitApps === true, 'Anthropic/Toolbox ohne angemeldete Apps: trotzdem mit Diagramm: ' . var_export($mitApps, true));
$fremd = $toolbox;
$fremd['io.modelcontextprotocol/clientInfo'] = ['name' => 'codex', 'version' => '1.0.0'];
$mitApps = null;
sende(['jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/list', 'params' => ['_meta' => $fremd]], $liste['header']);
pruefe($mitApps === false, 'andere Clients ohne Apps: weiter ohne: ' . var_export($mitApps, true));

/* --- Vorlagen (Prompts, Backlog B63) ------------------------------------------------------------ */

$a = sende($discover['koerper'], $discover['header']);
pruefe(isset($a['json']['result']['capabilities']['prompts']), 'server/discover nennt Prompts: ' . json_encode($a['json']['result']['capabilities'] ?? null));
$a = sende(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'prompts/list', 'params' => ['_meta' => $meta]], ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'prompts/list']);
$r = $a['json']['result'] ?? [];
pruefe($a['status'] === 200 && ($r['prompts'][0]['name'] ?? null) === 'leave_home' && ($r['resultType'] ?? null) === 'complete' && ($r['ttlMs'] ?? null) === 300000 && ($r['cacheScope'] ?? null) === 'private', 'prompts/list mit resultType und Zwischenspeichern: ' . $a['body']);
$holen = ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'prompts/get', 'params' => ['name' => 'leave_home', '_meta' => $meta]];
$a = sende($holen, ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'prompts/get', 'mcp-name' => 'leave_home']);
$r = $a['json']['result'] ?? [];
pruefe($a['status'] === 200 && ($r['messages'][0]['content']['text'] ?? null) === 'I am leaving the house.' && ($r['resultType'] ?? null) === 'complete', 'prompts/get liefert die Nachricht: ' . $a['body']);
$unbekannt = $holen;
$unbekannt['params']['name'] = 'gibt_es_nicht';
$a = sende($unbekannt, ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'prompts/get', 'mcp-name' => 'gibt_es_nicht']);
pruefe(($a['json']['error']['code'] ?? null) === -32602, 'unbekannte Vorlage: -32602: ' . $a['body']);
$a = sende(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'prompts/list'], []);
pruefe(($a['json']['result']['prompts'][0]['title'] ?? null) === 'Leaving home' && !isset($a['json']['result']['ttlMs']), 'prompts/list auch für ältere Clients: ' . $a['body']);
$a = sende(['jsonrpc' => '2.0', 'id' => 12, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], []);
pruefe(isset($a['json']['result']['capabilities']['prompts']), 'initialize nennt Prompts');

ende('Protokoll 2026-07-28');
