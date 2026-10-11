<?php

declare(strict_types=1);

/*
 * OAuth, Teil 1: reservierte Unterpfade unter dem Hook und die Metadaten, die Claude beim Verbinden
 * abfragt. Die Anfragen stammen aus dem Mitschnitt vom 11.10.2026 (Connector in claude.ai mit
 * „Jetzt anmelden“ und CIMD); Host und Instanz sind ersetzt.
 */

require __DIR__ . '/pruefen.php';
if (!is_file(dirname(__DIR__) . '/libs/OAuthServer.php')) {
    pruefe(false, 'libs/OAuthServer.php fehlt');
    ende('OAuth');
}
require_once dirname(__DIR__) . '/libs/OAuthServer.php';

$mitschnitt = fixture('oauth_claude_2026-10-11.json');
$endpunkt = $mitschnitt['endpoint'];
$hook = '/hook/homeagent/12345';

/* --- Reservierte Unterpfade ---------------------------------------------------------------------- */

foreach ($mitschnitt['requests'] as $anfrage) {
    $route = OAuthServer::route($hook . ($anfrage['route'] === '' ? '' : '/' . $anfrage['route']), $hook);
    $erwartet = $anfrage['route'] === '' ? null : $anfrage['route'];
    pruefe($route === $erwartet, 'Route aus dem Mitschnitt erkannt: ' . json_encode($anfrage['route']) . ' → ' . json_encode($route));
}
pruefe(OAuthServer::route($hook . '/oauth/authorize?response_type=code&state=abc', $hook) === 'oauth/authorize', 'der Query-String zählt nicht zum Pfad');
pruefe(OAuthServer::route($hook . '/oauth/token', $hook) === 'oauth/token' && OAuthServer::route($hook . '/oauth/register', $hook) === 'oauth/register', 'Token- und Registrierungsendpunkt sind reserviert');
pruefe(OAuthServer::route($hook . '/.well-known/oauth-authorization-server', $hook) === '.well-known/oauth-authorization-server', 'auch die zweite Metadatenadresse ist reserviert, obwohl Claude sie nicht abfragt');
pruefe(OAuthServer::route($hook . '/' . str_repeat('a1', 32), $hook) === null, 'ein Token in der Adresse ist keine Route');
pruefe(OAuthServer::route($hook, $hook) === null && OAuthServer::route($hook . '/', $hook) === null, 'der Hook selbst ist keine Route');
pruefe(OAuthServer::route('/hook/homeagent/99999/oauth/token', $hook) === null, 'nur unter dem eigenen Hook');
pruefe(OAuthServer::route($hook . '/.well-known/oauth-protected-resource/extra', $hook) === null, 'nur genau die reservierten Pfade');

/* --- Anmeldeaufforderung im 401 ------------------------------------------------------------------ */

$kopf = OAuthServer::challenge($endpunkt);
pruefe($kopf === 'Bearer resource_metadata="' . $endpunkt . '/.well-known/oauth-protected-resource", scope="mcp"', 'WWW-Authenticate nennt die Metadaten unter dem Hook und den Scope: ' . $kopf);

/* --- Protected Resource Metadata ----------------------------------------------------------------- */

$authorize = null;
foreach ($mitschnitt['requests'] as $anfrage) {
    if ($anfrage['route'] === 'oauth/authorize') {
        $authorize = $anfrage['query'];
    }
}

$a = OAuthServer::metadata('GET', '.well-known/oauth-protected-resource', $endpunkt);
$pr = json_decode($a['body'] ?? '', true);
pruefe(($a['status'] ?? 0) === 200 && ($a['headers']['Content-Type'] ?? '') === 'application/json', 'Ressourcen-Metadaten als JSON mit 200');
pruefe(($pr['resource'] ?? null) === $authorize['resource'], 'resource ist genau die Adresse, die Claude als resource schickt: ' . json_encode($pr['resource'] ?? null));
pruefe(($pr['authorization_servers'] ?? null) === [$endpunkt], 'der Autorisierungsserver ist der Endpunkt selbst (Issuer unter dem Hook)');
pruefe(($pr['scopes_supported'] ?? null) === ['mcp'] && ($pr['bearer_methods_supported'] ?? null) === ['header'], 'Scope mcp, Token nur im Header');

