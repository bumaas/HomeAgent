<?php

declare(strict_types=1);

/* Minimaler Testrunner (kein PHPUnit), gemeinsam für alle Tests dieses Repos. */

set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false;
    }
    if ($nr & (E_USER_ERROR | E_USER_WARNING | E_WARNING | E_NOTICE)) {
        throw new ErrorException($text, 0, $nr, $datei, $zeile);
    }
    return false;
});

$pruefungen = 0;
$fehler = [];

function pruefe(bool $ok, string $text): void
{
    global $pruefungen, $fehler;
    $pruefungen++;
    if (!$ok) {
        $fehler[] = $text;
    }
}

/** Beendet den Test mit Zusammenfassung und Exit-Code 1 bei Fehlern. */
function ende(string $titel): never
{
    global $pruefungen, $fehler;
    foreach ($fehler as $f) {
        echo 'ROT  ', $f, "\n";
    }
    echo $titel, ': ', $pruefungen - count($fehler), ' von ', $pruefungen, ' Prüfungen grün', "\n";
    exit($fehler === [] ? 0 : 1);
}

/** Lädt eine Fixture-Datei aus tests/fixtures. */
function fixture(string $datei): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/fixtures/' . $datei), true, 512, JSON_THROW_ON_ERROR);
}
