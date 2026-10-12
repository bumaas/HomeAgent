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

/* --- Teil 2: Client, Rücksprungadresse, PKCE, Kopplung, Autorisierungscode ---------------------- */

$cimdText = (string)file_get_contents(__DIR__ . '/fixtures/oauth_claude_cimd_2026-10-11.json');
$claudeId = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
$hat = static fn (string $methode): bool => method_exists(OAuthServer::class, $methode);
foreach (['clientIdAllowed', 'clientFromDocument', 'redirectAllowed', 's256', 'checkAuthorize', 'redirect', 'createPairing', 'usePairing', 'issueCode', 'consentPage'] as $methode) {
    pruefe($hat($methode), 'OAuthServer::' . $methode . ' gibt es');
}
if (!$hat('checkAuthorize')) {
    ende('OAuth');
}

// Client ID Metadata Document: nur HTTPS mit Pfad, keine Adressen im eigenen Netz
pruefe(OAuthServer::clientIdAllowed($claudeId), 'Claudes client_id ist als CIMD-Adresse erlaubt');
foreach (['http://claude.ai/oauth/x', 'https://claude.ai', 'https://claude.ai/', 'https://user:pw@claude.ai/x', 'https://claude.ai/x#frag', 'https://claude.ai/a/../b', 'https://192.168.178.86/x', 'https://127.0.0.1/x', 'https://[::1]/x', 'https://10.0.0.1/x', 'ftp://claude.ai/x', 'claude', ''] as $falsch) {
    pruefe(!OAuthServer::clientIdAllowed($falsch), 'keine CIMD-Adresse: ' . $falsch);
}
$client = OAuthServer::clientFromDocument($claudeId, $cimdText);
pruefe(($client['client_name'] ?? null) === 'Claude' && ($client['redirect_uris'] ?? null) === ['https://claude.ai/api/mcp/auth_callback'], 'Claudes Dokument ergibt Name und Rücksprungadresse: ' . json_encode($client));
pruefe(OAuthServer::clientFromDocument('https://evil.example/x', $cimdText) === null, 'client_id im Dokument muss die abgerufene Adresse sein');
pruefe(OAuthServer::clientFromDocument($claudeId, '{"client_id":"' . $claudeId . '","redirect_uris":[]}') === null, 'ohne Rücksprungadresse kein Client');
pruefe(OAuthServer::clientFromDocument($claudeId, '{"client_id":"' . $claudeId . '","redirect_uris":["https://x.example/cb"],"token_endpoint_auth_method":"client_secret_basic"}') === null, 'nur öffentliche Clients (none)');
pruefe(OAuthServer::clientFromDocument($claudeId, 'kein json') === null, 'kaputtes Dokument ergibt keinen Client');
pruefe((OAuthServer::clientFromDocument($claudeId, '{"client_id":"' . $claudeId . '","redirect_uris":["https://x.example/cb"]}')['client_name'] ?? null) === 'claude.ai', 'ohne client_name gilt der Host');

// Rücksprungadresse: genau, nur bei Loopback ohne Port (Claude Code wechselt den Port)
$cb = 'https://claude.ai/api/mcp/auth_callback';
pruefe(OAuthServer::redirectAllowed($cb, [$cb]), 'Claudes Callback passt genau');
pruefe(!OAuthServer::redirectAllowed($cb . '/', [$cb]) && !OAuthServer::redirectAllowed($cb . '?x=1', [$cb]) && !OAuthServer::redirectAllowed('https://claude.ai.evil.example/api/mcp/auth_callback', [$cb]), 'keine Abweichung, keine fremde Domain');
pruefe(OAuthServer::redirectAllowed('http://127.0.0.1:53682/callback', ['http://127.0.0.1/callback']) && OAuthServer::redirectAllowed('http://localhost:4711/callback', ['http://localhost:1234/callback']), 'Loopback mit beliebigem Port');
pruefe(!OAuthServer::redirectAllowed('http://127.0.0.1:53682/anders', ['http://127.0.0.1/callback']) && !OAuthServer::redirectAllowed('http://example.com:80/callback', ['http://example.com/callback']), 'Port frei nur bei Loopback, Pfad bleibt genau');

