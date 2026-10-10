<?php

declare(strict_types=1);

/*
 * MCP über Streamable HTTP, zustandslos: Anmeldung, Methoden, JSON-RPC-Fehlercodes,
 * Benachrichtigungen und Stapel. Die Nachrichten entsprechen dem, was ein Client beim
 * Verbinden schickt (initialize → notifications/initialized → tools/list → tools/call).
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/McpProtocol.php';

const TOKEN = 'geheim-123';
$info = ['name' => 'test', 'title' => 'Test', 'version' => '0.1.1', 'instructions' => 'Anleitung'];
$aufrufe = [];

/** Ein Zugang mit diesem Token, der es auch im Pfad annimmt; das Modul gibt handleHttp nur noch das Ergebnis der Zugangswahl. */
function http(string $methode, string $auth, string $body, string $token = TOKEN, string $pfadToken = ''): array
{
    global $info, $aufrufe;
    return McpProtocol::handleHttp(
        $methode,
        McpProtocol::findAccess($auth, $pfadToken, [['Token' => $token, 'InPath' => true]]) !== null,
        $body,
        static fn (): array => [['name' => 'get_state', 'inputSchema' => ['type' => 'object']]],
        static function (string $name, array $argumente) use (&$aufrufe): array {
            $aufrufe[] = [$name, $argumente];
            return ['text' => 'ok ' . json_encode($argumente), 'isError' => false];
        },
        $info
    );
}

function rpc(string $methode, array $params = [], int $id = 1): string
{
    return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $methode, 'params' => (object)$params]);
}

$auth = 'Bearer ' . TOKEN;

// Anmeldung
$a = http('POST', '', rpc('ping'));
pruefe($a['status'] === 401 && $a['headers']['WWW-Authenticate'] === 'Bearer', 'ohne Token 401 mit WWW-Authenticate');
$a = http('POST', 'Bearer falsch', rpc('ping'));
pruefe($a['status'] === 401, 'falsches Token 401');
$a = http('POST', 'Bearer ', rpc('ping'), '');
pruefe($a['status'] === 401, 'ohne konfiguriertes Token kommt niemand herein, auch nicht mit leerem Bearer');
$a = http('POST', ' ' . $auth . ' ', rpc('ping'));
pruefe($a['status'] === 200, 'Leerzeichen um den Header stören nicht');

// Token im Pfad (/hook/homeagent/<InstanzID>/<Token>) für Clients, die keinen Header mitschicken.
// So liefert die Testbox die Adresse im Hook (06.10.2026, 9.1 Rust): REQUEST_URI schon dekodiert und
// ohne Query-String, der Hook-Pfad steht daneben.
$hook = '/hook/homeagent/44715';
pruefe(McpProtocol::tokenFromPath($hook . '/' . TOKEN, $hook) === TOKEN, 'Token am Ende des Pfads');
pruefe(McpProtocol::tokenFromPath($hook . '/' . TOKEN . '/', $hook) === TOKEN, 'Schrägstrich am Ende stört nicht');
pruefe(McpProtocol::tokenFromPath($hook . '/' . TOKEN . '?x=1&y=2', $hook) === TOKEN, 'Query-String gehört nicht zum Token');
pruefe(McpProtocol::tokenFromPath($hook . '/ab cd+ef', $hook) === 'ab cd+ef', 'dekodiert geliefert (wie an der Testbox): bleibt, wie es ist');
pruefe(McpProtocol::tokenFromPath($hook, $hook) === '' && McpProtocol::tokenFromPath($hook . '/', $hook) === '', 'ohne Unterpfad kein Token');
pruefe(McpProtocol::tokenFromPath('/hook/homeagent/447150/' . TOKEN, $hook) === '', 'fremder Hook mit gleichem Anfang zählt nicht');
pruefe(McpProtocol::tokenFromPath('/hook/anderes/' . TOKEN, $hook) === '' && McpProtocol::tokenFromPath('', $hook) === '', 'fremder oder leerer Pfad: kein Token');
pruefe(McpProtocol::tokenFromPath($hook . '/a/b', $hook) === 'a/b', 'ein Token mit Schrägstrich bleibt ganz');

