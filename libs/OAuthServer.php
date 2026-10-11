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

    private const JSON_HEADERS = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

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