// PKCE S256 mit dem Beispiel aus RFC 7636 Anhang B
$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
$challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
pruefe(OAuthServer::s256($verifier) === $challenge, 'S256 nach RFC 7636 Anhang B');

// Autorisierungsanfrage: Parameter wie im Mitschnitt; state und code_challenge stehen dort nur als
// Länge, hier der Prüfwert aus RFC 7636 und ein angenommener state mit 43 Zeichen
$anfrage = array_merge($authorize, ['code_challenge' => $challenge, 'state' => str_repeat('s', 43)]);
$r = OAuthServer::checkAuthorize($anfrage, $endpunkt, $client);
pruefe($r['status'] === 'ok', 'die Anfrage aus dem Mitschnitt ist gültig: ' . json_encode($r));
$r = OAuthServer::checkAuthorize($anfrage, $endpunkt, null);
pruefe($r['status'] === 'page', 'ohne lesbares Client-Dokument keine Weiterleitung, nur eine Fehlerseite');
$r = OAuthServer::checkAuthorize(['redirect_uri' => 'https://evil.example/cb'] + $anfrage, $endpunkt, $client);
pruefe($r['status'] === 'page', 'fremde Rücksprungadresse: Fehlerseite statt Weiterleitung (keine offene Weiterleitung)');
$fehlerCode = static function (array $aenderung) use ($anfrage, $endpunkt, $client): string {
    $r = OAuthServer::checkAuthorize(array_merge($anfrage, $aenderung), $endpunkt, $client);
    if ($r['status'] !== 'redirect') {
        return 'status ' . $r['status'];
    }
    parse_str((string)parse_url($r['location'], PHP_URL_QUERY), $q);
    return str_starts_with($r['location'], 'https://claude.ai/api/mcp/auth_callback?') && ($q['state'] ?? '') === str_repeat('s', 43) && ($q['iss'] ?? '') === $endpunkt ? (string)$q['error'] : 'falsche Adresse ' . $r['location'];
};
pruefe($fehlerCode(['response_type' => 'token']) === 'unsupported_response_type', 'nur response_type=code: ' . $fehlerCode(['response_type' => 'token']));
pruefe($fehlerCode(['code_challenge_method' => 'plain']) === 'invalid_request', 'PKCE nur S256: ' . $fehlerCode(['code_challenge_method' => 'plain']));
pruefe($fehlerCode(['code_challenge' => 'kurz']) === 'invalid_request', 'code_challenge mit 43 bis 128 Zeichen');
$ohne = $anfrage;
unset($ohne['code_challenge']);
$r = OAuthServer::checkAuthorize($ohne, $endpunkt, $client);
pruefe($r['status'] === 'redirect' && str_contains($r['location'] ?? '', 'error=invalid_request'), 'ohne code_challenge kein Code');
pruefe($fehlerCode(['resource' => 'https://example.ipmagic.de/hook/homeagent/99999']) === 'invalid_target', 'fremde resource: invalid_target');
pruefe($fehlerCode(['scope' => 'mcp admin']) === 'invalid_scope', 'unbekannter Scope: invalid_scope');
$ohneScope = $anfrage;
unset($ohneScope['scope'], $ohneScope['resource']);
pruefe(OAuthServer::checkAuthorize($ohneScope, $endpunkt, $client)['status'] === 'ok', 'scope und resource dürfen fehlen');
pruefe(OAuthServer::redirect('https://x.example/cb?a=1', ['code' => 'c d', 'state' => 's']) === 'https://x.example/cb?a=1&code=c%20d&state=s', 'Weiterleitung hängt an vorhandene Parameter an');

