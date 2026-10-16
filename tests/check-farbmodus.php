<?php

declare(strict_types=1);

/*
 * Farbmodus und Farbbedienung auf der Karte (Burkhard 16.10.2026, Hardcopy aus der Claude-App: „Gibt es noch keine
 * Möglichkeit, Farbe einzustellen?“). Die Karte zeigte „an (2732 K, gelb)“ bei einem weißen Licht und „an (4739 K,
 * rot)“ bei einem roten; genannt wurden immer Farbtemperatur und Farbe, auch der Wert, der gerade nicht gilt.
 *
 * Neben Farbe oder Farbtemperatur liegt oft eine Variable mit dem Farbmodus (Zigbee2MQTT color_mode, HomeAssistant-Modul
 * light_color_mode, Matter ColorControl_ColorMode). Ist sie da, nennen Zustand und Karte nur den gültigen Wert. Die Karte
 * bekommt einen Regler für die Farbtemperatur und Farbtupfer aus DeviceIndex::COLORS.
 * Mitschnitt betreut_2026-10-16_farbmodus.json.
 */

require __DIR__ . '/pruefen.php';
require_once dirname(__DIR__) . '/libs/DeviceIndex.php';
require_once __DIR__ . '/FakeBackend.php';

$fx = fixture('betreut_2026-10-16_farbmodus.json');
$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];

/** @return array{0: DeviceIndex, 1: FakeBackend} */
function anlage(array $mitschnitt, ?array $uebersetzungen = null, array $aendern = []): array
{
    $variablen = $mitschnitt['variablen'];
    foreach ($aendern as $id => $felder) {
        $variablen[(string)$id] = $felder === null ? null : array_merge($variablen[(string)$id], $felder);
        if ($felder === null) {
            unset($variablen[(string)$id]);
        }
    }
    $backend = new FakeBackend($variablen, $uebersetzungen);
    return [new DeviceIndex($mitschnitt['geraete'], $backend), $backend];
}

function rufe(callable $aufruf): array
{
    try {
        return $aufruf();
    } catch (Throwable $e) {
        return ['text' => 'FEHLT: ' . $e->getMessage(), 'isError' => true];
    }
}

function karte(array $r, string $name): array
{
    foreach ($r['structuredContent']['cards'] ?? [] as $k) {
        if (($k['name'] ?? null) === $name) {
            return $k;
        }
    }
    return [];
}

$zeile = static function (array $r, string $name): string {
    foreach (explode("\n", $r['text'] ?? '') as $z) {
        if (str_contains($z, '| ' . $name . ' |')) {
            return $z;
        }
    }
    return '';
};

/* --- Schreibweisen des Farbmodus ------------------------------------------------------------------ */

$hat = method_exists(DeviceIndex::class, 'colorModeKind');
pruefe($hat, 'DeviceIndex::colorModeKind gibt es');
foreach ($fx['farbmodus_andere']['werte'] as [$ident, $wert, $erwartet]) {
    $ist = $hat ? DeviceIndex::colorModeKind($ident, $wert) : 'fehlt';
    pruefe($ist === $erwartet, 'Farbmodus ' . $ident . ' = ' . json_encode($wert) . ' → ' . json_encode($erwartet) . ', ist ' . json_encode($ist));
}

/* --- Zustandstext: nur der gültige Wert ------------------------------------------------------------ */

[$idx] = anlage($fx);
$r = rufe(static fn () => $idx->getState(['room' => 'Esszimmer']));
$down = $zeile($r, 'Esstisch Downlight');
$up = $zeile($r, 'Esstisch Uplight');
pruefe(str_contains($down, '2732 K') && !str_contains($down, 'yellow'), 'weißes Licht (COLOR_TEMP): nur die Farbtemperatur: ' . $down);
pruefe(str_contains($up, 'red') && !str_contains($up, '4739 K'), 'farbiges Licht (XY): nur die Farbe: ' . $up);

// Ohne Farbmodus bleibt es wie bisher: beides
[$idx] = anlage($fx, null, [45386 => ['farbmodus' => null], 59287 => ['farbmodus' => null]]);
$down = $zeile(rufe(static fn () => $idx->getState(['room' => 'Esszimmer'])), 'Esstisch Downlight');
pruefe(str_contains($down, '2732 K') && str_contains($down, 'yellow'), 'ohne Farbmodus beide Angaben wie bisher: ' . $down);

