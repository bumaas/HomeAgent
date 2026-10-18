<?php

declare(strict_types=1);

// Gemeinsame Kopfzeilen der JSON-Antworten (BASE_HEADERS), Code-Review 12.10.2026
require_once __DIR__ . '/McpProtocol.php';

/**
 * OAuth 2.1 für den MCP-Endpunkt, ohne Symcon-Abhängigkeit.
 *
 * Autorisierungsserver und Ressource sind derselbe Endpunkt: Der Issuer liegt unter dem Hook, die
 * Metadaten unter <Endpunkt>/.well-known/… Ein Client findet sie dort über den dritten Pfad der
 * MCP-Spezifikation (<Issuer>/.well-known/openid-configuration); Claude fragt genau diesen ab
 * (Mitschnitt 11.10.2026). Die Adressen unter der Wurzel des Webservers erreicht ein Modul nicht.
 *
 * Die Basis ist die öffentliche Adresse des Endpunkts (über Connect), nicht der Host der Anfrage.
 */
final class OAuthServer
{
    /** Reservierte Unterpfade unter dem Hook; sie gelten nie als Token in der Adresse. */
    public const ROUTES = [
        '.well-known/oauth-protected-resource',
        '.well-known/oauth-authorization-server',
        '.well-known/openid-configuration',
        'oauth/authorize',
        'oauth/token',
        'oauth/register',
    ];

    /** Scope für den Zugriff auf die Werkzeuge; offline_access fordert ein Erneuerungstoken an. */
    public const SCOPE = 'mcp';

    /** Kopplungscode: Gültigkeit und erlaubte Fehleingaben. */
    public const PAIRING_SECONDS = 600;
    public const PAIRING_ATTEMPTS = 5;

    /** Autorisierungscode: Gültigkeit und Höchstzahl offener Codes. */
    public const CODE_SECONDS = 600;
    private const MAX_OPEN_CODES = 20;

    /** Zugriffstoken 1 Stunde, Erneuerungstoken 90 Tage ab der letzten Erneuerung. */
    public const ACCESS_SECONDS = 3600;
    public const REFRESH_SECONDS = 90 * 86400;
    private const MAX_ACCESS_TOKENS = 200;
    private const MAX_FAMILIES = 50;
    private const MAX_USED_REFRESH = 20;

    /**
     * So lange gilt das vorige Erneuerungstoken einer Familie noch, solange der Client die letzte Erneuerung nicht
     * benutzt hat (Sekunden). Kommt die Antwort des Token-Endpunkts nicht an, hält der Client weiter das alte Paar;
     * am 16.10.2026 erneuerte claude.ai damit 3 h 38 min später, und die Kopplung fiel. Der Client erneuert erst,
     * wenn er den Endpunkt wieder braucht, deshalb eine Woche.
     */
    public const PREVIOUS_SECONDS = 7 * 86400;

    /** Abgelaufene Zugriffstokens bleiben so lange bekannt, damit sie als abgelaufen und nicht als Raten gelten. */
    public const EXPIRED_KEEP_SECONDS = 86400;

