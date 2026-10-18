<?php

declare(strict_types=1);

/*
 * OAuth: eine verlorene Antwort des Token-Endpunkts darf die Kopplung nicht zerstören.
 *
 * Mitschnitt einer betreuten Anlage vom 16.10.2026 (Fixture oauth_betreut_2026-10-16_antwort_verloren.json):
 * Um 14:23:15 erneuerte claude.ai, das Modul rotierte das Erneuerungstoken, die Antwort kam aber nie an
 * (Connect-Tageslimit). Danach kamen acht Anfragen mit dem alten, abgelaufenen Zugriffstoken, die als
 * Fehlversuche zählten, und um 18:01 eine Erneuerung mit dem alten Erneuerungstoken. Das galt als
 * verbraucht und widerrief die ganze Kopplung; der Anwender musste neu koppeln.
 *
 * Echt sind Ablauf und Zeiten. Angenommen (das Protokoll nennt keine Tokens): welches Token jede Anfrage trug.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/OAuthServer.php';

$mitschnitt = fixture('oauth_betreut_2026-10-16_antwort_verloren.json');
date_default_timezone_set('Europe/Berlin');
$zeit = static function (string $uhr) use ($mitschnitt): int {
    foreach ($mitschnitt['lines'] as $zeile) {
        if (str_starts_with($zeile, '2026-10-16 ' . $uhr)) {
            return (int)strtotime(substr($zeile, 0, 19));
        }
    }
    throw new RuntimeException('Zeile fehlt im Mitschnitt: ' . $uhr);
};
$hat = static fn (string $name): bool => method_exists(OAuthServer::class, $name);

$endpunkt = 'https://example.ipmagic.de/hook/homeagent/12345';
$claudeId = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
$grant = ['client_id' => $claudeId, 'client_name' => 'Claude', 'access' => 'zugang-a', 'scope' => 'mcp offline_access', 'resource' => $endpunkt];
$erneuern = static fn (array $familien, string $token, string $neu, int $jetzt): array => OAuthServer::refresh(
    $familien,
    ['grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => $claudeId],
    $neu,
    $endpunkt,
    $jetzt,
    ['zugang-a']
);
$bindung = ['family' => 'fam-1', 'access' => 'zugang-a', 'client_id' => $claudeId, 'scope' => 'mcp offline_access'];
// Wie oauthAccess im Modul: ein gültiges Zugriffstoken bestätigt, dass die letzte Erneuerung angekommen ist
$benutzen = static function (array $familien, array $tokens, string $token, int $jetzt) use ($hat): array {
    $eintrag = OAuthServer::accessFor($tokens, $familien, 'Bearer ' . $token, $jetzt);
    if ($eintrag !== null && $hat('confirm')) {
        $familien = OAuthServer::confirm($familien, $eintrag, $jetzt) ?? $familien;
    }
    return [$eintrag !== null, $familien];
};

$t1214 = $zeit('12:14:39');
$t1423 = $zeit('14:23:15');
$t1801 = $zeit('18:01:23');

// 12:14:39 Erneuerung mit RT0, Antwort angekommen; 12:14:40 benutzt claude.ai das neue Zugriffstoken
$familien = OAuthServer::startFamily([], 'fam-1', $grant, 'RT0', $t1214 - 3700);
$e = $erneuern($familien, 'RT0', 'RT1', $t1214);
pruefe($e['error'] === null, '12:14 Erneuerung gilt');
$familien = $e['families'];
$tokens = OAuthServer::issueAccessToken([], 'AT1', $bindung, $t1214);
[$ok, $familien] = $benutzen($familien, $tokens, 'AT1', $zeit('12:14:40'));
pruefe($ok, '12:14:40 server/discover mit dem neuen Zugriffstoken');

// 14:23:15 Erneuerung mit RT1, rotiert auf RT2, neues Zugriffstoken AT2; die Antwort geht verloren
$e = $erneuern($familien, 'RT1', 'RT2', $t1423);
pruefe($e['error'] === null, '14:23:15 Erneuerung gilt');
$familien = $e['families'];
$tokens = OAuthServer::issueAccessToken($tokens, 'AT2', $bindung, $t1423);

// 14:23:18 bis :32 acht Anfragen mit AT1 (seit 13:14 abgelaufen): bekannt und abgelaufen, also kein Raten
foreach (['14:23:18', '14:23:19', '14:23:21', '14:23:22', '14:23:25', '14:23:26', '14:23:31', '14:23:32'] as $uhr) {
    pruefe(OAuthServer::isExpiredToken($tokens, 'Bearer AT1', $zeit($uhr)), $uhr . ' altes Zugriffstoken gilt als abgelaufen, nicht als falsch');
}

// 18:01:23 Erneuerung mit RT1: Die Rotation von 14:23 wurde nie benutzt, also gilt RT1 noch einmal
$e = $erneuern($familien, 'RT1', 'RT3', $t1801);
pruefe($e['error'] === null && isset($e['families']['fam-1']), '18:01 Erneuerung mit dem vorigen Erneuerungstoken gilt, die Kopplung bleibt: ' . json_encode($e['error']));
$nachher = $e['families'];
pruefe(($e['family']['access'] ?? '') === 'zugang-a', 'dieselbe Kopplung mit demselben Zugang');
$tokens = OAuthServer::issueAccessToken($tokens, 'AT3', $bindung, $t1801);
pruefe(OAuthServer::isExpiredToken($tokens, 'Bearer AT1', $zeit('18:01:24')), '18:01:24 AT1 ist weiter als abgelaufen bekannt (neun Stunden nach Ausgabe)');
pruefe(!str_contains((string)json_encode($nachher), 'RT1') && !str_contains((string)json_encode($nachher), 'RT3'), 'auch das vorige Erneuerungstoken nur als Hash');

// Das verlorene RT2 taucht doch noch auf: Das ist Wiederverwendung, die Kopplung fällt
$e = $erneuern($nachher, 'RT2', 'RT-X', $t1801 + 60);
pruefe($e['error'] === 'invalid_grant' && !isset($e['families']['fam-1']), 'das nie zugestellte RT2 nach der Gnade: Kopplung widerrufen');

// Danach benutzt claude.ai AT3: Die Erneuerung ist angekommen, RT1 gilt nicht mehr, auch RT3 nach erneuter Rotation
[$ok, $bestaetigt] = $benutzen($nachher, $tokens, 'AT3', $t1801 + 2);
pruefe($ok, 'AT3 gilt');
pruefe($hat('confirm') && OAuthServer::confirm($bestaetigt, ['family' => 'fam-1', 'expires' => $t1801 + 3600], $t1801 + 5) === null, 'bestätigt: weitere Anfragen schreiben nichts');
$e = $erneuern($bestaetigt, 'RT1', 'RT-Y', $t1801 + 10);
pruefe($e['error'] === 'invalid_grant' && !isset($e['families']['fam-1']), 'nach Benutzung des neuen Zugriffstokens widerruft RT1 die Kopplung wie bisher');
$e = $erneuern($bestaetigt, 'RT3', 'RT4', $t1801 + 3700);
pruefe($e['error'] === null, 'das jüngste Erneuerungstoken gilt nach der Bestätigung');
$e = $erneuern($e['families'], 'RT1', 'RT-Z', $t1801 + 3800);
pruefe($e['error'] === 'invalid_grant' && !isset($e['families']['fam-1']), 'ein älteres als das vorige widerruft immer');

// Normaler Ablauf: Erneuern, Zugriffstoken benutzen; danach widerruft das vorige Erneuerungstoken
$f = OAuthServer::startFamily([], 'fam-1', $grant, 'A0', $t1214);
$f = $erneuern($f, 'A0', 'A1', $t1214 + 3600)['families'];
$tok = OAuthServer::issueAccessToken([], 'B1', $bindung, $t1214 + 3600);
[, $f] = $benutzen($f, $tok, 'B1', $t1214 + 3601);
$e = $erneuern($f, 'A0', 'A-X', $t1214 + 3700);
pruefe($e['error'] === 'invalid_grant' && !isset($e['families']['fam-1']), 'angekommene Erneuerung: das vorige Token widerruft wie bisher (RFC 9700)');

// Die Gnade hält nicht ewig
$f = OAuthServer::startFamily([], 'fam-1', $grant, 'A0', $t1214);
$f = $erneuern($f, 'A0', 'A1', $t1214 + 3600)['families'];
$frist = defined(OAuthServer::class . '::PREVIOUS_SECONDS') ? OAuthServer::PREVIOUS_SECONDS : 0;
pruefe($frist > 4 * 3600, 'Frist deckt den Mitschnitt (3 h 38 min): ' . $frist);
pruefe($frist > 0 && $erneuern($f, 'A0', 'A2', $t1214 + 3600 + $frist)['error'] === null, 'innerhalb der Frist gilt das vorige');
pruefe($frist > 0 && $erneuern($f, 'A0', 'A2', $t1214 + 3600 + $frist + 1)['error'] === 'invalid_grant', 'nach der Frist widerruft es');
// Die Frist zählt ab der ersten unbestätigten Erneuerung, nicht ab der letzten (Review 18.10.2026, N1): Wer das vorige
// Token alle sechs Tage einlöst, ohne je ein Zugriffstoken zu benutzen, verlängerte sie vorher beliebig.
$schritt = 6 * 86400;
$e = $erneuern($f, 'A0', 'A2', $t1214 + 3600 + $schritt);
pruefe($e['error'] === null, 'nach sechs Tagen gilt das vorige noch');
$e = $erneuern($e['families'], 'A0', 'A3', $t1214 + 3600 + 2 * $schritt);
pruefe($e['error'] === 'invalid_grant' && !isset($e['families']['fam-1']), 'nach zwölf Tagen nicht mehr, auch wenn es nach sechs Tagen noch einmal galt: ' . json_encode($e['error']));

// Abgelaufene Zugriffstokens bleiben einen Tag bekannt, danach fallen sie heraus
$tok = OAuthServer::issueAccessToken([], 'C1', $bindung, $t1214);
$tok = OAuthServer::issueAccessToken($tok, 'C2', $bindung, $t1214 + 3600 + 86400 - 1);
pruefe(OAuthServer::isExpiredToken($tok, 'Bearer C1', $t1214 + 3600 + 86400 - 1), 'abgelaufen und unter einem Tag: noch bekannt');
$tok = OAuthServer::issueAccessToken($tok, 'C3', $bindung, $t1214 + 3600 + 86400);
pruefe(!OAuthServer::isExpiredToken($tok, 'Bearer C1', $t1214 + 3600 + 86400) && count($tok) === 2, 'nach einem Tag fällt es heraus');

ende('OAuth verlorene Antwort');