/* --- Authorization Server Metadata (Claude fragt openid-configuration ab) ------------------------ */

foreach (['.well-known/openid-configuration', '.well-known/oauth-authorization-server'] as $route) {
    $a = OAuthServer::metadata('GET', $route, $endpunkt);
    $as = json_decode($a['body'] ?? '', true);
    pruefe(($a['status'] ?? 0) === 200, $route . ': 200');
    pruefe(($as['issuer'] ?? null) === $endpunkt, $route . ': issuer ist der Endpunkt');
    pruefe(($as['authorization_endpoint'] ?? null) === $endpunkt . '/oauth/authorize' && ($as['token_endpoint'] ?? null) === $endpunkt . '/oauth/token', $route . ': Endpunkte unter dem Hook');
    pruefe(OAuthServer::route(parse_url((string)($as['authorization_endpoint'] ?? ''), PHP_URL_PATH), parse_url($endpunkt, PHP_URL_PATH)) === 'oauth/authorize', $route . ': der Autorisierungsendpunkt ist eine eigene Route');
    pruefe(($as['code_challenge_methods_supported'] ?? null) === ['S256'], $route . ': PKCE nur S256 (Claude verlangt die Angabe)');
    pruefe(($as['client_id_metadata_document_supported'] ?? null) === true && in_array('none', $as['token_endpoint_auth_methods_supported'] ?? [], true), $route . ': CIMD mit token_endpoint_auth_method none, wie Claudes Dokument');
    pruefe(($as['response_types_supported'] ?? null) === ['code'] && ($as['grant_types_supported'] ?? null) === ['authorization_code', 'refresh_token'], $route . ': nur Code-Fluss und Erneuerung');
    pruefe(array_diff(explode(' ', $authorize['scope']), $as['scopes_supported'] ?? []) === [], $route . ': alle Scopes, die Claude anfragt, sind bekannt: ' . json_encode($as['scopes_supported'] ?? null));
    pruefe(!array_key_exists('registration_endpoint', $as), $route . ': ohne Registrierungsendpunkt, solange es DCR nicht gibt');
}

// Claudes Client-Dokument passt zu den Angaben des Servers
$cimd = $mitschnitt['client_metadata_document'];
$as = json_decode(OAuthServer::metadata('GET', '.well-known/openid-configuration', $endpunkt)['body'], true);
pruefe(in_array($cimd['token_endpoint_auth_method'], $as['token_endpoint_auth_methods_supported'], true), 'Claudes token_endpoint_auth_method wird angeboten');
pruefe($authorize['code_challenge_method'] === 'S256' && $authorize['client_id'] === $cimd['client_id'], 'der Mitschnitt nutzt S256 und die client_id des Dokuments');

/* --- Methoden, Ende des Pfads, keine Metadaten für fremde Routen -------------------------------- */

$a = OAuthServer::metadata('POST', '.well-known/openid-configuration', $endpunkt);
pruefe(($a['status'] ?? 0) === 405 && ($a['headers']['Allow'] ?? '') === 'GET', 'Metadaten nur per GET, sonst 405');
pruefe(OAuthServer::metadata('GET', 'oauth/token', $endpunkt) === null, 'für andere Routen liefert metadata nichts');
$a = OAuthServer::metadata('GET', '.well-known/oauth-protected-resource', $endpunkt . '/');
pruefe(json_decode($a['body'], true)['resource'] === $endpunkt, 'ein Schrägstrich am Ende der Basis fällt weg');

ende('OAuth');