    /** Parameter der Autorisierungsanfrage, die die Einwilligungsseite verdeckt weitergibt. */
    private const AUTHORIZE_PARAMS = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];

    /**
     * Reservierter Unterpfad der Anfrage, sonst null. Der Query-String zählt nicht dazu.
     */
    public static function route(string $requestUri, string $hookPath): ?string
    {
        $path = explode('?', $requestUri, 2)[0];
        $hookPath = rtrim($hookPath, '/');
        if ($hookPath === '' || !str_starts_with($path, $hookPath . '/')) {
            return null;
        }
        $rest = substr($path, strlen($hookPath) + 1);
        return in_array($rest, self::ROUTES, true) ? $rest : null;
    }

    /**
     * Wert für WWW-Authenticate im 401: Damit beginnt ein Client die Anmeldung. Mit $invalidToken
     * (ein Token kam mit, gilt aber nicht) erneuert der Client zuerst (RFC 6750, Abschnitt 3.1).
     */
    public static function challenge(string $base, bool $invalidToken = false): string
    {
        return 'Bearer resource_metadata="' . rtrim($base, '/') . '/.well-known/oauth-protected-resource", scope="' . self::SCOPE . '"'
            . ($invalidToken ? ', error="invalid_token"' : '');
    }

    /**
     * Ist das Bearer-Token ein bekanntes, aber abgelaufenes Zugriffstoken? Dann erneuert der Client,
     * das ist kein Fehlversuch.
     */
    public static function isExpiredToken(array $tokens, string $authorization, int $now): bool
    {
        if (preg_match('/^\s*Bearer[ \t]+(\S+)\s*$/i', $authorization, $parts) !== 1) {
            return false;
        }
        $entry = $tokens[hash('sha256', $parts[1])] ?? null;
        return is_array($entry) && $entry['expires'] <= $now;
    }

    /**
     * Prüft ein Bearer-Token als OAuth-Zugriffstoken: bekannt, nicht abgelaufen, und seine Kopplung
     * besteht noch. Ob der Zugang noch benutzbar ist, prüft der Aufrufer.
     *
     * @return ?array{family: string, access: string, client_id: string, scope: string, expires: int}
     */
    public static function accessFor(array $tokens, array $families, string $authorization, int $now): ?array
    {
        if (preg_match('/^\s*Bearer[ \t]+(\S+)\s*$/i', $authorization, $parts) !== 1) {
            return null;
        }
        $entry = $tokens[hash('sha256', $parts[1])] ?? null;
        if (!is_array($entry) || $entry['expires'] <= $now || !isset($families[$entry['family']])) {
            return null;
        }
        return $entry;
    }

    /**
     * Antwort auf eine Metadatenadresse, null für andere Routen.
     *
     * @return ?array{status: int, headers: array<string, string>, body: string}
     */
    public static function metadata(string $method, string $route, string $base): ?array
    {
        $base = rtrim($base, '/');
        $content = match ($route) {
            '.well-known/oauth-protected-resource' => [
                // resource muss genau die Adresse sein, die der Anwender im Client einträgt
                'resource'                 => $base,
                'authorization_servers'    => [$base],
                'scopes_supported'         => [self::SCOPE],
                'bearer_methods_supported' => ['header'],
                'resource_name'            => 'Symcon Home Agent',
            ],
            '.well-known/oauth-authorization-server', '.well-known/openid-configuration' => [
                'issuer'                                => $base,
                'authorization_endpoint'                => $base . '/oauth/authorize',
                'token_endpoint'                        => $base . '/oauth/token',
                'response_types_supported'              => ['code'],
                'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported'      => ['S256'],
                // Öffentliche Clients mit Client ID Metadata Document (Claude), ohne Secret
                'token_endpoint_auth_methods_supported' => ['none'],
                'client_id_metadata_document_supported' => true,
                'scopes_supported'                      => [self::SCOPE, 'offline_access'],
            ],
            default => null,
        };
        if ($content === null) {
            return null;
        }
        if ($method !== 'GET') {
            return self::json(405, ['error' => 'invalid_request', 'error_description' => 'Only GET is supported'], ['Allow' => 'GET']);
        }
        return self::json(200, $content);
    }

    /**
     * Darf diese client_id als Adresse eines Client ID Metadata Document abgerufen werden? Nur HTTPS
     * mit Pfad, ohne Anmeldedaten, Fragment und Punktsegmente, und keine IP-Adresse aus einem
     * privaten oder reservierten Bereich. Namen prüft der Aufrufer nach der Auflösung noch einmal.
     */
    public static function clientIdAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            // Nur der HTTPS-Standardport: curl prüft die Adresse erst nach Verbindung und TLS-Handshake
            // (Code-Review 12.10.2026), ein anderer Port würde interne Dienste abtasten lassen.
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $path = (string)($parts['path'] ?? '');
        if ($path === '' || $path === '/' || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1) {
            return false;
        }
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::publicAddress($host);
        }
        return true;
    }

    /**
     * Ist die IP-Adresse global erreichbar? Ausgeschlossen sind private und reservierte Bereiche und
     * alle, die RFC 6890 nicht global nennt, etwa 100.64.0.0/10 (CGNAT, Tailscale).
     */
    public static function publicAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /** Textparameter einer Anfrage, '' wenn er fehlt oder kein Text ist. */
    private static function param(array $params, string $key): string
    {
        return is_string($params[$key] ?? null) ? $params[$key] : '';
    }

    /**
     * Prüft ein abgerufenes Client-Dokument. Es muss sich selbst unter seiner Adresse nennen,
     * mindestens eine Rücksprungadresse haben und ohne Secret auskommen.
     *
     * @return ?array{client_id: string, client_name: string, redirect_uris: list<string>}
     */
    public static function clientFromDocument(string $clientId, string $json): ?array
    {
        $doc = json_decode($json, true);
        if (!is_array($doc) || ($doc['client_id'] ?? null) !== $clientId) {
            return null;
        }
        $uris = $doc['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || array_any($uris, static fn (mixed $uri): bool => !is_string($uri) || $uri === '')) {
            return null;
        }
        if (($doc['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            return null;
        }
        $name = is_string($doc['client_name'] ?? null) && trim($doc['client_name']) !== '' ? trim($doc['client_name']) : (string)parse_url($clientId, PHP_URL_HOST);
        return ['client_id' => $clientId, 'client_name' => mb_substr($name, 0, 80), 'redirect_uris' => $uris];
    }

    /**
     * Rücksprungadresse genau wie registriert; bei Loopback-Adressen (Claude Code) ohne den Port,
     * weil der Client ihn bei jedem Start neu wählt (RFC 8252, Abschnitt 7.3).
     */
    public static function redirectAllowed(string $uri, array $registered): bool
    {
        foreach ($registered as $allowed) {
            if (is_string($allowed) && hash_equals($allowed, $uri)) {
                return true;
            }
        }
        $withoutPort = static function (string $address): ?string {
            $p = parse_url($address);
            if (!is_array($p) || ($p['scheme'] ?? '') !== 'http' || !in_array($p['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)) {
                return null;
            }
            return 'http://' . $p['host'] . ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
        };
        $given = $withoutPort($uri);
        return $given !== null && array_any($registered, static fn (mixed $allowed): bool => is_string($allowed) && $withoutPort($allowed) === $given);
    }

    /** PKCE S256 (RFC 7636): BASE64URL(SHA256(Prüfwert)) ohne Auffüllung. */
    public static function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Prüft eine Autorisierungsanfrage. Solange Client und Rücksprungadresse nicht feststehen, gibt
     * es nur eine Fehlerseite (keine offene Weiterleitung); danach gehen Fehler an den Client zurück.
     *
     * @param ?array{client_id: string, client_name: string, redirect_uris: list<string>} $client null = Dokument nicht lesbar
     * @return array{status: 'ok'|'page'|'redirect', error?: string, location?: string}
     */
    public static function checkAuthorize(array $params, string $base, ?array $client): array
    {
        $base = rtrim($base, '/');
        $text = static fn (string $key): string => self::param($params, $key);
        if ($client === null || $client['client_id'] !== $text('client_id')) {
            return ['status' => 'page', 'error' => 'client'];
        }
        $redirect = $text('redirect_uri');
        if ($redirect === '' || !self::redirectAllowed($redirect, $client['redirect_uris'])) {
            return ['status' => 'page', 'error' => 'redirect_uri'];
        }
        $back = static fn (string $error): array => ['status' => 'redirect', 'error' => $error, 'location' => self::redirect($redirect, array_filter(['error' => $error, 'state' => $text('state'), 'iss' => $base], static fn (string $v): bool => $v !== ''))];
        if ($text('response_type') !== 'code') {
            return $back('unsupported_response_type');
        }
        if ($text('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $text('code_challenge')) !== 1) {
            return $back('invalid_request');
        }
        if ($text('resource') !== '' && rtrim($text('resource'), '/') !== $base) {
            return $back('invalid_target');
        }
        $scopes = array_filter(explode(' ', $text('scope')), static fn (string $s): bool => $s !== '');
        if (array_diff($scopes, [self::SCOPE, 'offline_access']) !== []) {
            return $back('invalid_scope');
        }
        return ['status' => 'ok'];
    }

    /** Hängt Parameter an eine Adresse an, auch wenn sie schon welche trägt. */
    public static function redirect(string $uri, array $query): string
    {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Neuer Kopplungscode für einen Zugang; er ersetzt einen offenen Code desselben Zugangs.
     * Gespeichert wird nur der Hash.
     */
    public static function createPairing(array $pairings, string $access, string $code, int $now): array
    {
        $pairings = array_values(array_filter($pairings, static fn (array $p): bool => $p['expires'] > $now && $p['access'] !== $access));
        $pairings[] = ['access' => $access, 'hash' => hash('sha256', $code), 'expires' => $now + self::PAIRING_SECONDS, 'failures' => 0];
        return $pairings;
    }

    /**
     * Löst einen eingegebenen Kopplungscode ein. Ein Treffer verbraucht ihn; jede falsche Eingabe
     * zählt bei allen offenen Codes, nach PAIRING_ATTEMPTS sind sie verfallen.
     *
     * @return array{pairings: array, access: ?string}
     */
    public static function usePairing(array $pairings, string $code, int $now): array
    {
        $hash = hash('sha256', preg_replace('/\s+/', '', $code) ?? '');
        $pairings = array_values(array_filter($pairings, static fn (array $p): bool => $p['expires'] > $now && $p['failures'] < self::PAIRING_ATTEMPTS));
        foreach ($pairings as $index => $pairing) {
            if (hash_equals($pairing['hash'], $hash)) {
                unset($pairings[$index]);
                return ['pairings' => array_values($pairings), 'access' => $pairing['access']];
            }
        }
        $pairings = array_map(static fn (array $p): array => ['failures' => $p['failures'] + 1] + $p, $pairings);
        return ['pairings' => array_values(array_filter($pairings, static fn (array $p): bool => $p['failures'] < self::PAIRING_ATTEMPTS)), 'access' => null];
    }

    /**
     * Legt einen Autorisierungscode ab, nur als Hash, mit allem, was der Token-Endpunkt prüft.
     *
     * @param array{client_id: string, redirect_uri: string, code_challenge: string, scope: string, resource: string, access: string} $grant
     */
    public static function issueCode(array $codes, string $code, array $grant, int $now): array
    {
        $codes = array_filter($codes, static fn (array $c): bool => $c['expires'] > $now);
        $codes[hash('sha256', $code)] = $grant + ['expires' => $now + self::CODE_SECONDS];
        return array_slice($codes, -self::MAX_OPEN_CODES, null, true);
    }

    /**
     * Löst einen Autorisierungscode ein (grant_type=authorization_code). Der Code ist danach in jedem
     * Fall verbraucht, auch bei falschem Prüfwert; sonst ließe sich der Prüfwert raten.
     *
     * @return array{codes: array, grant: ?array, error: ?string} error nach RFC 6749, Abschnitt 5.2
     */
    public static function redeemCode(array $codes, array $params, string $base, int $now): array
    {
        $text = static fn (string $key): string => self::param($params, $key);
        if ($text('code') === '' || $text('code_verifier') === '' || $text('client_id') === '' || $text('redirect_uri') === '') {
            return ['codes' => $codes, 'grant' => null, 'error' => 'invalid_request'];
        }
        $hash = hash('sha256', $text('code'));
        $grant = $codes[$hash] ?? null;
        unset($codes[$hash]);
        $codes = array_filter($codes, static fn (array $c): bool => $c['expires'] > $now);
        if (!is_array($grant) || $grant['expires'] <= $now || !hash_equals($grant['client_id'], $text('client_id'))
            || !hash_equals($grant['redirect_uri'], $text('redirect_uri')) || !hash_equals($grant['code_challenge'], self::s256($text('code_verifier')))) {
            return ['codes' => $codes, 'grant' => null, 'error' => 'invalid_grant'];
        }
        if ($text('resource') !== '' && rtrim($text('resource'), '/') !== rtrim($base, '/')) {
            return ['codes' => $codes, 'grant' => null, 'error' => 'invalid_target'];
        }
        unset($grant['expires'], $grant['code_challenge'], $grant['redirect_uri']);
        return ['codes' => $codes, 'grant' => $grant, 'error' => null];
    }

    /**
     * Neue Familie von Erneuerungstokens nach einem eingelösten Code. Eine Familie ist eine Kopplung
     * eines Clients mit einem Zugang; gespeichert wird der Hash des aktuellen Erneuerungstokens und
     * der verbrauchten, damit eine Wiederverwendung auffällt.
     *
     * @param array{client_id: string, scope: string, resource: string, access: string} $grant
     */
    /** Die letzte Nutzung einer Kopplung wird höchstens so oft gespeichert (Sekunden), sonst bei jeder Anfrage. */
    public const USE_STEP = 600;

    /**
     * Wann eine Kopplung zuletzt benutzt wurde: gemerkte Nutzung, sonst die letzte Erneuerung oder die Kopplung selbst
     * (Kopplungen von vor build 120 haben keinen gemerkten Wert).
     */
    public static function lastUse(array $family): int
    {
        return max((int)($family['lastUse'] ?? 0), (int)($family['created'] ?? 0), (int)($family['expires'] ?? 0) - self::REFRESH_SECONDS);
    }

    /**
     * Merkt die Nutzung einer Kopplung (Burkhard 12.10.2026: drei gleiche Zeilen „Claude-App“, welche ist noch in
     * Gebrauch?). null, wenn nichts zu schreiben ist: unbekannte Kopplung oder zuletzt vor weniger als USE_STEP gemerkt.
     */
    public static function markUsed(array $families, string $id, int $now): ?array
    {
        if (!isset($families[$id]) || $now - (int)($families[$id]['lastUse'] ?? 0) < self::USE_STEP) {
            return null;
        }
        $families[$id]['lastUse'] = $now;
        return $families;
    }

    public static function startFamily(array $families, string $id, array $grant, string $refreshToken, int $now): array
    {
        $families = array_filter($families, static fn (array $f): bool => $f['expires'] > $now);
        $families[$id] = [
            'id'        => $id,
            'client_id' => $grant['client_id'],
            'client_name' => (string)($grant['client_name'] ?? ''),
            'access'    => $grant['access'],
            'scope'     => $grant['scope'],
            'resource'  => $grant['resource'],
            'refresh'   => hash('sha256', $refreshToken),
            'used'      => [],
            'created'   => $now,
            'expires'   => $now + self::REFRESH_SECONDS,
        ];
        return array_slice($families, -self::MAX_FAMILIES, null, true);
    }

    /**
     * Erneuert mit einem Erneuerungstoken (grant_type=refresh_token) und rotiert es. Ein schon
     * verbrauchtes Token widerruft die ganze Familie (RFC 9700, Abschnitt 4.14.2).
     *
     * Ausnahme: das unmittelbar vorige Token, solange die letzte Erneuerung unbenutzt ist (`pending`, gelöscht
     * von confirm()) und höchstens PREVIOUS_SECONDS ab der ersten unbestätigten Erneuerung (`since`; vorher ab der
     * letzten, alle sechs Tage eingelöst galt es beliebig lange, Review 18.10.2026, N1). Dann ist die Antwort wohl verloren gegangen; die Familie
     * bekommt ein neues Paar, das nie zugestellte Token gilt als verbraucht. Taucht es doch noch auf, fällt die
     * Familie wie bisher. Ein gestohlenes voriges Token nützt nur, bis der Client das neue Zugriffstoken benutzt.
     *
     * @return array{families: array, family: ?array, error: ?string}
     */
    public static function refresh(array $families, array $params, string $newRefreshToken, string $base, int $now, ?array $usableAccesses = null): array
    {
        $unchanged = $families;
        $text = static fn (string $key): string => self::param($params, $key);
        if ($text('refresh_token') === '' || $text('client_id') === '') {
            return ['families' => $families, 'family' => null, 'error' => 'invalid_request'];
        }
        $families = array_filter($families, static fn (array $f): bool => $f['expires'] > $now);
        $hash = hash('sha256', $text('refresh_token'));
        foreach ($families as $id => $family) {
            $previous = isset($family['previous'], $family['pending']) && hash_equals($family['previous'], $hash)
                && $now - (int)($family['since'] ?? $family['pending']) <= self::PREVIOUS_SECONDS;
            if (!$previous && in_array($hash, $family['used'], true)) {
                unset($families[$id]);
                return ['families' => $families, 'family' => null, 'error' => 'invalid_grant'];
            }
            if (!$previous && !hash_equals($family['refresh'], $hash)) {
                continue;
            }
            if (!hash_equals($family['client_id'], $text('client_id'))) {
                return ['families' => $families, 'family' => null, 'error' => 'invalid_grant'];
            }
            if ($text('resource') !== '' && rtrim($text('resource'), '/') !== rtrim($base, '/')) {
                return ['families' => $families, 'family' => null, 'error' => 'invalid_target'];
            }
            $requested = array_filter(explode(' ', $text('scope')), static fn (string $s): bool => $s !== '');
            if (array_diff($requested, explode(' ', $family['scope'])) !== []) {
                return ['families' => $families, 'family' => null, 'error' => 'invalid_scope'];
            }
            // Ist der Zugang gerade nicht benutzbar, bleibt alles, wie es ist: Sonst hielte der
            // Client nach der Absage ein Token, das als verbraucht gilt, und verlöre die Kopplung.
            if ($usableAccesses !== null && !in_array($family['access'], $usableAccesses, true)) {
                return ['families' => $unchanged, 'family' => null, 'error' => 'invalid_grant'];
            }
            if ($previous) {
                // Das nie zugestellte Token gilt als verbraucht, das vorige bleibt das vorige
                $family['used'] = array_slice(array_merge($family['used'], [$family['refresh']]), -self::MAX_USED_REFRESH);
            } else {
                $family['used'] = array_slice(array_merge($family['used'], [$hash]), -self::MAX_USED_REFRESH);
                $family['previous'] = $hash;
                $family['since'] = $now;
            }
            $family['pending'] = $now;
            $family['refresh'] = hash('sha256', $newRefreshToken);
            $family['expires'] = $now + self::REFRESH_SECONDS;
            // ans Ende: Bei zu vielen Kopplungen fallen die am längsten unbenutzten heraus
            unset($families[$id]);
            $families[$id] = $family;
            return ['families' => $families, 'family' => $family, 'error' => null];
        }
        return ['families' => $families, 'family' => null, 'error' => 'invalid_grant'];
    }

    /**
     * Ein gültiges Zugriffstoken, ausgegeben bei oder nach der letzten Erneuerung, zeigt: Die Antwort ist
     * angekommen, das vorige Erneuerungstoken gilt nicht mehr. Gibt die geänderten Familien zurück, null, wenn
     * nichts zu schreiben ist (nichts offen, älteres Token, unbekannte Familie).
     *
     * @param array{family: string, expires: int, issued?: int} $entry
     */
    public static function confirm(array $families, array $entry, int $now): ?array
    {
        $id = (string)$entry['family'];
        if (!isset($families[$id]['pending'])) {
            return null;
        }
        $issued = (int)($entry['issued'] ?? ((int)$entry['expires'] - self::ACCESS_SECONDS));
        if ($issued < (int)$families[$id]['pending'] || $issued > $now) {
            return null;
        }
        unset($families[$id]['pending'], $families[$id]['previous'], $families[$id]['since']);
        return $families;
    }

    /**
     * Legt ein Zugriffstoken ab, nur als Hash, mit Familie, Zugang und Ablauf.
     *
     * @param array{family: string, access: string, client_id: string, scope: string} $binding
     */
    public static function issueAccessToken(array $tokens, string $token, array $binding, int $now): array
    {
        // Abgelaufene bleiben einen Tag, sonst zählte ein Client mit einem alten Token als Fehlversuch (16.10.2026)
        $tokens = array_filter($tokens, static fn (array $t): bool => $t['expires'] + self::EXPIRED_KEEP_SECONDS > $now);
        $tokens[hash('sha256', $token)] = ['family' => $binding['family'], 'access' => $binding['access'], 'client_id' => $binding['client_id'], 'scope' => $binding['scope'], 'issued' => $now, 'expires' => $now + self::ACCESS_SECONDS];
        return array_slice($tokens, -self::MAX_ACCESS_TOKENS, null, true);
    }

    /**
     * Erfolgreiche Antwort des Token-Endpunkts (RFC 6749, Abschnitt 5.1).
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function tokenResponse(string $accessToken, ?string $refreshToken, string $scope): array
    {
        $content = ['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_SECONDS];
        if ($refreshToken !== null) {
            $content['refresh_token'] = $refreshToken;
        }
        if ($scope !== '') {
            $content['scope'] = $scope;
        }
        return self::json(200, $content, ['Pragma' => 'no-cache']);
    }

    /** Zufälliges Token mit 256 Bit, base64url ohne Auffüllung. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Einwilligungsseite: Client, Host der Rücksprungadresse, Feld für den Kopplungscode. Alle
     * Parameter der Anfrage gehen verdeckt mit, ein POST prüft sie neu.
     *
     * @param array{title: string, intro: string, returnTo: string, codeLabel: string, help: string, allow: string, deny: string, error: string} $texts
     */
    public static function consentPage(array $texts, array $client, array $params, string $action): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $host = (string)parse_url((string)($params['redirect_uri'] ?? ''), PHP_URL_HOST);
        $hidden = '';
        foreach (self::AUTHORIZE_PARAMS as $key) {
            if (is_string($params[$key] ?? null)) {
                $hidden .= '<input type="hidden" name="' . $key . '" value="' . $e($params[$key]) . '">';
            }
        }
        $error = $texts['error'] === '' ? '' : '<p class="error">' . $e($texts['error']) . '</p>';
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Home Agent</title><style>'
            . 'body{font-family:system-ui,sans-serif;max-width:28rem;margin:2rem auto;padding:0 1rem;color:#222;background:#fff}'
            . 'h1{font-size:1.3rem}.host{font-weight:600}.error{color:#b00020}.help{color:#555;font-size:.9rem}'
            . 'input[type=text]{font-size:1.5rem;letter-spacing:.3rem;width:9rem;padding:.3rem}'
            . 'button{font-size:1rem;padding:.5rem 1.2rem;margin:1rem .5rem 0 0}'
            . '@media (prefers-color-scheme:dark){body{color:#eee;background:#121212}.help{color:#aaa}.error{color:#ff8a80}}'
            . '</style></head><body>'
            . '<h1>' . $e(sprintf($texts['title'], $client['client_name'] . ' (' . parse_url($client['client_id'], PHP_URL_HOST) . ')')) . '</h1>'
            . '<p>' . $e($texts['intro']) . '</p>'
            . '<p>' . str_replace($e('%s'), '<span class="host">' . $e($host) . '</span>', $e($texts['returnTo'])) . '</p>'
            . $error
            . '<form method="post" action="' . $e($action) . '">' . $hidden
            . '<label>' . $e($texts['codeLabel']) . '<br><input type="text" name="pairing_code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus></label>'
            . '<p class="help">' . $e($texts['help']) . '</p>'
            . '<button type="submit" name="decision" value="allow">' . $e($texts['allow']) . '</button>'
            . '<button type="submit" name="decision" value="deny">' . $e($texts['deny']) . '</button>'
            . '</form></body></html>';
    }

    /**
     * Antwort im Format der OAuth-Fehler (RFC 6749, Abschnitt 5.2).
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function error(int $status, string $error, string $description): array
    {
        return self::json($status, ['error' => $error, 'error_description' => $description]);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function json(int $status, array $content, array $headers = []): array
    {
        return [
            'status'  => $status,
            'headers' => $headers + McpProtocol::BASE_HEADERS,
            'body'    => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }
}
