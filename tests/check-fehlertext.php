<?php

declare(strict_types=1);

/*
 * Fehlertext einer fehlgeschlagenen Aktion: Wirft ein fremdes Modul in RequestAction eine Exception, kommt sie als
 * „Fatal error: Uncaught Exception: … in <Datei>:<Zeile>“ mit Stacktrace an. Der Assistent bekam das bis 300 Zeichen
 * in die Zeile „failed: …“ (13.11.2026, Sonos mit leerer Warteschlange): Pfade der Anlage und Quelltextstellen, mit
 * denen er nichts anfangen kann. DeviceIndex::errorText() lässt nur die Meldung der Exception stehen; das Log der
 * Instanz behält den ganzen Text.
 * Mitschnitt nuc_2026-11-13_fehlertexte.json. Angenommen und nicht gemessen sind die Fälle unter „Angenommen“.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';

$mitschnitt = fixture('nuc_2026-11-13_fehlertexte.json');
$kurz = static fn (string $text): string => method_exists(DeviceIndex::class, 'errorText') ? DeviceIndex::errorText($text) : $text;
$meldung = 'Error during Soap Call: UPnPError s:Client 701 (ERROR_AV_UPNP_AVT_INVALID_TRANSITION)';

/* --- Gemessen ------------------------------------------------------------------------------------- */

pruefe($kurz($mitschnitt['sonos_701']) === $meldung, 'aus dem rohen Text des Sonos-Moduls bleibt die Meldung der Exception: ' . json_encode($kurz($mitschnitt['sonos_701'])));
pruefe($kurz($mitschnitt['sonos_701_log']) === $meldung, 'ebenso aus dem einzeiligen, gekürzten Text: ' . json_encode($kurz($mitschnitt['sonos_701_log'])));
foreach (['sonos_701', 'sonos_701_log'] as $fall) {
    $text = $kurz($mitschnitt[$fall]);
    pruefe(!str_contains($text, 'ProgramData') && !str_contains($text, 'Stack trace') && !str_contains($text, 'Fatal error'), $fall . ': kein Pfad, kein Stacktrace, kein „Fatal error“: ' . json_encode($text));
}
foreach ($mitschnitt['schlicht'] as $text) {
    pruefe($kurz($text) === $text, 'ein Text ohne PHP-Rahmen bleibt, wie er ist: ' . json_encode($kurz($text)));
}

/* --- Angenommen ----------------------------------------------------------------------------------- */

// Unter Linux hat der Pfad Schrägstriche (SymBox); der Rahmen von PHP ist derselbe.
$linux = "\nFatal error: Uncaught Exception: " . $meldung . " in /var/lib/symcon/modules/Sonos/libs/sonosAccess.php:812\nStack trace:\n#0 /var/lib/symcon/modules/Sonos/libs/sonosAccess.php(476): SonosAccess->processSoapCall()";
pruefe($kurz($linux) === $meldung, 'Pfad mit Schrägstrichen: ' . json_encode($kurz($linux)));
// Eine Meldung, die selbst „ in “ enthält, wird nicht mittendrin abgeschnitten.
$mitIn = "Fatal error: Uncaught RuntimeException: Timeout in group 3 in C:\\ProgramData\\Symcon\\modules\\X\\module.php:17\nStack trace:\n#0 {main}";
pruefe($kurz($mitIn) === 'Timeout in group 3', 'es zählt das letzte „ in “ vor der Zeilennummer: ' . json_encode($kurz($mitIn)));
// Klasse mit Namensraum
$namensraum = "PHP Fatal error:  Uncaught Foo\\Bar\\DeviceException: Gerät antwortet nicht in /x/y.php:5\nStack trace:\n#0 {main}";
pruefe($kurz($namensraum) === 'Gerät antwortet nicht', 'Klasse mit Namensraum und Vorsatz „PHP“: ' . json_encode($kurz($namensraum)));
// Ist der Text vor der Zeilennummer abgeschnitten, fällt wenigstens der Vorsatz weg.
$abgeschnitten = 'Fatal error: Uncaught Exception: Eine sehr lange Meldung ohne Ende…';
pruefe($kurz($abgeschnitten) === 'Eine sehr lange Meldung ohne Ende…', 'ohne Dateiangabe nur der Vorsatz weg: ' . json_encode($kurz($abgeschnitten)));
// Ungültiges UTF-8 (CP850 eines Fremdmoduls) darf nichts verschlucken.
$kaputt = "Ger\x81t meldet einen Fehler";
pruefe($kurz($kaputt) === $kaputt, 'ein Text mit ungültigem Byte bleibt unverändert');
pruefe($kurz('') === '', 'leer bleibt leer');

ende('Fehlertext');
