<?php

declare(strict_types=1);

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

    /** Parameter der Autorisierungsanfrage, die die Einwilligungsseite verdeckt weitergibt. */
    private const AUTHORIZE_PARAMS = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];

    private const JSON_HEADERS =['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

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

    /** Wert für WWW-Authenticate im 401: Damit beginnt ein Client die Anmeldung. */
    public static function challenge(string $base): string
    {
        return 'Bearer resource_metadata="' . rtrim($base, '/') . '/.well-known/oauth-protected-resource", scope="' . self::SCOPE . '"';
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
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
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

    /** Liegt die IP-Adresse außerhalb privater und reservierter Bereiche? */
    public static function publicAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
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
        $text = static fn (string $key): string => is_string($params[$key] ?? null) ? $params[$key] : '';
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
            . '<h1>' . $e(sprintf($texts['title'], $client['client_name'])) . '</h1>'
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
            'headers' => $headers + self::JSON_HEADERS,
            'body'    => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }
}