$a = http('POST', '', rpc('ping'), TOKEN, TOKEN);
pruefe($a['status'] === 200, 'richtiges Token im Pfad, kein Header: 200');
$a = http('POST', '', rpc('ping'), TOKEN, 'falsch');
pruefe($a['status'] === 401, 'falsches Token im Pfad: 401');
$a = http('POST', '', rpc('ping'), 'ab cd', 'ab%20cd');
pruefe($a['status'] === 200, 'ein Server, der den Pfad kodiert liefert, kommt auch an');
$a = http('POST', '', rpc('ping'), '', '');
pruefe($a['status'] === 401, 'ohne konfiguriertes Token hilft auch ein leerer Pfad nicht');
$a = http('POST', 'Bearer falsch', rpc('ping'), TOKEN, TOKEN);
pruefe($a['status'] === 401, 'der Header hat Vorrang: falscher Header, richtiger Pfad: 401');
$a = http('POST', $auth, rpc('ping'), TOKEN, 'falsch');
pruefe($a['status'] === 200, 'der Header hat Vorrang: richtiger Header, falscher Pfad: 200');
$a = http('GET', '', '', TOKEN, TOKEN);
pruefe($a['status'] === 405, 'GET mit Pfad-Token: 405, nicht 401');

// Mehrere Zugänge: Die Anmeldung bestimmt, welcher gilt (Index der Zugangsliste, null = keiner)
$zugaenge = [
    ['Name' => 'Küche', 'Token' => 'kueche-1', 'InPath' => false],
    ['Name' => 'Gast', 'Token' => 'gast-2', 'InPath' => true],
    ['Name' => 'Leer', 'Token' => '', 'InPath' => true],
    ['Name' => 'Doppelt', 'Token' => 'gast-2', 'InPath' => true],
];
pruefe(McpProtocol::findAccess('Bearer kueche-1', '', $zugaenge) === 0, 'Header trifft den ersten Zugang');
pruefe(McpProtocol::findAccess(' Bearer gast-2 ', '', $zugaenge) === 1, 'Header trifft den zweiten Zugang, bei gleichem Token den früheren');
pruefe(McpProtocol::findAccess('Bearer falsch', '', $zugaenge) === null && McpProtocol::findAccess('', '', $zugaenge) === null, 'falsches oder fehlendes Token: kein Zugang');
pruefe(McpProtocol::findAccess('', 'gast-2', $zugaenge) === 1, 'Token im Pfad bei einem Zugang, der das erlaubt');
pruefe(McpProtocol::findAccess('', 'kueche-1', $zugaenge) === null, 'Token im Pfad bei einem Zugang, der das nicht erlaubt: kein Zugang');
pruefe(McpProtocol::findAccess('Bearer falsch', 'gast-2', $zugaenge) === null, 'der Header hat Vorrang vor dem Pfad');
pruefe(McpProtocol::findAccess('Bearer kueche-1', 'gast-2', $zugaenge) === 0, 'mit Header zählt nur der Header');
pruefe(McpProtocol::findAccess('Bearer ', '', $zugaenge) === null && McpProtocol::findAccess('Bearer', '', $zugaenge) === null, 'ein Zugang ohne Token passt nie, auch nicht zu einem leeren Bearer');
pruefe(McpProtocol::findAccess('', 'gast%2D2', $zugaenge) === 1, 'kodiert gelieferter Pfad');
pruefe(McpProtocol::findAccess('Bearer kueche-1', '', []) === null, 'ohne Zugänge kommt niemand herein');
pruefe(McpProtocol::findAccess('Bearer gast-2', '', [3 => $zugaenge[1]]) === 3, 'zurück kommt der Schlüssel der Liste');