// Kopplungscode: einmal, 10 Minuten, nach 5 Fehlversuchen verfallen
$t = 1760000000;
$p = OAuthServer::createPairing([], 'zugang-a', '123456', $t);
pruefe(!str_contains((string)json_encode($p), '123456'), 'der Kopplungscode wird nur als Hash gespeichert');
$p = OAuthServer::createPairing($p, 'zugang-b', '654321', $t);
$p2 = OAuthServer::createPairing($p, 'zugang-a', '111111', $t);
pruefe(OAuthServer::usePairing($p2, '123456', $t)['access'] === null && OAuthServer::usePairing($p2, '111111', $t)['access'] === 'zugang-a', 'ein neuer Code ersetzt den alten desselben Zugangs');
$u = OAuthServer::usePairing($p, '654321', $t + 599);
pruefe($u['access'] === 'zugang-b' && OAuthServer::usePairing($u['pairings'], '654321', $t + 599)['access'] === null, 'richtiger Code gilt genau einmal');
pruefe(OAuthServer::usePairing($p, '123456', $t + 600)['access'] === null, 'nach 10 Minuten verfallen');
pruefe(OAuthServer::usePairing($p, ' 123 456 ', $t)['access'] === 'zugang-a', 'Leerzeichen bei der Eingabe stören nicht');
$f = $p;
for ($i = 0; $i < 5; $i++) {
    $f = OAuthServer::usePairing($f, '000000', $t)['pairings'];
}
pruefe(OAuthServer::usePairing($f, '123456', $t)['access'] === null, 'nach 5 falschen Eingaben ist kein Code mehr gültig');
$f = $p;
for ($i = 0; $i < 4; $i++) {
    $f = OAuthServer::usePairing($f, '000000', $t)['pairings'];
}
pruefe(OAuthServer::usePairing($f, '123456', $t)['access'] === 'zugang-a', 'nach 4 falschen Eingaben gilt der Code noch');

// Autorisierungscode: nur als Hash, mit allem, was der Token-Endpunkt prüfen muss
$grant = ['client_id' => $claudeId, 'redirect_uri' => $cb, 'code_challenge' => $challenge, 'scope' => 'mcp offline_access', 'resource' => $endpunkt, 'access' => 'zugang-a'];
$codes = OAuthServer::issueCode([], 'CODE-abc', $grant, $t);
$eintrag = array_values($codes)[0] ?? [];
pruefe(!str_contains((string)json_encode($codes), 'CODE-abc') && ($eintrag['expires'] ?? 0) === $t + 600 && ($eintrag['access'] ?? '') === 'zugang-a' && ($eintrag['code_challenge'] ?? '') === $challenge, 'Autorisierungscode als Hash mit Ablauf und Bindung: ' . json_encode($eintrag));
$codes = OAuthServer::issueCode($codes, 'CODE-def', $grant, $t + 601);
pruefe(count($codes) === 1, 'abgelaufene Codes fallen beim nächsten heraus');

// Einwilligungsseite
$texte = ['title' => 'Connect %s', 'intro' => 'Intro', 'returnTo' => 'Return to %s', 'codeLabel' => 'Pairing code', 'help' => 'Hilfe', 'allow' => 'Allow', 'deny' => 'Deny', 'error' => ''];
$seite = OAuthServer::consentPage($texte, $client, $anfrage, $endpunkt . '/oauth/authorize');
pruefe(str_contains($seite, 'Connect Claude') && str_contains(strip_tags($seite), 'Return to claude.ai') && str_contains($seite, 'action="' . $endpunkt . '/oauth/authorize"') && str_contains($seite, 'method="post"'), 'Seite nennt Client und Host der Rücksprungadresse');
pruefe(str_contains($seite, 'name="state" value="' . str_repeat('s', 43) . '"') && str_contains($seite, 'name="code_challenge" value="' . $challenge . '"'), 'die Parameter gehen verdeckt mit');
$boese = OAuthServer::consentPage($texte, ['client_name' => '<script>x</script>'] + $client, ['state' => '"><b>'] + $anfrage, $endpunkt . '/oauth/authorize');
pruefe(!str_contains($boese, '<script>x') && !str_contains($boese, '"><b>'), 'Name und Parameter werden maskiert');

/* --- Teil 3: Token-Endpunkt ---------------------------------------------------------------------- */

