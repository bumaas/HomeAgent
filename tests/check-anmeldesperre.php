<?php

declare(strict_types=1);

/*
 * Sperre nach Fehlversuchen je Absender (Backlog B60, 12.10.2026). Über Symcon Connect kommt jede Anfrage von
 * 127.0.0.1; bisher sperrten 20 falsche Tokens von irgendwem den Endpunkt für alle, auch für den eigenen
 * Assistenten. Jetzt zählt jeder Absender für sich. Gegen Raten von vielen Adressen bleibt eine Obergrenze für
 * alle zusammen. Die Uhrzeit kommt von außen, die Regeln sind so ohne Symcon prüfbar.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/SignInGuard.php';

// Vor dem Umbau gibt es Konstanten und sender() nicht; dann rote Prüfungen statt Abbruch.
$maxAlle = defined('SignInGuard::MAX_FAILURES_ALL') ? SignInGuard::MAX_FAILURES_ALL : 100;
$maxAbsender = defined('SignInGuard::MAX_SENDERS') ? SignInGuard::MAX_SENDERS : 100;
$absender = static fn (array $server): string => method_exists(SignInGuard::class, 'sender') ? SignInGuard::sender($server) : 'FEHLT';

$ereignisse = static fn (array $schritt): array => array_column($schritt['events'], 0);
$fehlversuche = static function (array $state, int $anzahl, int $ab, string $absender): array {
    $schritt = ['state' => $state, 'events' => []];
    $alle = [];
    for ($i = 0; $i < $anzahl; $i++) {
        $schritt = SignInGuard::failure($schritt['state'], $ab + intdiv($i, 10), $absender);
        $alle = array_merge($alle, $schritt['events']);
    }
    return ['state' => $schritt['state'], 'events' => $alle];
};

/* --- Je Absender --------------------------------------------------------------------------------- */

$s = $fehlversuche([], 20, 1000, '203.0.113.9');
pruefe(SignInGuard::blockedFor($s['state'], 1002, '203.0.113.9') === 59, 'nach 20 Fehlversuchen (der letzte bei 1001) ist der Absender 60 s gesperrt: ' . SignInGuard::blockedFor($s['state'], 1002, '203.0.113.9'));
pruefe(SignInGuard::blockedFor($s['state'], 1002, '160.79.106.181') === 0, 'ein anderer Absender kommt weiter durch');
pruefe(in_array(['blocked', '203.0.113.9'], $s['events'], true), 'das Ereignis nennt den gesperrten Absender: ' . json_encode($s['events']));
pruefe(!in_array('blocked_all', $ereignisse($s), true), 'der Endpunkt bleibt für alle offen');
pruefe(SignInGuard::blockedFor($s['state'], 1061, '203.0.113.9') === 0, 'nach 60 s ist auch der Absender wieder offen');

$s = $fehlversuche([], 19, 1000, '203.0.113.9');
$s = $fehlversuche($s['state'], 19, 1000, '198.51.100.4');
pruefe(SignInGuard::blockedFor($s['state'], 1002, '203.0.113.9') === 0 && SignInGuard::blockedFor($s['state'], 1002, '198.51.100.4') === 0, 'je 19 Fehlversuche von zwei Absendern sperren keinen');

/* --- Obergrenze für alle ------------------------------------------------------------------------- */

$s = ['state' => [], 'events' => []];
$alle = [];
for ($i = 0; $i < $maxAlle; $i++) {
    $s = SignInGuard::failure($s['state'], 2000, '192.0.2.' . $i);
    $alle = array_merge($alle, $ereignisse($s));
}
pruefe($maxAlle === 100 && defined('SignInGuard::MAX_FAILURES_ALL'), 'Obergrenze für alle: 100 Fehlversuche in der Minute');
pruefe(in_array('blocked_all', $alle, true) && SignInGuard::blockedFor($s['state'], 2001, '160.79.106.181') === 59, 'Raten von vielen Adressen sperrt den Endpunkt für alle: ' . SignInGuard::blockedFor($s['state'], 2001, '160.79.106.181'));
pruefe(count($s['state']['senders'] ?? []) <= $maxAbsender && isset($s['state']['senders']), 'gemerkt werden höchstens ' . $maxAbsender . ' Absender, nicht ' . count($s['state']['senders'] ?? []));

/* --- Meldungen fürs Log: höchstens zweimal je Minute ------------------------------------------- */

$s = SignInGuard::failure([], 3000, '203.0.113.9');
pruefe($ereignisse($s) === ['first'], 'der erste Fehlversuch einer Minute wird gemeldet: ' . json_encode($s['events']));
$s = SignInGuard::failure($s['state'], 3001, '198.51.100.4');
pruefe($s['events'] === [], 'weitere Fehlversuche derselben Minute bleiben still, auch von anderen Absendern');
$s = SignInGuard::failure($s['state'], 3070, '203.0.113.9');
pruefe($s['events'] === [['summary', 2, 3000], ['first']], 'nach der Minute die Zusammenfassung aller: ' . json_encode($s['events']));

/* --- Absender ermitteln -------------------------------------------------------------------------- */

pruefe($absender(['REMOTE_ADDR' => '192.168.178.109']) === '192.168.178.109', 'im Heimnetz die Adresse der Verbindung');
pruefe($absender(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '160.79.106.181']) === '160.79.106.181', 'über Connect der Absender aus X-Forwarded-For (Mitschnitt 11.10.2026)');
pruefe($absender(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.9.9.9, 203.0.113.9']) === '203.0.113.9', 'bei mehreren Einträgen der letzte, den der Proxy anhängt; den ersten kann der Absender selbst setzen');
pruefe($absender(['REMOTE_ADDR' => '192.168.178.50', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']) === '192.168.178.50', 'X-Forwarded-For zählt nur von 127.0.0.1, sonst könnte jeder im Heimnetz seinen Absender wählen');
pruefe($absender(['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::7']) === '2001:db8::7', 'auch über ::1');
pruefe($absender([]) === '?', 'ohne Angabe ein Fragezeichen');

ende('Anmeldesperre');