// Methoden
$a = http('GET', $auth, '');
pruefe($a['status'] === 405 && $a['headers']['Allow'] === 'POST', 'GET (Datenstrom) wird mit 405 abgewiesen');
$a = http('POST', $auth, '{kaputt');
pruefe($a['status'] === 400 && json_decode($a['body'], true)['error']['code'] === -32700, 'kaputtes JSON: 400 mit -32700');
pruefe($a['headers']['Content-Type'] === 'application/json', 'Antworten sind JSON, nicht text/html');

// initialize
$a = http('POST', $auth, rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']]));
$r = json_decode($a['body'], true)['result'];
pruefe($r['protocolVersion'] === '2025-06-18', 'gewünschte bekannte Version wird bestätigt');
pruefe($r['capabilities']['tools']['listChanged'] === false && $r['serverInfo']['version'] === '0.1.1', 'Fähigkeiten und Serverversion');
pruefe($r['instructions'] === 'Anleitung', 'Anleitung für den Assistenten wird mitgeliefert');
$a = http('POST', $auth, rpc('initialize', ['protocolVersion' => '2099-01-01']));
pruefe(json_decode($a['body'], true)['result']['protocolVersion'] === McpProtocol::VERSIONS[0], 'unbekannte Version: neueste eigene');

// Benachrichtigung allein: 202 ohne Inhalt
$a = http('POST', $auth, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
pruefe($a['status'] === 202 && $a['body'] === '', 'notifications/initialized: 202 ohne Inhalt');

// tools/list und tools/call
$a = http('POST', $auth, rpc('tools/list'));
pruefe(json_decode($a['body'], true)['result']['tools'][0]['name'] === 'get_state', 'tools/list liefert das Werkzeug');
$a = http('POST', $auth, rpc('tools/call', ['name' => 'get_state', 'arguments' => ['type' => 'light']], 7));
$r = json_decode($a['body'], true);
pruefe($r['id'] === 7 && $r['result']['content'][0]['type'] === 'text', 'tools/call: Text unter derselben id');
pruefe($r['result']['isError'] === false && $aufrufe === [['get_state', ['type' => 'light']]], 'Argumente kommen unverändert an');
$a = http('POST', $auth, rpc('tools/call', ['name' => 'switch_on', 'arguments' => []]));
$r = json_decode($a['body'], true);
pruefe($r['error']['code'] === -32602 && str_contains($r['error']['message'], 'Available: get_state'), 'unbekanntes Werkzeug: -32602 mit Liste');
$a = http('POST', $auth, rpc('resources/list'));
pruefe(json_decode($a['body'], true)['error']['code'] === -32601, 'unbekannte Methode: -32601');
$a = http('POST', $auth, json_encode(['id' => 3, 'method' => 'ping']));
pruefe(json_decode($a['body'], true)['error']['code'] === -32600, 'ohne jsonrpc 2.0: -32600');

// Stapel: Antworten in Reihenfolge, Benachrichtigungen ohne Antwort
$a = http('POST', $auth, json_encode([
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
    ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
    ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
]));
$r = json_decode($a['body'], true);
pruefe($a['status'] === 200 && array_column($r, 'id') === [1, 2], 'Stapel: zwei Antworten, Benachrichtigung ausgelassen');

// Ungültiges UTF-8 aus der Anlage (Anzeigetext, Gerätename, Warnung eines Fremdmoduls in CP850)
// darf die Antwort nicht verhindern (Code- und Sicherheitsreview 06.10.2026). Vorher warf
// json_encode eine JsonException, und der Hook endete mit einem Fatal Error ohne Antwort.
try {
    $a = McpProtocol::handleHttp('POST', true, rpc('tools/call', ['name' => 'get_state', 'arguments' => []]), static fn (): array => [['name' => 'get_state', 'inputSchema' => ['type' => 'object']]], static fn (): array => ['text' => "K\x81che | Fenster | window | offen", 'isError' => false], $info);
    $r = json_decode($a['body'], true);
    $text = $r['result']['content'][0]['text'] ?? '';
    pruefe($a['status'] === 200 && $text === "K\u{FFFD}che | Fenster | window | offen", 'ungültiges Byte wird ersetzt, die Antwort kommt an: ' . json_encode($text));
} catch (Throwable $e) {
    pruefe(false, 'ungültiges UTF-8 bricht die Antwort ab: ' . get_class($e) . ' ' . $e->getMessage());
}

/* --- Anmeldung: Schreibweise und Leerzeichen (Code-Review 06.10.2026) ----------------------- */

// RFC 7235: Das Schema „Bearer“ ist unabhängig von Groß- und Kleinschreibung, danach Leerraum
$zugaenge = [['Token' => 'lang-token-0123456789abcdef', 'InPath' => false]];
foreach (['bearer lang-token-0123456789abcdef', 'BEARER  lang-token-0123456789abcdef', "Bearer\tlang-token-0123456789abcdef"] as $kopf) {
    pruefe(McpProtocol::findAccess($kopf, '', $zugaenge) === 0, 'Schema-Schreibweise wird angenommen: ' . json_encode($kopf));
}
foreach (['Bearerlang-token-0123456789abcdef', 'Basic lang-token-0123456789abcdef', 'Bearer lang-token-0123456789abcdeF', 'Bearer'] as $kopf) {
    pruefe(McpProtocol::findAccess($kopf, '', $zugaenge) === null, 'falsches Schema oder Token wird abgewiesen: ' . json_encode($kopf));
}
// Ein Token mit Leerzeichen am Rand (beim Einfügen mitkopiert) passt trotzdem; der Header wird ohnehin getrimmt
$mitRand = [['Token' => ' lang-token-0123456789abcdef ', 'InPath' => true]];
pruefe(McpProtocol::findAccess('Bearer lang-token-0123456789abcdef', '', $mitRand) === 0, 'Leerzeichen am Rand des gespeicherten Tokens stören nicht');
pruefe(McpProtocol::findAccess('', 'lang-token-0123456789abcdef', $mitRand) === 0, 'auch nicht beim Token im Pfad');
pruefe(McpProtocol::findAccess('Bearer  ', '', [['Token' => '   ', 'InPath' => false]]) === null, 'ein Token nur aus Leerzeichen passt nie');

// Antworten werden nicht zwischengespeichert und nicht als anderer Typ gedeutet
foreach ([http('POST', $auth, rpc('ping')), http('POST', 'Bearer falsch', rpc('ping')), http('POST', $auth, '{kaputt')] as $antwort) {
    pruefe(($antwort['headers']['Cache-Control'] ?? '') === 'no-store' && ($antwort['headers']['X-Content-Type-Options'] ?? '') === 'nosniff', 'Cache-Control und nosniff bei HTTP ' . $antwort['status'] . ': ' . json_encode($antwort['headers']));
}

/* --- Eingaben begrenzen (Sicherheitsreview 06.10.2026) ------------------------------------ */

/** handleHttp mit einem Werkzeug, dessen Schema Text, Zahl und Wahrheitswert kennt; zählt die Aufrufe. */
function mitSchema(string|Closure $body, bool $angemeldet = true): array
{
    global $info, $ausgefuehrt, $argumenteZuletzt;
    $ausgefuehrt = 0;
    $argumenteZuletzt = null;
    $schema = ['type' => 'object', 'properties' => ['room' => ['type' => 'string'], 'percent' => ['type' => 'number'], 'active_only' => ['type' => 'boolean']]];
    try {
        return McpProtocol::handleHttp('POST', $angemeldet, $body, static fn (): array => [['name' => 'set_state', 'inputSchema' => $schema]], static function (string $name, array $argumente) use (&$ausgefuehrt, &$argumenteZuletzt): array {
            $ausgefuehrt++;
            $argumenteZuletzt = $argumente;
            return ['text' => 'ok', 'isError' => false];
        }, $info);
    } catch (Throwable $e) {
        return ['status' => 0, 'headers' => [], 'body' => get_class($e) . ': ' . $e->getMessage()];
    }
}

function aufruf(array $argumente, int $id = 1): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => 'set_state', 'arguments' => $argumente]];
}