// Die Form der Token-Anfrage ist noch nicht mitgeschnitten. Angenommen nach Claude-Doku
// („Authentication for connectors“) und RFC 6749/7636: grant_type, code, redirect_uri, code_verifier,
// client_id, resource als application/x-www-form-urlencoded.
foreach (['redeemCode', 'startFamily', 'refresh', 'issueAccessToken', 'tokenResponse'] as $methode) {
    pruefe($hat($methode), 'OAuthServer::' . $methode . ' gibt es');
}
if (!$hat('redeemCode') || !$hat('refresh')) {
    ende('OAuth');
}

// Seit dem Mitschnitt vom 11.10.2026 belegt: Claude schickt genau diese Felder, ohne scope und ohne
// Authorization-Header; code hat 43, code_verifier 128 Zeichen.
$mitschnittToken = $mitschnitt['token_request'] ?? [];
pruefe(($mitschnittToken['content_type'] ?? '') === 'application/x-www-form-urlencoded' && array_key_exists('authorization', $mitschnittToken) && $mitschnittToken['authorization'] === null, 'Token-Anfrage im Mitschnitt: Formular ohne Client-Anmeldung');
$verifierRfc = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
$codes = OAuthServer::issueCode([], 'CODE-1', ['code_challenge' => $challenge] + $grant, $t);
$einloesen = ['grant_type' => 'authorization_code', 'code' => 'CODE-1', 'redirect_uri' => $cb, 'code_verifier' => $verifierRfc, 'client_id' => $claudeId, 'resource' => $endpunkt];
$felder = array_keys($mitschnittToken['fields'] ?? []);
sort($felder);
$erwartet = array_keys($einloesen);
sort($erwartet);
pruefe($felder === $erwartet, 'der Test löst mit genau den Feldern ein, die Claude schickt: ' . json_encode($felder));
pruefe(($mitschnittToken['fields']['resource'] ?? '') === $endpunkt && ($mitschnittToken['fields']['redirect_uri'] ?? '') === $cb, 'resource und redirect_uri wie bei der Autorisierung');
$r = OAuthServer::redeemCode($codes, $einloesen, $endpunkt, $t + 5);
pruefe(($r['error'] ?? null) === null && ($r['grant']['access'] ?? '') === 'zugang-a' && ($r['grant']['scope'] ?? '') === 'mcp offline_access', 'gültiger Code mit passendem Prüfwert wird eingelöst: ' . json_encode($r['error'] ?? null));
pruefe(OAuthServer::redeemCode($r['codes'], $einloesen, $endpunkt, $t + 6)['error'] === 'invalid_grant', 'ein Code gilt nur einmal');
$abweichend = static fn (array $aenderung, int $zeit = 5): ?string => OAuthServer::redeemCode($codes, array_merge($einloesen, $aenderung), $endpunkt, $t + $zeit)['error'];
pruefe($abweichend(['code_verifier' => str_repeat('x', 43)]) === 'invalid_grant', 'falscher Prüfwert (PKCE): invalid_grant');
pruefe($abweichend(['redirect_uri' => $cb . '/x']) === 'invalid_grant', 'andere Rücksprungadresse: invalid_grant');
pruefe($abweichend(['client_id' => 'https://evil.example/client']) === 'invalid_grant', 'anderer Client: invalid_grant');
pruefe($abweichend(['resource' => $endpunkt . '/x']) === 'invalid_target', 'andere Ressource: invalid_target');
pruefe($abweichend([], 600) === 'invalid_grant', 'abgelaufener Code: invalid_grant');
pruefe($abweichend(['code' => 'CODE-2']) === 'invalid_grant', 'unbekannter Code: invalid_grant');
$ohneVerifier = $einloesen;
unset($ohneVerifier['code_verifier']);
pruefe(OAuthServer::redeemCode($codes, $ohneVerifier, $endpunkt, $t + 5)['error'] === 'invalid_request', 'ohne code_verifier: invalid_request');
$ohneResource = $einloesen;
unset($ohneResource['resource']);
pruefe(OAuthServer::redeemCode($codes, $ohneResource, $endpunkt, $t + 5)['error'] === null, 'resource darf fehlen');
$falsch = OAuthServer::redeemCode($codes, ['code_verifier' => str_repeat('x', 43)] + $einloesen, $endpunkt, $t + 5);
pruefe(OAuthServer::redeemCode($falsch['codes'], $einloesen, $endpunkt, $t + 6)['error'] === 'invalid_grant', 'ein falscher Versuch verbraucht den Code (kein Raten des Prüfwerts)');

