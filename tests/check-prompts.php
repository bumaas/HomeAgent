<?php

declare(strict_types=1);

/*
 * Vorlagen (MCP-Prompts, Backlog B63, Burkhard 12.10.2026): „Haus verlassen“, „Gute Nacht“, „Energiebericht“. Der
 * Anwender wählt sie im Client aus, statt eine lange Frage zu tippen. Angeboten wird nur, was die Freigabeliste hergibt
 * (ohne Fenster kein Fensterteil, ohne Zähler kein Energiebericht). Ein Nur-Lese-Zugang bekommt keine Frage nach dem
 * Ausschalten.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/PromptCatalog.php';

$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
$englisch = static fn (string $text): string => $text;
$deutsch = static fn (string $text): string => $de[$text] ?? $text;

$katalog = static fn (array $typen, bool $energie, bool $nurLesen, callable $t): PromptCatalog => new PromptCatalog($typen, $energie, $nurLesen, $t);

/* --- Liste ------------------------------------------------------------------------------------- */

$k = $katalog(['light', 'window', 'door', 'media', 'temperature'], true, false, $englisch);
$liste = $k->list();
pruefe(array_column($liste, 'name') === ['leave_home', 'good_night', 'energy_report'], 'drei Vorlagen: ' . json_encode(array_column($liste, 'name')));
pruefe(($liste[0]['title'] ?? null) === 'Leaving home' && is_string($liste[0]['description'] ?? null), 'mit Titel und Beschreibung: ' . json_encode($liste[0]));
pruefe(!isset($liste[0]['arguments']), 'ohne Argumente');
pruefe(array_column($katalog(['light'], false, false, $englisch)->list(), 'name') === ['leave_home', 'good_night'], 'ohne Zähler kein Energiebericht');
pruefe(array_column($katalog(['temperature'], true, false, $englisch)->list(), 'name') === ['energy_report'], 'ohne Fenster, Türen, Licht und Mediaplayer nur der Energiebericht');
pruefe($katalog(['temperature'], false, false, $englisch)->list() === [], 'ohne Passendes keine Vorlage');

/* --- Inhalt ------------------------------------------------------------------------------------- */

$haus = $k->get('leave_home');
$text = (string)($haus['messages'][0]['content']['text'] ?? '');
pruefe(($haus['messages'][0]['role'] ?? null) === 'user' && ($haus['messages'][0]['content']['type'] ?? null) === 'text', 'eine Nachricht des Anwenders als Text');
pruefe(str_contains($text, 'type=window') && str_contains($text, 'type=door') && str_contains($text, 'type=light') && str_contains($text, 'type=media') && str_contains($text, 'active_only=true'), 'prüft Fenster, Türen, Licht und Mediaplayer mit active_only: ' . $text);
pruefe(str_contains($text, 'Do not switch anything without asking me.'), 'ohne Rückfrage wird nichts geschaltet');
$nurLicht = (string)($katalog(['light'], false, false, $englisch)->get('leave_home')['messages'][0]['content']['text'] ?? '');
pruefe(str_contains($nurLicht, 'type=light') && !str_contains($nurLicht, 'type=window'), 'nur, was es gibt: ' . $nurLicht);
$nacht = (string)($k->get('good_night')['messages'][0]['content']['text'] ?? '');
pruefe(str_contains($nacht, 'ask whether you should switch it off or close it'), 'Gute Nacht fragt nach dem Ausschalten: ' . $nacht);
$nachtLesen = (string)($katalog(['light'], false, true, $englisch)->get('good_night')['messages'][0]['content']['text'] ?? '');
pruefe(!str_contains($nachtLesen, 'ask whether') && !str_contains($nachtLesen, 'without asking'), 'ein Nur-Lese-Zugang bekommt keine Frage nach dem Schalten: ' . $nachtLesen);
$energie = (string)($k->get('energy_report')['messages'][0]['content']['text'] ?? '');
pruefe(str_contains($energie, 'period=yesterday'), 'Energiebericht über period=yesterday: ' . $energie);
pruefe($k->get('gibt_es_nicht') === null && $katalog(['light'], false, false, $englisch)->get('energy_report') === null, 'unbekannte oder nicht angebotene Vorlage: null');
pruefe(is_string($k->get('leave_home')['description'] ?? null), 'mit Beschreibung');

/* --- Deutsch ------------------------------------------------------------------------------------- */

$kd = $katalog(['light', 'window'], true, false, $deutsch);
pruefe(($kd->list()[0]['title'] ?? null) === 'Haus verlassen' && ($kd->list()[1]['title'] ?? null) === 'Gute Nacht' && ($kd->list()[2]['title'] ?? null) === 'Energiebericht', 'Titel deutsch: ' . json_encode(array_column($kd->list(), 'title'), JSON_UNESCAPED_UNICODE));
$textDe = (string)($kd->get('leave_home')['messages'][0]['content']['text'] ?? '');
pruefe(str_starts_with($textDe, 'Ich verlasse das Haus.'), 'Text deutsch: ' . $textDe);

ende('Vorlagen');