// Falscher Typ: vorher „Array to string conversion“ als Warnung ins Log und in die Antwort
foreach ([['room' => ['Küche']], ['room' => 5],['percent' => '50'], ['percent' => true], ['active_only' => 'ja']] as $falsch) {
    $a = mitSchema(json_encode(aufruf($falsch)));
    $r = json_decode($a['body'], true);
    pruefe(($r['error']['code'] ?? null) === -32602 && $ausgefuehrt === 0, 'falscher Typ wird mit -32602 abgewiesen, ohne Ausführung: ' . json_encode($falsch) . ' → ' . $a['body']);
}
$a = mitSchema(json_encode(aufruf(['room' => 'Küche', 'percent' => 50, 'active_only' => false])));
pruefe($a['status'] === 200 && !isset(json_decode($a['body'], true)['error']) && $ausgefuehrt === 1, 'richtige Typen gehen durch, ganze Zahl gilt als number: ' . $a['body']);
$a = mitSchema(json_encode(aufruf(['percent' => 12.5])));
pruefe($ausgefuehrt === 1, 'Kommazahl gilt als number');
// null heißt „nicht angegeben“: Manche Clients schicken unbenutzte Felder so mit
$a = mitSchema(json_encode(aufruf(['room' => null, 'percent' => 30])));
pruefe($ausgefuehrt === 1 && $argumenteZuletzt === ['percent' => 30], 'null-Felder entfallen, statt abgewiesen zu werden: ' . json_encode($argumenteZuletzt) . ' ' . $a['body']);