// Ein Farbmodus, den das Modul nicht kennt (HomeAssistant „brightness“): beides
[$idx] = anlage($fx, null, [45386 => ['farbmodus' => ['ident' => 'light_color_mode', 'wert' => 'brightness']], 59287 => ['farbmodus' => ['ident' => 'light_color_mode', 'wert' => 'brightness']]]);
$down = $zeile(rufe(static fn () => $idx->getState(['room' => 'Esszimmer'])), 'Esstisch Downlight');
pruefe(str_contains($down, '2732 K') && str_contains($down, 'yellow'), 'unbekannter Farbmodus: beide Angaben: ' . $down);

/* --- Karte ------------------------------------------------------------------------------------------ */

[$idx] = anlage($fx, $de);
$r = rufe(static fn () => $idx->cards(['room' => 'Esszimmer'], false));
$kd = karte($r, 'Esstisch Downlight');
$ku = karte($r, 'Esstisch Uplight');
pruefe(($kd['status'] ?? null) === 'an (2732 K)', 'Karte Downlight: „an (2732 K)“: ' . json_encode($kd['status'] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(($ku['status'] ?? null) === 'an (rot)', 'Karte Uplight: „an (rot)“: ' . json_encode($ku['status'] ?? null, JSON_UNESCAPED_UNICODE));

$ct = $kd['controls']['colortemp'] ?? null;
pruefe(is_array($ct) && $ct['value'] === 2732 && $ct['min'] === 2000 && $ct['max'] === 6500 && $ct['active'] === true, 'Regler Farbtemperatur: 2732 K, Bereich auf 2000 bis 6500 K gekappt, aktiv: ' . json_encode($ct));
pruefe(($ct['label'] ?? null) === 'Farbtemperatur', 'Beschriftung des Reglers übersetzt: ' . json_encode($ct['label'] ?? null, JSON_UNESCAPED_UNICODE));
$ctu = $ku['controls']['colortemp'] ?? null;
pruefe(is_array($ctu) && $ctu['value'] === 4739 && $ctu['active'] === false, 'Uplight im Farbmodus: Regler da, aber nicht aktiv: ' . json_encode($ctu));

$farbe = $kd['controls']['color'] ?? null;
pruefe(is_array($farbe) && array_column($farbe['entries'] ?? [], 'name') === array_keys(DeviceIndex::COLORS), 'Farbtupfer aus der festen Liste: ' . json_encode(array_column($farbe['entries'] ?? [], 'name')));
pruefe(($farbe['entries'][0]['label'] ?? null) === 'rot' && ($farbe['entries'][0]['rgb'] ?? null) === '#ff0000', 'Tupfer mit übersetztem Namen und Farbwert: ' . json_encode($farbe['entries'][0] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(array_key_exists('value', $farbe ?? []) && $farbe['value'] === null, 'weißes Licht: kein Tupfer gewählt (die Farbe ist nur umgerechnet)');
pruefe(($ku['controls']['color']['value'] ?? null) === 'red', 'rotes Licht: Tupfer „red“ gewählt');
pruefe(($farbe['label'] ?? null) === 'Farbe', 'Beschriftung der Tupfer übersetzt');
pruefe(($farbe['active'] ?? null) === false && ($ku['controls']['color']['active'] ?? null) === true, 'Tupfer blass beim weißen Licht, aktiv beim farbigen');

// Nur-Lese-Zugang: keine Bedienung
$r = rufe(static fn () => $idx->cards(['room' => 'Esszimmer'], true));
pruefe(!isset(karte($r, 'Esstisch Downlight')['controls']['colortemp']) && !isset(karte($r, 'Esstisch Downlight')['controls']['color']), 'Nur-Lese-Zugang: weder Regler noch Tupfer');

/* --- Bedienen über control_device ------------------------------------------------------------------- */

[$idx, $backend] = anlage($fx);
$r = rufe(static fn () => $idx->controlDevice(['device' => $kd['key'] ?? '', 'color_temperature' => 3000]));
pruefe(in_array([45386, 3000], $backend->requests, true), 'Regler schickt color_temperature 3000 an die Kelvin-Variable: ' . json_encode($backend->requests));
pruefe(($r['structuredContent']['cards'][0]['controls']['colortemp']['value'] ?? null) === 3000, 'zurück kommt die neue Karte mit 3000 K');

[$idx, $backend] = anlage($fx);
$r = rufe(static fn () => $idx->controlDevice(['device' => $ku['key'] ?? '', 'color' => 'blue']));
pruefe(in_array([22826, 0x0000FF], $backend->requests, true), 'Tupfer schickt color blue als 0x0000FF: ' . json_encode($backend->requests));

ende('Farbmodus');