// Familie mit rotierendem Erneuerungstoken
$f = OAuthServer::startFamily([], 'fam-1', $r['grant'], 'REFRESH-1', $t);
pruefe(!str_contains((string)json_encode($f), 'REFRESH-1') && ($f['fam-1']['expires'] ?? 0) === $t + 90 * 86400, 'Erneuerungstoken nur als Hash, 90 Tage gültig: ' . json_encode($f));
$e = OAuthServer::refresh($f, ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-1', 'client_id' => $claudeId], 'REFRESH-2', $endpunkt, $t + 3600);
pruefe(($e['error'] ?? null) === null && ($e['family']['id'] ?? '') === 'fam-1' && ($e['family']['access'] ?? '') === 'zugang-a', 'Erneuern liefert die Familie mit Zugang: ' . json_encode($e['error'] ?? null));
pruefe(($e['families']['fam-1']['expires'] ?? 0) === $t + 3600 + 90 * 86400, 'jedes Erneuern verlängert um 90 Tage');
$e2 = OAuthServer::refresh($e['families'], ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-2', 'client_id' => $claudeId], 'REFRESH-3', $endpunkt, $t + 7200);
pruefe(($e2['error'] ?? null) === null, 'das neue Erneuerungstoken gilt');
$alt = OAuthServer::refresh($e2['families'], ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-1', 'client_id' => $claudeId], 'REFRESH-X', $endpunkt, $t + 7300);
pruefe($alt['error'] === 'invalid_grant' && !isset($alt['families']['fam-1']), 'ein schon verbrauchtes Erneuerungstoken widerruft die ganze Familie');
pruefe(OAuthServer::refresh($alt['families'], ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-3', 'client_id' => $claudeId], 'REFRESH-Y', $endpunkt, $t + 7400)['error'] === 'invalid_grant', 'danach gilt auch das jüngste nicht mehr');
pruefe(OAuthServer::refresh($e['families'], ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-2', 'client_id' => 'https://evil.example/client'], 'R', $endpunkt, $t + 7200)['error'] === 'invalid_grant', 'Erneuern nur durch denselben Client');
pruefe(OAuthServer::refresh($e['families'], ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-2', 'client_id' => $claudeId], 'R', $endpunkt, $t + 3600 + 90 * 86400)['error'] === 'invalid_grant', 'nach 90 Tagen ohne Erneuern verfallen');
pruefe(OAuthServer::refresh($e['families'], ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-2', 'client_id' => $claudeId, 'scope' => 'mcp admin'], 'R', $endpunkt, $t + 7200)['error'] === 'invalid_scope', 'Erneuern kann den Scope nicht erweitern');

// Zugriffstoken und Antwort
$tok = OAuthServer::issueAccessToken([], 'ACCESS-1', ['family' => 'fam-1', 'access' => 'zugang-a', 'client_id' => $claudeId, 'scope' => 'mcp offline_access'], $t);
$tEintrag = array_values($tok)[0] ?? [];
pruefe(!str_contains((string)json_encode($tok), 'ACCESS-1') && ($tEintrag['expires'] ?? 0) === $t + 3600 && ($tEintrag['family'] ?? '') === 'fam-1', 'Zugriffstoken nur als Hash, 1 h gültig');
$tok = OAuthServer::issueAccessToken($tok, 'ACCESS-2', $tEintrag, $t + 3601);
pruefe(count($tok) === 1, 'abgelaufene Zugriffstokens fallen heraus');
$a = OAuthServer::tokenResponse('ACCESS-1', 'REFRESH-1', 'mcp offline_access');
$k = json_decode($a['body'], true);
pruefe($a['status'] === 200 && ($a['headers']['Cache-Control'] ?? '') === 'no-store' && $k === ['access_token' => 'ACCESS-1', 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => 'REFRESH-1', 'scope' => 'mcp offline_access'], 'Token-Antwort nach RFC 6749: ' . $a['body']);
$k = json_decode(OAuthServer::tokenResponse('ACCESS-1', null, 'mcp')['body'], true);
pruefe(!array_key_exists('refresh_token', $k), 'ohne offline_access kein Erneuerungstoken');

/* --- Teil 4: Zugriffstoken am Endpunkt ----------------------------------------------------------- */

pruefe($hat('accessFor'), 'OAuthServer::accessFor gibt es');
$kopf = OAuthServer::challenge($endpunkt, true);
pruefe($kopf === 'Bearer resource_metadata="' . $endpunkt . '/.well-known/oauth-protected-resource", scope="mcp", error="invalid_token"', 'ungültiges Token: WWW-Authenticate mit error="invalid_token": ' . $kopf);
if ($hat('accessFor')) {
    $familien = OAuthServer::startFamily([], 'fam-1', $r['grant'], 'REFRESH-1', $t);
    $tokens = OAuthServer::issueAccessToken([], 'ACCESS-1', ['family' => 'fam-1', 'access' => 'zugang-a', 'client_id' => $claudeId, 'scope' => 'mcp offline_access'], $t);
    $gefunden = OAuthServer::accessFor($tokens, $familien, 'Bearer ACCESS-1', $t + 10);
    pruefe(($gefunden['access'] ?? '') === 'zugang-a' && ($gefunden['family'] ?? '') === 'fam-1', 'gültiges Zugriffstoken ergibt Zugang und Familie: ' . json_encode($gefunden));
    pruefe(OAuthServer::accessFor($tokens, $familien, 'bearer   ACCESS-1 ', $t + 10) !== null, 'Schema ohne Rücksicht auf Groß- und Kleinschreibung, Leerzeichen egal');
    pruefe(OAuthServer::accessFor($tokens, $familien, 'Bearer ACCESS-1', $t + 3600) === null, 'nach einer Stunde abgelaufen');
    pruefe(OAuthServer::accessFor($tokens, [], 'Bearer ACCESS-1', $t + 10) === null, 'widerrufene Familie: Token gilt sofort nicht mehr');
    pruefe(OAuthServer::accessFor($tokens, $familien, 'Bearer ACCESS-2', $t + 10) === null && OAuthServer::accessFor($tokens, $familien, '', $t + 10) === null && OAuthServer::accessFor($tokens, $familien, 'Basic ACCESS-1', $t + 10) === null, 'unbekanntes Token, keins oder anderes Schema: null');
}

/* --- Befunde aus /code-review vom 11.10.2026 ----------------------------------------------------- */

// R5: nur global erreichbare Adressen (Tailscale/CGNAT 100.64.0.0/10 liegt am nuc im eigenen Netz)
foreach (['100.100.1.1', '100.97.187.41', '192.0.0.1', '198.18.0.1', '169.254.1.1', 'fd00::1'] as $ip) {
    pruefe(!OAuthServer::publicAddress($ip), 'keine öffentliche Adresse: ' . $ip);
}
pruefe(OAuthServer::publicAddress('160.79.106.181') && OAuthServer::publicAddress('2606:4700::6810:1'), 'öffentliche Adressen bleiben erlaubt');
pruefe(!OAuthServer::clientIdAllowed('https://100.64.0.1/client'), 'CGNAT-Adresse als client_id abgewiesen');
// Code-Review 12.10.2026: Die Adresse prüft curl erst nach Verbindungsaufbau und TLS-Handshake. Damit ein Name,
// der auf eine interne Adresse zeigt, keine internen Ports abtastet, nur der HTTPS-Standardport.
pruefe(!OAuthServer::clientIdAllowed('https://example.org:8443/client') && !OAuthServer::clientIdAllowed('https://example.org:22/client'), 'client_id mit anderem Port als 443 abgewiesen');
pruefe(OAuthServer::clientIdAllowed('https://example.org:443/client'), 'Port 443 ausdrücklich genannt ist erlaubt');

// R4: Die Überschrift nennt den Host der client_id, den Namen wählt der Client selbst
$falscherClaude = OAuthServer::clientFromDocument('https://evil.example/cimd', '{"client_id":"https://evil.example/cimd","client_name":"Claude","redirect_uris":["https://evil.example/cb"]}');
$seite = OAuthServer::consentPage($texte, $falscherClaude, ['redirect_uri' => 'https://evil.example/cb'] + $anfrage, $endpunkt . '/oauth/authorize');
pruefe(preg_match('#<h1>[^<]*evil\.example[^<]*</h1>#', $seite) === 1, 'ein Client, der sich „Claude“ nennt, steht mit seinem Host in der Überschrift: ' . (preg_match('#<h1>.*?</h1>#', $seite, $h) === 1 ? $h[0] : '-'));
$seite = OAuthServer::consentPage($texte, $client, $anfrage, $endpunkt . '/oauth/authorize');
pruefe(preg_match('#<h1>Connect Claude \(claude\.ai\)</h1>#', $seite) === 1, 'echter Client: Name und Host in der Überschrift: ' . (preg_match('#<h1>.*?</h1>#', $seite, $h) === 1 ? $h[0] : '-'));

// R1: Erneuern rotiert nicht, wenn der Zugang gerade nicht benutzbar ist
$f1 = OAuthServer::startFamily([], 'fam-1', $r['grant'], 'REFRESH-1', $t);
$ohneZugang = OAuthServer::refresh($f1, ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-1', 'client_id' => $claudeId], 'REFRESH-2', $endpunkt, $t + 10, ['zugang-b']);
pruefe($ohneZugang['error'] === 'invalid_grant' && $ohneZugang['families'] === $f1, 'Zugang nicht benutzbar: invalid_grant ohne Rotation');
$spaeter = OAuthServer::refresh($ohneZugang['families'], ['grant_type' => 'refresh_token', 'refresh_token' => 'REFRESH-1', 'client_id' => $claudeId], 'REFRESH-2', $endpunkt, $t + 20, ['zugang-a', 'zugang-b']);
pruefe($spaeter['error'] === null, 'ist der Zugang wieder da, gilt dasselbe Erneuerungstoken noch');

// R8: Bei vielen Kopplungen fallen zuerst die am längsten unbenutzten heraus
$viele = [];
for ($i = 0; $i < 50; $i++) {
    $viele = OAuthServer::startFamily($viele, 'f' . $i, $r['grant'], 'R' . $i, $t + $i);
}
$benutzt = OAuthServer::refresh($viele, ['grant_type' => 'refresh_token', 'refresh_token' => 'R0', 'client_id' => $claudeId], 'R0b', $endpunkt, $t + 100);
$viele = OAuthServer::startFamily($benutzt['families'], 'neu', $r['grant'], 'RN', $t + 101);
pruefe(isset($viele['f0']) && !isset($viele['f1']) && isset($viele['neu']) && count($viele) === 50, 'die zuletzt erneuerte älteste Kopplung bleibt, die unbenutzte nächste fällt heraus: ' . implode(',', array_slice(array_keys($viele), 0, 3)));

// R7: ein abgelaufenes, aber bekanntes Zugriffstoken ist kein Fehlversuch
pruefe($hat('isExpiredToken'), 'OAuthServer::isExpiredToken gibt es');
if ($hat('isExpiredToken')) {
    $tok = OAuthServer::issueAccessToken([], 'ACCESS-1', ['family' => 'fam-1', 'access' => 'zugang-a', 'client_id' => $claudeId, 'scope' => 'mcp'], $t);
    pruefe(OAuthServer::isExpiredToken($tok, 'Bearer ACCESS-1', $t + 3600) && !OAuthServer::isExpiredToken($tok, 'Bearer ACCESS-1', $t + 10) && !OAuthServer::isExpiredToken($tok, 'Bearer ANDERES', $t + 3600), 'abgelaufen erkannt, gültige und unbekannte nicht');
}

ende('OAuth');