// Stapel: höchstens ein Werkzeugaufruf je HTTP-Anfrage, sonst hält ein Stapel den Hook minutenlang fest
$a = mitSchema(json_encode([aufruf(['room' => 'A'], 1), aufruf(['room' => 'B'], 2), aufruf(['room' => 'C'], 3)]));
$r = json_decode($a['body'], true);
pruefe($ausgefuehrt === 1, 'aus einem Stapel läuft höchstens ein Werkzeugaufruf: ' . $ausgefuehrt);
pruefe(is_array($r) && count($r) === 3 && array_column(array_column(array_slice($r, 1), 'error'), 'code') === [-32600, -32600], 'die übrigen bekommen je einen Fehler: ' . $a['body']);
$a = mitSchema(json_encode(array_map(static fn (int $i): array => ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'ping'], range(1, 51))));
pruefe($a['status'] === 400 && $ausgefuehrt === 0, 'ein Stapel mit mehr als 50 Nachrichten wird ganz abgewiesen: ' . $a['status']);
$a = mitSchema(json_encode([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], aufruf(['room' => 'A'], 2)]));
pruefe($ausgefuehrt === 1 && count(json_decode($a['body'], true)) === 2, 'tools/list plus ein Aufruf im Stapel bleibt erlaubt');

// Den Körper liest erst, wer angemeldet ist
$gelesen = false;
$a = mitSchema(static function () use (&$gelesen): string {
    $gelesen = true;
    return json_encode(aufruf([]));
}, false);
pruefe($a['status'] === 401 && $gelesen === false, 'ohne Anmeldung wird der Körper nicht gelesen: ' . $a['status'] . ' ' . $a['body']);
$a = mitSchema(static function () use (&$gelesen): string {
    $gelesen = true;
    return json_encode(aufruf(['room' => 'A']));
});
pruefe($a['status'] === 200 && $gelesen && $ausgefuehrt === 1, 'mit Anmeldung schon: ' . $a['body']);

ende('Protokoll');
