<?php

declare(strict_types=1);

/*
 * Das Modul im offiziellen Kernel-Stub (Submodul tests/stubs): Hook-Anmeldung in Create,
 * Status je nach Konfiguration, Klartext im Log bei fehlerhafter Freigabeliste und die
 * Skriptfunktion QueryState mit echten Stub-Variablen.
 */

require __DIR__ . '/pruefen.php';
require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/HomeAgent/module.php';

final class HomeAgentHarness extends HomeAgent
{
    public const MODULE_ID = '{89E40AE0-BB2A-40B8-A16F-C9C5FFBFC09F}';

    /** @var list<string> */
    public static array $hooks = [];

    /** Wie oft die Listen ganz geprüft wurden (findProblems). */
    public static int $problemChecks = 0;

    protected function findProblems(): array
    {
        self::$problemChecks++;
        return parent::findProblems();
    }

    protected function RegisterHook(string $HookPath): bool
    {
        self::$hooks[] = $HookPath; // der Stub hat hier nur einen leeren Rumpf
        return true;
    }

    public function logs(): array
    {
        return IPS\LogServer::getLogMessages((string)$this->InstanceID);
    }

    /** @var list<array{0: string, 1: string, 2: mixed}> Änderungen am geöffneten Dialog */
    public array $formUpdates = [];

    protected function UpdateFormField(string $Field, string $Parameter, mixed $Value): bool
    {
        $this->formUpdates[] = [$Field, $Parameter, $Value];
        return true;
    }

    /** Zählt die Übersetzungen; jede kostet unter Symcon Zeit, ein Maß für die Arbeit je Anfrage. */
    public static int $translations = 0;

    public function Translate(string $Text): string
    {
        self::$translations++;
        return parent::Translate($Text);
    }

    /** Körper der nächsten Hook-Anfrage; php://input ist in der CLI leer. */
    public static string $body = '';

    /** @var int höchstens so viele Bytes liest das Modul; 0 = ohne Grenze */
    public static int $bodyLimit = 0;

    protected function requestBody(int $limit = 0): string
    {
        self::$bodyLimit = $limit;
        return $limit > 0 ? substr(self::$body, 0, $limit) : self::$body;
    }

    /** @var array<string, string> Client-Dokumente (CIMD) nach Adresse; ohne Netz im Test. */
    public static array $documents = [];

    /** @var list<string> abgerufene Client-Dokumente */
    public static array $fetched = [];

    protected function fetchClientDocument(string $url): ?string
    {
        self::$fetched[] = $url;
        return self::$documents[$url] ?? null;
    }

    /** Connect-Adresse der Anlage; der Kernel-Stub kennt CC_GetConnectURL nicht. */
    public static string $connect = '';

    public static int $connectCalls = 0;

    /** IP der Anlage im Heimnetz, wie sie Sys_GetNetworkInfo am nuc liefert. */
    public static string $lanIp = '192.168.178.86';

    /** Gibt es eine Connect-Instanz, auch wenn sie gerade nicht verbunden ist? */
    public static bool $connectConfigured = false;

    protected function connectConfigured(): bool
    {
        return self::$connectConfigured;
    }

    protected function localAddress(): string
    {
        return self::$lanIp;
    }

    /** Rechnername der Anlage, wie gethostname() ihn am nuc liefert. */
    protected function hostName(): string
    {
        return 'nuc';
    }

    protected function connectUrl(): string
    {
        self::$connectCalls++;
        return self::$connect;
    }

    /** @var ?array{status: int, headers: array<string, string>, body: string} letzte Antwort des Hooks */
    public ?array $response = null;

    protected function send(array $response): void
    {
        $this->response = $response;
        echo $response['body'];
    }

    /** Das Backend, mit dem die Werkzeuge arbeiten (Fahrtmerker im Buffer „Travels“). */
    public function testBackend(): SymconDeviceBackend
    {
        return \Closure::bind(fn (): SymconDeviceBackend => $this->backend(), $this, HomeAgent::class)();
    }
}

/** Ergebnis des Aufrufs; null, solange es die Funktion nicht gibt (roter Lauf vor dem Umbau). */
function oderNull(callable $aufruf): mixed
{
    try {
        return $aufruf();
    } catch (Error) {
        return null;
    }
}

function neueInstanz(): HomeAgentHarness
{
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => HomeAgentHarness::MODULE_ID,
        'ModuleName' => 'HomeAgent',
        'ModuleType' => 3,
        'Class'      => HomeAgentHarness::class,
    ]);
    return IPS\InstanceManager::getInstanceInterface($id);
}

function variable(int $typ, mixed $wert): int
{
    $id = IPS_CreateVariable($typ);
    SetValue($id, $wert);
    return $id;
}

/** Alle Formularelemente, auch die in aufklappbaren Bereichen (ExpansionPanel) und Zeilen (RowLayout). */
function flach(array $elemente): array
{
    $alle = [];
    foreach ($elemente as $e) {
        $alle[] = $e;
        if (is_array($e['items'] ?? null)) {
            $alle = array_merge($alle, flach($e['items']));
        }
    }
    return $alle;
}

IPS\Kernel::reset();
$m = neueInstanz();
$iid = (int)(new ReflectionProperty(IPSModuleStrict::class, 'InstanceID'))->getValue($m);

pruefe(HomeAgentHarness::$hooks === ['homeagent/' . $iid], 'Create meldet den Hook homeagent/<InstanzID> an: ' . json_encode(HomeAgentHarness::$hooks));
pruefe($m->GetEndpoint() === '/hook/homeagent/' . $iid, 'GetEndpoint nennt den vollen Pfad');
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === 104, 'ohne Token Status 104, nicht aktiv: ' . IPS_GetInstance($iid)['InstanceStatus']);
// Der Text zu 104 sagt, wo und was zu tun ist; das Token erzeugt das Modul beim Übernehmen selbst (Burkhard 14.10.2026:
// „Legen Sie einen Zugang mit Token an“ war für Anwender nicht deutlich genug)
$status104 = array_values(array_filter(json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/form.json'), true)['status'], static fn (array $s): bool => $s['code'] === 104))[0]['caption'] ?? '';
$deutsch = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true)['translations']['de'][$status104] ?? '';
pruefe($deutsch === 'Noch kein Zugang: Fügen Sie unter „Endpunkt und Zugänge“ einen Zugang hinzu und klicken Sie auf „Übernehmen“. Das Token erzeugt das Modul selbst.', 'Status 104 auf Deutsch: ' . $deutsch);
pruefe(str_contains($m->RunSelfTest(), $status104), 'der Selbsttest sagt dasselbe: ' . $m->RunSelfTest());
// Die übrigen Texte aus Sicht des Anwenders (Burkhard 14.10.2026): Status 201 ohne „das Log“, Home Assistant in der
// richtigen Reihenfolge, Schritte der Claude-App mit „Sie“ und den Menünamen der App, „Umgekehrt“ ohne „true“
$deutschVon = static fn (string $englisch): string => json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true)['translations']['de'][$englisch] ?? '';
$status201 = array_values(array_filter(json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/form.json'), true)['status'], static fn (array $s): bool => $s['code'] === 201))[0]['caption'] ?? '';
pruefe($deutschVon($status201) === 'Die Raumliste ist fehlerhaft, oder kein Zugang ist fehlerfrei. Die roten Zeilen im Formular nennen den Fehler.', 'Status 201 auf Deutsch: ' . $deutschVon($status201));
pruefe($deutschVon('1. In the Claude app open "Connectors" › "Add connector", add a custom connector with this address and sign in: %s') === '1. Öffnen Sie in der Claude-App „Konnektoren“ › „Connector hinzufügen“, legen Sie einen eigenen Connector mit dieser Adresse an und melden Sie sich an: %s'
    && $deutschVon('2. Create a pairing code for the access') === '2. Erzeugen Sie einen Kopplungscode für den Zugang', 'Schritte der Claude-App mit „Sie“ und den Menünamen der App');
pruefe(str_starts_with($deutschVon('1. Tick "Token also in the address" for the access and click "Apply". 2. In Home Assistant add the integration "Model Context Protocol" and enter the address that "Show address" gives.'), '1. Setzen Sie beim Zugang „Token auch in der Adresse“'), 'Home Assistant: zuerst der Haken, dann die Integration');
// ohne den unsichtbaren Hinweis für Skripte, dort beschreibt „true“ die Property
$alleDeutsch = implode("\n", array_filter(json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true)['translations']['de'], static fn (string $text): bool => !str_starts_with($text, 'Hinweis für Skripte')));
pruefe(!str_contains($alleDeutsch, '(true =') && !str_contains($alleDeutsch, 'Zähler, die als Zähler archiviert'), 'kein „true“ in den Beschriftungen, keine doppelte Erklärung der Zähler');

$licht = variable(VARIABLETYPE_BOOLEAN, true);
$temperatur = variable(VARIABLETYPE_FLOAT, 21.5);
IPS_SetProperty($iid, 'Accesses', json_encode([['Name' => 'Test', 'Token' => 'abc-token-0123456789abcdef', 'DefaultRoom' => '', 'ReadOnly' => false, 'InPath' => false]]));
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Deckenlicht', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $licht, 'LevelVariableID' => 1, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Temperatur', 'Aliases' => '', 'Type' => 'temperature', 'StateVariableID' => $temperatur, 'LevelVariableID' => 0, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Token und gültige Liste: aktiv');

pruefe(str_starts_with($m->RunSelfTest(), "OK\n"), 'Selbsttest bei gültiger Konfiguration OK: ' . $m->RunSelfTest());

$text = $m->QueryState('küche', 'light', '', true);
pruefe(str_starts_with($text, '1 of 1 checked devices active') && str_contains($text, 'Küche | Deckenlicht | light | on'), 'QueryState mit Stub-Variable: ' . $text);
pruefe(str_contains($m->QueryState('', 'temperature', '', false), '| 21.5'), 'Messwert kommt über GetValueFormatted');

// Schaltende Skriptfunktionen: Die Stub-Variablen haben keine Aktion, also darf nichts geschaltet werden.
$text = $m->SwitchDevices('Küche', 'light', '', false);
pruefe(str_starts_with($text, '0 of 1 devices are now off') && str_contains($text, '| cannot be switched'), 'SwitchDevices ohne Aktion: ' . $text);
pruefe(GetValue($licht) === true, 'die Variable bleibt unverändert');
pruefe(str_contains($m->SetLevel('', '', 'Deckenlicht', 50.0), 'has no adjustable brightness or position'), 'SetLevel ohne Stufenvariable');
pruefe(str_starts_with($m->SwitchDevices('', '', '', true), 'Nothing was changed'), 'SwitchDevices ohne Filter ändert nichts');
pruefe(str_contains($m->RunSelfTest(), 'Note: Küche Deckenlicht has no action'), 'Selbsttest weist auf das nicht schaltbare Licht hin, mit Raum und Name: ' . $m->RunSelfTest());

// Wertebereich: aus der Darstellung, sonst aus dem Legacy-Profil, sonst unbekannt
$anlage = new SymconDeviceBackend(static fn (string $t): string => $t);
$stufe = variable(VARIABLETYPE_INTEGER, 254);
IPS_SetVariableCustomPresentation($stufe, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 1, 'MAX' => 254, 'PERCENTAGE' => true]);
$bereich = static fn (?array $r): ?array => $r === null ? null : ['min' => $r['min'], 'max' => $r['max']];
pruefe($bereich($anlage->range($stufe)) === ['min' => 1.0, 'max' => 254.0], 'Bereich aus der Darstellung (wie Hue Aurelle): ' . json_encode($anlage->range($stufe)));
$rollo = variable(VARIABLETYPE_INTEGER, 0);
IPS_SetVariableCustomProfile($rollo, '~Shutter');
pruefe($bereich($anlage->range($rollo)) === ['min' => 0.0, 'max' => 100.0], 'Bereich aus dem Legacy-Profil: ' . json_encode($anlage->range($rollo)));

// Rollladenrichtung: die Darstellung Rollladen sagt sie selbst, ein Profil mit „.Reversed“ dreht sie, sonst ist der kleinste Wert offen
$richtung = static fn (?array $r): ?array => $r === null ? null : ['open' => $r['open'] ?? null, 'closed' => $r['closed'] ?? null, 'source' => $r['source'] ?? null];
pruefe($richtung($anlage->range($rollo)) === ['open' => 0.0, 'closed' => 100.0, 'source' => 'default'], '~Shutter: kleinster Wert offen: ' . json_encode($anlage->range($rollo)));
$rolloDarstellung = variable(VARIABLETYPE_INTEGER, 0);
IPS_SetVariableCustomPresentation($rolloDarstellung, ['PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER, 'USAGE_TYPE' => 0, 'OPEN_OUTSIDE_VALUE' => 100, 'CLOSE_INSIDE_VALUE' => 0]);
$r = $anlage->range($rolloDarstellung);
pruefe($richtung($r) === ['open' => 100.0, 'closed' => 0.0, 'source' => 'presentation'] && $bereich($r) === ['min' => 0.0, 'max' => 100.0], 'Darstellung Rollladen liefert Richtung und Bereich (wie beim HomeAssistant-Modul): ' . json_encode($r));
$lamelle = variable(VARIABLETYPE_INTEGER, 0);
IPS_SetVariableCustomPresentation($lamelle, ['PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER, 'USAGE_TYPE' => 1, 'OPEN_OUTSIDE_VALUE' => 0, 'CLOSE_INSIDE_VALUE' => 100]);
pruefe(($anlage->range($lamelle)['source'] ?? null) === 'rotation', 'Darstellung Rollladen mit Rotation wird als solche erkannt: ' . json_encode($anlage->range($lamelle)));
$ohneWerte = variable(VARIABLETYPE_INTEGER, 0);
IPS_SetVariableCustomPresentation($ohneWerte, ['PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER, 'USAGE_TYPE' => 0, 'OPEN_OUTSIDE_VALUE' => 5, 'CLOSE_INSIDE_VALUE' => 5]);
pruefe($anlage->range($ohneWerte) === null, 'Darstellung Rollladen ohne Spanne: Bereich unbekannt');
IPS_CreateVariableProfile('Rollladen.Reversed', VARIABLETYPE_FLOAT);
IPS_SetVariableProfileValues('Rollladen.Reversed', 0, 1, 0);
$rolloHm = variable(VARIABLETYPE_FLOAT, 0.0);
IPS_SetVariableCustomProfile($rolloHm, 'Rollladen.Reversed');
$r = $anlage->range($rolloHm);
pruefe($richtung($r) === ['open' => 1.0, 'closed' => 0.0, 'source' => 'reversed'] && ($r['profile'] ?? '') === 'Rollladen.Reversed', 'Profil mit .Reversed (wie Homematic LEVEL): größter Wert offen: ' . json_encode($r));
// Fehlende Variablen heißen hier 69993 bis 69999: Der Kernel-Stub vergibt IDs zufällig aus 10000 bis 59999, mit 59999
// traf es in der CI am 18.10.2026 eine echte Variable mit Rollladen-Darstellung, und der Lauf brach ab.
pruefe($anlage->range($licht) === null && $anlage->range(69999) === null, 'ohne Bereich und ohne Variable: unbekannt');

// Rollläden aus dem HomeAssistant-Modul: Die Position (Ident cover_status) ist dort ein Schieberegler 0 bis 100 %
// ohne Rollladen-Darstellung, und Home Assistant zählt 100 als offen (Blindtest 11.10.2026, Mitschnitt Testbox).
$haMitschnitt = json_decode((string)file_get_contents(__DIR__ . '/fixtures/testbox_2026-10-11_ha_rollladen.json'), true);
final class HaDeviceAttrappe extends IPSModule
{
}
$haGeraet = static function (array $modul): int {
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, $modul + ['Class' => HaDeviceAttrappe::class]);
    return $id;
};
$haVariable = static function (array $mitschnitt, int $geraet, string $ident): int {
    $id = variable($mitschnitt['typ'], (float)$mitschnitt['wert']);
    IPS_SetParent($id, $geraet);
    IPS_SetIdent($id, $ident);
    IPS_SetVariableCustomPresentation($id, $mitschnitt['darstellung']);
    return $id;
};
$haRollo = $haVariable($haMitschnitt['rolllaeden'][0], $haGeraet($haMitschnitt['modul']), 'cover_status');
$r = $anlage->range($haRollo);
pruefe($richtung($r) === ['open' => 100.0, 'closed' => 0.0, 'source' => 'module'] && $bereich($r) === ['min' => 0.0, 'max' => 100.0] && ($r['module'] ?? '') === 'Home Assistant', 'HomeAssistant-Modul, Position als Schieberegler: 100 ist offen: ' . json_encode($r));
$haDarstellung = $haVariable($haMitschnitt['rolllaeden'][2], $haGeraet($haMitschnitt['modul']), 'cover_status');
pruefe(($anlage->range($haDarstellung)['source'] ?? null) === 'presentation', 'HomeAssistant-Modul mit Darstellung Rollladen: die Darstellung entscheidet: ' . json_encode($anlage->range($haDarstellung)));
$haHelligkeit = $haVariable($haMitschnitt['rolllaeden'][0], $haGeraet($haMitschnitt['modul']), 'light_brightness');
pruefe(($anlage->range($haHelligkeit)['source'] ?? null) === 'default', 'HomeAssistant-Modul, keine Rollladen-Position: Standard bleibt: ' . json_encode($anlage->range($haHelligkeit)));
$fremd = $haVariable($haMitschnitt['rolllaeden'][0], $haGeraet(['ModuleID' => '{00000000-0000-0000-0000-000000000001}', 'ModuleName' => 'Anderes', 'ModuleType' => 3]), 'cover_status');
pruefe(($anlage->range($fremd)['source'] ?? null) === 'default', 'anderes Modul mit demselben Ident: Standard bleibt: ' . json_encode($anlage->range($fremd)));
pruefe($anlage->hasAction($licht) === false && $anlage->read(69999) === null, 'keine Aktion, fehlende Variable');

// Profil mit leerem Suffix: Symcon hängt ein Leerzeichen an, die Antwort soll es nicht tragen
IPS_CreateVariableProfile('Test.Position', VARIABLETYPE_INTEGER);
IPS_SetVariableProfileText('Test.Position', '', ' ');
$position = variable(VARIABLETYPE_INTEGER, 5);
IPS_SetVariableCustomProfile($position, 'Test.Position');
pruefe(GetValueFormatted($position) === '5 ' && $anlage->read($position)['formatted'] === '5', 'Leerzeichen am Ende des Anzeigetexts entfällt: ' . json_encode($anlage->read($position)));

// Fehlschlag einer Aktion: Das Aktionsskript meldet ihn an die Instanz, das wartende Werkzeug holt ihn ab
$lesen = new ReflectionMethod(HomeAgent::class, 'readFailure');
pruefe($lesen->invoke($m, 'a1b2a1b2a1b2') === null, 'ohne Meldung kein Fehlschlag');
IPS_RequestAction($iid, 'ActionFailed', json_encode(['key' => 'a1b2a1b2a1b2', 'variable' => $licht, 'error' => 'Cannot auto-convert value for parameter VariableValue (stod)']));
IPS_RequestAction($iid, 'ActionFailed', json_encode(['key' => 'c3d4c3d4c3d4', 'variable' => $temperatur, 'error' => 'No valid action available']));
pruefe($lesen->invoke($m, 'a1b2a1b2a1b2') === 'Cannot auto-convert value for parameter VariableValue (stod)' && $lesen->invoke($m, 'c3d4c3d4c3d4') === 'No valid action available', 'gemeldete Fehlschläge sind je Auftrag abholbar');
pruefe(str_contains(implode("\n", array_column($m->logs(), 'Message')), 'Action on variable ' . $licht . ' failed: Cannot auto-convert'), 'jeder Fehlschlag steht im Log');
$mitInstanz = new SymconDeviceBackend(static fn (string $t): string => $t, $iid, static fn (string $key): ?string => $lesen->invoke($m, $key));
pruefe($mitInstanz->failure($licht) === null, 'failure() ohne ausgelöste Aktion: nichts');
// Ein Fehlertext mit ungültigem UTF-8 (Fremdmodul, CP850-Ausgabe unter Windows) muss trotzdem
// ankommen (Code- und Sicherheitsreview 06.10.2026). Vorher lieferte json_encode im
// Aktionsskript false, die Meldung wurde als „kein JSON“ abgewiesen und ging verloren.
$scheitert = variable(VARIABLETYPE_BOOLEAN, false);
$aktion = IPS_CreateScript(0);
IPS_SetScriptContent($aktion, '<?php throw new Exception("Gerät meldet: \x81bertragung gestört");');
IPS_SetVariableCustomAction($scheitert, $aktion);
try {
    $mitInstanz->request($scheitert, true);
    $text = $mitInstanz->failure($scheitert);
    pruefe(is_string($text) && mb_check_encoding($text, 'UTF-8') && str_contains($text, "\u{FFFD}bertragung gestört"), 'Fehlertext mit ungültigem Byte kommt ersetzt an: ' . json_encode($text));
} catch (Throwable $e) {
    pruefe(false, 'Fehlertext mit ungültigem Byte geht verloren: ' . get_class($e) . ' ' . $e->getMessage());
}
foreach (['Toggle' => true, 'ActionFailed' => 'kein JSON'] as $ident => $wert) {
    try {
        IPS_RequestAction($iid, $ident, $wert);
        pruefe(false, 'RequestAction ' . $ident . ' hätte eine Warnung geben müssen');
    } catch (ErrorException $e) {
        pruefe(str_contains($e->getMessage(), $ident === 'Toggle' ? 'Unknown action "Toggle"' : 'ActionFailed expects'), 'RequestAction ' . $ident . ' wird mit Klartext abgewiesen: ' . $e->getMessage());
    }
}

/* --- Zugänge: mehrere Assistenten an einer Instanz, jeder mit eigenem Token ----------------- */

$setze = static function (string $property, array $wert) use ($iid): void {
    IPS_SetProperty($iid, $property, json_encode($wert));
    IPS_ApplyChanges($iid);
};
$zugang = static fn (string $name, string $token, string $raum = '', bool $nurLesen = false, bool $imPfad = false): array => ['Name' => $name, 'Token' => $token, 'DefaultRoom' => $raum, 'ReadOnly' => $nurLesen, 'InPath' => $imPfad];
$status = static fn (): int => IPS_GetInstance($iid)['InstanceStatus'];
$logText = static fn (): string => implode("\n", array_column($m->logs(), 'Message'));
$geraeteStart = json_decode(IPS_GetProperty($iid, 'Devices'), true);

$setze('Accesses', [$zugang('Leer', '')]);
$gefuellt = json_decode(IPS_GetProperty($iid, 'Accesses'), true);
pruefe(preg_match('/^[0-9a-f]{64}$/', (string)($gefuellt[0]['Token'] ?? '')) === 1 && $status() === IS_ACTIVE, 'ein Zugang ohne Token bekommt beim Übernehmen eines (64 Hex-Zeichen) und ist aktiv: ' . $status());
$setze('Accesses', [$zugang('A', 'abc-token-0123456789abcdef'), $zugang('', 'def-token-0123456789abcdef'), $zugang('C', ''), $zugang('D', 'abc-token-0123456789abcdef'), $zugang('E', 'xyz-token-0123456789abcdef', 'Dachboden')]);
pruefe($status() === IS_ACTIVE, 'fehlerhafte Zugänge sperren nur sich selbst, A hält den Endpunkt offen: Status ' . $status());
pruefe(str_contains($logText(), 'Faulty entries are disabled until they are corrected: '), 'das Log sagt, dass die fehlerhaften Zugänge gesperrt sind: ' . $logText());
$log = $logText();
pruefe(str_contains($log, 'Access without a name: name is missing') && !str_contains($log, 'Access "C": token is missing') && str_contains($log, 'Access "D": token is used twice'), 'Log nennt Zugänge ohne Name, ohne Token und mit doppeltem Token: ' . $log);
pruefe(str_contains($log, 'Access "E": default room "Dachboden" is neither in the room list nor the room of a device'), 'unbekannter Standardraum wird benannt');
pruefe(!str_contains($log, 'abc-token-0123456789abcdef') && !str_contains($log, 'xyz-token-0123456789abcdef'), 'kein Token steht im Log');

$zugaenge = [$zugang('Küche', 'abc-token-0123456789abcdef', 'Küche'), $zugang('Gast', 'gast-token-0123456789abcdef', '', true), $zugang('HA', 'pfad-token-0123456789abcdef', '', false, true)];
$setze('Accesses', $zugaenge);
pruefe($status() === IS_ACTIVE, 'drei gültige Zugänge, der Standardraum ist der Raum eines Geräts: aktiv, nicht ' . $status());

$werkzeuge = new ReflectionMethod(HomeAgent::class, 'tools');
$namen = static fn (?array $z): array => array_column($werkzeuge->invoke($m, $z), 'name');
pruefe($namen($zugaenge[1]) === ['get_state'], 'ein Nur-Lese-Zugang sieht nur get_state: ' . implode(', ', $namen($zugaenge[1])));
pruefe($namen($zugaenge[0]) === ['get_state', 'set_state'] && $namen(null) === $namen($zugaenge[0]), 'ein voller Zugang sieht alle Werkzeuge: ' . implode(', ', $namen($zugaenge[0])));
$aufruf = new ReflectionMethod(HomeAgent::class, 'callTool');
$r = $aufruf->invoke($m, 'set_state', ['room' => 'Küche', 'command' => 'off'], $zugaenge[1]);
pruefe($r['isError'] && str_contains($r['text'], 'Unknown tool') && GetValue($licht) === true, 'ein schreibender Aufruf über den Nur-Lese-Zugang wird abgewiesen: ' . $r['text']);
$r = $aufruf->invoke($m, 'get_state', ['type' => 'temperature'], $zugaenge[1]);
pruefe(!$r['isError'] && str_starts_with($r['text'], '1 device(s) found (type=temperature).'), 'lesen darf der Nur-Lese-Zugang: ' . $r['text']);
$r = $aufruf->invoke($m, 'get_state', ['type' => 'temperature'], $zugaenge[0]);
pruefe(str_starts_with($r['text'], '1 device(s) found (room=Küche, type=temperature).'), 'der Standardraum des Zugangs gilt im Werkzeug: ' . $r['text']);
pruefe(str_starts_with($m->QueryState('', 'temperature', '', false), '1 device(s) found (type=temperature).'), 'Skriptfunktionen haben keinen Standardraum');
$info = new ReflectionMethod(HomeAgent::class, 'serverInfo');
pruefe(str_ends_with($info->invoke($m, $zugaenge[0])['instructions'], ' You are in room Küche. Commands and questions without a room mean this room; use room=all for the whole house.'), 'instructions nennen den Standardraum: ' . $info->invoke($m, $zugaenge[0])['instructions']);
pruefe(!str_contains($info->invoke($m, $zugaenge[1])['instructions'], 'You are in room') && !str_contains($info->invoke($m, null)['instructions'], 'You are in room'), 'ohne Standardraum kein solcher Satz');
// HA Assist am nuc (09.10.2026): Nach „fährt“ sagte das Modell „ist jetzt ganz aufgemacht“.
pruefe(str_contains($info->invoke($m, null)['instructions'], '"moving" or "command sent" means the device has not arrived yet; then say that it is still moving.'), 'instructions erklären „fährt“: ' . $info->invoke($m, null)['instructions']);
// Claude-App (10.10.2026): Über den Nur-Lese-Zugang lehnte Claude das Schalten ab, nannte als Grund aber
// ein fehlendes Werkzeug. Instructions und Beschreibung von get_state sagen es jetzt beim Nur-Lese-Zugang.
$nurLesen = 'This access can only read the house, it cannot switch or set anything.';
pruefe(str_contains($info->invoke($m, $zugaenge[1])['instructions'], ' ' . $nurLesen), 'instructions nennen den Nur-Lese-Zugang: ' . $info->invoke($m, $zugaenge[1])['instructions']);
pruefe(!str_contains($info->invoke($m, $zugaenge[0])['instructions'], $nurLesen) && !str_contains($info->invoke($m, null)['instructions'], $nurLesen), 'ein voller Zugang bekommt den Satz nicht');
$beschreibung = static fn (?array $z): string => array_column($werkzeuge->invoke($m, $z), 'description', 'name')['get_state'];
pruefe(str_contains($beschreibung($zugaenge[1]), $nurLesen), 'get_state nennt beim Nur-Lese-Zugang den Grund: ' . $beschreibung($zugaenge[1]));
pruefe(!str_contains($beschreibung($zugaenge[0]), $nurLesen), 'beim vollen Zugang nicht');
// Zwei Instanzen hießen in HA gleich („symcon-voicecontrol“); der Servername trägt jetzt den Namen der Instanz.
IPS_SetName($iid, 'Sprachsteuerung (Test)');
pruefe($info->invoke($m, null)['name'] === 'Symcon Sprachsteuerung (Test)', 'der Servername nennt die Instanz: ' . $info->invoke($m, null)['name']);
pruefe(!isset(array_column($werkzeuge->invoke($m, null), 'inputSchema', 'name')['set_state']['properties']['color_temperature']), 'ohne Licht mit Farbtemperatur kein Feld color_temperature');
$farbtemperatur = variable(VARIABLETYPE_INTEGER, 370);
$geraeteMitFarbe = json_decode(IPS_GetProperty($iid, 'Devices'), true);
$geraeteMitFarbe[0]['ColorTempVariableID'] = $farbtemperatur;
$farbe = variable(VARIABLETYPE_INTEGER, 0);
$geraeteMitFarbe[0]['ColorVariableID'] = $farbe;
IPS_SetProperty($iid, 'Devices', json_encode($geraeteMitFarbe));
IPS_ApplyChanges($iid);
$feld = array_column($werkzeuge->invoke($m, null), 'inputSchema', 'name')['set_state']['properties']['color_temperature'] ?? [];
pruefe(($feld['type'] ?? '') === 'number' && str_contains($feld['description'] ?? '', '2700 warm white'), 'mit einem Licht mit Farbtemperatur bietet set_state color_temperature an: ' . json_encode($feld));
$feld = array_column($werkzeuge->invoke($m, null), 'inputSchema', 'name')['set_state']['properties']['color'] ?? [];
pruefe(($feld['enum'] ?? []) === array_keys(DeviceIndex::COLORS), 'und color mit der festen Liste der Farben: ' . json_encode($feld));
IPS_SetProperty($iid, 'Devices', json_encode(array_map(static function (array $g): array {
    unset($g['ColorTempVariableID'], $g['ColorVariableID']);
    return $g;
}, $geraeteMitFarbe)));
IPS_ApplyChanges($iid);
$schema = array_column($werkzeuge->invoke($m, $zugaenge[0]), 'inputSchema', 'name');
pruefe(str_contains($schema['set_state']['properties']['room']['description'] ?? '', 'the room Küche is meant; "all" means the whole house') && str_contains($schema['get_state']['properties']['room']['description'], 'the room Küche is meant'), 'die Beschreibung von room nennt Standardraum und all: ' . ($schema['set_state']['properties']['room']['description'] ?? '?'));
$schema = array_column($werkzeuge->invoke($m, null), 'inputSchema', 'name');
pruefe($schema['get_state']['properties']['room']['description'] === 'Room as the user calls it, e.g. Küche. Known rooms: Küche. Empty = all rooms.' && ($schema['set_state']['properties']['room']['description'] ?? '') === 'Room as the user calls it, e.g. Küche. Known rooms: Küche.', 'ohne Standardraum: Beispiel, bekannte Räume, bei get_state „leer = alle“: ' . $schema['get_state']['properties']['room']['description']);
pruefe(!isset($schema['get_state']['properties']['floor']) && !isset($schema['set_state']['properties']['floor']), 'ohne Etagen gibt es den Parameter floor nicht');
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Note: access "HA" also accepts its token as the last part of the address') && !str_contains($test, 'access "Küche" also'), 'Selbsttest nennt den Zugang mit Token im Pfad: ' . $test);

$formular = json_decode($m->GetConfigurationForm(), true);
$benannt = array_column(array_filter(flach($formular['elements']), static fn (array $e): bool => isset($e['name'])), null, 'name');
pruefe(isset($benannt['Accesses'], $benannt['Rooms']) && !isset($benannt['Token']) && !isset($benannt['AllowTokenInPath']) && !isset($benannt['EndpointWithToken']), 'Formular: Listen für Zugänge und Räume statt des einen Tokens: ' . implode(', ', array_keys($benannt)));
$zugangsSpalten = array_column($benannt['Accesses']['columns'] ?? [], null, 'name');
pruefe(array_keys($zugangsSpalten) === ['Name', 'ReadOnly', 'DefaultRoom', 'Connection', 'Tools', 'Token', 'InPath'], 'Spalten der Zugänge, mit Werkzeugliste: ' . json_encode(array_keys($zugangsSpalten)));
pruefe(($zugangsSpalten['Tools']['save'] ?? null) === false, 'die Spalte Werkzeugliste ist nur Anzeige');
pruefe(($zugangsSpalten['Token']['visible'] ?? true) === false && ($zugangsSpalten['Token']['save'] ?? false) === true && ($zugangsSpalten['InPath']['visible'] ?? true) === false && ($zugangsSpalten['InPath']['save'] ?? false) === true, 'Token und „Token in der Adresse“ sind unsichtbar, werden aber gespeichert');
pruefe(($zugangsSpalten['Connection']['save'] ?? null) === false && ($zugangsSpalten['Name']['save'] ?? false) === true && ($zugangsSpalten['DefaultRoom']['save'] ?? false) === true, 'die Spalte Verbindung ist nur Anzeige, Name und Standardraum werden gespeichert');
$zugangsDialog = array_column(flach($benannt['Accesses']['form'] ?? []), null, 'name');
// Burkhard 12.10.2026: Das Token braucht der Anwender nur für Claude Code und ähnliche Clients. Im Dialog steht es
// deshalb gar nicht mehr sichtbar; ein leeres Token füllt das Modul beim Übernehmen.
pruefe(($zugangsDialog['Token']['visible'] ?? true) === false && !isset($zugangsDialog['Token']['validate']) && isset($zugangsDialog['Name'], $zugangsDialog['ReadOnly'], $zugangsDialog['DefaultRoom'], $zugangsDialog['InPath']), 'der Bearbeiten-Dialog zeigt das Token nicht: ' . json_encode($zugangsDialog['Token'] ?? null));
pruefe(array_column($benannt['Rooms']['columns'] ?? [], 'name') === ['Name', 'Aliases', 'Floor'], 'Spalten der Raumliste');
pruefe(str_contains($m->GetConfigurationForm(), 'Bearer <token>') && !str_contains($m->GetConfigurationForm(), 'gast-token-0123456789abcdef'), 'das Formular nennt das Token nur mit Platzhalter, kein echtes Token');

/* --- Raumliste mit Aliasen und Etagen -------------------------------------------------------- */

$setze('Rooms', [['Name' => 'Küche', 'Aliases' => 'Kochbereich', 'Floor' => 'EG'], ['Name' => '', 'Aliases' => '', 'Floor' => ''], ['Name' => 'küche', 'Aliases' => '', 'Floor' => '']]);
pruefe($status() === 201 && str_contains($logText(), 'Room without a name: name is missing') && str_contains($logText(), 'Room "küche": name is used twice'), 'fehlerhafte Raumliste: Status 201 mit Klartext: ' . $status());
$setze('Rooms', [['Name' => 'Küche', 'Aliases' => 'Kochbereich', 'Floor' => 'EG'], ['Name' => 'Bad', 'Aliases' => '', 'Floor' => 'OG']]);
pruefe($status() === IS_ACTIVE, 'gültige Raumliste: aktiv');
$schema = array_column($werkzeuge->invoke($m, null), 'inputSchema', 'name');
pruefe(str_ends_with($schema['get_state']['properties']['floor']['description'] ?? '', 'Known floors: EG, OG.') && isset($schema['set_state']['properties']['floor']), 'mit Etagen nennen die Werkzeuge den Parameter floor samt den bekannten Etagen');
// HA Assist am nuc (10.10.2026): „Rollladen im Bad oben“ wurde zu room=Bad, das Modell kannte die
// Raumnamen nicht. room nennt die Räume der Geräte und der Raumliste, sortiert und ohne Doppelte.
pruefe(str_contains($schema['get_state']['properties']['room']['description'], 'Known rooms: Bad, Küche.') && str_contains($schema['set_state']['properties']['room']['description'] ?? '', 'Known rooms: Bad, Küche.'), 'room nennt die Räume aus Geräten und Raumliste: ' . $schema['get_state']['properties']['room']['description']);
pruefe(str_contains(array_column($werkzeuge->invoke($m, null), 'description', 'name')['set_state'] ?? '', 'Give at least one of room, floor, type and name.'), 'die Auswahlregel nennt die Etage');
$r = $aufruf->invoke($m, 'get_state', ['floor' => 'eg'], null);
pruefe(str_starts_with($r['text'], '2 device(s) found (floor=eg).'), 'Etage über das Werkzeug: ' . $r['text']);
pruefe(str_starts_with($m->QueryState('Kochbereich', 'light', '', false), '1 device(s) found (room=Kochbereich, type=light).'), 'Raum-Alias auch in der Skriptfunktion: ' . $m->QueryState('Kochbereich', 'light', '', false));
$setze('Accesses', [$zugang('Küche', 'abc-token-0123456789abcdef', 'Kochbereich')]);
pruefe($status() === IS_ACTIVE, 'der Standardraum darf ein Alias der Raumliste sein');

// Selbsttest: Tippfehler im Raum und Räume ohne Gerät als Hinweis, kein Fehler. „Nur abfragen“ je Gerät.
$setze('Devices', array_merge($geraeteStart, [['Room' => 'Flur', 'Name' => 'Flurlicht', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $licht, 'LevelVariableID' => 0, 'Invert' => false, 'ReadOnly' => true]]));
$test = $m->RunSelfTest();
pruefe(str_starts_with($test, "OK\n") && str_contains($test, 'Note: room "Flur" (1 device(s)) is not in the room list') && str_contains($test, 'Note: room "Bad" of the room list has no device') && !str_contains($test, 'room "Küche"'), 'Selbsttest weist auf Räume außerhalb der Liste und Räume ohne Gerät hin: ' . $test);
pruefe(str_contains($test, 'Note: Küche Deckenlicht has no action') && !str_contains($test, 'Flurlicht has no action'), 'bei „nur abfragen“ entfällt der Hinweis auf die fehlende Aktion');
pruefe($m->SwitchDevices('', '', 'Flurlicht', false) === 'Nothing was changed: the devices matching name=Flurlicht can only be read.', '„nur abfragen“ bei Namensnennung: ' . $m->SwitchDevices('', '', 'Flurlicht', false));
pruefe(str_contains($m->QueryState('', '', 'Flurlicht', false), 'Flur | Flurlicht | light | on'), 'abfragen lässt sich das Gerät');

$setze('Rooms', []);
$setze('Devices', $geraeteStart);
$setze('Accesses', [$zugang('Test', 'abc-token-0123456789abcdef')]);
pruefe($status() === IS_ACTIVE && !str_contains($m->RunSelfTest(), 'room list') && !str_contains($m->RunSelfTest(), 'last part of the address'), 'ohne Raumliste und ohne Pfad-Zugang keine Hinweise dazu');

// Szenen: ein Skript der Anlage, gestartet über RunScene
$skript = IPS_CreateScript(SCRIPTTYPE_PHP);
IPS_SetProperty($iid, 'Scenes', json_encode([['Room' => 'Küche', 'Name' => 'Kochen', 'Aliases' => 'Kochlicht', 'ScriptID' => $skript]]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'gültige Szenenliste: aktiv');
pruefe($anlage->scriptExists($skript) && !$anlage->scriptExists(69999) && !$anlage->scriptExists(0), 'scriptExists kennt das Skript, 0 und Fremdes nicht');
pruefe($m->RunScene('', 'kochlicht') === 'Scene "Kochen" was started.', 'RunScene über den Alias: ' . $m->RunScene('', 'kochlicht'));
pruefe(str_starts_with($m->RunScene('', ''), '1 scene(s) available, none was started') && str_contains($m->RunScene('', ''), "\nKüche | Kochen"), 'RunScene ohne Namen nennt die Szenen: ' . $m->RunScene('', ''));
pruefe(str_contains($m->RunSelfTest(), '2 released device(s), 1 scene(s), endpoint'), 'Selbsttest zählt die Szenen: ' . $m->RunSelfTest());

/* --- Drei Werkzeuge; das Schema von set_state nennt nur, was ein freigegebenes Gerät kann --- */

$liste = $werkzeuge->invoke($m, null);
pruefe(array_column($liste, 'name') === ['get_state', 'set_state', 'run_scene'], 'drei Werkzeuge: ' . implode(', ', array_column($liste, 'name')));
$hinweise = array_column($liste, 'annotations', 'name');
pruefe(array_unique(array_column($hinweise, 'openWorldHint')) === [false] && count(array_column($hinweise, 'openWorldHint')) === 3, 'alle drei Werkzeuge tragen openWorldHint false: ' . json_encode($hinweise));
pruefe(($hinweise['get_state']['readOnlyHint'] ?? null) === true && ($hinweise['set_state'] ?? null) === ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], 'Annotationen von get_state und set_state: ' . json_encode($hinweise['set_state'] ?? null));
$stellen = static fn (): ?array => array_column($werkzeuge->invoke($m, null), 'inputSchema', 'name')['set_state'] ?? null;
$s1 = $stellen();
pruefe(($s1['properties']['command']['enum'] ?? null) === ['on', 'off'] && !isset($s1['properties']['percent']) && ($s1['properties']['type']['enum'] ?? null) === ['light'], 'nur ein Licht mit Schalter: on und off, kein percent, Typ light: ' . json_encode($s1['properties'] ?? null));
pruefe(!isset($s1['required']) && ($s1['additionalProperties'] ?? null) === false, 'kein Pflichtfeld im Schema (command oder percent), keine weiteren Felder');
$r = $aufruf->invoke($m, 'set_state', ['room' => 'Küche', 'command' => 'off'], null);
pruefe(str_starts_with($r['text'], '0 of 1 devices are now off (room=Küche).') && str_contains($r['text'], '| cannot be switched') && GetValue($licht) === true, 'set_state über das Modul, Stub-Variable ohne Aktion: ' . $r['text']);
foreach (['switch_device', 'set_level'] as $alt) {
    $r = $aufruf->invoke($m, $alt, ['room' => 'Küche', 'on' => false, 'percent' => 0], null);
    pruefe($r['isError'] && str_contains($r['text'], 'Unknown tool'), 'das frühere Werkzeug ' . $alt . ' gibt es nicht mehr: ' . $r['text']);
}
pruefe(str_starts_with($m->SwitchDevices('Küche', 'light', '', false), '0 of 1 devices are now off') && str_contains($m->SetLevel('', '', 'Deckenlicht', 50.0), 'has no adjustable brightness or position'), 'die Skriptfunktionen SwitchDevices und SetLevel bleiben');

$setze('Devices', array_merge($geraeteStart, [['Room' => 'Küche', 'Name' => 'Rollladen', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rollo, 'Invert' => false]]));
$s2 = $stellen();
pruefe(($s2['properties']['command']['enum'] ?? null) === ['on', 'off', 'open', 'close'] && isset($s2['properties']['percent']) && ($s2['properties']['type']['enum'] ?? null) === ['light', 'blind'], 'mit Rollladen kommen open, close und percent dazu: ' . json_encode(array_keys($s2['properties'] ?? [])));
$setze('Devices', [$geraeteStart[1]]);
pruefe($namen(null) === ['get_state', 'run_scene'], 'ohne schaltbares Gerät gibt es set_state nicht: ' . implode(', ', $namen(null)));
$setze('Devices', [$geraeteStart[0] + ['ReadOnly' => true], $geraeteStart[1]]);
pruefe($namen(null) === ['get_state', 'run_scene'], 'auch nicht, wenn alles Schaltbare „nur abfragen“ ist: ' . implode(', ', $namen(null)));
$setze('Devices', $geraeteStart);

IPS_SetProperty($iid, 'Scenes', json_encode([['Room' => '', 'Name' => '', 'Aliases' => '', 'ScriptID' => 1]]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'fehlerhafte Szene sperrt nur sich selbst: aktiv');
$log = implode("\n", array_column($m->logs(), 'Message'));
pruefe(str_contains($log, 'Scene without a name: name is missing') && str_contains($log, 'Scene without a name: script does not exist'), 'Log nennt die fehlerhafte Szene: ' . $log);
IPS_SetProperty($iid, 'Scenes', '[]');

/* --- Gerät mit Fähigkeiten: Validierung je Typ ------------------------------------------- */

IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => $licht, 'LevelVariableID' => 0, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Lampe', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => 0, 'LevelVariableID' => 1, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Dimmer', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $licht, 'LevelVariableID' => 69999, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Tür', 'Aliases' => '', 'Type' => 'door', 'StateVariableID' => $licht, 'LevelVariableID' => 69999, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Temperatur', 'Aliases' => '', 'Type' => 'temperature', 'StateVariableID' => 69998, 'LevelVariableID' => 0, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Zeilen ohne die Fähigkeit ihres Typs sperren nur sich selbst: aktiv');
$log = implode("\n", array_column($m->logs(), 'Message'));
pruefe(str_contains($log, 'Device "Rollo" (Küche): no variable selected for Position'), 'Rollladen ohne Position wird benannt: ' . $log);
pruefe(str_contains($log, 'Device "Lampe" (Küche): no variable selected for Switching state / Brightness'), 'Licht braucht Schaltzustand oder Helligkeit');
pruefe(str_contains($log, 'Device "Dimmer" (Küche): variable for Brightness does not exist'), 'fehlende Helligkeitsvariable wird benannt');
pruefe(!str_contains($log, 'Device "Tür"'), 'eine Fähigkeit, die nicht zum Typ gehört, wird nicht geprüft');
pruefe(str_contains($log, 'Device "Temperatur" (Küche): variable for Measured value does not exist'), 'fehlender Messwert wird benannt');
$geraeteWerte = array_column(flach(json_decode($m->GetConfigurationForm(), true)['elements']), null, 'name')['Devices']['values'] ?? [];
pruefe(array_column($geraeteWerte, 'rowColor') === ['#FFC0C0', '#FFC0C0', '#FFC0C0', '', '#FFC0C0'], 'in der Geräteliste sind genau die fehlerhaften Zeilen rot: ' . json_encode(array_column($geraeteWerte, 'rowColor')));
pruefe(($geraeteWerte[3]['StateLabel'] ?? null) !== null, 'die Textspalten bleiben gefüllt');

$dimmerStufe = variable(VARIABLETYPE_INTEGER, 40); // ohne Darstellung: Die Schieberegler-Darstellung kann der Stub nicht formatieren
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rollo, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Dimmer', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => 0, 'LevelVariableID' => $dimmerStufe, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Tür', 'Aliases' => '', 'Type' => 'door', 'StateVariableID' => $licht, 'LevelVariableID' => 69999, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Rollladen mit Position, Licht nur mit Helligkeit, Fenster mit Kontakt: aktiv');
$text = $m->QueryState('', '', '', false);
pruefe(str_starts_with($text, '3 device(s) found') && !str_contains($text, 'not available') && str_contains($text, 'Küche | Dimmer | light | on (') && str_contains($text, 'Küche | Tür | door | open'), 'alle drei sind abfragbar: ' . $text);
pruefe(str_contains($m->RunSelfTest(), 'Note: Küche Rollo has no action') && str_contains($m->RunSelfTest(), 'Note: Küche Dimmer has no action'), 'Selbsttest prüft die Aktion an der Variable, die gestellt wird: ' . $m->RunSelfTest());

// Den Selbsttest gibt es als Knopf im Formular, dort liest der Anwender die erkannte Richtung ab
$knoepfe = array_column(json_decode($m->GetConfigurationForm(), true)['actions'], 'onClick', 'caption');
pruefe(($knoepfe['Run self-test'] ?? '') === 'echo HAGT_RunSelfTest($id);', 'Formular hat den Knopf für den Selbsttest: ' . json_encode($knoepfe));

// Hinweis für Skripte und KI-Assistenten: Was im Blindtest vom 11.10.2026 fehlte (Backlog B52), steht drin,
// englisch im Formular und deutsch in der Übersetzung.
$hinweis = '';
foreach (flach(json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/form.json'), true)['elements']) as $element) {
    if (str_starts_with((string)($element['caption'] ?? ''), 'Hint for scripts and AI assistants')) {
        $hinweis = (string)$element['caption'];
    }
}
$hinweisDe = (string)(json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true)['translations']['de'][$hinweis] ?? '');
foreach ([
    // Prompttest 13.10.2026: Der Hinweistext nannte für die Claude-App noch das Token in der Adresse; seit OAuth ist der
    // empfohlene Weg die Anmeldung mit Kopplungscode, und die Werkzeuge für Diagramme und Karten fehlten.
    'Home Assistant (MCP integration) only works with InPath.'                                                                                       => 'Home Assistant (MCP-Integration) geht nur mit InPath.',
    'The Claude app signs in with OAuth: a custom connector with the Connect address from the instance Connect Control (or another public HTTPS address) followed by the hook path, without a token, and HAGT_CreatePairingCode for the pairing code; it needs neither InPath nor the token.' => 'Die Claude-App meldet sich per OAuth an: benutzerdefinierter Connector mit der Connect-Adresse aus der Instanz Connect Control (oder einer anderen öffentlichen HTTPS-Adresse), dahinter der Hook-Pfad ohne Token, und HAGT_CreatePairingCode für den Kopplungscode; sie braucht weder InPath noch das Token.',
    'Clients that show MCP Apps, such as the Claude app, also get show_chart (history, consumption and the energy balance as charts) and show_devices (device cards with switches and sliders, operated through control_device, which only the cards see).' => 'Clients, die MCP Apps anzeigen, etwa die Claude-App, bekommen dazu show_chart (Verlauf, Verbrauch und Energiebilanz als Diagramm) und show_devices (Gerätekarten mit Schaltern und Reglern, bedient über control_device, das nur die Karten sehen).',
    'Depending on the released devices set_state also offers humidity, mode, fan_speed, color_temperature, color, volume, volume_change and mute, and the commands play, pause, next and previous.' => 'Je nach freigegebenen Geräten bietet set_state außerdem humidity, mode, fan_speed, color_temperature, color, volume, volume_change und mute sowie die Befehle play, pause, next und previous.',
    // Energiebilanz als Liste der Quellen (B82, Burkhard 17.10.2026), mit Vorzeichen je Zeile (B77)
    'The energy balance is the property Sources, a JSON list with one row per grid connection, solar plant or battery: Type (grid, solar or battery), Name, EnergyIn and EnergyOut (energy meters archived as counters: grid import and export, solar production, battery discharge and charge), PowerIn and PowerOut (the current power in the same order) and Invert. Solar only has EnergyIn and PowerIn.' => 'Die Energiebilanz ist die Property Sources, eine JSON-Liste mit einer Zeile je Netzanschluss, PV-Anlage oder Batterie: Type (grid, solar oder battery), Name, EnergyIn und EnergyOut (als Zähler archivierte Energiezähler: Netzbezug und Einspeisung, PV-Ertrag, Batterie entladen und geladen), PowerIn und PowerOut (die aktuelle Leistung in derselben Reihenfolge) und Invert. solar hat nur EnergyIn und PowerIn.',
    'A single signed power in PowerIn counts negative as export or charging, Invert reverses it; HAGT_RunSelfTest shows how the current value counts. The older properties GridImportVariableID and so on are moved into Sources on apply.' => 'Eine einzelne Leistung mit Vorzeichen in PowerIn zählt negativ als Einspeisung bzw. Laden, Invert kehrt sie um; HAGT_RunSelfTest zeigt, wie der aktuelle Wert zählt. Die älteren Properties GridImportVariableID und so weiter übernimmt das Modul beim Übernehmen in Sources.',
    'An access without a token gets one on apply (64 hex characters); a token set by script needs at least 24 characters.'                         => 'Ein Zugang ohne Token bekommt beim Übernehmen eines (64 Hex-Zeichen); ein per Skript gesetztes Token braucht mindestens 24 Zeichen.',
    'Room names are the words the user speaks (Küche, Bad oben), without technical additions.'                                                      => 'Raumnamen sind die Wörter, die der Anwender spricht (Küche, Bad oben), ohne technische Zusätze.',
    // Zähler mit „gemessen von“ (B82, 17.10.2026)
    'water_leak, temperature, humidity, air_quality, measurement, meter.' => 'water_leak, temperature, humidity, air_quality, measurement, meter.',
    'The type meter (a sub-distribution, wallbox or flat meter) needs EnergyVariableID and never counts in a device total. MeteredBy is the EnergyVariableID of the meter above a device or meter (0 = none); the answers name per meter what is measured below it and the rest.' => 'Der Typ meter (Unterverteilung, Wallbox, Wohnungszähler) braucht EnergyVariableID und zählt nie in einer Gerätesumme. MeteredBy ist die EnergyVariableID des Zählers über einem Gerät oder Zähler (0 = keiner); die Antworten nennen je Zähler, was darunter gemessen ist, und den Rest.',
    // Raum als Auswahl der Raumliste (B75, Burkhard 13.10.2026)
    'The Room of a device is a room of the room list or empty; the form names rooms that are missing there.' => 'Der Raum eines Geräts ist ein Raum der Raumliste oder leer; das Formular nennt Räume, die dort fehlen.',
    'The fields of each type (volume, mode, fan and others) are named by HAGT_UIDeviceForm(int $InstanceID, string $Type, string $Room, int $MeteredBy); \'\' and 0 are enough for Room and MeteredBy.'                           => 'Die Felder je Typ (Lautstärke, Modus, Lüfter und andere) nennt HAGT_UIDeviceForm(int $InstanceID, string $Type, string $Room, int $MeteredBy); für Room und MeteredBy genügen \'\' und 0.',
    // Prompttest an der Testbox 22.10.2026: volle Signatur, Feldnamen als Schlüssel, Abgrenzung der Messwerte, Einheit der Wärme
    'HAGT_UISourceForm(int $InstanceID, string $Type, int $PowerOut) names the fields of each type; 0 is enough for PowerOut.' => 'Die Felder je Art nennt HAGT_UISourceForm(int $InstanceID, string $Type, int $PowerOut); für PowerOut genügt 0.',
    'The name of each field is its key in the rows of Devices (for example PowerVariableID, EnergyVariableID, MeteredBy). air_quality is for CO2, VOC, particulate matter and air quality indices, measurement for every other value such as brightness or pressure.' => 'Der Name jedes Felds ist sein Schlüssel in den Zeilen von Devices (etwa PowerVariableID, EnergyVariableID, MeteredBy). air_quality ist für CO2, VOC, Feinstaub und Luftqualitätsindizes, measurement für alle anderen Werte wie Helligkeit oder Luftdruck.',
    // Prompttest am nuc 22.10.2026: Pflichtfelder je Typ, fehlende Schlüssel, Aliase, Einheiten, Melder mit Zahl
    'Each type needs at least one of these fields: light StateVariableID or LevelVariableID, blind LevelVariableID, window StateVariableID or LevelVariableID, thermostat SetpointVariableID, appliance StateVariableID or PowerVariableID, media StateVariableID, PlaybackVariableID or VolumeVariableID, humidifier StateVariableID or SetpointVariableID, fan StateVariableID or FanVariableID, meter EnergyVariableID, all other types StateVariableID; an ID of 0 or 1 means not set. A missing key counts as 0; the columns ending in Label are filled by the form, a script may leave them out.' => 'Jeder Typ braucht mindestens eines dieser Felder: light StateVariableID oder LevelVariableID, blind LevelVariableID, window StateVariableID oder LevelVariableID, thermostat SetpointVariableID, appliance StateVariableID oder PowerVariableID, media StateVariableID, PlaybackVariableID oder VolumeVariableID, humidifier StateVariableID oder SetpointVariableID, fan StateVariableID oder FanVariableID, meter EnergyVariableID, alle anderen Typen StateVariableID; eine ID von 0 oder 1 bedeutet nicht gesetzt. Ein fehlender Schlüssel zählt als 0; die Spalten, die auf Label enden, füllt das Formular, ein Skript kann sie weglassen.',
    'Room names are the words the user speaks (Küche, Bad oben), without technical additions. Each room name may appear only once; an alias may belong to several rooms, room= then means all of them.' => 'Raumnamen sind die Wörter, die der Anwender spricht (Küche, Bad oben), ohne technische Zusätze. Jeder Raumname darf nur einmal vorkommen; ein Alias darf zu mehreren Räumen gehören, room= meint dann alle.',
    'Power, also a heat output, counts in mW, W, kW or MW (without a unit W); energy meters and heat meters count in mWh, Wh, kWh or MWh (without a unit kWh) and are archived as counters. Any other unit does not count.' => 'Leistung, auch eine Wärmeleistung, zählt in mW, W, kW oder MW (ohne Einheit W); Energie- und Wärmezähler zählen in mWh, Wh, kWh oder MWh (ohne Einheit kWh) und sind als Zähler archiviert. Eine andere Einheit zählt nicht.',
    'For every blind HAGT_RunSelfTest shows which value counts as open; if open and closed are swapped, set Invert. window, door, presence and water_leak count as active (open, detected) when the value is true or above 0, with Invert only when it is false or 0; a window contact with 0 closed, 1 tilted and 2 open thus counts as open from 1.' => 'Für jeden Rollladen zeigt HAGT_RunSelfTest, welcher Wert als offen gilt; sind offen und geschlossen vertauscht, Invert setzen. window, door, presence und water_leak gelten als aktiv (offen, erkannt), wenn der Wert true oder über 0 ist, mit Invert nur bei false oder 0; ein Fensterkontakt mit 0 zu, 1 gekippt und 2 offen gilt also ab 1 als offen.',
    'For every blind HAGT_RunSelfTest shows which value counts as open; if open and closed are swapped, set Invert.'                                 => 'Für jeden Rollladen zeigt HAGT_RunSelfTest, welcher Wert als offen gilt; sind offen und geschlossen vertauscht, Invert setzen.',
    // Einrichtungs-Blindtest 12.10.2026 (Backlog B58, B59)
    'The volume counts in percent of the range of the variable, also for a range in dB (-80 to 18 dB: 50 % = -31 dB), and is sent in the step size of the variable.' => 'Die Lautstärke zählt in Prozent des Bereichs der Variable, auch bei einem Bereich in dB (-80 bis 18 dB: 50 % = -31 dB), und wird in der Schrittweite der Variable gesendet.',
    'HAGT_GetAddresses(int $InstanceID, string $AccessName): string names the addresses of an access (empty name = all accesses) with <token> as a placeholder; no script function returns a token.' => 'HAGT_GetAddresses(int $InstanceID, string $AccessName): string nennt die Adressen eines Zugangs (leerer Name = alle Zugänge) mit <token> als Platzhalter; keine Skriptfunktion gibt ein Token heraus.',
] as $en => $de) {
    pruefe(str_contains($hinweis, $en), 'Hinweistext nennt: ' . $en);
    pruefe(str_contains($hinweisDe, $de), 'Hinweistext deutsch nennt: ' . $de);
}
pruefe(!str_contains($hinweis, 'the Claude app (custom connector without sign-in) only work with InPath'), 'der Hinweistext schickt die Claude-App nicht mehr auf den Weg mit Token in der Adresse');

// Der Selbsttest nennt je Rollladen die Richtung und woher sie stammt
pruefe(str_contains($m->RunSelfTest(), 'Blind Küche Rollo: open at 0, closed at 100 (default: the smallest value is open)'), 'Selbsttest nennt die Richtung des Rollladens: ' . $m->RunSelfTest());
pruefe(str_contains($m->QueryState('', 'blind', '', false), 'Küche | Rollo | blind | open'), 'QueryState rechnet die Position selbst: ' . $m->QueryState('', 'blind', '', false));
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rolloHm, 'Invert' => false],
    ['Room' => 'Bad', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rolloDarstellung, 'Invert' => true],
    ['Room' => 'Flur', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $licht, 'Invert' => false],
    ['Room' => 'Wohnzimmer', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $haRollo, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Blind Wohnzimmer Rollo: open at 100, closed at 0 (from the module Home Assistant)'), 'Richtung aus dem Modul des Geräts: ' . $test);
pruefe(str_contains($test, 'Blind Küche Rollo: open at 1, closed at 0 (from the profile name Rollladen.Reversed)'), 'Richtung aus dem Profilnamen: ' . $test);
pruefe(str_contains($test, 'Blind Bad Rollo: open at 0, closed at 100 (from the blind presentation, reversed by the checkbox)'), 'Richtung aus der Darstellung, vom Haken umgekehrt: ' . $test);
pruefe(str_contains($test, 'Note: blind Flur Rollo has no known value range, the assistant cannot set it'), 'Rollladen ohne Bereich wird benannt: ' . $test);
// Den Anzeigetext der Darstellung Rollladen kann der Stub nicht bilden (GetValueFormatted wirft dort),
// abgefragt wird deshalb nur der Rollladen mit Legacy-Profil. Die Darstellung prüft check-switch.php.
$text = $m->QueryState('Küche', 'blind', '', false);
pruefe(str_contains($text, 'Küche | Rollo | blind | closed'), 'Rohwert 0 heißt bei einem Profil mit .Reversed geschlossen: ' . $text);

/* --- Bearbeitungsdialog je Typ ------------------------------------------------------------ */

$formular = json_decode($m->GetConfigurationForm(), true);
$geraete = array_values(array_filter(flach($formular['elements']), static fn ($e) => ($e['name'] ?? '') === 'Devices'))[0];
$spalten = array_column($geraete['columns'], 'name');
pruefe(in_array('StateVariableID', $spalten, true) && in_array('LevelVariableID', $spalten, true) && in_array('ReadOnly', $spalten, true) && !in_array('VariableID', $spalten, true), 'Spalten der Liste im neuen Format: ' . implode(', ', $spalten));
pruefe(($geraete['form'] ?? null) === "return json_decode(HAGT_UIDeviceForm(\$id, \$Devices['Type'] ?? 'light', \$Devices['Room'] ?? '', \$Devices['MeteredBy'] ?? 0), true);", 'die Liste erzeugt ihren Dialog je Zeile über das Modul, mit Raum und Zähler der Zeile: ' . json_encode($geraete['form'] ?? null));
pruefe(in_array('MeteredBy', $spalten, true), 'die Liste speichert „gemessen von“ (B82): ' . implode(', ', $spalten));
// Raum als Auswahl (B75): keine Hilfsspalte mehr, der Raum der Zeile ist der Wert der Auswahl
pruefe(!in_array('RoomChoice', $spalten, true), 'keine Spalte RoomChoice: ' . implode(', ', $spalten));
// Burkhard, 10.10.2026: Endpunkt mit Zugängen, Räume, Geräte, Szenen und Energiebilanz je einzeln aufklappbar
$oben = $formular['elements'];
$bereiche = array_values(array_filter($oben, static fn (array $e): bool => ($e['type'] ?? '') === 'ExpansionPanel'));
$inhalt = array_map(static fn (array $b): array => array_values(array_filter(array_column($b['items'], 'name'))), $bereiche);
pruefe(count($bereiche) === 6 && $inhalt[0] === ['Accesses', 'ConnectHeading', 'PanelClaude', 'PanelHA', 'PanelOther'] && $inhalt[1] === ['Rooms'] && $inhalt[2] === ['RoomsMissing', 'Devices'] && $inhalt[3] === ['Scenes'] && in_array('Sources', array_column(flach($bereiche[4]['items']), 'name'), true) && in_array('Problems', array_column(flach($bereiche[5]['items']), 'name'), true), 'sechs Bereiche in dieser Reihenfolge, zuletzt „Was nicht geklappt hat“: ' . json_encode($inhalt));
pruefe(array_values(array_filter(array_column(array_filter($oben, static fn (array $e): bool => ($e['type'] ?? '') !== 'ExpansionPanel'), 'name'))) === [], 'oben steht außer den Bereichen nur Erklärtext, kein Feld: ' . json_encode(array_column($oben, 'name')));
// Burkhard, 10.10.2026: einheitlich zugeklappt, und jeder Bereich merkt sich seinen Zustand
$zustand = static fn (): array => array_map(static fn (array $b): bool => ($b['expanded'] ?? false) === true, array_values(array_filter(json_decode($m->GetConfigurationForm(), true)['elements'], static fn (array $e): bool => ($e['type'] ?? '') === 'ExpansionPanel')));
pruefe($zustand() === [false, false, false, false, false, false], 'anfangs sind alle Bereiche zu, auch mit leerer Liste: ' . json_encode($zustand()));
pruefe(array_map(static fn (array $b): string => $b['onClick'] ?? '', $bereiche) === array_map(static fn (string $n): string => "HAGT_UITogglePanel(\$id, '$n');", ['PanelAccesses', 'PanelRooms', 'PanelDevices', 'PanelScenes', 'PanelBalance', 'PanelTrouble']), 'jeder Bereich meldet das Auf- und Zuklappen: ' . json_encode(array_column($bereiche, 'onClick')));
oderNull(static fn () => $m->UITogglePanel('PanelDevices'));
pruefe($zustand() === [false, false, true, false, false, false], 'nach dem Aufklappen bleibt „Geräte“ beim nächsten Öffnen auf: ' . json_encode($zustand()));
oderNull(static fn () => $m->UITogglePanel('PanelDevices'));
oderNull(static fn () => $m->UITogglePanel('Unbekannt'));
pruefe($zustand() === [false, false, false, false, false, false], 'nach dem Zuklappen wieder zu, ein unbekannter Name ändert nichts: ' . json_encode($zustand()));

// Unterbereiche in „Endpunkt und Zugänge“: je Assistent ein aufklappbarer Bereich, der sich seinen Zustand merkt
$unter = array_column(array_values(array_filter($bereiche[0]['items'], static fn (array $e): bool => ($e['type'] ?? '') === 'ExpansionPanel')), null, 'name');
pruefe(array_keys($unter) === ['PanelClaude', 'PanelHA', 'PanelOther'], 'drei Unterbereiche für Claude-App, Home Assistant und andere Assistenten: ' . json_encode(array_keys($unter)));
pruefe(array_values(array_filter(array_column($unter['PanelClaude']['items'] ?? [], 'name'))) === ['ClaudeStep1', 'ClaudeNoConnect', 'ClaudeConnectDown', 'PairingRow', 'ClaudeStep2', 'OAuthClients'], 'Claude-App: Schritte und gekoppelte Assistenten: ' . json_encode(array_column($unter['PanelClaude']['items'] ?? [], 'name')));
$kopplungen = array_values(array_filter($unter['PanelClaude']['items'] ?? [], static fn (array $e): bool => ($e['name'] ?? '') === 'OAuthClients'))[0] ?? [];
pruefe(array_column($kopplungen['columns'] ?? [], 'name') === ['Client', 'Access', 'Paired', 'Used', 'Expires', 'Family', 'Revoke'], 'Kopplungen mit „Zuletzt benutzt“ statt „Zuletzt erneuert“: ' . json_encode(array_column($kopplungen['columns'] ?? [], 'name')));
pruefe(array_values(array_filter(array_column($unter['PanelHA']['items'] ?? [], 'name'))) === ['HAText', 'AddressRow'], 'Home Assistant: Text und Adresse anzeigen');
pruefe(array_values(array_filter(array_column($unter['PanelOther']['items'] ?? [], 'name'))) === ['Endpoint', 'OtherText', 'TokenRow'], 'Andere Assistenten: Endpunkt, Text, Token anzeigen');
pruefe(array_map(static fn (array $b): string => $b['onClick'] ?? '', array_values($unter)) === ["HAGT_UITogglePanel(\$id, 'PanelClaude');", "HAGT_UITogglePanel(\$id, 'PanelHA');", "HAGT_UITogglePanel(\$id, 'PanelOther');"], 'jeder Unterbereich meldet das Auf- und Zuklappen');
$unterZustand = static function () use ($m): array {
    $zugaenge = array_values(array_filter(json_decode($m->GetConfigurationForm(), true)['elements'], static fn (array $e): bool => ($e['name'] ?? '') === 'PanelAccesses'))[0];
    return array_map(static fn (array $b): bool => ($b['expanded'] ?? false) === true, array_values(array_filter($zugaenge['items'], static fn (array $e): bool => ($e['type'] ?? '') === 'ExpansionPanel')));
};
pruefe($unterZustand() === [false, false, false], 'die Unterbereiche sind anfangs zu');
oderNull(static fn () => $m->UITogglePanel('PanelHA'));
pruefe($unterZustand() === [false, true, false], 'aufgeklappt bleibt „Home Assistant verbinden“ beim nächsten Öffnen auf: ' . json_encode($unterZustand()));
oderNull(static fn () => $m->UITogglePanel('PanelHA'));

$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/HomeAgent/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
$dialogFehler = [];
$ohneUebersetzung = [];
$felder = [];
foreach (DeviceIndex::TYPES as $typ => $woerter) {
    $dialog = json_decode((string)oderNull(static fn () => $m->UIDeviceForm($typ)), true);
    if (!is_array($dialog)) {
        $dialogFehler[] = $typ . ': kein gültiges JSON';
        continue;
    }
    $felder[$typ] = array_column($dialog, null, 'name');
    if (array_keys($felder[$typ]) !== ['Room', 'Name', 'Aliases', 'Type', 'StateVariableID', 'StateLabel', 'HumidityVariableID', 'HumidityLabel', 'SetpointVariableID', 'SetpointLabel', 'ModeVariableID', 'ModeLabel', 'FanVariableID', 'FanLabel', 'ColorTempVariableID', 'ColorTempLabel', 'ColorVariableID', 'ColorLabel', 'PlaybackVariableID', 'PlaybackLabel', 'VolumeVariableID', 'VolumeLabel', 'MuteVariableID', 'MuteLabel', 'TitleVariableID', 'TitleLabel', 'ArtistVariableID', 'ArtistLabel', 'LevelVariableID', 'LevelLabel', 'MovingVariableID', 'MovingLabel', 'StopVariableID', 'StopLabel', 'PowerVariableID', 'PowerLabel', 'EnergyVariableID', 'EnergyLabel', 'HeatVariableID', 'HeatLabel', 'HeatPowerVariableID', 'HeatPowerLabel', 'MeteredBy', 'Invert', 'Tilt', 'ReadOnly']) {
        $dialogFehler[] = $typ . ': Felder ' . implode(', ', array_keys($felder[$typ]));
        continue;
    }
    $rollenDesTyps = DeviceIndex::TYPE_ROLES[$typ]['roles'];
    foreach (DeviceIndex::ROLES as $rolle => $schluessel) {
        if ($felder[$typ][$schluessel]['visible'] !== in_array($rolle, $rollenDesTyps, true)) {
            $dialogFehler[] = $typ . ': Sichtbarkeit von ' . $schluessel;
        }
    }
    // „gemessen von“ (B82) bei jedem Typ mit Leistung oder Zähler, auch beim Zähler selbst
    if (($felder[$typ]['MeteredBy']['visible'] ?? null) !== (array_intersect(['energy', 'power'], $rollenDesTyps) !== [])) {
        $dialogFehler[] = $typ . ': Sichtbarkeit von MeteredBy';
    }
    if ($felder[$typ]['Invert']['visible'] !== ($woerter !== null || $typ === 'blind')) {
        $dialogFehler[] = $typ . ': Sichtbarkeit von Invert';
    }
    if ($felder[$typ]['ReadOnly']['visible'] !== (in_array($typ, DeviceIndex::CONTROLLABLE, true) || $typ === 'window')) {
        $dialogFehler[] = $typ . ': Sichtbarkeit von ReadOnly';
    }
    foreach ($dialog as $element) {
        // Die Einträge der Raum- und Zählerauswahl sind Räume und Zähler des Anwenders, kein Text des Moduls
        foreach (array_merge([$element['caption'] ?? ''], in_array($element['name'] ?? '', ['Room', 'MeteredBy'], true) ? [] : array_column($element['options'] ?? [], 'caption')) as $beschriftung) {
            if ($beschriftung !== '' && !isset($de[$beschriftung])) {
                $ohneUebersetzung[] = $beschriftung;
            }
        }
    }
}
pruefe($dialogFehler === [] && count($felder) === count(DeviceIndex::TYPES), 'Dialog für jeden Typ: gültiges JSON, alle Felder, nur die Fähigkeiten des Typs sichtbar: ' . implode('; ', $dialogFehler));
pruefe($ohneUebersetzung === [] && $felder !== [], 'jede Beschriftung der Dialoge hat eine deutsche Übersetzung, es fehlen: ' . implode(' / ', array_unique($ohneUebersetzung)));
pruefe(($felder['light']['StateVariableID']['caption'] ?? '') === 'Switching state' && ($felder['light']['LevelVariableID']['caption'] ?? '') === 'Brightness', 'Licht: Schaltzustand und Helligkeit');
pruefe(($felder['blind']['LevelVariableID']['caption'] ?? '') === 'Position' && ($felder['blind']['Invert']['caption'] ?? '') === 'Reverse direction (if open and closed are swapped)', 'Rollladen: Position, Richtung umkehren');
pruefe(($felder['window']['StateVariableID']['caption'] ?? '') === 'Contact' && ($felder['window']['Invert']['caption'] ?? '') === 'Inverted (the variable reports "on" or a number above 0 when it is closed)', 'Fenster: Kontakt, Umkehr');
pruefe(($felder['temperature']['StateVariableID']['caption'] ?? '') === 'Measured value', 'Messwert');
pruefe(($felder['socket']['Invert']['caption'] ?? '') === 'Inverted (the variable reports "on" when the device is off)', 'Steckdose: Umkehr');
pruefe(array_column($felder['light']['Type']['options'] ?? [], 'value') === array_keys(DeviceIndex::TYPES), 'die Typauswahl nennt alle Typen');
pruefe(($felder['light']['Type']['onChange'] ?? '') === 'HAGT_UIDeviceChanged($id, $Type, $StateVariableID, $LevelVariableID, $MovingVariableID, $PowerVariableID, $EnergyVariableID, $StopVariableID, $SetpointVariableID, $ColorTempVariableID, $ColorVariableID, $PlaybackVariableID, $VolumeVariableID, $MuteVariableID, $TitleVariableID, $ArtistVariableID, $HumidityVariableID, $ModeVariableID, $FanVariableID, $HeatVariableID, $HeatPowerVariableID);', 'ein Typwechsel im Dialog ruft das Modul');
pruefe(($felder['blind']['MovingVariableID']['visible'] ?? false) === true && ($felder['blind']['MovingVariableID']['caption'] ?? '') === 'Moving state' && ($felder['blind']['MovingVariableID']['type'] ?? '') === 'SelectVariable', 'Rollladen: wahlweise ein Fahrstatus');
pruefe(($felder['light']['MovingVariableID']['visible'] ?? true) === false, 'beim Licht gibt es keinen Fahrstatus');
// Farbtemperatur (build 57): wahlweise beim Licht, nicht beim Schalter.
pruefe(($felder['light']['ColorTempVariableID']['visible'] ?? false) === true && ($felder['light']['ColorTempVariableID']['caption'] ?? '') === 'Colour temperature' && ($felder['switch']['ColorTempVariableID']['visible'] ?? true) === false, 'Licht: wahlweise Farbtemperatur');
pruefe(($felder['light']['ColorVariableID']['visible'] ?? false) === true && ($felder['light']['ColorVariableID']['caption'] ?? '') === 'Colour' && ($felder['blind']['ColorVariableID']['visible'] ?? true) === false, 'Licht: wahlweise Farbe');
// Mediaplayer (build 60): Ein/Aus, Wiedergabe, Lautstärke, Stumm, Titel und Interpret.
pruefe(array_map(static fn (string $f): string => ($felder['media'][$f]['visible'] ?? false) ? ($felder['media'][$f]['caption'] ?? '') : '', ['StateVariableID', 'PlaybackVariableID', 'VolumeVariableID', 'MuteVariableID', 'TitleVariableID', 'ArtistVariableID']) === ['On/off', 'Playback', 'Volume', 'Mute', 'Title', 'Artist'], 'Mediaplayer: sechs Felder mit eigenen Beschriftungen: ' . json_encode(array_map(static fn (array $f): string => ($f['visible'] ?? false) ? ($f['caption'] ?? '') : '-', $felder['media'] ?? []), JSON_UNESCAPED_UNICODE));
pruefe(($felder['light']['PlaybackVariableID']['visible'] ?? true) === false && ($felder['media']['LevelVariableID']['visible'] ?? true) === false && ($felder['media']['ReadOnly']['visible'] ?? false) === true, 'Wiedergabe nur beim Mediaplayer, dort keine Helligkeit, aber „Nur abfragen“ (steuerbar seit build 61)');
pruefe(($felder['media']['PlaybackVariableID']['onChange'] ?? '') === 'HAGT_UIDeviceChanged($id, $Type, $StateVariableID, $LevelVariableID, $MovingVariableID, $PowerVariableID, $EnergyVariableID, $StopVariableID, $SetpointVariableID, $ColorTempVariableID, $ColorVariableID, $PlaybackVariableID, $VolumeVariableID, $MuteVariableID, $TitleVariableID, $ArtistVariableID, $HumidityVariableID, $ModeVariableID, $FanVariableID, $HeatVariableID, $HeatPowerVariableID);', 'die Wahl der Wiedergabe zieht den Spaltentext nach');
// Luftentfeuchter (build 65): Ein/Aus, Ist-Feuchte, Zielfeuchte.
pruefe(array_map(static fn (string $f): string => ($felder['humidifier'][$f]['visible'] ?? false) ? ($felder['humidifier'][$f]['caption'] ?? '') : '', ['StateVariableID', 'HumidityVariableID', 'SetpointVariableID', 'ModeVariableID', 'FanVariableID']) === ['On/off', 'Current humidity', 'Target humidity', 'Mode', 'Fan speed'], 'Luftentfeuchter: fünf Felder mit eigenen Beschriftungen: ' . json_encode(array_map(static fn (array $f): string => ($f['visible'] ?? false) ? ($f['caption'] ?? '') : '-', $felder['humidifier'] ?? []), JSON_UNESCAPED_UNICODE));
pruefe(($felder['thermostat']['HumidityVariableID']['visible'] ?? true) === false && ($felder['humidifier']['ReadOnly']['visible'] ?? false) === true, 'Ist-Feuchte nur beim Luftentfeuchter, der ist steuerbar');
// Lüftung (build 90): Ein/Aus, Betriebsmodus, Lüfterstufe, wahlweise Leistung und Zähler.
pruefe(array_map(static fn (string $f): string => ($felder['fan'][$f]['visible'] ?? false) ? ($felder['fan'][$f]['caption'] ?? '') : '', ['StateVariableID', 'ModeVariableID', 'FanVariableID', 'HumidityVariableID', 'SetpointVariableID']) === ['On/off', 'Mode', 'Fan speed', '', ''] && ($felder['fan']['ReadOnly']['visible'] ?? false) === true, 'Lüftung: Ein/Aus, Modus und Lüfterstufe, steuerbar: ' . json_encode(array_keys(array_filter($felder['fan'] ?? [], static fn ($e) => $e['visible'] ?? false))));
// Wärmepumpe (B83): Wärmezähler und Wärmeleistung wahlweise beim Gerät, sonst nirgends
pruefe(array_map(static fn (string $f): string => ($felder['appliance'][$f]['visible'] ?? false) ? ($felder['appliance'][$f]['caption'] ?? '') : '', ['HeatVariableID', 'HeatPowerVariableID']) === ['Heat meter (archived as counter)', 'Heat output']
    && ($felder['socket']['HeatVariableID']['visible'] ?? true) === false && ($felder['meter']['HeatVariableID']['visible'] ?? true) === false, 'Gerät: Wärmezähler und Wärmeleistung, nicht bei Steckdose und Zähler');
pruefe(($felder['appliance']['HeatVariableID']['type'] ?? '') === 'SelectVariable' && ($felder['appliance']['HeatPowerVariableID']['onChange'] ?? '') === ($felder['light']['Type']['onChange'] ?? '-'), 'die Wahl der Wärmeleistung zieht den Spaltentext nach');
$m->formUpdates = [];
$waerme = variable(VARIABLETYPE_FLOAT, 23.642);
IPS_SetName($waerme, 'V_Heizen_Summe Wärme');
oderNull(static fn () => $m->UIDeviceChanged('appliance', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, $waerme));
pruefe(in_array(['HeatLabel', 'value', 'V_Heizen_Summe Wärme'], $m->formUpdates, true) && in_array(['HeatPowerLabel', 'value', '-'], $m->formUpdates, true), 'die Wahl des Wärmezählers zieht den unsichtbaren Text nach: ' . json_encode($m->formUpdates, JSON_UNESCAPED_UNICODE));
$spalten = [];
foreach (flach(json_decode($m->GetConfigurationForm(), true)['elements']) as $element) {
    if (($element['name'] ?? '') === 'Devices') {
        $spalten = array_column($element['columns'], null, 'name');
    }
}
pruefe(isset($spalten['HeatVariableID'], $spalten['HeatLabel'], $spalten['HeatPowerVariableID'], $spalten['HeatPowerLabel']) && ($spalten['HeatVariableID']['add'] ?? null) === 1, 'die Geräteliste hat Spalten für Wärmezähler und Wärmeleistung');
$m->formUpdates = [];
$lautstaerke = variable(VARIABLETYPE_INTEGER, 37);
IPS_SetName($lautstaerke, 'Lautstärke');
oderNull(static fn () => $m->UIDeviceChanged('media', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, $lautstaerke));
pruefe(in_array(['VolumeLabel', 'value', 'Lautstärke'], $m->formUpdates, true) && in_array(['PlaybackLabel', 'value', '-'], $m->formUpdates, true), 'die Wahl der Lautstärke zieht den unsichtbaren Text nach: ' . json_encode($m->formUpdates, JSON_UNESCAPED_UNICODE));
pruefe(($felder['window']['LevelVariableID']['visible'] ?? false) === true && ($felder['window']['LevelVariableID']['caption'] ?? '') === 'Drive (opening)' && ($felder['window']['MovingVariableID']['visible'] ?? false) === true, 'Fenster: wahlweise Antrieb und Fahrstatus');
pruefe(($felder['window']['Tilt']['visible'] ?? false) === true && ($felder['window']['Tilt']['caption'] ?? '') === 'Tilt window (fully open means tilted)' && ($felder['blind']['Tilt']['visible'] ?? true) === false, 'Haken „Kippfenster“ nur beim Fenster');
pruefe(($felder['blind']['MovingVariableID']['onChange'] ?? '') === 'HAGT_UIDeviceChanged($id, $Type, $StateVariableID, $LevelVariableID, $MovingVariableID, $PowerVariableID, $EnergyVariableID, $StopVariableID, $SetpointVariableID, $ColorTempVariableID, $ColorVariableID, $PlaybackVariableID, $VolumeVariableID, $MuteVariableID, $TitleVariableID, $ArtistVariableID, $HumidityVariableID, $ModeVariableID, $FanVariableID, $HeatVariableID, $HeatPowerVariableID);', 'die Wahl des Fahrstatus zieht den Spaltentext nach');
pruefe(($felder['light']['StateVariableID']['type'] ?? '') === 'SelectVariable' && ($felder['light']['LevelVariableID']['type'] ?? '') === 'SelectVariable', 'Fähigkeiten sind Variablenauswahlen');
$fremd = json_decode((string)oderNull(static fn () => $m->UIDeviceForm('herd')), true);
pruefe(is_array($fremd) && $fremd === array_values($felder['light'] ?? []), 'unbekannter Typ: Dialog wie für ein Licht');

// Raum im Gerätedialog als Auswahl (B75, Wunsch doctee t/144611 #16, Burkhard 13.10.2026: nur ein angelegter Raum oder
// keiner): „-“ und die Räume der Raumliste. Ein Alias wählt seinen Raum vor. Ein Raum, der in der Raumliste fehlt, bleibt
// als eigener Eintrag stehen (ein Wert ohne Eintrag sperrt das Übernehmen), und das Formular nennt ihn über der Liste.
$rp = IPS\ObjectManager::registerObject(1);
IPS\InstanceManager::createInstance($rp, ['ModuleID' => HomeAgentHarness::MODULE_ID, 'ModuleName' => 'HomeAgent', 'ModuleType' => 3, 'Class' => HomeAgentHarness::class]);
$raumProbe = IPS\InstanceManager::getInstanceInterface($rp);
IPS_SetProperty($rp, 'Rooms', json_encode([['Name' => 'Küche', 'Aliases' => 'Kochen', 'Floor' => ''], ['Name' => 'Bad oben', 'Aliases' => 'Bad_DG', 'Floor' => '']]));
IPS_SetProperty($rp, 'Devices', json_encode([
    ['Room' => 'Wohnzimmer', 'Name' => 'Stehlampe', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => 69999, 'LevelVariableID' => 0, 'Invert' => false],
    ['Room' => 'Bad_DG', 'Name' => 'Spiegel', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => 69995, 'LevelVariableID' => 0, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Ofen', 'Aliases' => '', 'Type' => 'switch', 'StateVariableID' => 69998, 'LevelVariableID' => 0, 'Invert' => false],
    ['Room' => 'Küche ', 'Name' => 'Spüle', 'Aliases' => '', 'Type' => 'switch', 'StateVariableID' => 69997, 'LevelVariableID' => 0, 'Invert' => false],
    ['Room' => '', 'Name' => 'Ohne Raum', 'Aliases' => '', 'Type' => 'switch', 'StateVariableID' => 69996, 'LevelVariableID' => 0, 'Invert' => false],
]));
IPS_ApplyChanges($rp);
$raumFeld = static function (string $raum) use ($raumProbe): array {
    $dialog = json_decode((string)oderNull(static fn () => $raumProbe->UIDeviceForm('light', $raum)), true);
    return is_array($dialog) ? $dialog : [];
};
$raumAuswahl = static fn (string $raum): array => array_column($raumFeld($raum), null, 'name')['Room'] ?? [];
$auswahl = $raumAuswahl('Küche');
pruefe(($auswahl['type'] ?? '') === 'Select' && !isset($auswahl['onChange']), 'der Raum ist eine Auswahl: ' . json_encode($auswahl, JSON_UNESCAPED_UNICODE));
pruefe(array_column($auswahl['options'] ?? [], 'value') === ['', 'Bad oben', 'Küche'] && ($auswahl['options'][0]['caption'] ?? '') === '-', 'Einträge: „-“, dann die Räume der Raumliste sortiert: ' . json_encode($auswahl['options'] ?? [], JSON_UNESCAPED_UNICODE));
pruefe(($auswahl['value'] ?? null) === 'Küche', 'vorgewählt der Raum der Zeile');
// Die Konsole füllt den Dialog mit dem Wert der Zeile (Testbox 13.10.2026), eine andere Schreibweise oder ein Alias braucht
// deshalb einen eigenen Eintrag, sonst sperrt die Konsole das Übernehmen; die Beschriftung nennt den Raum dazu
$alias = $raumAuswahl('Kochen');
pruefe(($alias['value'] ?? null) === 'Kochen' && array_column($alias['options'] ?? [], 'caption', 'value')['Kochen'] === 'Kochen (Küche)', 'ein Alias steht als eigener Eintrag mit seinem Raum: ' . json_encode($alias['options'] ?? [], JSON_UNESCAPED_UNICODE));
$schreibweise = $raumAuswahl('küche ');
pruefe(($schreibweise['value'] ?? null) === 'küche ' && in_array('küche ', array_column($schreibweise['options'] ?? [], 'value'), true), 'eine andere Schreibweise bleibt wörtlich als Eintrag: ' . json_encode($schreibweise['options'] ?? [], JSON_UNESCAPED_UNICODE));
pruefe(($raumAuswahl('')['value'] ?? null) === '' && array_column($raumAuswahl('')['options'] ?? [], 'value') === ['', 'Bad oben', 'Küche'], 'ohne Raum steht „-“ da (Burkhard: der Raum darf leer bleiben)');
$fremdRaum = $raumAuswahl('Wohnzimmer');
pruefe(($fremdRaum['value'] ?? null) === 'Wohnzimmer' && array_column($fremdRaum['options'] ?? [], 'value') === ['', 'Bad oben', 'Küche', 'Wohnzimmer'] && str_starts_with((string)(end($fremdRaum['options'])['caption'] ?? ''), 'Wohnzimmer ('), 'ein Raum ohne Eintrag in der Raumliste bleibt als eigener Eintrag mit Hinweis: ' . json_encode($fremdRaum['options'] ?? [], JSON_UNESCAPED_UNICODE));
pruefe(!method_exists($raumProbe, 'UIRoomChosen'), 'UIRoomChosen gibt es nicht mehr');
$raumFormular = array_column(flach(json_decode($raumProbe->GetConfigurationForm(), true)['elements']), null, 'name');
pruefe(!in_array('RoomChoice', array_merge(...array_map('array_keys', $raumFormular['Devices']['values'] ?? [[]])), true), 'die Zeilen tragen kein RoomChoice mehr');
$fehlend = $raumFormular['RoomsMissing'] ?? [];
pruefe(($fehlend['visible'] ?? false) === true && str_contains((string)($fehlend['caption'] ?? ''), 'Wohnzimmer') && !str_contains((string)($fehlend['caption'] ?? ''), 'Küche') && !str_contains((string)($fehlend['caption'] ?? ''), 'Bad'), 'über der Liste stehen die Räume, die in der Raumliste fehlen: ' . ($fehlend['caption'] ?? '–'));
// Ein Alias zählt als Raum der Raumliste
IPS_SetProperty($rp, 'Devices', json_encode([
    ['Room' => 'Bad_DG', 'Name' => 'Spiegel', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => 69995, 'LevelVariableID' => 0, 'Invert' => false],
    ['Room' => '', 'Name' => 'Ohne Raum', 'Aliases' => '', 'Type' => 'switch', 'StateVariableID' => 69996, 'LevelVariableID' => 0, 'Invert' => false],
]));
IPS_ApplyChanges($rp);
$raumHinweis = array_column(flach(json_decode($raumProbe->GetConfigurationForm(), true)['elements']), null, 'name')['RoomsMissing'] ?? [];
pruefe(($raumHinweis['visible'] ?? true) === false, 'fehlt keiner, ist der Hinweis unsichtbar: ' . ($raumHinweis['caption'] ?? ''));
IPS_DeleteInstance($rp);

$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('blind', 0, 0));
pruefe(in_array(['StateVariableID', 'visible', false], $m->formUpdates, true) && in_array(['LevelVariableID', 'visible', true], $m->formUpdates, true) && in_array(['LevelVariableID', 'caption', 'Position'], $m->formUpdates, true) && in_array(['Invert', 'caption', 'Reverse direction (if open and closed are swapped)'], $m->formUpdates, true), 'Typwechsel auf Rollladen passt den offenen Dialog an: ' . json_encode($m->formUpdates));
// Ein Typ ohne Energie und Leistung blendet „gemessen von“ aus und leert es (Review 18.10.2026, M5)
$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('door', 0, 0));
pruefe(in_array(['MeteredBy', 'visible', false], $m->formUpdates, true) && in_array(['MeteredBy', 'value', 0], $m->formUpdates, true), 'Typwechsel auf Tür leert „gemessen von“: ' . json_encode(array_values(array_filter($m->formUpdates, static fn (array $u): bool => $u[0] === 'MeteredBy'))));
$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('socket', 0, 0));
pruefe(!in_array(['MeteredBy', 'value', 0], $m->formUpdates, true), 'Typwechsel auf Steckdose behält „gemessen von“');
$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('humidity', 0, 0));
pruefe(in_array(['Invert', 'visible', false], $m->formUpdates, true) && in_array(['StateVariableID', 'caption', 'Measured value'], $m->formUpdates, true), 'Typwechsel auf Messwert blendet die Umkehr aus: ' . json_encode($m->formUpdates));

IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => '', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => 69999, 'LevelVariableID' => 0, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Ofen', 'Aliases' => '', 'Type' => 'herd', 'StateVariableID' => $licht, 'LevelVariableID' => 0, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'fehlerhafte Geräte sperren nur sich selbst: aktiv');
$geraeteBereich = array_values(array_filter(json_decode($m->GetConfigurationForm(), true)['elements'], static fn (array $e): bool => in_array('Devices', array_column($e['items'] ?? [], 'name'), true)))[0] ?? [];
pruefe(($geraeteBereich['expanded'] ?? false) === false, 'eine fehlerhafte Geräteliste ändert den gemerkten Zustand nicht, der Fehler steht oben im Formular');
$log = implode("\n", array_column($m->logs(), 'Message'));
pruefe(str_contains($log, 'Device without a name (Küche): name is missing') && str_contains($log, 'Device without a name (Küche): variable for Switching state does not exist') && str_contains($log, 'Device "Ofen" (Küche): unknown type'), 'Log nennt jeden fehlerhaften Eintrag: ' . $log);

IPS_SetProperty($iid, 'Devices', '[]');
$logsVorher = count($m->logs());
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'nach Korrektur aktiv');
pruefe(!str_contains(implode("\n", array_column(array_slice($m->logs(), $logsVorher), 'Message')), 'Faulty entries'), 'nach Korrektur keine Warnung mehr');

pruefe(str_starts_with($m->RunSelfTest(), "ERROR\n") && str_contains($m->RunSelfTest(), 'is empty'), 'Selbsttest meldet die leere Liste: ' . $m->RunSelfTest());

$formular = json_decode($m->GetConfigurationForm(), true);
$endpunkt = array_values(array_filter(flach($formular['elements']), static fn ($e) => ($e['name'] ?? '') === 'Endpoint'))[0]['caption'];
pruefe(str_ends_with($endpunkt, '/hook/homeagent/' . $iid), 'Formular zeigt den Endpunkt: ' . $endpunkt);

/* --- Textspalten der Geräteliste: Name der Variable oder „-“ statt „Kein(e)“ --------------- */

$schalter = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetName($schalter, 'Status');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Lampe', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $schalter, 'LevelVariableID' => 1, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => $schalter, 'LevelVariableID' => 69998, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
$formular = json_decode($m->GetConfigurationForm(), true);
$geraete = array_values(array_filter(flach($formular['elements']), static fn ($e) => ($e['name'] ?? '') === 'Devices'))[0];
$spalten = array_column($geraete['columns'], null, 'name');
pruefe(isset($spalten['StateLabel'], $spalten['LevelLabel']) && !isset($spalten['StateLabel']['edit']) && ($spalten['StateLabel']['add'] ?? null) === '-', 'sichtbar sind Textspalten ohne Variablenauswahl, neue Zeilen zeigen „-“');
pruefe(($spalten['StateVariableID']['visible'] ?? true) === false && ($spalten['LevelVariableID']['visible'] ?? true) === false && isset($spalten['StateVariableID']['edit']), 'die Variablen selbst bleiben als unsichtbare, gespeicherte Spalten in der Zeile');
$werte = $geraete['values'] ?? [];
pruefe(($werte[0] ?? null) === ['StateLabel' => 'Status', 'LevelLabel' => '-', 'MovingLabel' => '-', 'StopLabel' => '-', 'PowerLabel' => '-', 'EnergyLabel' => '-', 'SetpointLabel' => '-', 'ColorTempLabel' => '-', 'ColorLabel' => '-', 'PlaybackLabel' => '-', 'VolumeLabel' => '-', 'MuteLabel' => '-', 'TitleLabel' => '-', 'ArtistLabel' => '-', 'HumidityLabel' => '-', 'ModeLabel' => '-', 'FanLabel' => '-', 'HeatLabel' => '-', 'HeatPowerLabel' => '-', 'rowColor' => ''], 'Licht ohne Helligkeit: Name der Variable und „-“: ' . json_encode($werte[0] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(($werte[1] ?? null) === ['StateLabel' => '-', 'LevelLabel' => '#69998 ?', 'MovingLabel' => '-', 'StopLabel' => '-', 'PowerLabel' => '-', 'EnergyLabel' => '-', 'SetpointLabel' => '-', 'ColorTempLabel' => '-', 'ColorLabel' => '-', 'PlaybackLabel' => '-', 'VolumeLabel' => '-', 'MuteLabel' => '-', 'TitleLabel' => '-', 'ArtistLabel' => '-', 'HumidityLabel' => '-', 'ModeLabel' => '-', 'FanLabel' => '-', 'HeatLabel' => '-', 'HeatPowerLabel' => '-', 'rowColor' => '#FFC0C0'], 'Rollladen: der Zustand zählt nicht zum Typ, eine gelöschte Variable ist kenntlich und die Zeile rot: ' . json_encode($werte[1] ?? null, JSON_UNESCAPED_UNICODE));

$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('light', $schalter, 0));
pruefe(in_array(['StateLabel', 'value', 'Status'], $m->formUpdates, true) && in_array(['LevelLabel', 'value', '-'], $m->formUpdates, true), 'eine andere Variable im Dialog zieht den Spaltentext nach: ' . json_encode($m->formUpdates, JSON_UNESCAPED_UNICODE));
$dialog = array_column(json_decode($m->UIDeviceForm('light'), true), null, 'name');
pruefe(($dialog['StateLabel']['visible'] ?? true) === false && ($dialog['StateVariableID']['onChange'] ?? '') === 'HAGT_UIDeviceChanged($id, $Type, $StateVariableID, $LevelVariableID, $MovingVariableID, $PowerVariableID, $EnergyVariableID, $StopVariableID, $SetpointVariableID, $ColorTempVariableID, $ColorVariableID, $PlaybackVariableID, $VolumeVariableID, $MuteVariableID, $TitleVariableID, $ArtistVariableID, $HumidityVariableID, $ModeVariableID, $FanVariableID, $HeatVariableID, $HeatPowerVariableID);', 'der Dialog trägt die Spaltentexte unsichtbar mit und meldet jede Variablenwahl');
$laeuft = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetName($laeuft, 'WORKING');
$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('blind', 0, 0, $laeuft));
pruefe(in_array(['MovingLabel', 'value', 'WORKING'], $m->formUpdates, true), 'die Wahl des Fahrstatus zieht den unsichtbaren Text nach: ' . json_encode($m->formUpdates, JSON_UNESCAPED_UNICODE));

/* --- Fenster mit Antrieb im Werkzeugschema (09.10.2026) ------------------------------------- */

$antrieb = variable(VARIABLETYPE_FLOAT, 1.0);
$antriebAktion = IPS_CreateScript(0);
IPS_SetScriptContent($antriebAktion, '<?php SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
IPS_SetVariableCustomAction($antrieb, $antriebAktion);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Flur', 'Name' => 'Fenster', 'Aliases' => '', 'Type' => 'window', 'StateVariableID' => 0, 'LevelVariableID' => $antrieb, 'Invert' => false, 'Tilt' => true],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Fenster nur mit Antrieb ist gültig');
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$schema = $werkzeuge['set_state']['inputSchema']['properties'] ?? [];
pruefe(in_array('window', $schema['type']['enum'] ?? [], true) && ($schema['command']['enum'] ?? []) === ['open', 'close'], 'set_state bietet window mit open und close an: ' . json_encode($schema['type']['enum'] ?? null) . ' ' . json_encode($schema['command']['enum'] ?? null));
pruefe(str_contains($schema['percent']['description'] ?? '', 'how far a blind or window is open'), 'percent heißt bei Rollläden und Fenstern „wie weit offen“: ' . ($schema['percent']['description'] ?? ''));
// HA Assist am nuc (09.10.2026): „Kipp das Fenster im Flur wieder“ lehnte das Modell ab, es kenne nur
// öffnen und schließen. Bei einem Kippfenster heißt öffnen kippen; das muss in der Beschreibung stehen.
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', 'Tilt windows open by tilting: "tilt the window" means command=open.'), 'die Beschreibung erklärt das Kippen: ' . ($werkzeuge['set_state']['description'] ?? ''));
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Flur', 'Name' => 'Fenster', 'Aliases' => '', 'Type' => 'window', 'StateVariableID' => 0, 'LevelVariableID' => $antrieb, 'Invert' => false, 'Tilt' => false],
]));
IPS_ApplyChanges($iid);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
pruefe(!str_contains($werkzeuge['set_state']['description'] ?? '', 'Tilt windows'), 'ohne Kippfenster kein Satz dazu');

/* --- Fahrstatus: Validierung, Selbsttest, Fahrtmerker im Buffer (Rollladen-Paket 09.10.2026) --- */

IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rollo, 'MovingVariableID' => 69997, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'gelöschte Fahrstatus-Variable sperrt nur das Gerät: aktiv');
pruefe(str_contains(implode("\n", array_column($m->logs(), 'Message')), 'Device "Rollo" (Küche): variable for Moving state does not exist'), 'das Log nennt den fehlenden Fahrstatus');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rollo, 'MovingVariableID' => $laeuft, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'mit Fahrstatus aktiv');
pruefe(str_contains($m->RunSelfTest(), 'Blind Küche Rollo reports its movement through WORKING'), 'der Selbsttest nennt den Fahrstatus: ' . $m->RunSelfTest());
// Der Fahrstatus wird nur gelesen und braucht keine Aktion (nuc 09.10.2026: Homematic WORKING hat
// keine, der Selbsttest meldete „hat keine Aktion“, obwohl die Position eine hat).
$position = variable(VARIABLETYPE_INTEGER, 0);
$positionAktion = IPS_CreateScript(0);
IPS_SetScriptContent($positionAktion, '<?php SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
IPS_SetVariableCustomAction($position, $positionAktion);
IPS_SetVariableCustomProfile($position, '~Shutter');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Gast', 'Name' => 'Rollladen', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $position, 'MovingVariableID' => $laeuft, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(!str_contains($m->RunSelfTest(), 'Note: Gast Rollladen has no action'), 'Fahrstatus ohne Aktion: kein Hinweis „hat keine Aktion“: ' . $m->RunSelfTest());
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rollo, 'MovingVariableID' => $laeuft, 'Invert' => false],
]));
IPS_ApplyChanges($iid);

$merker = $m->testBackend();
$merker->rememberTravel($rollo, ['target' => 100, 'since' => $merker->now()]);
pruefe($m->testBackend()->rememberedTravel($rollo) === ['target' => 100, 'since' => $merker->rememberedTravel($rollo)['since'] ?? -1], 'der Fahrtmerker überlebt die Anfrage (Buffer „Travels“)');
$merker->rememberTravel($rollo, null);
pruefe($m->testBackend()->rememberedTravel($rollo) === null, 'und lässt sich löschen');
$merker->rememberTravel(11111, ['target' => 1, 'since' => $merker->now() - 130000]);
$merker->rememberTravel($rollo, ['target' => 50, 'since' => $merker->now()]);
pruefe($m->testBackend()->rememberedTravel(11111) === null, 'Merker älter als 2 Minuten fallen beim nächsten Schreiben heraus');
for ($i = 1; $i <= 60; $i++) {
    $merker->rememberTravel(20000 + $i, ['target' => $i, 'since' => $merker->now()]);
}
pruefe($m->testBackend()->rememberedTravel(20001) === null && $m->testBackend()->rememberedTravel(20060) !== null, 'höchstens 50 Merker, die ältesten gehen zuerst');

/* --- Stopp (09.10.2026): Stoppvariable, sonst Weg nach Modul (HomeMatic, KLF200) -------------- */

pruefe(($felder['blind']['StopVariableID']['visible'] ?? false) === true && ($felder['blind']['StopVariableID']['caption'] ?? '') === 'Stop' && ($felder['blind']['StopVariableID']['type'] ?? '') === 'SelectVariable', 'Rollladen: wahlweise eine Stoppvariable');
pruefe(($felder['window']['StopVariableID']['visible'] ?? false) === true && ($felder['light']['StopVariableID']['visible'] ?? true) === false, 'Fenster ja, Licht nein');
$stoppTaster = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetName($stoppTaster, 'STOP');
$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('blind', 0, 0, 0, 0, 0, $stoppTaster));
pruefe(in_array(['StopLabel', 'value', 'STOP'], $m->formUpdates, true), 'die Wahl der Stoppvariable zieht den unsichtbaren Text nach: ' . json_encode($m->formUpdates, JSON_UNESCAPED_UNICODE));

// Ein Stopp über die Modulfunktion hat keinen Wert. IPS_RunScriptTextEx nimmt null in den Parametern
// nicht an (nuc 09.10.2026: „Cannot auto-convert value for parameter Parameters (Type is not
// supported)“, der Kernel-Stub nimmt es an). Die Parameter dürfen deshalb kein null tragen.
$parameter = !method_exists(SymconDeviceBackend::class, 'actionParameters') ? null : ((new ReflectionMethod(SymconDeviceBackend::class, 'actionParameters'))->invoke(null, 4711, null, $iid, 'a1b2a1b2a1b2'));
pruefe(is_array($parameter) && !in_array(null, $parameter, true) && ($parameter['HAGT_VARIABLE'] ?? 0) === 4711, 'Parameter des Aktionsskripts ohne null: ' . json_encode($parameter));
$parameter = !method_exists(SymconDeviceBackend::class, 'actionParameters') ? null : ((new ReflectionMethod(SymconDeviceBackend::class, 'actionParameters'))->invoke(null, 4711, 2, $iid, 'a1b2a1b2a1b2'));
pruefe(($parameter['HAGT_VALUE'] ?? null) === 2, 'mit Wert steht er in HAGT_VALUE: ' . json_encode($parameter));

// Stoppweg an der Anlage: Wahrheitswert mit Aktion, Eintrag „Stop“ im Profil, sonst keiner
$stoppAktion = IPS_CreateScript(0);
IPS_SetScriptContent($stoppAktion, '<?php SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
IPS_SetVariableCustomAction($stoppTaster, $stoppAktion);
$haAktion = variable(VARIABLETYPE_INTEGER, 0);
IPS_SetName($haAktion, 'Aktion');
IPS_CreateVariableProfile('VoiceControlTest.CoverAction', VARIABLETYPE_INTEGER);
IPS_SetVariableProfileAssociation('VoiceControlTest.CoverAction', 0, 'Öffnen', '', -1);
IPS_SetVariableProfileAssociation('VoiceControlTest.CoverAction', 1, 'Schließen', '', -1);
IPS_SetVariableProfileAssociation('VoiceControlTest.CoverAction', 2, 'Stop', '', -1);
IPS_SetVariableCustomProfile($haAktion, 'VoiceControlTest.CoverAction');
IPS_SetVariableCustomAction($haAktion, $stoppAktion);
$ohneStopp = variable(VARIABLETYPE_INTEGER, 0);
IPS_SetVariableCustomAction($ohneStopp, $stoppAktion);
$stoppweg = static fn (int $position, int $stopp): ?string => oderNull(static fn () => $m->testBackend()->stopMethod($position, $stopp));
pruefe($stoppweg($position, $stoppTaster) === 'variable', 'Wahrheitswert mit Aktion taugt als Stoppvariable: ' . json_encode($stoppweg($position, $stoppTaster)));
pruefe($stoppweg($position, $haAktion) === 'variable', 'Zahl mit Profileintrag „Stop“ ebenso (HomeAssistant-Modul): ' . json_encode($stoppweg($position, $haAktion)));
pruefe($stoppweg($position, $ohneStopp) === '' && $stoppweg($position, 0) === '', 'ohne Eintrag „Stop“ und ohne passendes Modul kein Stoppweg: ' . json_encode([$stoppweg($position, $ohneStopp), $stoppweg($position, 0)]));
oderNull(static fn () => $m->testBackend()->stop($position, $haAktion));
pruefe(GetValue($haAktion) === 2, 'der Stopp sendet den Wert des Eintrags „Stop“ an die Aktion: ' . json_encode(GetValue($haAktion)));

// Schema, Selbsttest und Validierung
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Gast', 'Name' => 'Rollladen', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $position, 'StopVariableID' => $haAktion, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'mit Stoppvariable aktiv');
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$schema = $werkzeuge['set_state']['inputSchema']['properties'] ?? [];
pruefe(($schema['command']['enum'] ?? []) === ['open', 'close', 'stop'], 'set_state bietet stop an: ' . json_encode($schema['command']['enum'] ?? null));
pruefe(str_contains($schema['command']['description'] ?? '', 'stop halts a moving blind or window'), 'die Beschreibung erklärt stop: ' . ($schema['command']['description'] ?? ''));
pruefe(str_contains($m->RunSelfTest(), 'Blind Gast Rollladen stops through Aktion'), 'der Selbsttest nennt den Stoppweg: ' . $m->RunSelfTest());
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Gast', 'Name' => 'Rollladen', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $position, 'StopVariableID' => $ohneStopp, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(str_contains($m->RunSelfTest(), 'Note: blind Gast Rollladen: the stop variable has no entry "Stop", the assistant cannot stop it'), 'eine Stoppvariable ohne Eintrag „Stop“ fällt im Selbsttest auf: ' . $m->RunSelfTest());
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
pruefe(!in_array('stop', $werkzeuge['set_state']['inputSchema']['properties']['command']['enum'] ?? [], true) && isset($werkzeuge['set_state']), 'dann bietet das Schema kein stop an');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Gast', 'Name' => 'Rollladen', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $position, 'StopVariableID' => 69996, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE && str_contains(implode("\n", array_column($m->logs(), 'Message')), 'Device "Rollladen" (Gast): variable for Stop does not exist'), 'gelöschte Stoppvariable: aktiv, das Log nennt sie');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rollo, 'MovingVariableID' => $laeuft, 'Invert' => false],
]));
IPS_ApplyChanges($iid);

/* --- Leistung und Verbrauch (09.10.2026) ----------------------------------------------------- */

pruefe(($felder['socket']['PowerVariableID']['visible'] ?? false) === true && ($felder['socket']['PowerVariableID']['caption'] ?? '') === 'Power'
    && ($felder['socket']['EnergyVariableID']['visible'] ?? false) === true && ($felder['socket']['EnergyVariableID']['caption'] ?? '') === 'Energy meter (archived as counter)', 'Steckdose: wahlweise Leistung und Zähler');
pruefe(($felder['light']['PowerVariableID']['visible'] ?? false) === true && ($felder['window']['PowerVariableID']['visible'] ?? true) === false && ($felder['temperature']['EnergyVariableID']['visible'] ?? true) === false, 'Licht ja, Fenster und Messwert nein');
$leistung = variable(VARIABLETYPE_FLOAT, 26.1);
IPS_SetName($leistung, 'POWER');
$zaehler = variable(VARIABLETYPE_FLOAT, 427530.6);
IPS_SetName($zaehler, 'ENERGY_COUNTER');
IPS_CreateVariableProfile('VoiceControlTest.Wh', VARIABLETYPE_FLOAT);
IPS_SetVariableProfileText('VoiceControlTest.Wh', '', ' Wh');
IPS_SetVariableCustomProfile($zaehler, 'VoiceControlTest.Wh');
$steckdose = variable(VARIABLETYPE_BOOLEAN, true);
$steckdoseAktion = IPS_CreateScript(0);
IPS_SetScriptContent($steckdoseAktion, '<?php SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
IPS_SetVariableCustomAction($steckdose, $steckdoseAktion);
$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('socket', $steckdose, 0, 0, $leistung, $zaehler));
pruefe(in_array(['PowerLabel', 'value', 'POWER'], $m->formUpdates, true) && in_array(['EnergyLabel', 'value', 'ENERGY_COUNTER'], $m->formUpdates, true), 'die Wahl von Leistung und Zähler zieht die Texte nach: ' . json_encode($m->formUpdates, JSON_UNESCAPED_UNICODE));

$entfeuchter = ['Room' => 'Keller', 'Name' => 'Entfeuchter', 'Aliases' => '', 'Type' => 'socket', 'StateVariableID' => $steckdose, 'PowerVariableID' => $leistung, 'EnergyVariableID' => $zaehler, 'Invert' => false];
IPS_SetProperty($iid, 'Devices', json_encode([['EnergyVariableID' => 69996] + $entfeuchter]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE && str_contains(implode("\n", array_column($m->logs(), 'Message')), 'Device "Entfeuchter" (Keller): variable for Energy meter (archived as counter) does not exist'), 'gelöschter Zähler: aktiv, das Log nennt ihn');

// Ohne Archiv: gültig, aber der Selbsttest sagt, dass es keinen Verlauf gibt
IPS_SetProperty($iid, 'Devices', json_encode([$entfeuchter]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Gerät mit Leistung und Zähler: aktiv');
pruefe(str_contains($m->RunSelfTest(), 'Note: Keller Entfeuchter: the energy meter ENERGY_COUNTER is not archived as a counter, so there is no consumption over periods'), 'Selbsttest: Zähler ohne Archiv: ' . $m->RunSelfTest());
// Wärmezähler (B83): derselbe Hinweis wie beim Stromzähler
$waermezaehler = variable(VARIABLETYPE_FLOAT, 23.642);
IPS_SetName($waermezaehler, 'V_Heizen_Summe Wärme');
$waermepumpe = ['Room' => 'Heizungsraum', 'Name' => 'Wärmepumpe', 'Aliases' => '', 'Type' => 'appliance', 'StateVariableID' => $steckdose, 'HeatVariableID' => $waermezaehler, 'Invert' => false];
IPS_SetProperty($iid, 'Devices', json_encode([$entfeuchter, $waermepumpe]));
IPS_ApplyChanges($iid);
$selbsttest = $m->RunSelfTest();
pruefe(str_contains($selbsttest, 'Note: Heizungsraum Wärmepumpe: the heat meter V_Heizen_Summe Wärme is not archived as a counter, so there is no heat over periods'), 'Selbsttest: Wärmezähler ohne Archiv: ' . $selbsttest);
$beschreibung = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name')['get_state']['description'] ?? '';
pruefe(str_contains($beschreibung, '"What was the performance factor of the heat pump in January?" → name=heat pump, from=' . date('Y') . '-01-01, to=' . date('Y') . '-01-31 (heat, electricity and performance factor in one line). '), 'mit Wärmezähler nennt get_state ein Beispiel für die Arbeitszahl: ' . $beschreibung);
// Ohne Wärmezähler kommt die Wärme aus der Wärmeleistung; die muss dann archiviert sein
$waermeleistung = variable(VARIABLETYPE_FLOAT, 4.18);
IPS_SetName($waermeleistung, 'Wärmeleistung');
IPS_SetProperty($iid, 'Devices', json_encode([$entfeuchter, array_merge($waermepumpe, ['HeatVariableID' => 0, 'HeatPowerVariableID' => $waermeleistung])]));
IPS_ApplyChanges($iid);
$selbsttest = $m->RunSelfTest();
pruefe(str_contains($selbsttest, 'Note: Heizungsraum Wärmepumpe: the heat output Wärmeleistung is not archived, so there is no heat over periods'), 'Selbsttest: Wärmeleistung ohne Archiv und ohne Wärmezähler: ' . $selbsttest);
$beschreibung = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name')['get_state']['description'] ?? '';
pruefe(str_contains($beschreibung, 'performance factor of the heat pump'), 'auch mit Wärmeleistung allein nennt get_state das Beispiel: ' . $beschreibung);
IPS_SetProperty($iid, 'Devices', json_encode([$entfeuchter, array_merge($waermepumpe, ['HeatPowerVariableID' => $waermeleistung])]));
IPS_ApplyChanges($iid);
pruefe(!str_contains($m->RunSelfTest(), 'the heat output'), 'mit Wärmezähler braucht die Wärmeleistung kein Archiv: ' . $m->RunSelfTest());
IPS_SetProperty($iid, 'Devices', json_encode([$entfeuchter]));
IPS_ApplyChanges($iid);
$beschreibung = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name')['get_state']['description'] ?? '';
pruefe(!str_contains($beschreibung, 'performance factor'), 'ohne Wärmezähler kein Beispiel (Tokens): ' . $beschreibung);
IPS_SetProperty($iid, 'Devices', json_encode([$entfeuchter]));
IPS_ApplyChanges($iid);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$periode = $werkzeuge['get_state']['inputSchema']['properties']['period'] ?? [];
pruefe(($periode['enum'] ?? null) === ['now', 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'], 'get_state bietet period an, sobald ein Gerät einen Zähler hat: ' . json_encode($periode));
// HA Assist am nuc (09.10.2026): „Wie viel Strom haben wir heute schon verbraucht?“ beantwortete das
// Modell mit der Summe der Geräte mit Zähler als Hausverbrauch. Die Beschreibung muss die Grenze nennen.
pruefe(str_contains($periode['description'] ?? '', 'Covers only devices with their own energy meter, not the whole house.'), 'period sagt, dass nur Geräte mit eigenem Zähler zählen: ' . ($periode['description'] ?? ''));
pruefe(str_contains($werkzeuge['get_state']['description'] ?? '', '"How much did the washing machine use last month?" → name=washing machine, period=last_month'), 'die Beschreibung nennt ein Beispiel für den Verbrauch: ' . ($werkzeuge['get_state']['description'] ?? ''));
// Zeitleiste (B69, 13.10.2026): period nennt auch „wann und wie lange“ für Geräte mit Zustand
pruefe(str_contains($periode['description'] ?? '', 'For devices with on/off, open/closed or a selection: when and how long each state lasted (archived states only).'), 'period nennt die Zeitleiste: ' . ($periode['description'] ?? ''));
// Freie Zeiträume (09.10.2026): from und to neben period, mit dem heutigen Datum für das Modell
$von = $werkzeuge['get_state']['inputSchema']['properties']['from'] ?? [];
$bis = $werkzeuge['get_state']['inputSchema']['properties']['to'] ?? [];
pruefe(($von['type'] ?? '') === 'string' && str_contains($von['description'] ?? '', 'Instead of period: first day, YYYY-MM-DD, or a start hour, YYYY-MM-DD HH:MM. Today is ' . date('Y-m-d') . '.'), 'from mit Format und heutigem Datum: ' . json_encode($von));
pruefe(($bis['type'] ?? '') === 'string' && str_contains($bis['description'] ?? '', 'Last day, inclusive; without it only the day given in from.'), 'to: ' . json_encode($bis));
pruefe(str_contains($werkzeuge['get_state']['description'] ?? '', '"How much did the washing machine use in January?" → name=washing machine, from=' . date('Y') . '-01-01, to=' . date('Y') . '-01-31.'), 'Beispiel für einen Monat: ' . ($werkzeuge['get_state']['description'] ?? ''));
$text = $m->QueryState('Keller', '', 'Entfeuchter', false);
pruefe(str_contains($text, 'Keller | Entfeuchter | socket | on (26.1'), 'QueryState nennt die Leistung: ' . $text);

// Mit Archiv als Zähler: die Summe kommt aus AC_GetAggregatedValues
IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
$archiv = IPS_CreateInstance('{43192F0B-135B-4CE7-A0A7-1475603F3060}'); // Archive Control
AC_SetLoggingStatus($archiv, $zaehler, true);
AC_SetAggregationType($archiv, $zaehler, 1);
$tag = mktime(0, 0, 0, 10, 8, 2026);
AC_StubsAddAggregatedValues($archiv, $zaehler, 1, [
    ['TimeStamp' => $tag, 'Duration' => 86400, 'Avg' => 2868.4, 'Min' => 0, 'Max' => 0, 'MinTime' => 0, 'MaxTime' => 0],
    ['TimeStamp' => $tag + 86400, 'Duration' => 86400, 'Avg' => 1387.2, 'Min' => 0, 'Max' => 0, 'MinTime' => 0, 'MaxTime' => 0],
]);
pruefe(!str_contains($m->RunSelfTest(), 'is not archived as a counter'), 'Selbsttest: archivierter Zähler ohne Hinweis: ' . $m->RunSelfTest());
$summe = oderNull(static fn () => $m->testBackend()->counterSum($zaehler, 1, $tag, $tag + 86399));
pruefe($summe === ['sum' => 2868.4, 'unit' => 'Wh'], 'counterSum über das Archiv, Einheit aus dem Profil: ' . json_encode($summe));
$summe = oderNull(static fn () => $m->testBackend()->counterSum($zaehler, 1, $tag, 0));
pruefe(is_array($summe) && abs($summe['sum'] - 4255.6) < 0.001, 'bis jetzt zählt der laufende Tag mit: ' . json_encode($summe));
$reihe = oderNull(static fn () => $m->testBackend()->counterSeries($zaehler, 1, $tag, 0));
pruefe($reihe === ['rows' => [['time' => $tag, 'sum' => 2868.4], ['time' => $tag + 86400, 'sum' => 1387.2]], 'unit' => 'Wh'], 'counterSeries je Zeitraum aufsteigend, für das Stapeldiagramm: ' . json_encode($reihe));
pruefe(oderNull(static fn () => $m->testBackend()->counterSeries($leistung, 1, $tag, 0)) === null, 'counterSeries ohne Zähler im Archiv: null');
pruefe(oderNull(static fn () => $m->testBackend()->counterSum($leistung, 1, $tag, 0)) === null, 'nicht archiviert: null');
AC_SetLoggingStatus($archiv, $leistung, true);
pruefe(oderNull(static fn () => $m->testBackend()->counterSum($leistung, 1, $tag, 0)) === null, 'archiviert, aber nicht als Zähler: null');
pruefe(is_int(oderNull(static fn () => $m->testBackend()->timestamp())), 'die Uhr der Anlage');

// Grenze von 10000 Datensätzen je Abfrage (Review 14.10.2026). Echt ist die Grenze: laut Doku AC_GetAggregatedValues und
// AC_GetLoggedValues „10000 ist das harte Limit, welches immer greift“, die neuesten zuerst; der Kernel-Stub bildet das
// nach. Angenommen sind die Werte: 15000 Stunden (from=2025-01-01 bis Mitte 2026, so rechnen Kosten und Heatmap) und 12000
// Änderungen eines Präsenzmelders. Vorher fehlten die ältesten 5000 Stunden bzw. 2000 Änderungen ohne Hinweis.
$beginn = mktime(0, 0, 0, 1, 1, 2025);
$stunden = static fn (int $anzahl, int $wert): array => array_map(static fn (int $i): array => ['TimeStamp' => $beginn + $i * 3600, 'Duration' => 3600, 'Avg' => $wert, 'Min' => 0, 'Max' => $wert, 'MinTime' => 0, 'MaxTime' => 0], range(0, $anzahl - 1));
$stundenzaehler = variable(VARIABLETYPE_FLOAT, 0.0);
AC_SetLoggingStatus($archiv, $stundenzaehler, true);
AC_SetAggregationType($archiv, $stundenzaehler, 1);
AC_StubsAddAggregatedValues($archiv, $stundenzaehler, 0, $stunden(15000, 1));
$reihe = oderNull(static fn () => $m->testBackend()->counterSeries($stundenzaehler, 0, $beginn, $beginn + 15000 * 3600 - 1));
pruefe(count($reihe['rows'] ?? []) === 15000 && ($reihe['rows'][0]['time'] ?? 0) === $beginn && ($reihe['rows'][14999]['time'] ?? 0) === $beginn + 14999 * 3600, 'counterSeries über 15000 Stunden: alle, die ältesten zuerst: ' . count($reihe['rows'] ?? []) . ' ab ' . date('Y-m-d H:i', $reihe['rows'][0]['time'] ?? 0));
$messwert = variable(VARIABLETYPE_FLOAT, 0.0);
AC_SetLoggingStatus($archiv, $messwert, true);
AC_StubsAddAggregatedValues($archiv, $messwert, 0, $stunden(12000, 20));
$reihe = oderNull(static fn () => $m->testBackend()->history($messwert, 0, $beginn, $beginn + 12000 * 3600 - 1));
pruefe(count($reihe['rows'] ?? []) === 12000 && ($reihe['rows'][0]['time'] ?? 0) === $beginn, 'history über 12000 Stunden: alle: ' . count($reihe['rows'] ?? []));
$praesenz = variable(VARIABLETYPE_BOOLEAN, false);
AC_SetLoggingStatus($archiv, $praesenz, true);
AC_AddLoggedValues($archiv, $praesenz, array_map(static fn (int $i): array => ['TimeStamp' => $beginn + 60 + $i * 600, 'Value' => $i % 2 === 0], range(0, 11999)));
$reihe = oderNull(static fn () => $m->testBackend()->stateSeries($praesenz, $beginn, $beginn + 12000 * 600));
pruefe(count($reihe['changes'] ?? []) === 12000 && ($reihe['changes'][0] ?? null) === [$beginn + 60, true] && ($reihe['changes'][11999][0] ?? 0) === $beginn + 60 + 11999 * 600, 'stateSeries mit 12000 Änderungen: alle, die erste zuerst: ' . count($reihe['changes'] ?? []) . ' / ' . json_encode($reihe['changes'][0] ?? null));

/* --- Energiebilanz (09.10.2026, seit B82 als Liste der Quellen): Hausverbrauch wie bei HA ------- */

// Seit B82 (Burkhard 17.10.2026) steht die Bilanz nur noch in der Liste „Quellen“, eine Zeile je Netzanschluss, PV-Anlage
// oder Batterie. Die Felder der Bilanz gibt es im Formular nicht mehr, die Properties bleiben für die Übernahme.
$bilanzFelder = ['GridImportVariableID', 'GridExportVariableID', 'SolarVariableID', 'BatteryChargeVariableID', 'BatteryDischargeVariableID'];
$leistungFelder = ['GridImportPowerVariableID', 'GridExportPowerVariableID', 'SolarPowerVariableID', 'BatteryChargePowerVariableID', 'BatteryDischargePowerVariableID'];
$formular = json_decode($m->GetConfigurationForm(), true);
$namen = array_column(flach($formular['elements']), 'name');
pruefe(in_array('Sources', $namen, true) && array_intersect(array_merge($bilanzFelder, $leistungFelder, ['InvertGridPower', 'InvertBatteryPower']), $namen) === [], 'die Bilanz steht im Formular nur als Liste, ohne die alten Felder: ' . json_encode(array_values(array_intersect(array_merge($bilanzFelder, $leistungFelder, ['InvertGridPower', 'InvertBatteryPower']), $namen))));
$bilanzZeile = static fn (string $art, string $name, int $ein = 0, int $aus = 0, int $leistungEin = 0, int $leistungAus = 0, bool $umkehren = false): array => ['Type' => $art, 'Name' => $name, 'EnergyIn' => $ein, 'EnergyOut' => $aus, 'PowerIn' => $leistungEin, 'PowerOut' => $leistungAus, 'Invert' => $umkehren];
$setzeBilanz = static function (array $zeilen) use ($iid): void {
    IPS_SetProperty($iid, 'Sources', json_encode($zeilen));
    IPS_ApplyChanges($iid);
};
// Nur Bilanz, kein Gerät mit Zähler: period gibt es trotzdem, und die Beschreibung nennt das ganze Haus
IPS_SetProperty($iid, 'Devices', json_encode([['EnergyVariableID' => 0] + $entfeuchter]));
$setzeBilanz([$bilanzZeile('grid', 'Grid', $zaehler)]);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'mit Netzbezug aktiv');
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$periode = $werkzeuge['get_state']['inputSchema']['properties']['period'] ?? [];
pruefe(isset($periode['enum']), 'period gibt es auch nur mit der Bilanz: ' . json_encode($periode));
$von = $werkzeuge['get_state']['inputSchema']['properties']['from']['description'] ?? '';
pruefe(str_contains($von, 'YYYY-MM-DD HH:MM') && str_contains($werkzeuge['get_state']['inputSchema']['properties']['to']['description'] ?? '', 'HH:MM'), 'from und to nennen die Uhrzeit (Burkhard 12.10.2026: stundengenau): ' . $von);
pruefe(str_contains($periode['description'] ?? '', 'For the whole house (no room, type or name) the first line gives the house consumption from grid, solar and battery.') && !str_contains($periode['description'] ?? '', 'not the whole house'), 'period nennt den Hausverbrauch: ' . ($periode['description'] ?? ''));
pruefe(!str_contains($m->RunSelfTest(), 'energy balance'), 'archivierter Netzbezug: kein Hinweis: ' . $m->RunSelfTest());
// Hausverbrauch als Stapeldiagramm (Burkhard 12.10.2026): show_chart gibt es mit Bilanz auch ohne Messwert
$mitApps = array_column(json_decode((string)json_encode(oderNull(static fn () => \Closure::bind(fn () => $this->tools(null, true), $m, HomeAgent::class)()) ?? []), true), null, 'name');
pruefe(isset($mitApps['show_chart']) && str_contains($mitApps['show_chart']['description'] ?? '', 'stacked bars'), 'mit Bilanz: show_chart nennt den Hausverbrauch als Stapel: ' . ($mitApps['show_chart']['description'] ?? json_encode(array_keys($mitApps))));
// Ohne Messwert und Zähler bleibt vom Feld type nur power, weil der Entfeuchter eine Leistung hat (B84)
pruefe(($mitApps['show_chart']['inputSchema']['properties']['type']['enum'] ?? null) === ['power'], 'ohne Messwert und Zähler nur type=power: ' . json_encode($mitApps['show_chart']['inputSchema']['properties']['type'] ?? null));
pruefe(in_array('power', $werkzeuge['get_state']['inputSchema']['properties']['type']['enum'] ?? [], true) && str_contains($werkzeuge['get_state']['inputSchema']['properties']['type']['description'] ?? '', 'minimum and maximum power'), 'get_state bietet type=power an, wenn ein Gerät eine Leistung hat: ' . json_encode($werkzeuge['get_state']['inputSchema']['properties']['type'] ?? null));
// Die Claude-App beantwortete „min und max Werte der Leistung für Oktober“ trotz type=power in der Liste mit 17 Abfragen je
// Tag und nannte Tagesverbräuche in kWh (nuc, 18.10.2026). Beispiel fürs Haus und Hinweis bei period führen zu type=power.
pruefe(str_contains($werkzeuge['get_state']['description'] ?? '', '"Minimum and maximum power in October?" → type=power, from=' . date('Y') . '-10-01, to=' . date('Y') . '-10-31.'), 'get_state nennt ein Beispiel für Minimum und Maximum der Leistung: ' . ($werkzeuge['get_state']['description'] ?? ''));
pruefe(str_contains($werkzeuge['get_state']['inputSchema']['properties']['period']['description'] ?? '', 'For minimum and maximum power over a period add type=power (one call, not one per day).'), 'period verweist für Minimum und Maximum der Leistung auf type=power: ' . ($werkzeuge['get_state']['inputSchema']['properties']['period']['description'] ?? ''));
// B87 (Vergleichslauf 18.10.2026): „Ist die Fußbodenheizung an?“ ergab in 2 von 13 Läufen type=thermostat und „keine
// Fußbodenheizung“, die Geräte sind Schalter. Ein genanntes Gerät gehört in name.
$genannt = 'A device the user names (e.g. floor heating) goes into name; leave type empty unless the type is clear.';
pruefe(str_contains($werkzeuge['get_state']['inputSchema']['properties']['type']['description'] ?? '', $genannt), 'get_state: type schickt genannte Geräte zu name: ' . json_encode($werkzeuge['get_state']['inputSchema']['properties']['type']['description'] ?? null));
pruefe(str_contains($werkzeuge['set_state']['inputSchema']['properties']['type']['description'] ?? '', $genannt), 'set_state: type schickt genannte Geräte zu name: ' . json_encode($werkzeuge['set_state']['inputSchema']['properties']['type']['description'] ?? null));
// Teil der Bilanz (B74, 13.10.2026): part nennt nur die eingetragenen Zähler
pruefe(($mitApps['show_chart']['inputSchema']['properties']['part']['enum'] ?? null) === ['grid'], 'show_chart bietet part nur mit den Teilen der Bilanz an: ' . json_encode($mitApps['show_chart']['inputSchema']['properties']['part'] ?? null));
// Ringe für Autarkie und Eigenverbrauch (Anregung pitti, t/144633, 13.10.2026)
pruefe(str_contains($mitApps['show_chart']['description'] ?? '', 'with self-sufficiency and self-consumed solar as rings'), 'show_chart nennt die Ringe: ' . ($mitApps['show_chart']['description'] ?? ''));
pruefe(!in_array('now', $mitApps['show_chart']['inputSchema']['properties']['period']['enum'] ?? ['now'], true), 'ohne Leistungsbilanz kein now im Diagramm');
$setzeBilanz([$bilanzZeile('grid', 'Grid', $zaehler), $bilanzZeile('solar', 'Solar', $leistung)]);
pruefe(str_contains($m->RunSelfTest(), 'Note: energy balance: solar production "Solar" POWER is not archived as a counter, so there is no house consumption'), 'Selbsttest: Bilanzzähler nicht als Zähler archiviert: ' . $m->RunSelfTest());
// Plausibilität der Zähler (B78): der „Verbrauch“ der betreuten Anlage aus mix_2026-10-17_zaehlerstand.json mit seinen
// echten Aggregaten und Ablesungen im Archiv des Kernel-Stubs, als Netzbezug eingetragen.
$plausibel = fixture('mix_2026-10-17_zaehlerstand.json');
$verbrauchQuelle = $plausibel['betreut']['variablen']['14339'];
$alteZone = date_default_timezone_get();
date_default_timezone_set($plausibel['zeitzone']);
$verbrauch = variable(VARIABLETYPE_FLOAT, $verbrauchQuelle['wert']);
IPS_SetName($verbrauch, 'Verbrauch');
IPS_CreateVariableProfile('HomeAgentTest.kWh', VARIABLETYPE_FLOAT);
IPS_SetVariableProfileText('HomeAgentTest.kWh', '', ' kWh');
IPS_SetVariableCustomProfile($verbrauch, 'HomeAgentTest.kWh');
AC_SetLoggingStatus($archiv, $verbrauch, true);
AC_SetAggregationType($archiv, $verbrauch, 1);
foreach ([3, 4] as $stufe) {
    AC_StubsAddAggregatedValues($archiv, $verbrauch, $stufe, array_map(static fn (array $a): array => ['TimeStamp' => $a['zeit'], 'Duration' => 0, 'Avg' => $a['summe'], 'Min' => 0, 'Max' => 0, 'MinTime' => 0, 'MaxTime' => 0], $verbrauchQuelle['aggregate'][(string)$stufe]));
}
AC_AddLoggedValues($archiv, $verbrauch, array_map(static fn (array $r): array => ['TimeStamp' => $r[0], 'Value' => $r[1]], $verbrauchQuelle['ablesungen']));
$ablesung = oderNull(static fn () => $m->testBackend()->counterReadings($verbrauch, [$verbrauchQuelle['ablesungen'][1][0], $verbrauchQuelle['ablesungen'][1][0] + 1, 1000]));
pruefe($ablesung === [$verbrauchQuelle['ablesungen'][1][0] => (float)$verbrauchQuelle['ablesungen'][0][1], $verbrauchQuelle['ablesungen'][1][0] + 1 => (float)$verbrauchQuelle['ablesungen'][1][1], 1000 => null], 'counterReadings: der letzte Satz vor dem Zeitpunkt, vor dem ersten keiner: ' . json_encode($ablesung));
pruefe(oderNull(static fn () => $m->testBackend()->counterReadings($leistung, [1000])) === null, 'counterReadings ohne Zähler im Archiv: null');
// Nach jetzt ist der Zählerstand der Wert der Variable, ohne Archiv: In einer betreuten Anlage brauchte die Abfrage des
// letzten Satzes eines Netzzählers, der alle paar Sekunden schreibt, 0,9 bis 1,4 s je Zähler (Selbsttest 8,2 s, 18.10.2026).
SetValue($verbrauch, $verbrauchQuelle['wert'] + 1.5);
$spaeter = time() + 60;
pruefe(oderNull(static fn () => $m->testBackend()->counterReadings($verbrauch, [$spaeter])) === [$spaeter => (float)$verbrauchQuelle['wert'] + 1.5], 'counterReadings nach jetzt: der Wert der Variable');
// „Jetzt“ als festes Zeichen (Review 18.10.2026, N3): Die Bibliothek übergab $now + 1, und war die Uhr im Backend schon eine
// Sekunde weiter, kam der Satz doch aus dem Archiv
$jetzt = defined('DeviceBackend::LATEST') ? constant('DeviceBackend::LATEST') : -1;
pruefe($jetzt > 0 && oderNull(static fn () => $m->testBackend()->counterReadings($verbrauch, [$jetzt])) === [$jetzt => (float)$verbrauchQuelle['wert'] + 1.5], 'counterReadings mit DeviceBackend::LATEST: der Wert der Variable');
SetValue($verbrauch, 0.0);
pruefe(oderNull(static fn () => $m->testBackend()->counterReadings($verbrauch, [$spaeter])) === [$spaeter => (float)$verbrauchQuelle['ablesungen'][count($verbrauchQuelle['ablesungen']) - 1][1]], 'nach jetzt mit 0 in der Variable: der letzte Satz über 0 aus dem Archiv');
// Ein stillgelegter Zähler geht übers Archiv: Nach einer Reparatur kann es neuere Sätze haben als die Variable (alter
// Netzzähler der betreuten Anlage, nach dem 12.05.2026 unverändert, im Archiv aufgefüllt bis zum 14.05.)
SetValue($verbrauch, $verbrauchQuelle['wert'] + 1.5);
$variablen = new ReflectionProperty(\IPS\VariableManager::class, 'variables');
$liste = $variablen->getValue();
$liste[$verbrauch]['VariableUpdated'] = time() - 2 * 86400;
$variablen->setValue(null, $liste);
pruefe(oderNull(static fn () => $m->testBackend()->counterReadings($verbrauch, [$spaeter])) === [$spaeter => (float)$verbrauchQuelle['ablesungen'][count($verbrauchQuelle['ablesungen']) - 1][1]], 'nach jetzt mit seit zwei Tagen unveränderter Variable: der letzte Satz aus dem Archiv');
SetValue($verbrauch, $verbrauchQuelle['wert']);
$setzeBilanz([$bilanzZeile('grid', 'Netz', $verbrauch)]);
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Note: grid import "Netz" Verbrauch: the archive counts more than the meter reading rose (01/2024 +659.8 kWh). Usually that is a single wrong value'), 'Selbsttest nennt den Monat mit Fehlwert, nicht den Neustart im Mai 2026: ' . $test);
date_default_timezone_set($alteZone);
$setzeBilanz([$bilanzZeile('grid', 'Grid', $zaehler), $bilanzZeile('battery', 'Battery', 0, 69995)]);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE && str_contains(implode("\n", array_column($m->logs(), 'Message')), 'Source "Battery": variable for battery charge does not exist'), 'gelöschter Bilanzzähler: aktiv, das Log nennt ihn mit Quelle und Teil: ' . implode(' | ', array_slice(array_column($m->logs(), 'Message'), -2)));
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
pruefe(!str_contains($werkzeuge['get_state']['inputSchema']['properties']['period']['description'] ?? '', 'house consumption'), 'eine Bilanz mit gelöschtem Zähler ist gesperrt, period nennt keinen Hausverbrauch mehr: ' . json_encode($werkzeuge['get_state']['inputSchema']['properties']['period'] ?? null));
pruefe(in_array($zaehler, $m->GetReferenceList(), true), 'die Zähler der Bilanz sind als Referenz gemeldet: ' . json_encode($m->GetReferenceList()));
// Aktuelle Leistung (09.10.2026), period=now
$setzeBilanz([]); // der Abschnitt davor hinterlässt einen gelöschten Bilanzzähler
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$periode = $werkzeuge['get_state']['inputSchema']['properties']['period'] ?? [];
pruefe(($periode['enum'][0] ?? null) === 'now' && str_contains($periode['description'] ?? '', '"now" gives the current power in W instead.'), 'mit einem Gerät mit Leistung bietet period „now“ an: ' . json_encode($periode));
pruefe(str_contains($werkzeuge['get_state']['description'] ?? '', '"How much power are we using right now?" → period=now.'), 'die Beschreibung nennt ein Beispiel: ' . ($werkzeuge['get_state']['description'] ?? ''));
// Mit Leistungsbilanz zeigt show_chart mit period=now die Hausleistung als Ringe (13.10.2026)
$setzeBilanz([$bilanzZeile('grid', 'Grid', 0, 0, $leistung)]);
$mitApps = array_column(json_decode((string)json_encode(oderNull(static fn () => \Closure::bind(fn () => $this->tools(null, true), $m, HomeAgent::class)()) ?? []), true), null, 'name');
pruefe(($mitApps['show_chart']['inputSchema']['properties']['period']['enum'][0] ?? null) === 'now', 'mit Leistungsbilanz: show_chart bietet now an: ' . json_encode($mitApps['show_chart']['inputSchema']['properties']['period'] ?? null));
pruefe(str_contains($mitApps['show_chart']['description'] ?? '', 'period=now without room, floor, type or name shows the current house power as rings.'), 'show_chart erklärt now: ' . ($mitApps['show_chart']['description'] ?? json_encode(array_keys($mitApps))));
$setzeBilanz([$bilanzZeile('grid', 'Grid', 0, 0, $zaehler)]);
pruefe(str_contains($m->RunSelfTest(), 'Note: energy balance: grid import power "Grid" ENERGY_COUNTER is in Wh, not a power value, so there is no house power'), 'Selbsttest: ein Zähler als Leistung: ' . $m->RunSelfTest());
$setzeBilanz([$bilanzZeile('solar', 'Solar', 0, 0, 69994)]);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE && str_contains(implode("\n", array_column($m->logs(), 'Message')), 'Source "Solar": variable for solar power does not exist'), 'gelöschte Leistungsvariable: aktiv, das Log nennt sie');
$setzeBilanz([]);

// Vorzeichen umkehren (B77, Burkhard 14.10.2026): Der Marstek Venus E meldet positiv = laden. Der Haken steht je Zeile;
// der Selbsttest nennt den Rohwert und wie er zählt.
$jetzt = static fn (): string => (string)strtok((string)(oderNull(static fn () => \Closure::bind(fn () => $this->deviceIndex()->getState(['period' => 'now'])['text'], $m, HomeAgent::class)()) ?? ''), "\n");
$setzeBilanz([$bilanzZeile('battery', 'Battery', 0, 0, $leistung, 0, true)]);
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Energy balance: battery discharge power "Battery" POWER reads 26 W now and counts as battery charge 26 W, reversed by the checkbox'), 'Selbsttest: Batterie umgekehrt, positiv zählt als Laden: ' . $test);
pruefe(str_contains($jetzt(), 'battery charge 26 W'), 'get_state period=now zählt die Batterie als Laden: ' . $jetzt());
$setzeBilanz([$bilanzZeile('battery', 'Battery', 0, 0, $leistung)]);
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Energy balance: battery discharge power "Battery" POWER reads 26 W now and counts as battery discharge 26 W') && !str_contains($test, 'battery charge 26 W'), 'Selbsttest ohne Haken: positiv zählt als Entladen: ' . $test);
pruefe(str_contains($jetzt(), 'battery discharge 26 W'), 'get_state ohne Haken: Entladen: ' . $jetzt());
$setzeBilanz([$bilanzZeile('grid', 'Grid', 0, 0, $leistung, 0, true)]);
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Energy balance: grid import power "Grid" POWER reads 26 W now and counts as grid export 26 W, reversed by the checkbox'), 'Selbsttest: Netz umgekehrt, positiv zählt als Einspeisung: ' . $test);
$setzeBilanz([]);

// Ein Zähler, der keine Energie zählt (Wasser, Gas), fällt im Selbsttest auf
$wasser = variable(VARIABLETYPE_FLOAT, 352171.0);
IPS_SetName($wasser, 'WASSER');
IPS_CreateVariableProfile('VoiceControlTest.m3', VARIABLETYPE_FLOAT);
IPS_SetVariableProfileText('VoiceControlTest.m3', '', ' m³');
IPS_SetVariableCustomProfile($wasser, 'VoiceControlTest.m3');
AC_SetLoggingStatus($archiv, $wasser, true);
AC_SetAggregationType($archiv, $wasser, 1);
IPS_SetProperty($iid, 'Devices', json_encode([['EnergyVariableID' => $wasser] + $entfeuchter]));
$setzeBilanz([$bilanzZeile('grid', 'Grid', $wasser)]);
$selbsttest = $m->RunSelfTest();
pruefe(str_contains($selbsttest, 'Note: Keller Entfeuchter: the energy meter WASSER counts in m³, not in kWh, so it is left out of the consumption'), 'Selbsttest: Gerätezähler in m³: ' . $selbsttest);
pruefe(str_contains($selbsttest, 'Note: energy balance: grid import "Grid" WASSER counts in m³, not in kWh, so there is no house consumption'), 'Selbsttest: Bilanzzähler in m³: ' . $selbsttest);
$setzeBilanz([]);

/* --- Heizungs-Sollwert (10.10.2026): Typ thermostat, Bereich aus Profil oder Darstellung ----- */

pruefe(($felder['thermostat']['SetpointVariableID']['visible'] ?? false) === true && ($felder['thermostat']['SetpointVariableID']['caption'] ?? '') === 'Setpoint'
    && ($felder['thermostat']['SetpointVariableID']['type'] ?? '') === 'SelectVariable', 'Thermostat: Feld Sollwert im Dialog');
pruefe(($felder['thermostat']['StateVariableID']['caption'] ?? '') === 'Current temperature' && ($felder['thermostat']['LevelVariableID']['visible'] ?? true) === false, 'Thermostat: Ist-Temperatur, keine Stufe');
pruefe(($felder['thermostat']['Invert']['visible'] ?? true) === false && ($felder['thermostat']['ReadOnly']['visible'] ?? false) === true, 'Thermostat: kein „Umgekehrt“, aber „Nur abfragen“');
// Klimagerät = Thermostat mit Modus und Lüfterstufe (B19, 13.10.2026)
pruefe(($felder['thermostat']['ModeVariableID']['visible'] ?? false) === true && ($felder['thermostat']['ModeVariableID']['caption'] ?? '') === 'Mode'
    && ($felder['thermostat']['FanVariableID']['visible'] ?? false) === true && ($felder['thermostat']['FanVariableID']['caption'] ?? '') === 'Fan speed', 'Thermostat: Felder Modus und Lüfterstufe im Dialog');
pruefe(($felder['light']['SetpointVariableID']['visible'] ?? true) === false, 'beim Licht gibt es keinen Sollwert');
pruefe(in_array(['caption' => 'Thermostat', 'value' => 'thermostat'], $felder['light']['Type']['options'] ?? [], true), 'Typauswahl mit Thermostat');

// Wertebereich des Sollwerts: Darstellung (HomeAssistant-Modul), sonst Profil; Sonderwerte am Rand
// mit Text statt Zahl („On“ bei Temperature.HM_30.5 am nuc) zählen nicht dazu.
$anlage = new SymconDeviceBackend(static fn (string $t): string => $t);
$sollbereich = static fn (int $id): mixed => oderNull(static fn () => $anlage->setpointRange($id));
IPS_CreateVariableProfile('Temperature.HM_30.5', VARIABLETYPE_FLOAT);
IPS_SetVariableProfileValues('Temperature.HM_30.5', 6, 30.5, 0.5);
IPS_SetVariableProfileText('Temperature.HM_30.5', '', ' °C');
IPS_SetVariableProfileDigits('Temperature.HM_30.5', 1);
foreach ([[16, '16,0'], [17, '17,0'], [21, '21,0'], [30.5, 'On']] as [$wert, $text]) {
    IPS_SetVariableProfileAssociation('Temperature.HM_30.5', $wert, $text, '', -1);
}
$soll = variable(VARIABLETYPE_FLOAT, 21.0);
IPS_SetName($soll, 'SET_TEMPERATURE');
IPS_SetVariableCustomProfile($soll, 'Temperature.HM_30.5');
$sollAktion = IPS_CreateScript(0);
IPS_SetScriptContent($sollAktion, '<?php SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
IPS_SetVariableCustomAction($soll, $sollAktion);
pruefe($sollbereich($soll) === ['min' => 6.0, 'max' => 30.0, 'step' => 0.5], 'Profil wie am nuc: 30,5 „On“ ist ein Sonderwert, „16,0“ eine Zahl: ' . json_encode($sollbereich($soll)));
$sollAlt = variable(VARIABLETYPE_FLOAT, 100.0);
IPS_SetVariableCustomProfile($sollAlt, '~Temperature.HM');
pruefe($sollbereich($sollAlt) === ['min' => 6.0, 'max' => 30.0, 'step' => 0.5], '~Temperature.HM (HM-CC-TC): 6 bis 30: ' . json_encode($sollbereich($sollAlt)));
IPS_CreateVariableProfile('VoiceControlTest.SollAusEin', VARIABLETYPE_FLOAT);
IPS_SetVariableProfileValues('VoiceControlTest.SollAusEin', 4.5, 30.5, 0.5);
IPS_SetVariableProfileAssociation('VoiceControlTest.SollAusEin', 4.5, 'Aus', '', -1);
IPS_SetVariableProfileAssociation('VoiceControlTest.SollAusEin', 30.5, 'Ein', '', -1);
$sollAusEin = variable(VARIABLETYPE_FLOAT, 20.0);
IPS_SetVariableCustomProfile($sollAusEin, 'VoiceControlTest.SollAusEin');
pruefe($sollbereich($sollAusEin) === ['min' => 5.0, 'max' => 30.0, 'step' => 0.5], 'Sonderwerte an beiden Rändern fallen heraus: ' . json_encode($sollbereich($sollAusEin)));
// Profil ohne Schrittweite, gelesen an einer betreuten Anlage (16.10.2026, Homematic-IP-Wandthermostate): Einträge
// 15 bis 24, „On“ bei 30,5. Vorher blieb 30,5 im Bereich, „+“ bei 30 öffnete das Ventil ganz. Ohne Schrittweite
// endet der Bereich 0,5 vor dem Sonderwert; die Einträge begrenzen die Eingabe nicht (Burkhard 16.10.2026).
IPS_CreateVariableProfile('Temperature.HM_my', VARIABLETYPE_FLOAT);
IPS_SetVariableProfileValues('Temperature.HM_my', 6, 30.5, 0);
IPS_SetVariableProfileText('Temperature.HM_my', '', ' °C');
IPS_SetVariableProfileDigits('Temperature.HM_my', 1);
foreach ([[15, '15,0'], [16, '16,0'], [17, '17,0'], [18, '18,0'], [19, '19,0'], [20, '20,0'], [20.5, '20,5'], [21, '21,0'], [21.5, '21,5'], [22, '22,0'], [22.5, '22,5'], [23, '23,0'], [23.5, '23,5'], [24, '24,0'], [30.5, 'On']] as [$wert, $text]) {
    IPS_SetVariableProfileAssociation('Temperature.HM_my', $wert, $text, '', -1);
}
$sollOhneSchritt = variable(VARIABLETYPE_FLOAT, 22.0);
IPS_SetVariableCustomProfile($sollOhneSchritt, 'Temperature.HM_my');
// Ohne Schrittweite gilt der kleinste Abstand der Einträge mit Zahlen als Raster (B81, Burkhard 18.10.2026): Der Selbsttest
// meldete dort „in Schritten von 0“, set_state rundete gar nicht, die Karte nahm stillschweigend 0,5.
pruefe($sollbereich($sollOhneSchritt) === ['min' => 6.0, 'max' => 30.0, 'step' => 0.5], 'ohne Schrittweite: „On“ bei 30,5 fällt heraus, Ende 30, Raster aus den Einträgen 0,5: ' . json_encode($sollbereich($sollOhneSchritt)));
IPS_CreateVariableProfile('HomeAgentTest.SollOhneRaster', VARIABLETYPE_FLOAT);
IPS_SetVariableProfileValues('HomeAgentTest.SollOhneRaster', 6, 30, 0);
$sollOhneRaster = variable(VARIABLETYPE_FLOAT, 21.0);
IPS_SetVariableCustomProfile($sollOhneRaster, 'HomeAgentTest.SollOhneRaster');
pruefe($sollbereich($sollOhneRaster) === ['min' => 6.0, 'max' => 30.0, 'step' => 0.0], 'ohne Schrittweite und ohne Einträge bleibt sie 0: ' . json_encode($sollbereich($sollOhneRaster)));
// Grobe Einträge sind kein Raster (Review 18.10.2026, M2): Zwei Zahlen an den Rändern ergaben Schrittweite 24 (21 °C wurde
// 30 °C), Farbbänder bei 5, 18, 22 und 26 ergaben 4. Ein Raster braucht mindestens drei Abstände, alle ein Vielfaches des
// kleinsten, und höchstens 1.
$grob = static function (string $name, array $eintraege) use ($sollbereich): ?array {
    IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
    IPS_SetVariableProfileValues($name, 5, 30, 0);
    foreach ($eintraege as [$wert, $text]) {
        IPS_SetVariableProfileAssociation($name, $wert, $text, '', -1);
    }
    $id = variable(VARIABLETYPE_FLOAT, 21.0);
    IPS_SetVariableCustomProfile($id, $name);
    return $sollbereich($id);
};
$r = $grob('HomeAgentTest.Raender', [[6, '6,0'], [30, '30,0']]);
pruefe(($r['step'] ?? null) === 0.0, 'zwei Einträge an den Rändern sind kein Raster: ' . json_encode($r));
$r = $grob('HomeAgentTest.Farbband', [[5, '5'], [18, '18'], [22, '22'], [26, '26']]);
pruefe(($r['step'] ?? null) === 0.0, 'Farbbänder 5, 18, 22, 26 sind kein Raster: ' . json_encode($r));
$r = $grob('HomeAgentTest.Luecke', [[15, '15,0'], [15.5, '15,5'], [16, '16,0'], [17.25, '17,25']]);
pruefe(($r['step'] ?? null) === 0.0, 'ein Abstand, der kein Vielfaches des kleinsten ist, ergibt kein Raster: ' . json_encode($r));
$r = $grob('HomeAgentTest.Dicht', [[15, '15,0'], [15.5, '15,5'], [16, '16,0'], [17, '17,0']]);
pruefe(($r['step'] ?? null) === 0.5, 'dichte Einträge mit einer Lücke bleiben ein Raster von 0,5: ' . json_encode($r));
// Ein Eintrag ohne Text (nur Farbe) oder mit Formatnamen zeigt den Wert selbst und ist kein Sonderwert (Review 18.10.2026,
// N7). Echt vom nuc gelesen (19.10.2026): „Bodenfeuchte“ 0 bis 100, Schritt 1, Einträge „%d“ ab 0; vorher begann der
// Bereich bei 1. Der Eintrag nur aus einem Leerzeichen ist angenommen (Farbband ohne Beschriftung; ein leerer Name löscht den Eintrag).
IPS_CreateVariableProfile('Bodenfeuchte', VARIABLETYPE_INTEGER);
IPS_SetVariableProfileValues('Bodenfeuchte', 0, 100, 1);
IPS_SetVariableProfileText('Bodenfeuchte', '', ' %');
foreach ([[0, 16711680], [8, 16743936], [25, 16776960], [30, 8322816], [35, 65280], [40, 65406], [45, 65535], [48, 32511]] as [$wert, $farbe]) {
    IPS_SetVariableProfileAssociation('Bodenfeuchte', $wert, '%d', '', $farbe);
}
$feuchteFormat = variable(VARIABLETYPE_INTEGER, 40);
IPS_SetVariableCustomProfile($feuchteFormat, 'Bodenfeuchte');
pruefe($sollbereich($feuchteFormat) === ['min' => 0.0, 'max' => 100.0, 'step' => 1.0], 'Formatname „%d“ am Rand ist kein Sonderwert: ' . json_encode($sollbereich($feuchteFormat)));
$r = $grob('HomeAgentTest.FarbeAmRand', [[5, ' '], [30, ' ']]);
pruefe(($r['min'] ?? null) === 5.0 && ($r['max'] ?? null) === 30.0, 'Einträge ohne Text an den Rändern sind keine Sonderwerte: ' . json_encode($r));
$r = $grob('HomeAgentTest.OnAmRand', [[30, 'On']]);
pruefe(($r['max'] ?? null) === 29.5, 'ein Text am Rand bleibt ein Sonderwert: ' . json_encode($r));

// Farbmodus neben der Farbvariable (16.10.2026): Ident endet auf color_mode, wie bei Zigbee2MQTT, dem
// HomeAssistant-Modul (auch mit Entitätsnamen davor) und Matter; andere Geschwister zählen nicht.
$lampe = IPS_CreateCategory();
$farbe = variable(VARIABLETYPE_INTEGER, 0xFF0000);
IPS_SetParent($farbe, $lampe);
$helligkeit = variable(VARIABLETYPE_INTEGER, 100);
IPS_SetParent($helligkeit, $lampe);
IPS_SetIdent($helligkeit, 'brightness');
pruefe(oderNull(static fn () => $anlage->colorMode($farbe)) === null, 'ohne Farbmodus-Variable: null');
$modus = variable(VARIABLETYPE_STRING, 'XY');
IPS_SetParent($modus, $lampe);
IPS_SetIdent($modus, 'light_wohnzimmer_licht_esstisch_color_mode');
pruefe(oderNull(static fn () => $anlage->colorMode($farbe)) === ['ident' => 'light_wohnzimmer_licht_esstisch_color_mode', 'value' => 'XY'], 'Farbmodus neben der Farbe gefunden: ' . json_encode(oderNull(static fn () => $anlage->colorMode($farbe))));
pruefe(oderNull(static fn () => $anlage->colorMode(69998)) === null, 'unbekannte Variable: null');
$haSoll = variable(VARIABLETYPE_FLOAT, 21.5);
IPS_SetVariableCustomPresentation($haSoll, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 6, 'MAX' => 30, 'STEP_SIZE' => 0.5, 'SUFFIX' => ' °C']);
pruefe($sollbereich($haSoll) === ['min' => 6.0, 'max' => 30.0, 'step' => 0.5], 'Darstellung Schieberegler (HomeAssistant-Modul): ' . json_encode($sollbereich($haSoll)));
pruefe($sollbereich(variable(VARIABLETYPE_FLOAT, 20.0)) === null && $sollbereich(69999) === null, 'ohne Profil und Darstellung oder ohne Variable: unbekannt');

// Freigabeliste mit Thermostat: Schema, Abfrage, Stellen, Selbsttest, Validierung
$ist = variable(VARIABLETYPE_FLOAT, 21.7);
IPS_SetVariableCustomProfile($ist, '~Temperature');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Gast', 'Name' => 'Heizung', 'Aliases' => 'Thermostat', 'Type' => 'thermostat', 'StateVariableID' => $ist, 'SetpointVariableID' => $soll, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Thermostat mit Sollwert: aktiv');
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$schema = $werkzeuge['set_state']['inputSchema']['properties'] ?? [];
pruefe(($schema['temperature']['type'] ?? '') === 'number' && str_contains($schema['temperature']['description'] ?? '', 'Target temperature in °C'), 'set_state hat temperature: ' . json_encode($schema['temperature'] ?? null));
pruefe(!isset($schema['command']) && !isset($schema['percent']) && ($schema['type']['enum'] ?? []) === ['thermostat'], 'nur ein Thermostat: kein command, kein percent, Typ thermostat: ' . json_encode(array_keys($schema)));
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', '"Set the heating in the guest room to 21 degrees" → room=Gast, temperature=21.'), 'die Beschreibung nennt ein Beispiel: ' . ($werkzeuge['set_state']['description'] ?? ''));
pruefe(in_array('thermostat', $werkzeuge['get_state']['inputSchema']['properties']['type']['enum'] ?? [], true), 'get_state kennt den Typ thermostat');
$text = $m->QueryState('Gast', 'thermostat', '', false);
pruefe(str_contains($text, 'Gast | Heizung | thermostat | setpoint 21 °C (currently ' . GetValueFormatted($ist) . ')'), 'Abfrage über den Kernel-Stub, die Ist-Temperatur als Anzeigetext der Anlage: ' . $text);
$aufruf = new ReflectionMethod(HomeAgent::class, 'callTool');
$r = oderNull(static fn () => $aufruf->invoke($m, 'set_state', ['room' => 'Gast', 'temperature' => 22.5], null));
pruefe(is_array($r) && GetValue($soll) === 22.5, 'Stellen über das Werkzeug schreibt den Sollwert: ' . json_encode(GetValue($soll)) . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE));
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Thermostat Gast Heizung: 6 to 30 °C in steps of 0.5'), 'der Selbsttest nennt den Bereich des Sollwerts: ' . $test);
// Ohne bekannte Schrittweite sagt der Selbsttest das, statt „in Schritten von 0“ (B81)
IPS_SetVariableCustomProfile($soll, 'HomeAgentTest.SollOhneRaster');
pruefe(str_contains($m->RunSelfTest(), 'Thermostat Gast Heizung: 6 to 30 °C without a fixed step'), 'ohne Schrittweite: ' . $m->RunSelfTest());
// Mit dem Profil der betreuten Anlage rundet set_state auf das Raster der Einträge (0,5)
IPS_SetVariableCustomProfile($soll, 'Temperature.HM_my');
oderNull(static fn () => $aufruf->invoke($m, 'set_state', ['room' => 'Gast', 'temperature' => 21.3], null));
pruefe(GetValue($soll) === 21.5, 'set_state rundet 21,3 auf 21,5: ' . json_encode(GetValue($soll)));
IPS_SetVariableCustomProfile($soll, 'Temperature.HM_30.5');
pruefe(!str_contains($test, 'Gast Heizung has no action'), 'die Ist-Temperatur braucht keine Aktion: ' . $test);
$m->formUpdates = [];
oderNull(static fn () => $m->UIDeviceChanged('thermostat', $ist, 0, 0, 0, 0, 0, $soll));
pruefe(in_array(['SetpointLabel', 'value', 'SET_TEMPERATURE'], $m->formUpdates, true) && in_array(['SetpointVariableID', 'visible', true], $m->formUpdates, true), 'die Wahl des Sollwerts zieht den Spaltentext nach: ' . json_encode($m->formUpdates, JSON_UNESCAPED_UNICODE));

$sollOhneAktion = variable(VARIABLETYPE_FLOAT, 20.0);
IPS_SetVariableCustomProfile($sollOhneAktion, '~Temperature.HM');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Gast', 'Name' => 'Heizung', 'Aliases' => '', 'Type' => 'thermostat', 'StateVariableID' => $ist, 'SetpointVariableID' => $sollOhneAktion, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(str_contains($m->RunSelfTest(), 'Note: Gast Heizung has no action on its setpoint, the assistant can read it but not set it'), 'Sollwert ohne Aktion fällt im Selbsttest auf: ' . $m->RunSelfTest());
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Gast', 'Name' => 'Heizung', 'Aliases' => '', 'Type' => 'thermostat', 'StateVariableID' => $ist, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE && str_contains(implode("\n", array_column($m->logs(), 'Message')), 'Device "Heizung" (Gast): no variable selected for Setpoint'), 'Thermostat ohne Sollwert: aktiv, das Log nennt es');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Rollo', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rollo, 'MovingVariableID' => $laeuft, 'Invert' => false],
]));
IPS_ApplyChanges($iid);

/* --- Alexa-Import (build 37/39): Vorschläge im Fenster, freigegeben wird nur, was angehakt ist --- */

// Aktionen nach Namen, auch innerhalb einer Zeile (RowLayout)
$aktionen = static function () use ($m): array {
    $flach = [];
    foreach (json_decode($m->GetConfigurationForm(), true)['actions'] ?? [] as $element) {
        foreach (($element['type'] ?? '') === 'RowLayout' ? $element['items'] : [$element] as $e) {
            if (isset($e['name'])) {
                $flach[$e['name']] = $e;
            }
        }
    }
    return $flach;
};
$zeilen = array_values(array_filter(json_decode($m->GetConfigurationForm(), true)['actions'] ?? [], static fn (array $e): bool => ($e['type'] ?? '') === 'RowLayout'));
pruefe(array_filter($zeilen, static fn (array $z): bool => array_column($z['items'], 'name') === ['AlexaInstance', 'ImportAlexa']) !== [], 'Auswahl und Knopf stehen in einer Zeile (Burkhard: Platz sparen)');
pruefe(($aktionen()['AlexaInstance']['visible'] ?? true) === false, 'ohne Alexa-Instanz ist die Auswahl unsichtbar: ' . json_encode($aktionen()['AlexaInstance'] ?? null));

// Ersatz für das Alexa-Modul von Symcon: echte GUID, Listen als Properties wie am nuc (#10110)
final class AlexaErsatz extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        foreach (['DeviceGenericSwitch', 'DeviceLightSwitch', 'DeviceLightDimmer', 'DeviceLock', 'DeviceShutter', 'DeviceThermostat', 'DeviceTemperatureSensor', 'DeviceSimpleScene'] as $liste) {
            $this->RegisterPropertyString($liste, '[]');
        }
    }
}
$alexa = IPS\ObjectManager::registerObject(1 /* Instance */);
IPS\InstanceManager::createInstance($alexa, ['ModuleID' => '{CC759EB6-7821-4AA5-9267-EF08C6A6A5B3}', 'ModuleName' => 'Alexa', 'ModuleType' => 0, 'Class' => AlexaErsatz::class]);
IPS_SetName($alexa, 'Alexa');
$kuechenlicht = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetName($kuechenlicht, 'STATE');
$flurlicht = variable(VARIABLETYPE_BOOLEAN, false);
$schloss = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetProperty($alexa, 'DeviceLightSwitch', json_encode([
    ['Status' => 'OK', 'ID' => '1', 'Name' => 'Deckenlicht Küche', 'PowerControllerID' => $kuechenlicht],
    ['Status' => 'OK', 'ID' => '3', 'Name' => 'Deckenlicht Flur', 'PowerControllerID' => $flurlicht],
]));
IPS_SetProperty($alexa, 'DeviceLock', json_encode([['Status' => 'OK', 'ID' => '2', 'Name' => 'Tür Küche', 'LockControllerID' => $schloss]]));
IPS_ApplyChanges($alexa);
IPS_SetProperty($iid, 'Rooms', json_encode([['Name' => 'Küche', 'Aliases' => '', 'Floor' => ''], ['Name' => 'Flur', 'Aliases' => '', 'Floor' => '']]));
IPS_ApplyChanges($iid);
$vorher = IPS_GetProperty($iid, 'Devices');

$auswahl = $aktionen()['AlexaInstance'] ?? [];
pruefe(($auswahl['type'] ?? '') === 'Select' && ($auswahl['visible'] ?? false) === true && ($auswahl['options'] ?? []) === [['caption' => 'Alexa (#' . $alexa . ')', 'value' => $alexa]] && ($auswahl['value'] ?? 0) === $alexa, 'Auswahl nennt die Alexa-Instanz und wählt sie vor: ' . json_encode($auswahl, JSON_UNESCAPED_UNICODE));
pruefe(($aktionen()['ImportAlexa']['onClick'] ?? '') === 'HAGT_UIImportAlexa($id, $AlexaInstance);' && ($aktionen()['ImportAlexa']['visible'] ?? false) === true, 'Knopf für den Vorschlag: ' . json_encode($aktionen()['ImportAlexa'] ?? null));
$fenster = $aktionen()['AlexaPopup'] ?? [];
$elemente = array_column($fenster['popup']['items'] ?? [], null, 'name');
pruefe(($fenster['type'] ?? '') === 'PopupAlert' && ($fenster['visible'] ?? true) === false, 'das Vorschlagsfenster ist ein PopupAlert und anfangs zu: ' . json_encode(array_diff_key($fenster, ['popup' => 1])));
pruefe(array_column($elemente['AlexaProposals']['columns'] ?? [], 'name') === ['Take', 'Room', 'Name', 'Type', 'Aliases', 'Index'] && (($elemente['AlexaProposals']['columns'][0]['edit']['type'] ?? '') === 'CheckBox'), 'Vorschlagsliste mit Haken je Zeile: ' . json_encode(array_column($elemente['AlexaProposals']['columns'] ?? [], 'name')));
pruefe(isset($elemente['AlexaSummary']) && str_contains($fenster['popup']['buttons'][0]['onClick'] ?? '', 'HAGT_UIApplyAlexa($id, json_encode($ausgewaehlt))'), 'Meldung im Fenster und Knopf „Ausgewählte freigeben“: ' . json_encode($fenster['popup']['buttons'] ?? null));
pruefe((array_column($elemente['AlexaProposals']['columns'] ?? [], null, 'name')['Room']['edit']['type'] ?? '') === 'ValidationTextBox' && str_contains($fenster['popup']['buttons'][0]['onClick'] ?? '', "[\$zeile['Index'], \$zeile['Room']]"), 'der Raum ist im Fenster bearbeitbar und geht mit der Nummer an das Modul: ' . ($fenster['popup']['buttons'][0]['onClick'] ?? ''));

$m->formUpdates = [];
$meldung = (string)oderNull(static fn () => $m->UIImportAlexa($alexa));
$felder = [];
foreach ($m->formUpdates as [$feld, $parameter, $wert]) {
    $felder[$feld . '.' . $parameter] = $wert;
}
$vorschlaege = json_decode((string)($felder['AlexaProposals.values'] ?? '[]'), true);
pruefe(str_starts_with($meldung, '2 device(s) proposed, 0 already released, 1 without a matching type (lock 1)') && ($felder['AlexaSummary.caption'] ?? '') === $meldung, 'Meldung im Fenster: ' . $meldung);
pruefe(($felder['AlexaPopup.visible'] ?? null) === true, 'das Fenster öffnet sich');
pruefe(count($vorschlaege) === 2 && ($vorschlaege[0] ?? null) === ['Take' => true, 'Room' => 'Küche', 'Name' => 'Deckenlicht', 'Type' => 'Light', 'Aliases' => 'Deckenlicht Küche', 'Index' => 0], 'Vorschläge mit vorbelegtem Haken: ' . json_encode($vorschlaege, JSON_UNESCAPED_UNICODE));
pruefe(!isset($felder['Devices.values']) && IPS_GetProperty($iid, 'Devices') === $vorher, 'die Freigabeliste bleibt beim Vorschlagen unberührt');

// Alle oder keine anhaken: Knöpfe im Fenster (ein Knopf in popup.buttons schlösse es ohne Ausgabe)
$knoepfe = [];
foreach ($fenster['popup']['items'] ?? [] as $element) {
    foreach (($element['type'] ?? '') === 'RowLayout' ? $element['items'] : [] as $knopf) {
        $knoepfe[$knopf['caption'] ?? ''] = $knopf['onClick'] ?? '';
    }
}
pruefe($knoepfe === ['Tick all' => 'HAGT_UISetAlexaTakes($id, true);', 'Tick none' => 'HAGT_UISetAlexaTakes($id, false);'], 'Knöpfe „Alle anhaken“ und „Keine anhaken“ über der Liste: ' . json_encode($knoepfe));
$haken = static function (bool $an) use ($m): ?array {
    $m->formUpdates = [];
    oderNull(static fn () => $m->UISetAlexaTakes($an));
    $werte = array_values(array_filter($m->formUpdates, static fn (array $u): bool => $u[0] === 'AlexaProposals' && $u[1] === 'values'));
    return isset($werte[0]) ? array_column(json_decode((string)$werte[0][2], true), 'Take') : null;
};
pruefe($haken(false) === [false, false], 'keine anhaken: ' . json_encode($haken(false)));
pruefe($haken(true) === [true, true], 'alle anhaken: ' . json_encode($haken(true)));

$m->formUpdates = [];
$meldung = (string)oderNull(static fn () => $m->UIApplyAlexa('[1]'));
$liste = json_decode(IPS_GetProperty($iid, 'Devices'), true);
$neu = end($liste) ?: [];
// Ein Popup-Knopf zeigt jede Ausgabe rot im Popup, außer sie beginnt mit „MESSAGE:“ (Doku PopupAlert, buttons).
pruefe($meldung === 'MESSAGE:1 device(s) released.', 'Erfolg als Meldungsdialog, nicht als rote Fehlermeldung: ' . $meldung);
pruefe(count($liste) === count(json_decode($vorher, true)) + 1 && ($neu['Room'] ?? '') === 'Flur' && ($neu['StateVariableID'] ?? 0) === $flurlicht, 'nur die angehakte Zeile ist gespeichert: ' . json_encode($neu, JSON_UNESCAPED_UNICODE));
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE && in_array(['AlexaPopup', 'visible', false], $m->formUpdates, true), 'Instanz aktiv, Fenster zu');
pruefe((string)oderNull(static fn () => $m->UIApplyAlexa('[0]')) === 'Propose devices first.', 'ohne neuen Vorschlag gibt es nichts freizugeben');
oderNull(static fn () => $m->UIImportAlexa($alexa));
pruefe((string)oderNull(static fn () => $m->UIApplyAlexa('[]')) === 'Nothing was selected.' && count(json_decode(IPS_GetProperty($iid, 'Devices'), true)) === count($liste), 'ohne Haken wird nichts gespeichert');
$m->formUpdates = [];
$meldung = (string)oderNull(static fn () => $m->UIImportAlexa($alexa));
pruefe(str_starts_with($meldung, '1 device(s) proposed, 1 already released'), 'beim nächsten Vorschlag ist das Flurlicht schon freigegeben: ' . $meldung);

// Stufe 1 der Räume (Burkhard, 10.10.2026): Raum im Fenster ändern, ein neuer Raum kommt in die Raumliste
$meldung = (string)oderNull(static fn () => $m->UIApplyAlexa('[[0, "Wohnküche"]]'));
$liste = json_decode(IPS_GetProperty($iid, 'Devices'), true);
$raumNamen = array_column(json_decode(IPS_GetProperty($iid, 'Rooms'), true), 'Name');
pruefe($meldung === 'MESSAGE:1 device(s) released, 1 room(s) added to the room list.', 'Meldung nennt den neuen Raum: ' . $meldung);
pruefe((end($liste)['Room'] ?? '') === 'Wohnküche' && $raumNamen === ['Küche', 'Flur', 'Wohnküche'], 'Gerät im geänderten Raum, der Raum steht in der Raumliste: ' . json_encode($raumNamen, JSON_UNESCAPED_UNICODE));
IPS_SetProperty($iid, 'Devices', $vorher);
IPS_SetProperty($iid, 'Rooms', json_encode([['Name' => 'Küche', 'Aliases' => 'Kochen', 'Floor' => ''], ['Name' => 'Flur', 'Aliases' => '', 'Floor' => '']]));
IPS_ApplyChanges($iid);
oderNull(static fn () => $m->UIImportAlexa($alexa));
$meldung = (string)oderNull(static fn () => $m->UIApplyAlexa('[[0, "kochen"], [1, " "]]'));
$liste = json_decode(IPS_GetProperty($iid, 'Devices'), true);
$neue = array_slice($liste, -2);
pruefe($meldung === 'MESSAGE:2 device(s) released.' && count(json_decode(IPS_GetProperty($iid, 'Rooms'), true)) === 2, 'ein Alias ist kein neuer Raum: ' . $meldung);
pruefe(array_column($neue, 'Room') === ['Küche', ''], 'der Alias wird zum Namen aus der Raumliste, ein geleerter Raum bleibt leer: ' . json_encode(array_column($neue, 'Room'), JSON_UNESCAPED_UNICODE));
IPS_SetProperty($iid, 'Devices', $vorher);
IPS_SetProperty($iid, 'Rooms', json_encode([['Name' => 'Küche', 'Aliases' => '', 'Floor' => ''], ['Name' => 'Flur', 'Aliases' => '', 'Floor' => '']]));
IPS_ApplyChanges($iid);
// Raumvorschlag mit Etage (build 56): Liegen die Geräte eines vorgeschlagenen Raums unter verschiedenen
// Etagen-Kategorien, entsteht je Etage ein Raum mit dem gemeinsamen Namen als Alias (Burkhard, 10.10.2026).
$baum = static function (array $namen): int {
    $eltern = 0;
    foreach ($namen as $name) {
        $kategorie = IPS_CreateCategory();
        IPS_SetName($kategorie, $name);
        IPS_SetParent($kategorie, $eltern);
        $eltern = $kategorie;
    }
    return $eltern;
};
$dieleUnten = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetParent($dieleUnten, $baum(['Hardware', 'EG', 'Diele', 'Diele_EG_Schalter']));
$dieleOben = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetParent($dieleOben, $baum(['Hardware', 'OG', 'Diele', 'Diele_OG_Schalter']));
IPS_SetProperty($alexa, 'DeviceLightSwitch', json_encode([
    ['Status' => 'OK', 'ID' => '5', 'Name' => 'Diele Erdgeschoss', 'PowerControllerID' => $dieleUnten],
    ['Status' => 'OK', 'ID' => '6', 'Name' => 'Diele Obergeschoss', 'PowerControllerID' => $dieleOben],
]));
IPS_SetProperty($alexa, 'DeviceLock', '[]');
IPS_ApplyChanges($alexa);
$m->formUpdates = [];
oderNull(static fn () => $m->UIImportAlexa($alexa));
$felder = [];
foreach ($m->formUpdates as [$feld, $parameter, $wert]) {
    $felder[$feld . '.' . $parameter] = $wert;
}
$vorschlagsRaeume = array_column(json_decode((string)($felder['AlexaProposals.values'] ?? '[]'), true), 'Room');
pruefe($vorschlagsRaeume === ['Diele EG', 'Diele OG'], 'je Etage ein Raum im Fenster: ' . json_encode($vorschlagsRaeume, JSON_UNESCAPED_UNICODE));
$meldung = (string)oderNull(static fn () => $m->UIApplyAlexa('[[0, "Diele EG"], [1, "Diele OG"]]'));
$raumListe = json_decode(IPS_GetProperty($iid, 'Rooms'), true);
pruefe($meldung === 'MESSAGE:2 device(s) released, 2 room(s) added to the room list.' && array_slice($raumListe, 2) === [['Name' => 'Diele EG', 'Aliases' => 'Diele', 'Floor' => 'EG'], ['Name' => 'Diele OG', 'Aliases' => 'Diele', 'Floor' => 'OG']], 'neue Räume mit Etage und gemeinsamem Alias: ' . json_encode($raumListe, JSON_UNESCAPED_UNICODE));
$r = $aufruf->invoke($m, 'get_state', ['room' => 'Diele'], null);
pruefe(str_starts_with($r['text'], '2 device(s) found (room=Diele).'), 'der gemeinsame Alias trifft beide Räume: ' . $r['text']);
$r = $aufruf->invoke($m, 'get_state', ['floor' => 'OG'], null);
pruefe(str_starts_with($r['text'], '1 device(s) found (floor=OG).'), 'die Etage wirkt: ' . $r['text']);
IPS_SetProperty($alexa, 'DeviceLightSwitch', json_encode([
    ['Status' => 'OK', 'ID' => '1', 'Name' => 'Deckenlicht Küche', 'PowerControllerID' => $kuechenlicht],
    ['Status' => 'OK', 'ID' => '3', 'Name' => 'Deckenlicht Flur', 'PowerControllerID' => $flurlicht],
]));
IPS_ApplyChanges($alexa);
IPS_SetProperty($iid, 'Devices', $vorher);
IPS_SetProperty($iid, 'Rooms', json_encode([['Name' => 'Küche', 'Aliases' => '', 'Floor' => ''], ['Name' => 'Flur', 'Aliases' => '', 'Floor' => '']]));
IPS_ApplyChanges($iid);
// Rollladen nur mit Fahrbefehl: Profil wie am nuc (~ShutterMoveStop 0 Öffnen, 2 Stop, 4 Schließen)
if (!IPS_VariableProfileExists('~ShutterMoveStop')) {
    IPS_CreateVariableProfile('~ShutterMoveStop', VARIABLETYPE_INTEGER);
    IPS_SetVariableProfileValues('~ShutterMoveStop', 0, 4, 0);
    foreach ([[0, 'Öffnen'], [2, 'Stop'], [4, 'Schließen']] as [$wert, $text]) {
        IPS_SetVariableProfileAssociation('~ShutterMoveStop', $wert, $text, '', -1);
    }
}
$fahrbefehl = variable(VARIABLETYPE_INTEGER, 2);
IPS_SetVariableCustomProfile($fahrbefehl, '~ShutterMoveStop');
IPS_SetProperty($alexa, 'DeviceShutter', json_encode([['Status' => 'OK', 'ID' => '4', 'Name' => 'Rollladen Küche', 'RangeControllerShutterID' => $fahrbefehl]]));
IPS_ApplyChanges($alexa);
$m->formUpdates = [];
$meldung = (string)oderNull(static fn () => $m->UIImportAlexa($alexa));
$felder = [];
foreach ($m->formUpdates as [$feld, $parameter, $wert]) {
    $felder[$feld . '.' . $parameter] = $wert;
}
pruefe(str_contains($meldung, 'blind without position 1') && !in_array('Rollladen', array_column(json_decode((string)($felder['AlexaProposals.values'] ?? '[]'), true), 'Name'), true), 'ein Alexa-Rollladen mit ~ShutterMoveStop wird nicht als Position vorgeschlagen: ' . $meldung);
IPS_SetProperty($alexa, 'DeviceShutter', '[]');
IPS_ApplyChanges($alexa);

$m->formUpdates = [];
$meldung = (string)oderNull(static fn () => $m->UIImportAlexa($iid));
pruefe($meldung === 'Choose an Alexa instance.' && !in_array(['AlexaPopup', 'visible', true], $m->formUpdates, true), 'eine andere Instanz wird abgewiesen: ' . $meldung);
IPS_SetProperty($iid, 'Devices', $vorher);
IPS_ApplyChanges($iid);
/* --- Verlauf von Messwerten (10.10.2026): Zeiträume auch ohne Zähler im Schema ------------------ */

$geraeteVorher = IPS_GetProperty($iid, 'Devices');
$raumTemperatur = variable(VARIABLETYPE_FLOAT, 21.5);
IPS_SetProperty($iid, 'Devices', json_encode([['Room' => 'Gast', 'Name' => 'Temperatur', 'Aliases' => '', 'Type' => 'temperature', 'StateVariableID' => $raumTemperatur, 'Invert' => false]]));
IPS_ApplyChanges($iid);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$abfrage = $werkzeuge['get_state'] ?? [];
$felder = $abfrage['inputSchema']['properties'] ?? [];
pruefe(isset($felder['period'], $felder['from'], $felder['to']) && !in_array('now', $felder['period']['enum'] ?? [], true), 'nur ein Temperatursensor: period, from und to gibt es für den Verlauf, „now“ nicht: ' . json_encode(array_keys($felder)));
pruefe(str_contains($felder['period']['description'] ?? '', 'For measured values such as temperature or humidity: the history per hour, day or month (minimum / average / maximum).'), 'die Beschreibung nennt den Verlauf: ' . ($felder['period']['description'] ?? ''));
pruefe(str_contains($abfrage['description'] ?? '', '"How warm was it in the guest room over the last 30 days?" → room=Gast, type=temperature, from='), 'mit Beispiel: ' . ($abfrage['description'] ?? ''));
IPS_SetProperty($iid, 'Devices', $geraeteVorher);
IPS_ApplyChanges($iid);

/* --- Tokens: Mindestlänge, erzeugen, Fehlversuche (Sicherheitsreview 06.10.2026) ------------- */

IPS_SetProperty($iid, 'Devices', json_encode($geraeteStart)); // der Abschnitt davor hinterlässt eine fehlerhafte Liste
$setze('Accesses', [$zugang('Lang', 'lang-token-0123456789abcdef')]);
pruefe($status() === IS_ACTIVE, 'Ausgangslage: aktiv, nicht ' . $status());
$formular = json_decode($m->GetConfigurationForm(), true);
pruefe(array_filter($formular['elements'], static fn (array $e): bool => str_starts_with((string)($e['name'] ?? ''), 'ListError')) === [], 'ohne Fehler keine Fehlermeldung im Formular');
pruefe(array_filter(array_column(array_column(flach($formular['elements']), null, 'name')['Accesses']['values'] ?? [], 'rowColor')) === [], 'und keine rote Zeile');
$setze('Accesses', [$zugang('Kurz', 'abc123'), $zugang('Lang', 'lang-token-0123456789abcdef')]);
pruefe($status() === IS_ACTIVE, 'ein Token unter 24 Zeichen sperrt nur seinen Zugang, „Lang“ bleibt offen: Status ' . $status());
pruefe(str_contains($logText(), 'Access "Kurz": token is shorter than 24 characters') && !str_contains($logText(), 'abc123'), 'das Log nennt den Zugang, aber nicht das Token');

// Fehler im Formular: oben die Meldungen, in der Liste die Zeile rot (Wunsch Burkhard 06.10.2026,
// „Zugang 3“ taugt nicht, weil sich die Spalten sortieren lassen, und der Hinweis gehört ins Formular)
$formular = json_decode($m->GetConfigurationForm(), true);
$elemente = array_column(flach($formular['elements']), null, 'name');
$fehlerTexte = array_column(array_filter($formular['elements'], static fn (array $e): bool => str_starts_with((string)($e['name'] ?? ''), 'ListError')), 'caption');
pruefe(count($fehlerTexte) === 2 && in_array('Access "Kurz": token is shorter than 24 characters', $fehlerTexte, true), 'oben im Formular stehen Überschrift und Fehler: ' . json_encode($fehlerTexte));
pruefe(in_array('Faulty entries are disabled until they are corrected:', $fehlerTexte, true), 'die Überschrift sagt, dass nur die Zugänge gesperrt sind: ' . json_encode($fehlerTexte));
pruefe(array_column($elemente['Accesses']['values'] ?? [], 'rowColor') === ['#FFC0C0', ''], 'die fehlerhafte Zeile ist rot, die andere nicht: ' . json_encode($elemente['Accesses']['values'] ?? null));
pruefe(!str_contains(json_encode($formular), 'abc123'), 'das Formular trägt das Token nicht in die Werte');

$ausgaben = [];
try {
    $ausgaben[] = (string)$m->UIGenerateToken();
    $ausgaben[] = (string)$m->UIGenerateToken();
} catch (Throwable $e) {
    pruefe(false, 'UIGenerateToken fehlt: ' . $e->getMessage());
}
$geleert = array_values(array_filter($m->formUpdates, static fn (array $u): bool => $u[0] === 'Token' && $u[1] === 'value'));
pruefe(count($geleert) === 2 && $geleert[0][2] === '', 'der Knopf „Neues Token“ leert das unsichtbare Feld, das neue Token entsteht beim Übernehmen: ' . json_encode($geleert));
pruefe(preg_match('/[0-9a-f]{24,}/', $ausgaben[0] ?? '') === 0 && str_contains($ausgaben[0] ?? '', 'Show token'), 'er zeigt kein Token und sagt, wo man das neue findet: ' . json_encode($ausgaben[0] ?? null));
$formular = json_decode($m->GetConfigurationForm(), true);
$knopf = array_values(array_filter(flach(array_column(array_filter(flach($formular['elements']), static fn ($e) => ($e['name'] ?? '') === 'Accesses'), 'form')[0] ?? []), static fn ($e) => str_contains((string)($e['onClick'] ?? ''), 'HAGT_UIGenerateToken')))[0] ?? [];
pruefe(($knopf['onClick'] ?? '') === 'echo HAGT_UIGenerateToken($id);' && ($knopf['caption'] ?? '') === 'New token', 'der Knopf „Neues Token“ steht im Bearbeiten-Dialog: ' . json_encode($knopf));
pruefe(str_contains((string)($knopf['confirm'] ?? ''), 'disconnects every assistant'), 'der Knopf fragt vorher, weil ein neues Token alle Assistenten des Zugangs trennt: ' . json_encode($knopf['confirm'] ?? null));
pruefe(!in_array('GeneratedToken', array_column(flach($formular['actions']), 'name'), true) && !in_array('echo HAGT_UIGenerateToken($id);', array_column(flach($formular['actions']), 'onClick'), true), 'unten im Formular gibt es Knopf und Feld nicht mehr');

// Die Bremse selbst, mit vorgegebener Uhrzeit
if (is_file(dirname(__DIR__) . '/libs/SignInGuard.php')) {
    require_once dirname(__DIR__) . '/libs/SignInGuard.php';
}
if (class_exists('SignInGuard')) {
    $ereignisse = static fn (array $schritt): array => array_column($schritt['events'], 0);
    $s = SignInGuard::failure([], 1000);
    pruefe($ereignisse($s) === ['first'], 'der erste Fehlversuch einer Minute wird gemeldet: ' . json_encode($s['events']));
    for ($t = 1001; $t <= 1004; $t++) {
        $s = SignInGuard::failure($s['state'], $t);
        pruefe($s['events'] === [], 'weitere Fehlversuche derselben Minute bleiben still');
    }
    $s = SignInGuard::failure($s['state'], 1070);
    pruefe($s['events'] === [['summary', 5, 1000], ['first']], 'nach der Minute kommt die Zusammenfassung der vorigen: ' . json_encode($s['events']));

    $s = ['state' => []];
    for ($t = 0; $t < 20; $t++) {
        $s = SignInGuard::failure($s['state'], 2000 + $t);
    }
    pruefe(in_array('blocked', $ereignisse($s), true) && SignInGuard::blockedFor($s['state'], 2019) === 60 && SignInGuard::blockedFor($s['state'], 2050) === 29, 'nach 20 Fehlversuchen in einer Minute 60 s Sperre: ' . SignInGuard::blockedFor($s['state'], 2019));
    pruefe(SignInGuard::blockedFor($s['state'], 2079) === 0 && SignInGuard::blockedFor([], 2079) === 0, 'danach ist der Endpunkt wieder offen');
    $s = SignInGuard::failure($s['state'], 2079);
    pruefe($ereignisse($s) === ['summary', 'first'], 'der nächste Fehlversuch beginnt eine neue Minute: ' . json_encode($s['events']));
} else {
    pruefe(false, 'die Klasse SignInGuard fehlt');
}

// Am Hook: Fehlversuche ins Log, nach 20 in einer Minute 429 für alle
$token = 'lang-token-0123456789abcdef';
$setze('Accesses', [$zugang('Lang', $token)]);
pruefe($status() === IS_ACTIVE, 'Ausgangslage für den Hook: aktiv, nicht ' . $status());
$hook = new ReflectionMethod(HomeAgent::class, 'ProcessHookData');
$anfrage = static function (string $auth) use ($hook, $m, $iid): string {
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $iid, 'HTTP_AUTHORIZATION' => $auth, 'REMOTE_ADDR' => '192.0.2.1'];
    ob_start();
    try {
        $hook->invoke($m);
    } finally {
        $ausgabe = (string)ob_get_clean();
    }
    return $ausgabe;
};
$vorher = count($m->logs());
$a = $anfrage('Bearer ' . $token);
pruefe(str_contains($a, 'Parse error'), 'das richtige Token kommt durch (leerer Körper ergibt Parse error): ' . $a . ' Status ' . $status());
for ($i = 1; $i <= 19; $i++) {
    $anfrage('Bearer falsch-' . $i);
}
$neu = array_column(array_slice($m->logs(), $vorher), 'Message');
pruefe(count(array_filter($neu, static fn (string $t): bool => str_contains($t, 'Rejected sign-in'))) === 1, 'von 19 Fehlversuchen einer Minute steht einer im Log: ' . implode(' / ', $neu));
pruefe(str_contains($anfrage('Bearer ' . $token), 'Parse error'), 'nach 19 Fehlversuchen kommt das richtige Token noch durch');
$anfrage('Bearer falsch-20');
pruefe(str_contains($logText(), 'Too many rejected sign-ins from 192.0.2.1, this sender is blocked for 60 s'), 'der 20. Fehlversuch sperrt den Absender und steht im Log: ' . $logText());
$a = $anfrage('Bearer ' . $token);
pruefe(str_contains($a, 'Too many rejected sign-ins'), 'während der Sperre bekommt auch das richtige Token eine Absage: ' . $a);
pruefe(!str_contains($logText(), $token) && !str_contains($logText(), 'falsch-'), 'kein Token steht im Log');

// Je Absender (Backlog B60): Über Connect kommt alles von 127.0.0.1, der Absender steht in X-Forwarded-For.
// 20 falsche Tokens von einem Angreifer sperren nur ihn, der eigene Assistent kommt über Connect weiter durch.
$ueberConnect = static function (string $auth, string $absender) use ($hook, $m, $iid): string {
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $iid, 'HTTP_AUTHORIZATION' => $auth, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => $absender];
    ob_start();
    try {
        $hook->invoke($m);
    } finally {
        $ausgabe = (string)ob_get_clean();
    }
    return $ausgabe;
};
for ($i = 1; $i <= 20; $i++) {
    $ueberConnect('Bearer falsch-' . $i, '203.0.113.9');
}
pruefe(str_contains($ueberConnect('Bearer ' . $token, '203.0.113.9'), 'Too many rejected sign-ins'), 'der Angreifer ist gesperrt, auch mit richtigem Token');
pruefe(str_contains($ueberConnect('Bearer ' . $token, '160.79.106.181'), 'Parse error'), 'der eigene Assistent über Connect kommt durch');
pruefe(str_contains($ueberConnect('Bearer ' . $token, '10.0.0.1, 160.79.106.181'), 'Parse error'), 'ein vorangestellter falscher Absender hilft dem Angreifer nicht, gezählt wird der letzte Eintrag');
pruefe(str_contains($logText(), 'Too many rejected sign-ins from 203.0.113.9, this sender is blocked for 60 s'), 'das Log nennt den gesperrten Absender');

/* --- Rest aus Code- und Sicherheitsreview 06.10.2026 ------------------------------------------ */

// Nicht bereit (Status 104 oder 201): eine Antwort, die das sagt, statt „falsches Token“ (401).
// Vorher begann ein Client wie HA bei 401 mit der OAuth-Suche, und man suchte einen Tokenfehler.
$setze('Accesses', []);
pruefe($status() === 104, 'Ausgangslage: kein Zugang, Status 104');
$a = $anfrage('Bearer ' . $token);
pruefe(str_contains($a, 'not ready') && !str_contains($a, 'Missing or invalid bearer token'), 'nicht bereit wird als solches gemeldet: ' . $a);
pruefe(!str_contains($a, '104') && !str_contains($a, 'Access'), 'ohne Einzelheiten der Konfiguration: ' . $a);

// Ein Token mit Leerzeichen am Rand gilt wie ohne; die Mindestlänge zählt ohne Rand
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', ''); // die Sperre aus dem Abschnitt davor aufheben
$setze('Accesses', [$zugang('Rand', ' ' . $token . ' ')]);
pruefe($status() === IS_ACTIVE && str_contains($anfrage('Bearer ' . $token), 'Parse error'), 'eingefügtes Leerzeichen am Rand stört nicht: Status ' . $status());
$setze('Accesses', [$zugang('Kurz mit Rand', str_repeat(' ', 10) . 'kurz-token' . str_repeat(' ', 10))]);
pruefe($status() === 201, 'Leerzeichen zählen nicht zur Mindestlänge: Status ' . $status());

// ActionFailed begrenzen: Schlüssel prüfen, Text kürzen und ohne Steuerzeichen, höchstens 50 Einträge
$setze('Accesses', [$zugang('Lang', $token)]);
$lang = "Erste Zeile\nZweite Zeile\r\n" . str_repeat('x', 1000);
IPS_RequestAction($iid, 'ActionFailed', json_encode(['key' => 'aaaaaaaaaaaa', 'variable' => $licht, 'error' => $lang]));
$text = (string)$lesen->invoke($m, 'aaaaaaaaaaaa');
pruefe(mb_strlen($text) <= 300 && !preg_match('/[\x00-\x1F]/', $text) && str_starts_with($text, 'Erste Zeile Zweite Zeile'), 'Fehlertext gekürzt und einzeilig: ' . mb_strlen($text) . ' ' . json_encode(mb_substr($text, 0, 40)));
$alleLogs = $m->logs();
$letzte = (string)(end($alleLogs)['Message'] ?? '');
pruefe(!preg_match('/[\x00-\x1F]/', $letzte) && mb_strlen($letzte) < 400, 'auch im Log einzeilig und gekürzt: ' . mb_strlen($letzte));
foreach (['../etc', 'ABCDEFABCDEF', str_repeat('a', 13), ''] as $schluessel) {
    try {
        IPS_RequestAction($iid, 'ActionFailed', json_encode(['key' => $schluessel, 'variable' => $licht, 'error' => 'x']));
        pruefe(false, 'ungültiger Schlüssel hätte abgewiesen werden müssen: ' . json_encode($schluessel));
    } catch (ErrorException $e) {
        pruefe(str_contains($e->getMessage(), 'ActionFailed expects'), 'ungültiger Schlüssel wird abgewiesen: ' . json_encode($schluessel));
    }
}
for ($i = 0; $i < 60; $i++) {
    IPS_RequestAction($iid, 'ActionFailed', json_encode(['key' => sprintf('%012x', $i), 'variable' => $licht, 'error' => 'Fehler ' . $i]));
}
$gemerkt = json_decode((string)(new ReflectionMethod($m, 'GetBuffer'))->invoke($m, 'ActionFailures'), true);
pruefe(is_array($gemerkt) && count($gemerkt) <= 50 && $lesen->invoke($m, sprintf('%012x', 59)) === 'Fehler 59' && $lesen->invoke($m, sprintf('%012x', 0)) === null, 'höchstens 50 Einträge, die ältesten fallen heraus: ' . (is_array($gemerkt) ? count($gemerkt) : '?'));

/* --- Mediaplayer im Werkzeugschema (build 61) ---------------------------------------------------- */

$wiedergabe = variable(VARIABLETYPE_INTEGER, 3);
IPS_SetVariableCustomAction($wiedergabe, $antriebAktion);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Wintergarten', 'Name' => 'Sonos', 'Aliases' => '', 'Type' => 'media', 'PlaybackVariableID' => $wiedergabe, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Mediaplayer nur mit Wiedergabe ist gültig');
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$schema = $werkzeuge['set_state']['inputSchema']['properties'] ?? [];
pruefe(($schema['command']['enum'] ?? null) === ['on', 'off', 'stop', 'play', 'pause', 'next', 'previous'] && ($schema['type']['enum'] ?? null) === ['media'], 'set_state bietet die Wiedergabebefehle an: ' . json_encode($schema['command'] ?? null));
pruefe(str_contains($schema['command']['description'] ?? '', 'play/pause/next/previous control a media player') && str_contains($schema['command']['description'] ?? '', 'stop ends playback') && str_contains($schema['command']['description'] ?? '', 'off pauses a media player without power switch, on with type=media resumes it'), 'die Beschreibung erklärt die Befehle: ' . ($schema['command']['description'] ?? ''));
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', '"Pause the music in the conservatory" → room=Wintergarten, type=media, command=pause.'), 'mit Beispielsatz: ' . ($werkzeuge['set_state']['description'] ?? ''));

// Review 18.10.2026 (N11): Pausiert wird jeder Player ohne schaltbares Ein/Aus, auch einer mit Statustext (HomeAssistant-Modul,
// Zeichenkette ohne Aktion); mit Wahrheitswert und Aktion schaltet off aus, dann steht der Satz nicht da.
$beschreibung = static function (int $zustand) use ($iid, $m, $wiedergabe): string {
    IPS_SetProperty($iid, 'Devices', json_encode([
        ['Room' => 'Wintergarten', 'Name' => 'Sonos', 'Aliases' => '', 'Type' => 'media', 'StateVariableID' => $zustand, 'PlaybackVariableID' => $wiedergabe, 'Invert' => false],
    ]));
    IPS_ApplyChanges($iid);
    $liste = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
    return $liste['set_state']['inputSchema']['properties']['command']['description'] ?? '';
};
$text = $beschreibung(variable(VARIABLETYPE_STRING, 'playing'));
pruefe(str_contains($text, 'off pauses a media player'), 'Statustext ohne Aktion: off pausiert, die Beschreibung sagt es: ' . $text);
$schalter = variable(VARIABLETYPE_BOOLEAN, true);
IPS_SetVariableCustomAction($schalter, $antriebAktion);
$text = $beschreibung($schalter);
pruefe(!str_contains($text, 'off pauses'), 'Ein/Aus mit Aktion: off schaltet aus, kein Satz zum Pausieren: ' . $text);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Wintergarten', 'Name' => 'Sonos', 'Aliases' => '', 'Type' => 'media', 'PlaybackVariableID' => $wiedergabe, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(($werkzeuge['set_state']['annotations']['idempotentHint'] ?? null) === true && !isset($schema['volume']), 'ohne Lautstärke bleibt set_state idempotent');

/* --- Lautstärke und Stumm im Werkzeugschema (build 62) ------------------------------------------- */

$lautstaerke = variable(VARIABLETYPE_INTEGER, 20);
IPS_SetVariableCustomAction($lautstaerke, $antriebAktion);
$stumm = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetVariableCustomAction($stumm, $antriebAktion);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Wintergarten', 'Name' => 'Sonos', 'Aliases' => '', 'Type' => 'media', 'PlaybackVariableID' => $wiedergabe, 'VolumeVariableID' => $lautstaerke, 'MuteVariableID' => $stumm, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$schema = $werkzeuge['set_state']['inputSchema']['properties'] ?? [];
pruefe(($schema['volume']['type'] ?? null) === 'number' && ($schema['volume']['minimum'] ?? null) === 0 && ($schema['volume']['maximum'] ?? null) === 100, 'volume in Prozent: ' . json_encode($schema['volume'] ?? null));
pruefe(($schema['volume_change']['type'] ?? null) === 'number' && ($schema['volume_change']['minimum'] ?? null) === -100 && ($schema['volume_change']['maximum'] ?? null) === 100 && str_contains($schema['volume_change']['description'] ?? '', '10'), 'volume_change in Punkten, lauter ohne Zahl ist 10: ' . json_encode($schema['volume_change'] ?? null));
pruefe(($schema['mute']['type'] ?? null) === 'boolean', 'mute als Wahrheitswert: ' . json_encode($schema['mute'] ?? null));
pruefe(($werkzeuge['set_state']['annotations']['idempotentHint'] ?? null) === false, 'volume_change ist nicht idempotent: ' . json_encode($werkzeuge['set_state']['annotations'] ?? null));
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', '"Louder in the bathroom" → room=Bad, type=media, volume_change=10.'), 'Beispiel für lauter: ' . ($werkzeuge['set_state']['description'] ?? ''));
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', 'command=play alone resumes the media players paused in the last hour.'), 'play allein ist erklärt: ' . ($werkzeuge['set_state']['description'] ?? ''));
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', 'give a command, volume, volume_change or mute.'), 'die Felder stehen in der Aufzählung: ' . ($werkzeuge['set_state']['description'] ?? ''));

/* --- Fenster mit Antrieb in der Beschreibung von get_state (build 63) ----------------------------- */

$kontakt = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Dachboden', 'Name' => 'Giebelfenster', 'Aliases' => '', 'Type' => 'window', 'StateVariableID' => $kontakt, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
pruefe(!str_contains($werkzeuge['get_state']['description'] ?? '', 'with drive'), 'nur Kontakte: kein Satz zum Antrieb');
$antrieb = variable(VARIABLETYPE_FLOAT, 0.0);
IPS_SetVariableCustomAction($antrieb, $antriebAktion);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Dachboden', 'Name' => 'Giebelfenster', 'Aliases' => '', 'Type' => 'window', 'StateVariableID' => $kontakt, 'Invert' => false],
    ['Room' => 'Flur', 'Name' => 'Fenster', 'Aliases' => '', 'Type' => 'window', 'LevelVariableID' => $antrieb, 'Invert' => false, 'Tilt' => true],
]));
IPS_ApplyChanges($iid);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
pruefe(str_contains($werkzeuge['get_state']['description'] ?? '', 'Windows marked "with drive" can be opened and closed with set_state, the others only report their state.'), 'get_state erklärt „with drive“: ' . ($werkzeuge['get_state']['description'] ?? ''));

/* --- Fehlerhafter Zugang sperrt nur sich selbst (build 64, Backlog B06) --------------------------- */

(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
IPS_SetProperty($iid, 'Devices', json_encode($geraeteStart));
$setze('Accesses', [$zugang('Kurz', 'abc123'), $zugang('Lang', $token), $zugang('Dachboden', 'boden-token-0123456789abcdef', 'Dachboden')]);
pruefe($status() === IS_ACTIVE, 'zwei fehlerhafte Zugänge, einer gültig: aktiv, nicht ' . $status());
pruefe(str_contains($anfrage('Bearer ' . $token), 'Parse error'), 'der gültige Zugang kommt durch');
$a = $anfrage('Bearer abc123');
pruefe(str_contains($a, 'Missing or invalid bearer token'), 'der Zugang mit zu kurzem Token ist gesperrt: ' . $a);
$a = $anfrage('Bearer boden-token-0123456789abcdef');
pruefe(str_contains($a, 'Missing or invalid bearer token'), 'der Zugang mit unbekanntem Standardraum ist gesperrt: ' . $a);
$setze('Accesses', [$zugang('Kurz', 'abc123'), $zugang('Dachboden', 'boden-token-0123456789abcdef', 'Dachboden')]);
pruefe($status() === 201, 'ohne einen gültigen Zugang bleibt der Endpunkt zu: Status ' . $status());
$setze('Accesses', [$zugang('Lang', $token)]);
IPS_SetProperty($iid, 'Devices', json_encode([['Room' => 'Küche', 'Name' => '', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $licht, 'Invert' => false]]));
IPS_ApplyChanges($iid);
pruefe($status() === IS_ACTIVE, 'ein Fehler in der Geräteliste sperrt nur das Gerät: Status ' . $status());
IPS_SetProperty($iid, 'Devices', json_encode($geraeteStart));
IPS_ApplyChanges($iid);

/* --- Fehlerhaftes Gerät, Szene oder Bilanz sperrt nur sich selbst, Referenzen (Blindtest 11.10.2026) --- */
// Anlass: Geräte der Blindtest-Instanz zeigten auf Variablen, die danach gelöscht wurden. Das Formular meldete
// die Fehler, der Status blieb 102, und die Referenzsuche von Symcon fand die Instanz nicht.
$weg = variable(VARIABLETYPE_BOOLEAN, true);
$setze('Devices', array_merge($geraeteStart, [['Room' => 'Keller', 'Name' => 'Zwischenstecker', 'Aliases' => '', 'Type' => 'socket', 'StateVariableID' => $weg, 'Invert' => false]]));
pruefe($status() === IS_ACTIVE && str_contains($m->QueryState('Keller', '', '', false), 'Zwischenstecker'), 'Ausgangslage: Zwischenstecker freigegeben: ' . $m->QueryState('Keller', '', '', false));
pruefe(in_array($weg, $m->GetReferenceList(), true) && in_array($licht, $m->GetReferenceList(), true), 'die Variablen der Geräte sind als Referenz gemeldet: ' . json_encode($m->GetReferenceList()));
IPS_DeleteVariable($weg);
pruefe($status() === IS_ACTIVE, 'Variable gelöscht: der Endpunkt bleibt offen');
$text = $m->QueryState('', '', '', false);
pruefe(!str_contains($text, 'Zwischenstecker') && str_contains($text, 'Deckenlicht'), 'das Gerät ohne Variable ist sofort gesperrt, die übrigen bleiben: ' . $text);
$text = $m->SwitchDevices('', '', 'Zwischenstecker', true);
pruefe(!str_contains($text, '| on'), 'das gesperrte Gerät lässt sich nicht schalten: ' . $text);
$formular = json_decode($m->GetConfigurationForm(), true);
$fehlerTexte = array_column(array_filter($formular['elements'], static fn (array $e): bool => str_starts_with((string)($e['name'] ?? ''), 'ListError')), 'caption');
pruefe(in_array('Faulty entries are disabled until they are corrected:', $fehlerTexte, true) && array_any($fehlerTexte, static fn (string $t): bool => str_starts_with($t, 'Device "Zwischenstecker" (Keller): variable for')), 'das Formular sagt, dass nur der Eintrag gesperrt ist: ' . json_encode($fehlerTexte));
pruefe(!str_contains(json_encode($formular), 'endpoint stays closed'), 'und nicht, dass der Endpunkt zu ist');
IPS_ApplyChanges($iid);
pruefe($status() === IS_ACTIVE && str_contains($logText(), 'Faulty entries are disabled until they are corrected: Device "Zwischenstecker" (Keller)'), 'beim Übernehmen: aktiv, Warnung im Log: ' . $logText());
pruefe(!in_array($weg, $m->GetReferenceList(), true) && in_array($licht, $m->GetReferenceList(), true), 'die gelöschte Variable ist keine Referenz mehr: ' . json_encode($m->GetReferenceList()));
$kino = IPS_CreateScript(SCRIPTTYPE_PHP);
$setze('Scenes', [['Room' => '', 'Name' => 'Kino', 'Aliases' => '', 'ScriptID' => $kino], ['Room' => '', 'Name' => 'Party', 'Aliases' => '', 'ScriptID' => 69993]]);
pruefe($status() === IS_ACTIVE && in_array($kino, $m->GetReferenceList(), true), 'Szene mit gelöschtem Skript: aktiv, das gültige Skript ist Referenz');
pruefe(str_starts_with($m->RunScene('', 'Party'), 'Nothing was started'), 'die gesperrte Szene startet nicht: ' . $m->RunScene('', 'Party'));
pruefe($m->RunScene('', 'Kino') === 'Scene "Kino" was started.', 'die gültige startet: ' . $m->RunScene('', 'Kino'));
$setze('Scenes', []);
$setze('Rooms', [['Name' => '', 'Aliases' => '', 'Floor' => '']]);
pruefe($status() === 201, 'ein Fehler in der Raumliste schließt weiterhin den Endpunkt: Status ' . $status());
$formular = json_decode($m->GetConfigurationForm(), true);
pruefe(str_contains(json_encode($formular), 'The room list has errors or no access is free of errors, the endpoint stays closed until this is corrected'), 'das Formular sagt dann, dass der Endpunkt zu ist');
$setze('Rooms', []);
$setze('Devices', $geraeteStart);
pruefe($status() === IS_ACTIVE, 'Ausgangslage wiederhergestellt');

/* --- Luftentfeuchter im Werkzeugschema (build 65) ------------------------------------------------- */

$feuchteSoll = variable(VARIABLETYPE_FLOAT, 55.0);
IPS_SetVariableCustomAction($feuchteSoll, $antriebAktion);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Keller', 'Name' => 'Luftentfeuchter', 'Aliases' => '', 'Type' => 'humidifier', 'SetpointVariableID' => $feuchteSoll, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Luftentfeuchter nur mit Zielfeuchte ist gültig: ' . IPS_GetInstance($iid)['InstanceStatus']);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$schema = $werkzeuge['set_state']['inputSchema']['properties'] ?? [];
pruefe(($schema['humidity']['type'] ?? null) === 'number' && ($schema['humidity']['minimum'] ?? null) === 0 && ($schema['humidity']['maximum'] ?? null) === 100 && !isset($schema['command']), 'humidity in Prozent, ohne Befehle: ' . json_encode($schema));
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', 'give humidity.') && str_contains($werkzeuge['set_state']['description'] ?? '', '"Set the dehumidifier in the basement to 50 percent" → room=Keller, type=humidifier, humidity=50.'), 'Aufzählung und Beispiel: ' . ($werkzeuge['set_state']['description'] ?? ''));

/* --- Auswahl per Name im Werkzeugschema (build 66) ------------------------------------------------ */

$modus = variable(VARIABLETYPE_STRING, 'Smart');
IPS_SetVariableCustomAction($modus, $antriebAktion);
$luefter = variable(VARIABLETYPE_STRING, 'Low');
IPS_SetVariableCustomAction($luefter, $antriebAktion);
// Darstellung wie am nuc gelesen (Luftentfeuchter über das HomeAssistant-Modul, #25833 und #28873, 12.10.2026)
IPS_SetVariableCustomPresentation($modus, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => '[{"Caption":"Setzen","Color":-1,"IconActive":false,"IconValue":"","Value":"Set"},{"Caption":"Smart","Color":-1,"IconActive":false,"IconValue":"","Value":"Smart"},{"Caption":"Kontinuierlich","Color":-1,"IconActive":false,"IconValue":"","Value":"Continuous"},{"Caption":"Entfeuchten","Color":-1,"IconActive":false,"IconValue":"","Value":"Dry"}]']);
IPS_SetVariableCustomPresentation($luefter, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => '[{"Caption":"Niedrig","Color":-1,"IconActive":false,"IconValue":"","Value":"Low"},{"Caption":"Medium","Color":-1,"IconActive":false,"IconValue":"","Value":"Medium"},{"Caption":"Hoch","Color":-1,"IconActive":false,"IconValue":"","Value":"High"}]']);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Keller', 'Name' => 'Luftentfeuchter', 'Aliases' => '', 'Type' => 'humidifier', 'SetpointVariableID' => $feuchteSoll, 'ModeVariableID' => $modus, 'FanVariableID' => $luefter, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
$schema = $werkzeuge['set_state']['inputSchema']['properties'] ?? [];
pruefe(($schema['mode']['type'] ?? null) === 'string' && !isset($schema['mode']['enum']) && str_starts_with($schema['mode']['description'] ?? '', 'Operating mode by name'), 'mode als Text ohne feste Liste: ' . json_encode($schema['mode'] ?? null));
pruefe(($schema['fan_speed']['type'] ?? null) === 'string' && str_starts_with($schema['fan_speed']['description'] ?? '', 'Fan speed by name'), 'fan_speed als Text: ' . json_encode($schema['fan_speed'] ?? null));
// Bedienungs-Blindtest 12.10.2026: Der Agent fand Modi und Lüfterstufen nirgends, das Vokabular fehlte in der
// Beschreibung (rolesOf() auf schon aufbereitete Einträge, die ihre Variablen unter „roles“ tragen).
pruefe(str_ends_with($schema['mode']['description'] ?? '', 'Known modes: Setzen, Smart, Kontinuierlich, Entfeuchten.'), 'die Beschreibung nennt die Modi: ' . ($schema['mode']['description'] ?? ''));
pruefe(str_ends_with($schema['fan_speed']['description'] ?? '', 'Known fan speeds: Niedrig, Medium, Hoch.'), 'die Beschreibung nennt die Lüfterstufen: ' . ($schema['fan_speed']['description'] ?? ''));
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', 'give humidity, mode or fan_speed.') && str_contains($werkzeuge['set_state']['description'] ?? '', '"Set the dehumidifier fan to high" → type=humidifier, fan_speed=high.'), 'Aufzählung und Beispiel: ' . ($werkzeuge['set_state']['description'] ?? ''));

/* --- Lautstärke mit Befehl im Beispiel (Leitbild „schnell vor günstig“, 12.10.2026) ---------------- */

$lautMedia = variable(VARIABLETYPE_INTEGER, 20);
$wiedergabeMedia = variable(VARIABLETYPE_INTEGER, 1);
IPS_SetVariableCustomAction($wiedergabeMedia, $antriebAktion);
IPS_SetVariableCustomAction($lautMedia, $antriebAktion);
IPS_SetVariableCustomPresentation($lautMedia, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => 100]);
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Wintergarten', 'Name' => 'Sonos', 'Aliases' => '', 'Type' => 'media', 'VolumeVariableID' => $lautMedia, 'PlaybackVariableID' => $wiedergabeMedia, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
pruefe(str_contains($werkzeuge['set_state']['description'] ?? '', '"Quiet music in the conservatory" → room=Wintergarten, type=media, command=play, volume=15.'), 'Beispiel mit Lautstärke und Befehl in einem Aufruf: ' . ($werkzeuge['set_state']['description'] ?? ''));

/* --- Datum und Uhrzeit als Schlusszeile von get_state (Backlog B14) ------------------------------- */

$werkzeuge = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $m, HomeAgent::class)()), true), null, 'name');
pruefe(str_contains($werkzeuge['get_state']['description'] ?? '', 'The last line gives the current date and time.'), 'get_state nennt die Schlusszeile: ' . ($werkzeuge['get_state']['description'] ?? ''));
$aufrufZeit = new ReflectionMethod(HomeAgent::class, 'callTool');
$vorher = time();
$r = $aufrufZeit->invoke($m, 'get_state', ['room' => 'Keller'], null);
$letzte = (string)array_last(explode("\n", $r['text']));
pruefe(preg_match('/^Now: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}) \((Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\)$/', $letzte, $treffer) === 1, 'letzte Zeile mit Datum, Uhrzeit und Wochentag: ' . $letzte);
pruefe(isset($treffer[1]) && abs(strtotime($treffer[1]) - $vorher) < 120 && ($treffer[2] ?? '') === date('l', strtotime($treffer[1])), 'die Zeit stimmt: ' . ($treffer[1] ?? '-'));
$r = $aufrufZeit->invoke($m, 'get_state', ['room' => 'nirgendwo'], null);
pruefe(str_starts_with((string)array_last(explode("\n", $r['text'])), 'Now: '), 'auch bei leerem Treffer: ' . $r['text']);
$r = $aufrufZeit->invoke($m, 'set_state', ['room' => 'Keller', 'humidity' => 55], null);
pruefe(!str_contains($r['text'], 'Now: '), 'set_state bleibt ohne Zeitzeile: ' . $r['text']);
foreach (['Now: %s', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $text) {
    pruefe(isset($de[$text]), 'deutsche Übersetzung für „' . $text . '“');
}

/* --- Leistung an zwei Geräten: Hinweis im Selbsttest (build 69, Backlog B47) ---------------------- */

$steckdosenLeistung = variable(VARIABLETYPE_FLOAT, 264.6);
IPS_SetName($steckdosenLeistung, 'Leistung Entfeuchter');
IPS_SetProperty($iid, 'Devices', json_encode([
    ['Room' => 'Keller', 'Name' => 'Entfeuchter', 'Aliases' => '', 'Type' => 'switch', 'StateVariableID' => $licht, 'PowerVariableID' => $steckdosenLeistung, 'Invert' => false],
    ['Room' => 'Keller', 'Name' => 'Luftentfeuchter', 'Aliases' => '', 'Type' => 'humidifier', 'SetpointVariableID' => $feuchteSoll, 'PowerVariableID' => $steckdosenLeistung, 'Invert' => false],
]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Luftentfeuchter mit Leistung ist gültig: ' . IPS_GetInstance($iid)['InstanceStatus']);
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Note: Leistung Entfeuchter is used by Keller Entfeuchter and Keller Luftentfeuchter, consumption and power count it only once'), 'der Selbsttest nennt die doppelt genutzte Leistung: ' . $test);
pruefe(isset($de['Note: %s is used by %s and %s, consumption and power count it only once']), 'deutsche Übersetzung des Hinweises');

/* --- Laufzeit: Listen einmal je Anfrage prüfen, Texte nur für Fehler (build 72) ------------------ */

// Anlass 11.10.2026: get_state am nuc (152 Geräte) brauchte 4,5 bis 6,2 s statt 0,02 bis 0,4 s. Seit
// build 71 prüft jeder Aufruf die Listen, und das rund zehnmal je Anfrage; jede Prüfung übersetzte
// je Gerät über 20 Beschriftungen, auch für fehlerfreie Zeilen. Gezählt werden die Übersetzungen.
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
$viele = [];
for ($i = 1; $i <= 40; $i++) {
    $viele[] = ['Room' => 'Halle', 'Name' => 'Leuchte ' . $i, 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $licht, 'Invert' => false];
}
IPS_SetProperty($iid, 'Scenes', '[]');
IPS_SetProperty($iid, 'Rooms', '[]');
IPS_SetProperty($iid, 'Devices', json_encode($viele));
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Lang', $token)]));
IPS_ApplyChanges($iid);
pruefe(IPS_GetInstance($iid)['InstanceStatus'] === IS_ACTIVE, 'Ausgangslage Laufzeit: 40 Leuchten, aktiv: ' . IPS_GetInstance($iid)['InstanceStatus']);

$werkzeugAufruf = static function (array $argumente) use ($hook, $m, $iid, $token): string {
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $iid, 'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '192.0.2.1'];
    HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_state', 'arguments' => $argumente]]);
    ob_start();
    try {
        $hook->invoke($m);
    } finally {
        $ausgabe = (string)ob_get_clean();
        HomeAgentHarness::$body = '';
    }
    return $ausgabe;
};
HomeAgentHarness::$translations = 0;
$a = $werkzeugAufruf(['room' => 'Halle']);
$anzahl = HomeAgentHarness::$translations;
pruefe(str_contains($a, '40 of 40 checked devices active') || str_contains($a, '40 device'), 'get_state über den Hook liefert alle 40 Leuchten: ' . substr($a, 0, 200));
pruefe($anzahl < 200, 'ein Werkzeugaufruf mit 40 fehlerfreien Geräten übersetzt höchstens 200 Texte, nicht ' . $anzahl);

HomeAgentHarness::$translations = 0;
$m->RunSelfTest();
pruefe(HomeAgentHarness::$translations < 200, 'der Selbsttest mit 40 fehlerfreien Geräten übersetzt höchstens 200 Texte, nicht ' . HomeAgentHarness::$translations);

// Fehlerhafte Zeilen nennen weiter Gerät, Raum und Fähigkeit
$geloescht = variable(VARIABLETYPE_BOOLEAN, false);
IPS_DeleteVariable($geloescht);
$viele[] = ['Room' => 'Halle', 'Name' => 'Weg', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $geloescht, 'Invert' => false];
IPS_SetProperty($iid, 'Devices', json_encode($viele));
IPS_ApplyChanges($iid);
$test = $m->RunSelfTest();
pruefe(str_contains($test, 'Device "Weg" (Halle): variable for Switching state does not exist'), 'der Fehlertext nennt weiter Gerät, Raum und Fähigkeit: ' . $test);
$a = $werkzeugAufruf(['room' => 'Halle']);
pruefe(str_contains($a, '40 of 40 checked devices active') || str_contains($a, '40 device'), 'das fehlerhafte Gerät bleibt gesperrt, die übrigen 40 antworten: ' . substr($a, 0, 200));

/* --- OAuth Teil 1: Schalter, Metadaten unter dem Hook (build 72) --------------------------------- */

// Anfragen wie im Mitschnitt vom 11.10.2026: über Connect, also von 127.0.0.1 mit X-Forwarded-For
$fehlversuche = static fn (): string => (string)(new ReflectionMethod($m, 'GetBuffer'))->invoke($m, 'SignInFailures');
$oauthAnfrage = static function (string $methode, string $pfad, string $auth = '') use ($hook, $m, $iid): array {
    $_SERVER = ['REQUEST_METHOD' => $methode, 'REQUEST_URI' => '/hook/homeagent/' . $iid . $pfad, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '160.79.106.181'];
    if ($auth !== '') {
        $_SERVER['HTTP_AUTHORIZATION'] = $auth;
    }
    $m->response = null;
    ob_start();
    try {
        $hook->invoke($m);
    } finally {
        ob_end_clean();
    }
    return $m->response ?? ['status' => 0, 'headers' => [], 'body' => ''];
};
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
$setze('Accesses', [$zugang('Pfad', $token, '', false, true)]);
pruefe($status() === IS_ACTIVE, 'Ausgangslage OAuth: aktiv, nicht ' . $status());

pruefe(!array_key_exists('OAuthEnabled', json_decode(IPS_GetConfiguration($iid), true)), 'keinen Schalter mehr: OAuth gibt es, sobald Connect aktiv ist (Burkhard 12.10.2026)');

// ohne Connect: wie bisher, die reservierten Pfade sind aber nie ein Token und zählen nicht als Fehlversuch
$a = $oauthAnfrage('POST', '');
pruefe($a['status'] === 401 && ($a['headers']['WWW-Authenticate'] ?? '') === 'Bearer', 'ohne Connect: 401 ohne Metadaten: ' . json_encode($a['headers']['WWW-Authenticate'] ?? null));
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
$a = $oauthAnfrage('GET', '/.well-known/oauth-protected-resource');
pruefe($a['status'] === 404 && !str_contains($a['body'], 'resource'), 'ohne Connect: Metadaten gibt es nicht (404): ' . $a['status']);
pruefe($fehlversuche() === '', 'ohne Connect: die Metadatenabfrage zählt nicht als Fehlversuch: ' . $fehlversuche());

// Ohne Connect: Claude erreicht die Anlage nicht
HomeAgentHarness::$connect = '';
$a = $oauthAnfrage('GET', '/.well-known/openid-configuration');
pruefe($a['status'] === 404, 'ohne Connect: keine Metadaten: ' . $a['status']);
$a = $oauthAnfrage('POST', '');
pruefe(($a['headers']['WWW-Authenticate'] ?? '') === 'Bearer', 'ohne Connect: 401 ohne Metadaten');

// mit Connect: Ablauf wie im Mitschnitt
HomeAgentHarness::$connect = 'https://example.ipmagic.de/';
$basis = 'https://example.ipmagic.de/hook/homeagent/' . $iid;
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
$a = $oauthAnfrage('POST', '');
pruefe($a['status'] === 401 && ($a['headers']['WWW-Authenticate'] ?? '') === 'Bearer resource_metadata="' . $basis . '/.well-known/oauth-protected-resource", scope="mcp"', 'mit Connect: 401 nennt die Metadaten unter dem Hook: ' . json_encode($a['headers']['WWW-Authenticate'] ?? null));
$stand = $fehlversuche();
$a = $oauthAnfrage('GET', '/.well-known/oauth-protected-resource');
$pr = json_decode($a['body'], true);
pruefe($a['status'] === 200 && ($pr['resource'] ?? null) === $basis && ($pr['authorization_servers'] ?? null) === [$basis], 'Ressourcen-Metadaten mit der Connect-Adresse: ' . $a['body']);
$a = $oauthAnfrage('GET', '/.well-known/openid-configuration');
$as = json_decode($a['body'], true);
pruefe($a['status'] === 200 && ($as['issuer'] ?? null) === $basis && ($as['authorization_endpoint'] ?? null) === $basis . '/oauth/authorize', 'Autorisierungsserver-Metadaten unter dem Hook: ' . $a['body']);
$a = $oauthAnfrage('GET', '/.well-known/oauth-protected-resource?x=1');
pruefe($a['status'] === 200, 'ein Query-String stört die Metadaten nicht');
$a = $oauthAnfrage('GET', '/oauth/authorize?response_type=code');
pruefe($a['status'] === 400 && !isset($a['headers']['Location']), 'authorize ohne Client (seit Teil 2): Fehlerseite ohne Weiterleitung: ' . $a['status']);
$a = $oauthAnfrage('POST', '/oauth/token');
pruefe($a['status'] === 400 && str_contains($a['body'], 'invalid_request'), 'token ohne Felder (seit Teil 3): 400 invalid_request: ' . $a['status'] . ' ' . $a['body']);
foreach (['/oauth/register'] as $pfad) {
    $a = $oauthAnfrage('POST', $pfad);
    pruefe($a['status'] === 503 && str_contains($a['body'], 'temporarily_unavailable'), 'noch nicht gebaut: ' . $pfad . ' antwortet 503 temporarily_unavailable: ' . $a['status'] . ' ' . $a['body']);
}
pruefe($stand === '' && $fehlversuche() === '', 'weder das POST ohne Anmeldung noch die OAuth-Adressen zählen als Fehlversuch (Review R7): ' . $fehlversuche());

// Feste Tokens gelten weiter, im Header und in der Adresse
pruefe(str_contains($oauthAnfrage('POST', '', 'Bearer ' . $token)['body'], 'Parse error'), 'mit Connect: Token im Header kommt durch');
pruefe(str_contains($oauthAnfrage('POST', '/' . $token)['body'], 'Parse error'), 'mit Connect: Token in der Adresse kommt durch');
$a = $oauthAnfrage('POST', '', 'Bearer falsch-0123456789abcdefghij');
pruefe($a['status'] === 401 && str_contains($a['headers']['WWW-Authenticate'] ?? '', 'resource_metadata='), 'falsches Token: 401 mit Metadaten, damit Claude die Anmeldung neu startet');
try {
    IPS_ApplyChanges($iid);
} catch (Throwable) {
    // ohne die Property (roter Lauf) gibt es nichts zurückzusetzen
}
HomeAgentHarness::$connect = '';

/* --- OAuth Teil 2: Kopplungscode, Einwilligung, Autorisierungscode (build 74) -------------------- */

$claudeId = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
$callback = 'https://claude.ai/api/mcp/auth_callback';
HomeAgentHarness::$documents = [$claudeId => (string)file_get_contents(__DIR__ . '/fixtures/oauth_claude_cimd_2026-10-11.json')];
HomeAgentHarness::$fetched = [];
HomeAgentHarness::$connect = 'https://example.ipmagic.de';
$basis = 'https://example.ipmagic.de/hook/homeagent/' . $iid;
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', $token, 'Halle', true), $zugang('Andere', 'andere-token-0123456789abcdef')]));
try {
} catch (Throwable) {
    pruefe(false, 'die Property OAuthEnabled fehlt');
}
IPS_ApplyChanges($iid);
pruefe($status() === IS_ACTIVE, 'Ausgangslage OAuth Teil 2: aktiv, nicht ' . $status());

// Kopplungscode je Zugang; Anfrage wie im Mitschnitt, code_challenge aus RFC 7636 Anhang B
$kopplung = static function (string $name) use ($m): string {
    try {
        return $m->CreatePairingCode($name);
    } catch (Throwable) {
        return '';
    }
};
$text = $kopplung('Claude-App');
$code = preg_match('/\b(\d{6})\b/', $text, $treffer) === 1 ? $treffer[1] : '';
pruefe($code !== '' && str_contains($text, 'Claude-App'), 'CreatePairingCode nennt Zugang und sechsstelligen Code: ' . $text);
pruefe(preg_match('/\b\d{6}\b/', $kopplung('Gibt es nicht')) !== 1, 'für einen unbekannten Zugang gibt es keinen Code: ' . $kopplung('Gibt es nicht'));
pruefe(!str_contains($logText(), $code ?: 'kein Code'), 'der Kopplungscode steht nicht im Log');

$abfrage = [
    'response_type' => 'code', 'client_id' => $claudeId, 'redirect_uri' => $callback,
    'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256',
    'state' => str_repeat('s', 43), 'scope' => 'mcp offline_access', 'resource' => $basis, 'prompt' => 'consent',
];
$autorisieren = static function (string $methode, array $abfrage, array $formular = []) use ($hook, $m, $iid): array {
    $_SERVER = ['REQUEST_METHOD' => $methode, 'REQUEST_URI' => '/hook/homeagent/' . $iid . '/oauth/authorize' . ($methode === 'GET' ? '?' . http_build_query($abfrage) : ''), 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '79.250.0.1'];
    $_GET = $methode === 'GET' ? $abfrage : [];
    HomeAgentHarness::$body = $methode === 'POST' ? http_build_query($abfrage + $formular) : '';
    $m->response = null;
    ob_start();
    try {
        $hook->invoke($m);
    } finally {
        ob_end_clean();
        HomeAgentHarness::$body = '';
        $_GET = [];
    }
    return $m->response ?? ['status' => 0, 'headers' => [], 'body' => ''];
};

// GET zeigt die Einwilligung und verbraucht nichts (der Service Worker ruft die Seite ein zweites Mal)
$a = $autorisieren('GET', $abfrage);
pruefe($a['status'] === 200 && str_starts_with($a['headers']['Content-Type'] ?? '', 'text/html'), 'GET authorize: Einwilligungsseite als HTML: ' . $a['status'] . ' ' . substr($a['body'], 0, 120));
pruefe(str_contains($a['body'], 'Claude') && str_contains($a['body'], 'claude.ai') && str_contains($a['body'], 'action="' . $basis . '/oauth/authorize"'), 'die Seite nennt Client und Host der Rücksprungadresse');
pruefe(($a['headers']['X-Frame-Options'] ?? '') === 'DENY' && str_contains($a['headers']['Content-Security-Policy'] ?? '', "frame-ancestors 'none'"), 'die Seite lässt sich nicht einbetten');
pruefe(HomeAgentHarness::$fetched === [$claudeId], 'das Client-Dokument wurde von seiner Adresse geholt: ' . json_encode(HomeAgentHarness::$fetched));
$a = $autorisieren('GET', $abfrage);
pruefe($a['status'] === 200 && count(HomeAgentHarness::$fetched) === 1, 'zweites GET: wieder die Seite, das Dokument kommt aus dem Zwischenspeicher');
pruefe($fehlversuche() === '', 'ein GET zählt nicht als Fehlversuch');

// Fehler vor der Prüfung der Rücksprungadresse: Fehlerseite, keine Weiterleitung
$a = $autorisieren('GET', ['redirect_uri' => 'https://evil.example/cb'] + $abfrage);
pruefe($a['status'] === 400 && !isset($a['headers']['Location']), 'fremde Rücksprungadresse: 400 ohne Weiterleitung');
$a = $autorisieren('GET', ['client_id' => 'https://unbekannt.example/client'] + $abfrage);
pruefe($a['status'] === 400 && !isset($a['headers']['Location']), 'Client-Dokument nicht lesbar: 400 ohne Weiterleitung');
// Code-Review 12.10.2026 (Befund 1): oauth/authorize ist ohne Anmeldung erreichbar, und gethostbynamel() hatte
// kein Zeitlimit. Ein nicht lesbares Dokument wird kurz gemerkt, damit Wiederholungen nicht jedes Mal warten.
$a = $autorisieren('GET', ['client_id' => 'https://unbekannt.example/client'] + $abfrage);
pruefe(count(array_keys(HomeAgentHarness::$fetched, 'https://unbekannt.example/client', true)) === 1, 'ein nicht lesbares Client-Dokument wird nicht gleich noch einmal geholt: ' . json_encode(HomeAgentHarness::$fetched));
try {
    $optionen = (new ReflectionMethod(HomeAgent::class, 'clientDocumentOptions'))->invoke(null);
} catch (Throwable $e) {
    $optionen = [];
}
$vorher = $optionen[CURLOPT_PREREQFUNCTION] ?? null;
pruefe(is_callable($vorher) && $vorher(null, '10.0.0.1', '10.0.0.2', 443, 50000) === CURL_PREREQFUNC_ABORT && $vorher(null, '160.79.106.181', '10.0.0.2', 443, 50000) === CURL_PREREQFUNC_OK, 'die Adresse wird nach dem Verbindungsaufbau geprüft, bevor die Anfrage hinausgeht: privat abbrechen, öffentlich weiter');
pruefe(($optionen[CURLOPT_CONNECTTIMEOUT] ?? 0) === 5 && !isset($optionen[CURLOPT_RESOLVE]) && ($optionen[CURLOPT_PROTOCOLS_STR] ?? '') === 'https', 'curl löst selbst auf, mit Zeitlimit 5 s, nur HTTPS: ' . json_encode(array_keys($optionen)));
$quelle = (string)file_get_contents(dirname(__DIR__) . '/HomeAgent/module.php');
pruefe(!str_contains($quelle, 'gethostbynamel'), 'keine Namensauflösung ohne Zeitlimit mehr');
$a = $autorisieren('GET', ['client_id' => 'https://192.168.178.86/client'] + $abfrage);
pruefe($a['status'] === 400 && !in_array('https://192.168.178.86/client', HomeAgentHarness::$fetched, true), 'eine Adresse im eigenen Netz wird gar nicht erst abgerufen');
$a = $autorisieren('GET', ['code_challenge_method' => 'plain'] + $abfrage);
pruefe($a['status'] === 302 && str_starts_with($a['headers']['Location'] ?? '', $callback . '?error=invalid_request'), 'Fehler nach geprüfter Rücksprungadresse gehen zurück an den Client: ' . ($a['headers']['Location'] ?? '-'));

// POST: falscher Code, Ablehnen, richtiger Code
$a = $autorisieren('POST', $abfrage, ['pairing_code' => $code === '000000' ? '000001' : '000000', 'decision' => 'allow']);
pruefe($a['status'] === 200 && !isset($a['headers']['Location']) && str_contains($a['body'], 'pairing_code'), 'falscher Kopplungscode: Seite wieder mit Hinweis: ' . $a['status']);
pruefe($fehlversuche() !== '', 'ein falscher Kopplungscode zählt als Fehlversuch');
$a = $autorisieren('POST', $abfrage, ['pairing_code' => '', 'decision' => 'deny']);
pruefe($a['status'] === 302 && ($a['headers']['Location'] ?? '') === $callback . '?error=access_denied&state=' . str_repeat('s', 43) . '&iss=' . rawurlencode($basis), 'Ablehnen: access_denied mit state und iss: ' . ($a['headers']['Location'] ?? '-'));
$a = $autorisieren('POST', $abfrage, ['pairing_code' => $code, 'decision' => 'allow']);
$ziel = (string)($a['headers']['Location'] ?? '');
parse_str((string)parse_url($ziel, PHP_URL_QUERY), $antwort);
pruefe($a['status'] === 302 && str_starts_with($ziel, $callback . '?code=') && ($antwort['state'] ?? '') === str_repeat('s', 43) && ($antwort['iss'] ?? '') === $basis, 'richtiger Code: Weiterleitung mit code, state und iss: ' . $ziel);
$autCode = (string)($antwort['code'] ?? '');
pruefe(strlen($autCode) >= 43, 'der Autorisierungscode hat mindestens 256 Bit: ' . strlen($autCode));
$puffer = (string)(new ReflectionMethod($m, 'GetBuffer'))->invoke($m, 'OAuthCodes');
pruefe($puffer !== '' && !str_contains($puffer, $autCode) && !str_contains($puffer, $code), 'Codes nur als Hash im Buffer');
pruefe(!str_contains($logText(), $autCode) && !str_contains($logText(), $code), 'kein Code im Log');
$a = $autorisieren('POST', $abfrage, ['pairing_code' => $code, 'decision' => 'allow']);
pruefe($a['status'] === 200 && !isset($a['headers']['Location']), 'derselbe Kopplungscode gilt nur einmal');

try {
    IPS_ApplyChanges($iid);
} catch (Throwable) {
}
HomeAgentHarness::$connect = '';

/* --- OAuth Teil 3: Token-Endpunkt (build 75) ------------------------------------------------------ */

// Form der Token-Anfrage angenommen nach Claude-Doku und RFC 6749 (noch kein Mitschnitt).
HomeAgentHarness::$connect = 'https://example.ipmagic.de';
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', $token, 'Halle', true), $zugang('Andere', 'andere-token-0123456789abcdef')]));
try {
} catch (Throwable) {
}
IPS_ApplyChanges($iid);
$tokenAnfrage = static function (array $formular, string $methode = 'POST') use ($hook, $m, $iid): array {
    $_SERVER = ['REQUEST_METHOD' => $methode, 'REQUEST_URI' => '/hook/homeagent/' . $iid . '/oauth/token', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '160.79.106.181', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
    HomeAgentHarness::$body = http_build_query($formular);
    $m->response = null;
    ob_start();
    try {
        $hook->invoke($m);
    } finally {
        ob_end_clean();
        HomeAgentHarness::$body = '';
    }
    $antwort = $m->response ?? ['status' => 0, 'headers' => [], 'body' => ''];
    return $antwort + ['json' => json_decode($antwort['body'], true) ?? []];
};
$neuerCode = static function () use ($kopplung, $autorisieren, $abfrage): string {
    $text = $kopplung('Claude-App');
    $kopplungscode = preg_match('/\b(\d{6})\b/', $text, $t) === 1 ? $t[1] : '';
    $a = $autorisieren('POST', $abfrage, ['pairing_code' => $kopplungscode, 'decision' => 'allow']);
    parse_str((string)parse_url((string)($a['headers']['Location'] ?? ''), PHP_URL_QUERY), $q);
    return (string)($q['code'] ?? '');
};
$autCode = $neuerCode();
pruefe($autCode !== '', 'Ausgangslage Token: ein Autorisierungscode aus Teil 2');
$einloesen = ['grant_type' => 'authorization_code', 'code' => $autCode, 'redirect_uri' => $callback, 'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'client_id' => $claudeId, 'resource' => $basis];

$a = $tokenAnfrage($einloesen);
pruefe($a['status'] === 200 && ($a['json']['token_type'] ?? '') === 'Bearer' && ($a['json']['expires_in'] ?? 0) === 3600 && strlen((string)($a['json']['access_token'] ?? '')) >= 43 && strlen((string)($a['json']['refresh_token'] ?? '')) >= 43, 'Code eingelöst: Zugriffs- und Erneuerungstoken: ' . $a['status'] . ' ' . $a['body']);
pruefe(($a['headers']['Cache-Control'] ?? '') === 'no-store' && ($a['headers']['Content-Type'] ?? '') === 'application/json', 'Token-Antwort nicht zwischenspeichern');
$zugriff1 = (string)($a['json']['access_token'] ?? '');
$erneuern1 = (string)($a['json']['refresh_token'] ?? '');
try {
    $gespeichert = (string)(new ReflectionMethod($m, 'ReadAttributeString'))->invoke($m, 'OAuthGrants') . (string)(new ReflectionMethod($m, 'GetBuffer'))->invoke($m, 'OAuthTokens');
} catch (Throwable) {
    $gespeichert = ''; // ohne das Attribut (roter Lauf)
}
pruefe($gespeichert !== '' && $zugriff1 !== '' && !str_contains($gespeichert, $zugriff1) && !str_contains($gespeichert, $erneuern1), 'Tokens nur als Hash im Attribut und Buffer');
pruefe(!str_contains($logText(), $zugriff1 ?: 'x') && !str_contains($logText(), $erneuern1 ?: 'x'), 'kein Token im Log');

$a = $tokenAnfrage($einloesen);
pruefe($a['status'] === 400 && ($a['json']['error'] ?? '') === 'invalid_grant', 'derselbe Code ein zweites Mal: invalid_grant');
$a = $tokenAnfrage(['grant_type' => 'password', 'client_id' => $claudeId]);
pruefe($a['status'] === 400 && ($a['json']['error'] ?? '') === 'unsupported_grant_type', 'andere grant_type: unsupported_grant_type');
$a = $tokenAnfrage([], 'GET');
pruefe($a['status'] === 405, 'Token-Endpunkt nur per POST');

// Erneuern mit Rotation, Wiederverwendung widerruft die Familie
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => $erneuern1, 'client_id' => $claudeId]);
$erneuern2 = (string)($a['json']['refresh_token'] ?? '');
pruefe($a['status'] === 200 && $erneuern2 !== '' && $erneuern2 !== $erneuern1 && ($a['json']['access_token'] ?? '') !== $zugriff1, 'Erneuern liefert neue Tokens: ' . $a['status'] . ' ' . ($a['json']['error'] ?? ''));
// Die Antwort mit $erneuern2 ging verloren (betreute Anlage 16.10.2026): Das vorige gilt noch, solange die
// Erneuerung unbenutzt ist. Das nie zugestellte $erneuern2 ist danach verbraucht und widerruft die Familie.
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => $erneuern1, 'client_id' => $claudeId]);
$erneuern3 = (string)($a['json']['refresh_token'] ?? '');
pruefe($a['status'] === 200 && $erneuern3 !== '' && $erneuern3 !== $erneuern2, 'voriges Erneuerungstoken vor Benutzung der Erneuerung: neues Paar: ' . $a['status'] . ' ' . ($a['json']['error'] ?? ''));
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => $erneuern2, 'client_id' => $claudeId]);
pruefe($a['status'] === 400 && ($a['json']['error'] ?? '') === 'invalid_grant', 'das nie zugestellte Erneuerungstoken: invalid_grant');
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => $erneuern3, 'client_id' => $claudeId]);
pruefe($a['status'] === 400 && ($a['json']['error'] ?? '') === 'invalid_grant', 'nach der Wiederverwendung gilt auch das neue nicht mehr');

// Ein geändertes Token des Zugangs trennt dessen OAuth-Clients
$autCode = $neuerCode();
$a = $tokenAnfrage(['code' => $autCode] + $einloesen);
$erneuern3 = (string)($a['json']['refresh_token'] ?? '');
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', 'neues-token-0123456789abcdef', 'Halle', true)]));
IPS_ApplyChanges($iid);
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => $erneuern3, 'client_id' => $claudeId]);
pruefe($erneuern3 !== '' && $a['status'] === 400 && ($a['json']['error'] ?? '') === 'invalid_grant', 'Zugang mit neuem Token: Erneuern scheitert mit invalid_grant');
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => 'erfunden-0123456789', 'client_id' => $claudeId]);
pruefe($fehlversuche() !== '', 'ungültige Erneuerungen zählen als Fehlversuch');

try {
    IPS_ApplyChanges($iid);
} catch (Throwable) {
}
HomeAgentHarness::$connect = '';

/* --- OAuth Teil 4: Anmeldung mit dem Zugriffstoken (build 76) ------------------------------------- */

HomeAgentHarness::$connect = 'https://example.ipmagic.de';
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', $token, 'Halle', true), $zugang('Andere', 'andere-token-0123456789abcdef')]));
try {
} catch (Throwable) {
}
IPS_ApplyChanges($iid);
$mcp = static function (string $auth, string $methode, array $params = []) use ($hook, $m, $iid): array {
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $iid, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '160.79.106.181'];
    if ($auth !== '') {
        $_SERVER['HTTP_AUTHORIZATION'] = $auth;
    }
    HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $methode, 'params' => (object)$params]);
    $m->response = null;
    ob_start();
    try {
        $hook->invoke($m);
    } finally {
        ob_end_clean();
        HomeAgentHarness::$body = '';
    }
    $antwort = $m->response ?? ['status' => 0, 'headers' => [], 'body' => ''];
    return $antwort + ['json' => json_decode($antwort['body'], true) ?? []];
};
$koppeln = static function (string $scope = 'mcp offline_access') use ($neuerCode, $tokenAnfrage, $einloesen): array {
    $code = $neuerCode();
    return $tokenAnfrage(['code' => $code] + $einloesen)['json'];
};
$tokens = $koppeln();
$zugriff = (string)($tokens['access_token'] ?? '');
pruefe($zugriff !== '', 'Ausgangslage: Zugriffstoken über Kopplung, Code und Token-Endpunkt');

// Der OAuth-Client bekommt genau die Werkzeuge und Antworten des gekoppelten Zugangs
$perOAuth = $mcp('Bearer ' . $zugriff, 'tools/list');
$perToken = $mcp('Bearer ' . $token, 'tools/list');
pruefe($perOAuth['status'] === 200 && ($perOAuth['json']['result'] ?? null) === ($perToken['json']['result'] ?? false), 'tools/list mit dem Zugriffstoken gleicht dem des Zugangs (nur abfragen, Standardraum): ' . $perOAuth['status'] . ' ' . substr($perOAuth['body'], 0, 120));
$init = $mcp('Bearer ' . $zugriff, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object)[], 'clientInfo' => ['name' => 'claude-ai', 'version' => '0.1.0']]);
pruefe(str_contains((string)($init['json']['result']['instructions'] ?? ''), 'only read'), 'die Anleitung sagt dem Assistenten, dass der Zugang nur abfragen kann: ' . substr((string)($init['json']['result']['instructions'] ?? ''), 0, 160));
$aufruf = $mcp('Bearer ' . $zugriff, 'tools/call', ['name' => 'get_state', 'arguments' => (object)[]]);
pruefe(str_contains((string)($aufruf['json']['result']['content'][0]['text'] ?? ''), 'room=Halle'), 'ohne Raum gilt der Standardraum des Zugangs: ' . substr((string)($aufruf['json']['result']['content'][0]['text'] ?? ''), 0, 120));
pruefe(!str_contains($logText(), $zugriff), 'das Zugriffstoken steht nicht im Log');

// Ungültig, abgelaufen, widerrufen, Zugang geändert: 401 mit invalid_token und Metadaten
$basis = 'https://example.ipmagic.de/hook/homeagent/' . $iid;
$erwartet = 'Bearer resource_metadata="' . $basis . '/.well-known/oauth-protected-resource", scope="mcp", error="invalid_token"';
$a = $mcp('Bearer erfundenes-zugriffstoken-0123456789', 'tools/list');
pruefe($a['status'] === 401 && ($a['headers']['WWW-Authenticate'] ?? '') === $erwartet, 'unbekanntes Token: 401 mit invalid_token: ' . json_encode($a['headers']['WWW-Authenticate'] ?? null));
$a = $mcp('', 'tools/list');
pruefe($a['status'] === 401 && !str_contains($a['headers']['WWW-Authenticate'] ?? '', 'invalid_token'), 'ohne Anmeldung: 401 ohne invalid_token');
$puffer = (new ReflectionMethod($m, 'GetBuffer'));
$alt = (string)$puffer->invoke($m, 'OAuthTokens');
$abgelaufen = array_map(static fn (array $e): array => ['expires' => time() - 1] + $e, (array)json_decode($alt, true));
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'OAuthTokens', json_encode($abgelaufen));
$a = $mcp('Bearer ' . $zugriff, 'tools/list');
pruefe($a['status'] === 401 && ($a['headers']['WWW-Authenticate'] ?? '') === $erwartet, 'abgelaufenes Zugriffstoken: 401, Claude erneuert dann');
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'OAuthTokens', $alt);
pruefe($mcp('Bearer ' . $zugriff, 'tools/list')['status'] === 200, 'Gegenprobe: mit gültigem Ablauf wieder 200');
try {
    (new ReflectionMethod($m, 'WriteAttributeString'))->invoke($m, 'OAuthGrants', '{}');
} catch (Throwable) {
}
pruefe($mcp('Bearer ' . $zugriff, 'tools/list')['status'] === 401, 'widerrufene Kopplung: das Zugriffstoken gilt sofort nicht mehr');

$zugriff = (string)($koppeln()['access_token'] ?? '');
pruefe($mcp('Bearer ' . $zugriff, 'tools/list')['status'] === 200, 'neue Kopplung gilt');
HomeAgentHarness::$connect = '';
pruefe($mcp('Bearer ' . $zugriff, 'tools/list')['status'] === 401, 'ohne Connect: OAuth-Tokens gelten nicht');
HomeAgentHarness::$connect = 'https://example.ipmagic.de';
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', 'neues-token-0123456789abcdef', 'Halle', true)]));
IPS_ApplyChanges($iid);
pruefe($mcp('Bearer ' . $zugriff, 'tools/list')['status'] === 401, 'neues Token am Zugang: das Zugriffstoken gilt sofort nicht mehr');

try {
    IPS_ApplyChanges($iid);
} catch (Throwable) {
}
HomeAgentHarness::$connect = '';

/* --- Befunde aus /code-review vom 11.10.2026 (build 78) ------------------------------------------ */

HomeAgentHarness::$connect = 'https://example.ipmagic.de';
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');
IPS_SetProperty($iid, 'Rooms', json_encode([['Name' => 'Halle', 'Aliases' => '', 'Floor' => '']]));
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', $token, 'Halle', true)]));
IPS_ApplyChanges($iid);
pruefe($status() === IS_ACTIVE, 'Ausgangslage Review: aktiv, nicht ' . $status());

// R9: Ein Aufruf mit festem Token braucht die Connect-Adresse nicht
HomeAgentHarness::$connectCalls = 0;
$a = $mcp('Bearer ' . $token, 'tools/list');
pruefe($a['status'] === 200 && HomeAgentHarness::$connectCalls === 0, 'festes Token: keine Abfrage der Connect-Adresse, nicht ' . HomeAgentHarness::$connectCalls);

// R7: Was zum normalen Ablauf gehört, zählt nicht als Fehlversuch
$mcp('', 'tools/list');
pruefe($fehlversuche() === '', 'eine Anfrage ohne Anmeldung (Beginn der OAuth-Suche) zählt nicht');
$tokens = $koppeln();
$zugriff = (string)($tokens['access_token'] ?? '');
$alt = (string)(new ReflectionMethod($m, 'GetBuffer'))->invoke($m, 'OAuthTokens');
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'OAuthTokens', json_encode(array_map(static fn (array $e): array => ['expires' => time() - 1] + $e, (array)json_decode($alt, true))));
$a = $mcp('Bearer ' . $zugriff, 'tools/list');
pruefe($a['status'] === 401 && $fehlversuche() === '', 'ein abgelaufenes Zugriffstoken zählt nicht: ' . $fehlversuche());
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'OAuthTokens', $alt);
$mcp('Bearer erfundenes-token-0123456789abcdef', 'tools/list');
pruefe($fehlversuche() !== '', 'ein unbekanntes Token zählt weiter');
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'SignInFailures', '');

// R1: Erneuern, während der Zugang nicht benutzbar ist, kostet die Kopplung nicht
$erneuern = (string)($tokens['refresh_token'] ?? '');
// Ein Tippfehler im Standardraum sperrt den Zugang; ein zweiter hält den Endpunkt offen
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', $token, 'Hallle', true), $zugang('Andere', 'andere-token-0123456789abcdef')]));
IPS_ApplyChanges($iid);
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => $erneuern, 'client_id' => $claudeId]);
pruefe($a['status'] === 400 && ($a['json']['error'] ?? '') === 'invalid_grant', 'Zugang gesperrt: Erneuern scheitert: ' . $a['status']);
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', $token, 'Halle', true)]));
IPS_ApplyChanges($iid);
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => $erneuern, 'client_id' => $claudeId]);
pruefe($a['status'] === 200 && isset($a['json']['access_token']), 'Zugang wieder benutzbar: dasselbe Erneuerungstoken gilt noch: ' . $a['status'] . ' ' . ($a['json']['error'] ?? ''));

// R2: Ist die Sperre belegt, gibt es keine Tokens, die nie gespeichert wurden
$code = $neuerCode();
IPS_SemaphoreEnter('HAGT_OAuthGrants_' . $iid, 0);
$a = $tokenAnfrage(['code' => $code] + $einloesen);
IPS_SemaphoreLeave('HAGT_OAuthGrants_' . $iid);
pruefe($a['status'] === 503 && ($a['json']['error'] ?? '') === 'temporarily_unavailable' && !isset($a['json']['access_token']), 'Sperre belegt: 503 statt ungespeicherter Tokens: ' . $a['status'] . ' ' . $a['body']);
IPS_SemaphoreEnter('HAGT_OAuthPairings_' . $iid, 0);
$text = $kopplung('Claude-App');
IPS_SemaphoreLeave('HAGT_OAuthPairings_' . $iid);
pruefe(preg_match('/\b\d{6}\b/', $text) !== 1, 'Sperre belegt: kein Kopplungscode, der nie gespeichert wurde: ' . $text);

// R6: Der Körper der OAuth-Endpunkte ist begrenzt
$a = $tokenAnfrage(['grant_type' => 'refresh_token', 'refresh_token' => str_repeat('x', 20000), 'client_id' => $claudeId]);
pruefe($a['status'] === 413 && HomeAgentHarness::$bodyLimit > 0 && HomeAgentHarness::$bodyLimit <= 16384, 'Token-Endpunkt: übergroßer Körper 413, gelesen mit Grenze ' . HomeAgentHarness::$bodyLimit . ': ' . $a['status']);
$a = $autorisieren('POST', $abfrage, ['pairing_code' => str_repeat('1', 20000), 'decision' => 'allow']);
pruefe($a['status'] === 413 && HomeAgentHarness::$bodyLimit > 0 && HomeAgentHarness::$bodyLimit <= 16384, 'Autorisierung: übergroßer Körper 413: ' . $a['status']);

try {
    IPS_ApplyChanges($iid);
} catch (Throwable) {
}
HomeAgentHarness::$connect = '';

/* --- OAuth im Formular und im Selbsttest (build 79) ---------------------------------------------- */

HomeAgentHarness::$connect = '';
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', $token, 'Halle', true)]));
IPS_ApplyChanges($iid);
pruefe(str_contains($m->RunSelfTest(), 'Sign-in with OAuth for the Claude app: not available, Symcon Connect is not active'), 'Selbsttest ohne Connect: ' . $m->RunSelfTest());

HomeAgentHarness::$connect = 'https://example.ipmagic.de';
$basis = 'https://example.ipmagic.de/hook/homeagent/' . $iid;
try {
    (new ReflectionMethod($m, 'WriteAttributeString'))->invoke($m, 'OAuthGrants', '{}');
} catch (Throwable) {
}
$tokens = $koppeln();
$zugriff = (string)($tokens['access_token'] ?? '');
pruefe(str_contains($m->RunSelfTest(), 'Sign-in with OAuth: 1 assistant(s) paired'), 'Selbsttest nennt die gekoppelten Assistenten: ' . $m->RunSelfTest());

$formular = json_decode($m->GetConfigurationForm(), true);
$alle = flach(array_merge($formular['elements'], $formular['actions'] ?? []));
$element = static function (string $name) use (&$alle): ?array {
    foreach ($alle as $e) {
        if (($e['name'] ?? '') === $name) {
            return $e;
        }
    }
    return null;
};
$mitAufruf = static function (string $aufruf) use (&$alle): ?array {
    foreach ($alle as $e) {
        if (str_contains((string)($e['onClick'] ?? ''), $aufruf)) {
            return $e;
        }
    }
    return null;
};
pruefe($element('OAuthEnabled') === null, 'Formular: kein Schalter „Anmeldung per OAuth“ mehr');
pruefe(str_contains((string)($element('ClaudeStep1')['caption'] ?? ''), $basis) && ($element('ClaudeStep1')['visible'] ?? true) === true && ($element('ClaudeNoConnect')['visible'] ?? true) === false, 'Formular: Schritt 1 nennt die Adresse für die Claude-App: ' . ($element('ClaudeStep1')['caption'] ?? '-'));
$auswahl = $element('PairingAccess');
pruefe(($auswahl['type'] ?? '') === 'Select' && array_column($auswahl['options'] ?? [], 'value') === ['Claude-App'] && ($auswahl['value'] ?? '') === 'Claude-App', 'Formular: Auswahl des Zugangs direkt am Knopf, vorbelegt: ' . json_encode($auswahl));
pruefe($mitAufruf('HAGT_CreatePairingCode($id, $PairingAccess)') !== null, 'Formular: Knopf Kopplungscode für den gewählten Zugang');
pruefe($mitAufruf("HAGT_CreatePairingCode(\$id, \$Accesses['Name'])") === null, 'kein Knopf mehr, der eine ausgewählte Zeile voraussetzt');
$liste = $element('OAuthClients');
$zeile = $liste['values'][0] ?? [];
pruefe(($liste['type'] ?? '') === 'List' && ($liste['loadValuesFromConfiguration'] ?? true) === false && count($liste['values'] ?? []) === 1, 'Formular: Liste der gekoppelten Assistenten mit einer Zeile: ' . json_encode($liste['values'] ?? null));
// Burkhard 12.10.2026: wie in der Spalte „Verbindung“ heißt der Client so wie der Unterbereich
pruefe(($zeile['Client'] ?? '') === 'Claude app' && ($zeile['Access'] ?? '') === 'Claude-App' && ($zeile['Family'] ?? '') !== '', 'Zeile nennt Client mit Host und Zugang: ' . json_encode($zeile));
pruefe(preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', (string)($zeile['Paired'] ?? '')) === 1 && preg_match('/^\d{2}\.\d{2}\.\d{4}$/', (string)($zeile['Expires'] ?? '')) === 1, 'Zeile mit Datum der Kopplung und Ablauf: ' . json_encode($zeile));
$spalte = array_column($liste['columns'] ?? [], null, 'name')['Revoke'] ?? [];
pruefe(($spalte['onClick'] ?? '') === "HAGT_UIRevokeClient(\$id, \$OAuthClients['Family']);" && ($spalte['confirm'] ?? '') !== '' && ($zeile['Revoke'] ?? '') === '✖ revoke' && ($spalte['caption'] ?? '') === 'Action', 'Formular: „widerrufen“ als Spalte in der Zeile, mit Rückfrage: ' . json_encode($spalte));
pruefe($mitAufruf("HAGT_UIRevokeClient(\$id, \$OAuthClients['Family'])") === null, 'kein Knopf mehr, der eine ausgewählte Zeile voraussetzt');
pruefe(($liste['visible'] ?? true) === true && ($liste['rowCount'] ?? 0) === 1, 'die Liste ist sichtbar und so hoch wie ihre Einträge: ' . json_encode($liste['rowCount'] ?? null));
pruefe(!str_contains((string)json_encode($formular), $zugriff ?: 'kein Token'), 'kein Token im Formular');

// Widerrufen trennt sofort
pruefe($mcp('Bearer ' . $zugriff, 'tools/list')['status'] === 200, 'vor dem Widerruf gilt das Zugriffstoken');
$m->formUpdates = [];
try {
    $m->UIRevokeClient((string)($zeile['Family'] ?? ''));
} catch (Throwable $e) {
    pruefe(false, 'UIRevokeClient fehlt: ' . $e->getMessage());
}
pruefe($mcp('Bearer ' . $zugriff, 'tools/list')['status'] === 401, 'nach dem Widerruf gilt das Zugriffstoken sofort nicht mehr');
$aktualisiert = array_values(array_filter($m->formUpdates, static fn (array $u): bool => $u[0] === 'OAuthClients' && $u[1] === 'values'));
pruefe($aktualisiert !== [] && json_decode((string)end($aktualisiert)[2], true) === [], 'die Liste im offenen Formular wird leer: ' . json_encode($m->formUpdates));
pruefe(str_contains($m->RunSelfTest(), 'Sign-in with OAuth: 0 assistant(s) paired'), 'Selbsttest nach dem Widerruf: ' . $m->RunSelfTest());

HomeAgentHarness::$connect = '';
$formular = json_decode($m->GetConfigurationForm(), true);
$alle = flach(array_merge($formular['elements'], $formular['actions'] ?? []));
pruefe(($element('ClaudeStep1')['visible'] ?? true) === false && ($element('PairingRow')['visible'] ?? true) === false && ($element('ClaudeStep2')['visible'] ?? true) === false, 'ohne Connect: keine Schritte für die Claude-App');
pruefe(($element('ClaudeNoConnect')['visible'] ?? false) === true && ($element('ClaudeConnectDown')['visible'] ?? true) === false, 'ohne Connect: der Hinweis, dass die Claude-App Connect braucht');
// Burkhard 12.10.2026: Connect war kurz getrennt (Log 10:10 bis 10:11), das Formular sagte „braucht ein aktives
// Symcon Connect“. Ist Connect eingerichtet, aber gerade getrennt, sagt es das.
HomeAgentHarness::$connectConfigured = true;
$formular = json_decode($m->GetConfigurationForm(), true);
$alle = flach(array_merge($formular['elements'], $formular['actions'] ?? []));
pruefe(($element('ClaudeConnectDown')['visible'] ?? false) === true && ($element('ClaudeNoConnect')['visible'] ?? true) === false, 'Connect eingerichtet, aber getrennt: eigener Hinweis');
pruefe(str_contains($m->RunSelfTest(), 'Sign-in with OAuth for the Claude app: not available right now, Symcon Connect is set up but not connected'), 'Selbsttest bei getrenntem Connect: ' . $m->RunSelfTest());
HomeAgentHarness::$connectConfigured = false;

/* --- Einsteigerfreundlicher Bereich „Endpunkt und Zugänge“ (build 80) ----------------------------- */

// Burkhard 12.10.2026 zum Formular: „für einsteiger wirkt es kompliziert“. Die Zugänge zeigen in Worten,
// wie sie verbunden sind; darunter steht je Assistent, was zu tun ist, mit echten Adressen.
HomeAgentHarness::$connect = 'https://example.ipmagic.de';
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude-App', $token, 'Halle', true), $zugang('Home Assistant', 'ha-token-0123456789abcdefgh', '', false, true), $zugang('Claude Code', 'cc-token-0123456789abcdefgh')]));
IPS_ApplyChanges($iid);
try {
    (new ReflectionMethod($m, 'WriteAttributeString'))->invoke($m, 'OAuthGrants', '{}');
} catch (Throwable) {
}
$koppeln();
$formular = json_decode($m->GetConfigurationForm(), true);
$alle = flach(array_merge($formular['elements'], $formular['actions'] ?? []));
$verbindung = array_column($element('Accesses')['values'] ?? [], 'Connection');
// Burkhard 12.10.2026: „angemeldet: Claude-App“ statt „claude.ai“, dann weiß man, wo man suchen muss
pruefe($verbindung === ['signed in: Claude app', 'address with token', 'token in the header'], 'Spalte Verbindung je Zugang in Worten, mit dem Namen des Unterbereichs: ' . json_encode($verbindung));
$bezeichnung = static function (array $familie) use ($m): ?string {
    try {
        return (new ReflectionMethod($m, 'clientLabel'))->invoke($m, $familie);
    } catch (Throwable) {
        return null;
    }
};
pruefe($bezeichnung(['client_id' => 'https://claude.ai/oauth/claude-code-client-metadata', 'client_name' => 'Claude Code']) === 'Claude Code', 'Claude Code meldet sich auch von claude.ai und heißt Claude Code');
pruefe($bezeichnung(['client_id' => 'https://example.org/client.json', 'client_name' => 'Mein Client']) === 'Mein Client (example.org)', 'ein unbekannter Client mit seinem Namen und Host');
pruefe(!str_contains((string)json_encode($formular), $token) && !str_contains((string)json_encode($formular), 'ha-token-0123456789abcdefgh'), 'kein Token im Formular');
$endpunkt = (string)($element('Endpoint')['caption'] ?? '');
pruefe(str_contains($endpunkt, 'http://192.168.178.86:3777/hook/homeagent/' . $iid) && !str_contains($endpunkt, '<address>'), 'Endpunkt im Heimnetz mit der echten IP: ' . $endpunkt);
$adressAuswahl = $element('AddressAccess');
pruefe(array_column($adressAuswahl['options'] ?? [], 'value') === ['Home Assistant'], 'Adresse mit Token nur für Zugänge mit „Token in der Adresse“: ' . json_encode($adressAuswahl['options'] ?? null));
// Burkhard 12.10.2026: Die Adresse als Ausgabe des Knopfs öffnete die Konsole im Browser, das Token passte nicht in
// das kleine Meldungsfenster. Beides steht jetzt in einem eigenen Fenster in einem breiten Textfeld zum Kopieren.
$fenster = $element('SecretPopup');
$feld = array_column(flach($fenster['popup']['items'] ?? []), null, 'name');
pruefe(($fenster['type'] ?? '') === 'PopupAlert' && ($fenster['visible'] ?? true) === false && ($feld['SecretValue']['type'] ?? '') === 'ValidationTextBox' && isset($feld['SecretCaption']), 'eigenes Fenster mit Textfeld zum Kopieren, anfangs zu: ' . json_encode($fenster));
pruefe($mitAufruf('HAGT_UIShowAddress($id, $AddressAccess);') !== null && $mitAufruf('echo HAGT_UIShowAddress($id, $AddressAccess);') === null, 'Knopf „Adresse anzeigen“ neben der Auswahl, ohne Ausgabe');
$gezeigt = static function (callable $aufruf) use ($m): array {
    $m->formUpdates = [];
    oderNull($aufruf);
    $werte = [];
    foreach ($m->formUpdates as [$name, $parameter, $wert]) {
        $werte[$name . '.' . $parameter] = $wert;
    }
    return $werte;
};
$w = $gezeigt(static fn () => $m->UIShowAddress('Home Assistant'));
pruefe(($w['SecretValue.value'] ?? '') === 'http://192.168.178.86:3777/hook/homeagent/' . $iid . '/ha-token-0123456789abcdefgh' && ($w['SecretValue.visible'] ?? null) === true && ($w['SecretPopup.visible'] ?? null) === true && str_contains((string)($w['SecretCaption.caption'] ?? ''), 'Home Assistant'), 'Adresse mit Token im Fenster zum Kopieren: ' . json_encode($w));
$w = $gezeigt(static fn () => $m->UIShowAddress('Claude Code'));
pruefe(!str_contains((string)json_encode($w), 'cc-token') && ($w['SecretValue.visible'] ?? null) === false && str_contains((string)($w['SecretCaption.caption'] ?? ''), 'Token also in the address'), 'ohne „Token in der Adresse“ keine Adresse mit Token, sondern ein Hinweis im Fenster: ' . json_encode($w));
$tokenAuswahl = $element('TokenAccess');
pruefe(array_column($tokenAuswahl['options'] ?? [], 'value') === ['Claude-App', 'Home Assistant', 'Claude Code'], 'Token anzeigen: Auswahl aller benutzbaren Zugänge: ' . json_encode($tokenAuswahl['options'] ?? null));
pruefe($mitAufruf('HAGT_UIShowToken($id, $TokenAccess);') !== null && $mitAufruf('echo HAGT_UIShowToken($id, $TokenAccess);') === null, 'Knopf „Token anzeigen“ neben der Auswahl, ohne Ausgabe');
$w = $gezeigt(static fn () => $m->UIShowToken('Claude Code'));
pruefe(($w['SecretValue.value'] ?? '') === 'cc-token-0123456789abcdefgh' && ($w['SecretPopup.visible'] ?? null) === true && str_contains((string)($w['SecretCaption.caption'] ?? ''), 'Claude Code'), 'das Token im Fenster zum Kopieren: ' . json_encode($w));
$w = $gezeigt(static fn () => $m->UIShowToken('Gibt es nicht'));
pruefe(!str_contains((string)json_encode($w), 'token-') && ($w['SecretValue.visible'] ?? null) === false, 'unbekannter Zugang: kein Token: ' . json_encode($w));
HomeAgentHarness::$lanIp = '';
$formular = json_decode($m->GetConfigurationForm(), true);
$alle = flach(array_merge($formular['elements'], $formular['actions'] ?? []));
pruefe(str_contains((string)($element('Endpoint')['caption'] ?? ''), 'http://<address>:3777/hook/homeagent/' . $iid), 'ohne bekannte IP bleibt der Platzhalter: ' . ($element('Endpoint')['caption'] ?? '-'));
HomeAgentHarness::$lanIp = '192.168.178.86';
HomeAgentHarness::$connect = '';

/* --- Pausenmerker unter Sperre (Code-Review 12.10.2026, Befund 3) -------------------------------- */

// Alle anderen Buffer, die gleichzeitige Anfragen ändern, liegen unter einer Semaphore; der Merker der pausierten
// Mediaplayer nicht. Zwei gleichzeitige Pausen konnten so einen Merker verlieren. Im Kernel-Stub ist eine belegte
// Semaphore sofort „nicht frei“: Dann darf storePaused nichts schreiben.
$merken = static function (array $variablen, int $um) use ($m): void {
    try {
        (new ReflectionMethod($m, 'storePaused'))->invoke($m, $variablen, $um);
    } catch (Throwable) {
    }
};
$pausiert = static fn (): string => (string)(new ReflectionMethod($m, 'GetBuffer'))->invoke($m, 'Paused');
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'Paused', '');
IPS_SemaphoreEnter('HAGT_Paused_' . $iid, 0);
$merken([12345], 1_000_000);
pruefe($pausiert() === '', 'belegte Sperre: der Pausenmerker wird nicht ohne Sperre geschrieben: ' . $pausiert());
IPS_SemaphoreLeave('HAGT_Paused_' . $iid);
$merken([12345], 1_000_000);
pruefe(json_decode($pausiert(), true) === ['12345' => 1_000_000], 'freie Sperre: der Merker steht im Buffer: ' . $pausiert());
pruefe(IPS_SemaphoreEnter('HAGT_Paused_' . $iid, 0), 'die Sperre ist danach wieder frei');
IPS_SemaphoreLeave('HAGT_Paused_' . $iid);
(new ReflectionMethod($m, 'SetBuffer'))->invoke($m, 'Paused', '');

/* --- Token beim Übernehmen per Skript (Blindtest 12.10.2026) ------------------------------------ */

// Echt (Testbox 9.1 Rust, 12.10.2026, per Skript und direkt per JSON-RPC): Ruft ApplyChanges sich selbst über
// IPS_ApplyChanges auf, lehnt der Kernel das ab („Instanz #… ist durch eine selbst gestartete Operation belegt
// (re-entranter Aufruf abgelehnt)“), das äußere IPS_ApplyChanges liefert false, die Instanz bleibt auf 104.
// Angenommen: Ein einmaliger Timer läuft im Kernel erst nach dem laufenden ApplyChanges; die Probe stellt ihn
// deshalb zurück und führt ihn danach aus.
class HomeAgentRustProbe extends HomeAgent
{
    public static int $tiefe = 0;
    public static int $abgelehnt = 0;
    public static array $timer = [];

    protected function RegisterHook(string $HookPath): bool
    {
        return true; // der Stub hat hier nur einen leeren Rumpf
    }

    public function ApplyChanges(): void
    {
        if (self::$tiefe > 0) {
            self::$abgelehnt++;
            return;
        }
        self::$tiefe++;
        try {
            parent::ApplyChanges();
        } finally {
            self::$tiefe--;
        }
    }

    protected function RegisterOnceTimer(string $Ident, string $ScriptText): bool
    {
        self::$timer[$Ident] = [$ScriptText, $this->InstanceID];
        return true;
    }
}
$probeModul = ['ModuleID' => HomeAgentHarness::MODULE_ID, 'ModuleName' => 'HomeAgent', 'ModuleType' => 3, 'Class' => HomeAgentRustProbe::class];
$probe = IPS\ObjectManager::registerObject(1);
IPS\InstanceManager::createInstance($probe, $probeModul);
// Jede Auswahl muss einen Wert aus ihren Einträgen tragen, sonst lehnt die Konsole das Übernehmen ab („Aktueller Wert
// "" ist nicht verfügbar“). Ein neuer Zugang hat bis zum Übernehmen kein Token und ist nicht benutzbar; ohne
// Übernehmen bekommt er nie eins (loerdy im Forum, 13.10.2026).
$auswahlOhneWert = static function (int $instanz): array {
    $formular = json_decode(IPS_GetConfigurationForm($instanz), true);
    $fehler = [];
    foreach (flach(array_merge($formular['elements'], $formular['actions'] ?? [])) as $e) {
        if (($e['type'] ?? '') === 'Select' && !in_array($e['value'] ?? null, array_column($e['options'] ?? [], 'value'), true)) {
            $fehler[] = ($e['name'] ?? '?') . '=' . json_encode($e['value'] ?? null);
        }
    }
    return $fehler;
};
$leer = $auswahlOhneWert($probe);
pruefe($leer === [], 'ohne Zugang trägt jede Auswahl einen ihrer Einträge: ' . implode(', ', $leer));
IPS_SetProperty($probe, 'Accesses', json_encode([['Name' => 'A', 'Token' => '', 'DefaultRoom' => '', 'ReadOnly' => true, 'InPath' => false]]));
$leer = $auswahlOhneWert($probe);
pruefe($leer === [], 'mit einem neuen Zugang ohne Token trägt jede Auswahl einen ihrer Einträge: ' . implode(', ', $leer));
HomeAgentRustProbe::$abgelehnt = 0;
IPS_ApplyChanges($probe);
pruefe(HomeAgentRustProbe::$abgelehnt === 0, 'ApplyChanges ruft sich nicht selbst auf (Rust lehnt das ab): ' . HomeAgentRustProbe::$abgelehnt . ' abgelehnte Aufrufe');
foreach (HomeAgentRustProbe::$timer as [$skript, $ziel]) {
    IPS_RunScriptTextEx($skript, ['TARGET' => $ziel]);
}
HomeAgentRustProbe::$timer = [];
$probeZugang = json_decode(IPS_GetProperty($probe, 'Accesses'), true);
pruefe(preg_match('/^[0-9a-f]{64}$/', (string)($probeZugang[0]['Token'] ?? '')) === 1, 'der Zugang hat danach ein Token (64 Hex-Zeichen)');
pruefe(IPS_GetInstance($probe)['InstanceStatus'] === IS_ACTIVE, 'die Instanz ist danach aktiv, ohne zweites Übernehmen: ' . IPS_GetInstance($probe)['InstanceStatus']);
pruefe(HomeAgentRustProbe::$abgelehnt === 0, 'auch der nachgeholte Aufruf wird nicht abgelehnt: ' . HomeAgentRustProbe::$abgelehnt);
IPS_DeleteInstance($probe);

/* --- Adressen für Skripte und KI-Assistenten (Backlog B59, Blindtest 12.10.2026) ------------------- */

// Im Blindtest suchte der Agent die fertige Adresse; UIShowAddress und UIShowToken öffnen nur ein Fenster und
// liefern nichts. HAGT_GetAddresses nennt die Adressen, das Token nur als Platzhalter: Über den MCP-Server von
// Symcon käme es sonst beim Agenten an.
$merkeConnect = [HomeAgentHarness::$connect, HomeAgentHarness::$connectConfigured, HomeAgentHarness::$lanIp];
HomeAgentHarness::$lanIp = '192.168.178.86';
$setze('Accesses', [$zugang('Claude-App', str_repeat('a1', 32), '', false, true), $zugang('Nur Header', str_repeat('b2', 32), '', true, false)]);
$pfad = '/hook/homeagent/' . $iid;
$adressen = static fn (string $name): string => (string)oderNull(static fn () => $m->GetAddresses($name));

HomeAgentHarness::$connect = '';
HomeAgentHarness::$connectConfigured = false;
$a = $adressen('Claude-App');
pruefe(str_starts_with($a, 'Access "Claude-App": may query and switch, token also in the address'), 'erste Zeile nennt Zugang, Rechte und Anmeldung: ' . $a);
pruefe(str_contains($a, "\nHome network: http://192.168.178.86:3777" . $pfad . '/<token>' . "\n"), 'Adresse im Heimnetz mit Platzhalter: ' . $a);
pruefe(str_contains($a, "\nInternet: no address, Symcon Connect is not set up\n"), 'ohne Connect keine Adresse aus dem Internet: ' . $a);
pruefe(str_contains($a, "\nSign-in with OAuth: not possible without an active Symcon Connect\n"), 'ohne Connect kein OAuth: ' . $a);
pruefe(str_ends_with($a, "\n" . 'The token is shown in the form ("Show address", "Show token"); no script function returns it.'), 'Schlusszeile sagt, wo das Token steht: ' . $a);
pruefe(!str_contains($a, 'a1a1a1'), 'das Token steht nie in der Antwort');

HomeAgentHarness::$connectConfigured = true;
$a = $adressen('Claude-App');
pruefe(str_contains($a, "\nInternet: no address right now, Symcon Connect is set up but not connected\n"), 'Connect eingerichtet, aber getrennt: ' . $a);

HomeAgentHarness::$connect = 'https://beispiel.ipmagic.de';
$a = $adressen('Claude-App');
pruefe(str_contains($a, "\nInternet (Symcon Connect): https://beispiel.ipmagic.de" . $pfad . '/<token>' . "\n"), 'Adresse über Connect mit Platzhalter: ' . $a);
pruefe(str_contains($a, "\nSign-in with OAuth: possible with a pairing code for this access (HAGT_CreatePairingCode or the form), then the address without token\n"), 'mit Connect geht OAuth: ' . $a);

$a = $adressen('Nur Header');
pruefe(str_starts_with($a, 'Access "Nur Header": may only query, token in the header'), 'Nur-Lese-Zugang mit Token im Header: ' . $a);
pruefe(str_contains($a, "\nHome network: http://192.168.178.86:3777" . $pfad . "\n") && str_contains($a, "\nInternet (Symcon Connect): https://beispiel.ipmagic.de" . $pfad . "\n"), 'ohne Token in der Adresse kein Platzhalter im Pfad: ' . $a);
pruefe(str_contains($a, "\nSend the token as the header Authorization: Bearer <token>\n"), 'sagt, wie das Token mitgeht: ' . $a);
pruefe(!str_contains($a, 'b2b2b2'), 'auch hier kein Token');

$a = $adressen('');
pruefe(substr_count($a, 'Access "') === 2 && str_contains($a, "\n\nAccess \"Nur Header\"") && substr_count($a, 'no script function returns it') === 1, 'leerer Name: alle Zugänge, durch Leerzeilen getrennt, Schlusszeile einmal: ' . $a);
pruefe($adressen('Gibt es nicht') === 'There is no usable access named "Gibt es nicht"', 'unbekannter Zugang: ' . $adressen('Gibt es nicht'));
pruefe(($m->RunSelfTest() !== '') && !str_contains(json_encode($m->GetConfigurationForm()), 'a1a1a1a1'), 'das Formular zeigt die Tokens weiter nicht');
[HomeAgentHarness::$connect, HomeAgentHarness::$connectConfigured, HomeAgentHarness::$lanIp] = $merkeConnect;

/* --- Gliederung mit Unterbereichen und kleine Punkte (build 84) ---------------------------------- */

HomeAgentHarness::$connect = 'https://example.ipmagic.de';
$fuenf = [$zugang('Claude Code', 'cc-token-0123456789abcdefgh'), $zugang('Claude-App', $token, 'Halle', true), $zugang('A3', 'a3-token-0123456789abcdefgh'), $zugang('A4', 'a4-token-0123456789abcdefgh'), $zugang('A5', 'a5-token-0123456789abcdefgh')];
IPS_SetProperty($iid, 'Accesses', json_encode($fuenf));
IPS_ApplyChanges($iid);
$koppeln();
$formular = json_decode($m->GetConfigurationForm(), true);
$alle = flach(array_merge($formular['elements'], $formular['actions'] ?? []));
pruefe(($element('Accesses')['rowCount'] ?? 0) === 5, 'fünf Zugänge, fünf Zeilen ohne Scrollbalken: ' . json_encode($element('Accesses')['rowCount'] ?? null));
pruefe(($element('PairingAccess')['value'] ?? '') === 'Claude-App', 'Kopplungscode: vorausgewählt ist der Zugang, an dem schon ein Claude-Connector hängt: ' . json_encode($element('PairingAccess')['value'] ?? null));
IPS_SetProperty($iid, 'Accesses', json_encode(array_merge($fuenf, array_map(static fn (int $n): array => $zugang('B' . $n, 'b' . $n . '-token-0123456789abcdefgh'), range(1, 6)))));
IPS_ApplyChanges($iid);
$formular = json_decode($m->GetConfigurationForm(), true);
$alle = flach(array_merge($formular['elements'], $formular['actions'] ?? []));
// Burkhard 12.10.2026: ab mehr als fünf Einträgen ein Scrollbalken
pruefe(($element('Accesses')['rowCount'] ?? 0) === 5, 'bei elf Zugängen fünf Zeilen, der Rest mit Scrollbalken: ' . json_encode($element('Accesses')['rowCount'] ?? null));
IPS_SetProperty($iid, 'Accesses', json_encode([$zugang('Claude Code', 'cc-token-0123456789abcdefgh')]));
IPS_ApplyChanges($iid);
try {
    (new ReflectionMethod($m, 'WriteAttributeString'))->invoke($m, 'OAuthGrants', '{}');
} catch (Throwable) {
}
$formular = json_decode($m->GetConfigurationForm(), true);
$alle = flach(array_merge($formular['elements'], $formular['actions'] ?? []));
pruefe(($element('Accesses')['rowCount'] ?? 0) === 3, 'ein Zugang: mindestens drei Zeilen: ' . json_encode($element('Accesses')['rowCount'] ?? null));
pruefe(($element('OAuthClients')['visible'] ?? true) === false, 'ohne gekoppelte Assistenten keine leere Liste');
pruefe(!str_contains((string)json_encode($formular), '/<Token>'), 'der Text unter „Andere Assistenten“ ist kürzer, ohne /<Token>');
HomeAgentHarness::$connect = '';

/* --- Was nicht geklappt hat und Protokoll (Burkhard 12.10.2026) -------------------------------------- */

// Der Anwender soll sehen, wenn ein Assistent etwas nicht erreicht hat, etwa bei einem abweichenden Raum- oder
// Gerätenamen. Dazu ein Protokoll zum Einschalten, standardmäßig aus, zwei Dateien im Wechsel nach 24 Stunden
// oder n Zeilen, beide zusammen herunterzuladen. Tokens und Kopplungscodes stehen nie darin.
$p = neueInstanz();
$pid = (int)(new ReflectionProperty(IPSModuleStrict::class, 'InstanceID'))->getValue($p);
$pToken = str_repeat('c3', 32);
$kueche = variable(VARIABLETYPE_BOOLEAN, false);
IPS_SetProperty($pid, 'Devices', json_encode([['Room' => 'Küche', 'Name' => 'Deckenlicht', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $kueche, 'Invert' => false]]));
IPS_SetProperty($pid, 'Accesses', json_encode([['Name' => 'Claude-App', 'Token' => $pToken, 'DefaultRoom' => '', 'ReadOnly' => false, 'InPath' => true]]));
IPS_ApplyChanges($pid);
pruefe(IPS_GetInstance($pid)['InstanceStatus'] === IS_ACTIVE, 'Ausgangslage: aktiv');
$pHook = new ReflectionMethod(HomeAgent::class, 'ProcessHookData');
$ruf = static function (string $werkzeug, array $argumente, string $auth = '', string $pfad = '', string $absender = '192.0.2.5') use ($pHook, $p, $pid, $pToken): void {
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid . $pfad, 'REMOTE_ADDR' => $absender, 'HTTP_AUTHORIZATION' => $auth === '' ? 'Bearer ' . $pToken : $auth];
    if ($pfad !== '') {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $werkzeug, 'arguments' => (object)$argumente]]);
    ob_start();
    try {
        $pHook->invoke($p);
    } finally {
        ob_end_clean();
        HomeAgentHarness::$body = '';
    }
};
$pFormular = static fn (): array => json_decode($p->GetConfigurationForm(), true);
$pElement = static function (string $name) use ($pFormular): array {
    foreach (flach($pFormular()['elements']) as $e) {
        if (($e['name'] ?? '') === $name) {
            return $e;
        }
    }
    return [];
};
$zeilen = static fn (): array => $pElement('Problems')['values'] ?? [];

pruefe($pElement('PanelTrouble') !== [] && ($pElement('PanelTrouble')['caption'] ?? '') === 'What did not work', 'Bereich „Was nicht geklappt hat“, ohne Einträge ohne Zahl: ' . json_encode($pElement('PanelTrouble')['caption'] ?? null));
pruefe($zeilen() === [], 'anfangs ist die Liste leer');

$ruf('get_state', ['room' => 'Badezimmer']);
$ruf('get_state', ['room' => 'Küche']);
$ruf('set_state', ['name' => 'Stehlampe', 'command' => 'on']);
$z = $zeilen();
pruefe(count($z) === 2, 'zwei Einträge, die erfolgreiche Abfrage fehlt: ' . json_encode($z, JSON_UNESCAPED_UNICODE));
pruefe(($z[0]['Request'] ?? '') === 'set_state name=Stehlampe command=on' && ($z[1]['Request'] ?? '') === 'get_state room=Badezimmer', 'neueste zuerst, Anfrage kompakt: ' . json_encode(array_column($z, 'Request'), JSON_UNESCAPED_UNICODE));
pruefe(($z[1]['Access'] ?? '') === 'Claude-App' && preg_match('/^\d\d\.\d\d\. \d\d:\d\d:\d\d$/', (string)($z[1]['Time'] ?? '')) === 1, 'Zugang und Zeit: ' . json_encode($z[1] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(str_starts_with((string)($z[1]['Result'] ?? ''), 'No released device matches room=Badezimmer.'), 'Ergebnis ist die erste Zeile der Antwort: ' . ($z[1]['Result'] ?? ''));
pruefe(($z[1]['Hint'] ?? '') === 'Room unknown: enter the word as an alias of a room in the room list.', 'Hinweis zum Raum: ' . ($z[1]['Hint'] ?? ''));
pruefe(($z[0]['Hint'] ?? '') === 'Name unknown: enter the word as an alias of the device. A faulty entry is disabled and is not found either.', 'Hinweis zum Namen: ' . ($z[0]['Hint'] ?? ''));
pruefe(($pElement('PanelTrouble')['caption'] ?? '') === 'What did not work (2 in the last 24 hours)', 'die Überschrift zählt die letzten 24 Stunden: ' . ($pElement('PanelTrouble')['caption'] ?? ''));

// Skriptfunktionen haben keinen Assistenten, sie kommen nicht in die Liste
$p->SwitchDevices('Badezimmer', '', '', true);
pruefe(count($zeilen()) === 2, 'Skriptfunktionen stehen nicht in der Liste: ' . count($zeilen()));

// Abgewiesene Anmeldungen desselben Absenders werden zusammengefasst
for ($i = 0; $i < 3; $i++) {
    $ruf('get_state', [], 'Bearer falsch-' . $i, '', '192.0.2.7');
}
$z = $zeilen();
pruefe(count($z) === 3 && ($z[0]['Request'] ?? '') === 'Sign-in from 192.0.2.7' && ($z[0]['Result'] ?? '') === 'Token missing or wrong (3×)' && ($z[0]['Access'] ?? '') === '–', 'drei Fehlversuche, ein Eintrag: ' . json_encode($z[0] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(($z[0]['Hint'] ?? '') === 'Sign-in rejected: check the token or the address in the client.', 'Hinweis zur Anmeldung');

// Höchstens 50 Einträge
for ($i = 0; $i < 55; $i++) {
    $ruf('get_state', ['name' => 'Gibtsnicht' . $i]);
}
pruefe(count($zeilen()) === 50 && ($zeilen()[0]['Request'] ?? '') === 'get_state name=Gibtsnicht54', 'höchstens 50, die neuesten bleiben: ' . count($zeilen()));
oderNull(static fn () => $p->UIClearProblems());
pruefe($zeilen() === [] && ($pElement('PanelTrouble')['caption'] ?? '') === 'What did not work', 'Liste leeren');

// Protokoll: standardmäßig aus
$logAktuell = IPS_GetLogDir() . DIRECTORY_SEPARATOR . 'homeagent_' . $pid . '.log';
$logAlt = IPS_GetLogDir() . DIRECTORY_SEPARATOR . 'homeagent_' . $pid . '.old.log';
@unlink($logAktuell);
@unlink($logAlt);
// Vor dem Umbau gibt es die Properties nicht; der Stub wirft dann eine Exception.
$eigenschaft = static function (string $name, mixed $wert = null) use ($pid): mixed {
    try {
        if ($wert !== null) {
            IPS_SetProperty($pid, $name, $wert);
            return null;
        }
        return IPS_GetProperty($pid, $name);
    } catch (Throwable) {
        return 'FEHLT';
    }
};
pruefe($eigenschaft('LogEnabled') === false && $eigenschaft('LogLines') === 5000, 'Protokoll aus, 5000 Zeilen je Datei: ' . json_encode([$eigenschaft('LogEnabled'), $eigenschaft('LogLines')]));
$ruf('get_state', ['room' => 'Küche']);
pruefe(!file_exists($logAktuell), 'ohne Schalter keine Datei');

$eigenschaft('LogEnabled', true);
$eigenschaft('LogLines', 10);
IPS_ApplyChanges($pid);
$ruf('get_state', ['room' => 'Küche']);
$inhalt = (string)@file_get_contents($logAktuell);
pruefe(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3} \| Claude-App \| get_state \| room=Küche \| \d+ ms \| 1 device\(s\) found \(room=Küche\)\. ‖ Küche \| Deckenlicht \| light \| off/u', $inhalt) === 1, 'eine Zeile je Werkzeugaufruf: ' . $inhalt);
$ruf('get_state', ['room' => 'Küche'], '', '/' . $pToken);
$ruf('get_state', [], 'Bearer falsch-x', '', '192.0.2.8');
$p->SwitchDevices('Badezimmer', '', '', true);
$inhalt = (string)@file_get_contents($logAktuell);
pruefe(!str_contains($inhalt, $pToken) && !str_contains($inhalt, 'falsch-x'), 'weder das Token aus der Adresse noch ein falsches Token stehen im Protokoll');
pruefe(str_contains($inhalt, ' | – | sign-in rejected | from 192.0.2.8 | token missing or wrong'), 'abgewiesene Anmeldung mit Absender: ' . $inhalt);
pruefe(str_contains($inhalt, ' | script | SwitchDevices | room=Badezimmer on=true | '), 'Skriptfunktionen mit „script“: ' . $inhalt);
// Anfragen ohne Argumente (initialize) ohne leeres Feld (Burkhard 12.10.2026: Kleinigkeit)
$_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.5', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pToken];
HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object)[], 'clientInfo' => ['name' => 'Test', 'version' => '1']]]);
ob_start();
$pHook->invoke($p);
ob_end_clean();
HomeAgentHarness::$body = '';
$inhalt = (string)@file_get_contents($logAktuell);
pruefe(str_contains($inhalt, ' | Claude-App | initialize | Test 1 | HTTP 200') && !str_contains($inhalt, '|  |'), 'ohne Argumente kein leeres Feld, mit Name und Version des Clients: ' . $inhalt);
// Origin ins Protokoll (MCP verlangt eine Prüfung; zuerst sehen, was echte Clients schicken, 12.10.2026)
$_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.5', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pToken, 'HTTP_ORIGIN' => 'https://beispiel.example'];
HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
ob_start();
$pHook->invoke($p);
ob_end_clean();
HomeAgentHarness::$body = '';
$inhalt = (string)@file_get_contents($logAktuell);
// Seit der Messung (12.10.2026) steht nur noch die abgewiesene Origin im Protokoll, nicht jede (Review 14.10.2026: die
// Zeile kam vor der Anmeldung und ließ sich beliebig oft auslösen)
pruefe(str_contains($inhalt, ' | – | origin rejected | https://beispiel.example | HTTP 403') && !str_contains($inhalt, ' | – | origin | '), 'eine fremde Origin steht als abgewiesen im Protokoll, ohne eigene Zeile davor: ' . $inhalt);
// Für die Zählungen des Rundlaufs unten: eine Zeile für die weggefallene Origin-Zeile
$ruf('get_state', ['room' => 'Küche']);

// Rundlauf nach Zeilen: bei 10 Zeilen wird gewechselt
for ($i = 0; $i < 5; $i++) {
    $ruf('get_state', ['room' => 'Küche']);
}
$alt = file_exists($logAlt) ? file($logAlt, FILE_IGNORE_NEW_LINES) : [];
$neu = file_exists($logAktuell) ? file($logAktuell, FILE_IGNORE_NEW_LINES) : [];
pruefe(count($alt) === 10 && count($neu) === 2, 'nach 12 Zeilen: 10 in der alten, 2 in der neuen Datei: ' . count($alt) . ' / ' . count($neu));

// Rundlauf nach Zeit: die aktuelle Datei begann vor mehr als 24 Stunden
(new ReflectionMethod($p, 'SetBuffer'))->invoke($p, 'LogState', json_encode(['started' => time() - 90000, 'lines' => 2]));
$ruf('get_state', ['room' => 'Küche']);
$alt = file_exists($logAlt) ? file($logAlt, FILE_IGNORE_NEW_LINES) : [];
$neu = file_exists($logAktuell) ? file($logAktuell, FILE_IGNORE_NEW_LINES) : [];
pruefe(count($alt) === 2 && count($neu) === 1, 'nach 24 Stunden gewechselt, auch mit wenigen Zeilen: ' . count($alt) . ' / ' . count($neu));

// Herunterladen: beide Dateien, die alte zuerst, als Data-URL (Doku Button, Parameter download)
$daten = (string)oderNull(static fn () => $p->UIDownloadLog());
$praefix = 'data:text/plain;charset=utf-8;base64,';
$text = str_starts_with($daten, $praefix) ? (string)base64_decode(substr($daten, strlen($praefix))) : '';
pruefe($text === implode("\n", $alt) . "\n" . implode("\n", $neu) . "\n", 'Download enthält beide Dateien, die alte zuerst: ' . substr($daten, 0, 60));
$knoepfe = [];
foreach (flach($pFormular()['elements']) as $e) {
    if (($e['type'] ?? '') === 'Button') {
        $knoepfe[$e['caption'] ?? ''] = $e;
    }
}
pruefe(($knoepfe['Download protocol']['download'] ?? '') === 'homeagent.log' && ($knoepfe['Download protocol']['onClick'] ?? '') === 'echo HAGT_UIDownloadLog($id);', 'Knopf zum Herunterladen: ' . json_encode($knoepfe['Download protocol'] ?? null));
pruefe(isset($knoepfe['Show protocol'], $knoepfe['Clear protocol'], $knoepfe['Clear list']) && ($knoepfe['Clear protocol']['confirm'] ?? '') !== '', 'Knöpfe Anzeigen, Leeren (mit Rückfrage), Liste leeren: ' . implode(', ', array_keys($knoepfe)));

// Anzeigen: die letzten Zeilen, neueste zuerst, im Fenster
$p->formUpdates = [];
oderNull(static fn () => $p->UIShowLog());
$werte = [];
$sichtbar = false;
foreach ($p->formUpdates as [$feld, $parameter, $wert]) {
    if ($feld === 'LogView' && $parameter === 'values') {
        $werte = json_decode((string)$wert, true);
    }
    if ($feld === 'LogPopup' && $parameter === 'visible') {
        $sichtbar = $wert === true;
    }
}
pruefe($sichtbar && count($werte) === 3 && ($werte[0]['Line'] ?? '') === end($neu), 'Fenster mit den Zeilen, neueste zuerst: ' . json_encode(array_slice($werte, 0, 1), JSON_UNESCAPED_UNICODE));

// Leeren: beide Dateien weg
oderNull(static fn () => $p->UIClearLog());
pruefe(!file_exists($logAktuell) && !file_exists($logAlt), 'Protokoll leeren löscht beide Dateien');
pruefe(oderNull(static fn () => $p->UIDownloadLog()) === 'The protocol is empty.', 'leeres Protokoll: Hinweis statt Download');

// Große Protokolldateien (Review 14.10.2026): Nach einem Neustart las writeLog die Datei mit file() ganz ein, Anzeige und
// Download beide Dateien ganz. Gemessen: file() braucht rund das Doppelte der Datei, der Download rund das 3,8-Fache beider;
// bei 30000 vollen Zeilen sprengte das die 128 MB am nuc, der Download mit 5000 Zeilen schon die 64 MB der SymBox.
// Angenommen ist der Inhalt: 20 MB gleichartige Zeilen, wie sie LogLines = 100000 erlaubt hätte.
$grosseDatei = static function (string $datei, int $megabyte): void {
    $zeile = date('Y-m-d H:i:s') . '.000 | Claude Code | get_state | room=Küche | ' . str_repeat('x', 900) . "\n";
    $h = fopen($datei, 'wb');
    for ($i = 0, $n = (int)($megabyte * 1048576 / strlen($zeile)); $i < $n; $i++) {
        fwrite($h, $zeile);
    }
    fclose($h);
};
$grosseDatei($logAktuell, 20);
(new ReflectionMethod($p, 'SetBuffer'))->invoke($p, 'LogState', '');
$vorher = memory_get_usage();
memory_reset_peak_usage();
$ruf('get_state', ['room' => 'Küche']);
$spitze = memory_get_peak_usage() - $vorher;
pruefe($spitze < 8 * 1048576, 'nach einem Neustart liest writeLog eine Datei von 20 MB nicht ganz ein: ' . round($spitze / 1048576, 1) . ' MB');
pruefe(file_exists($logAlt) && filesize($logAlt) > 19 * 1048576 && filesize($logAktuell) < 1048576, 'gewechselt wie bisher, die große Datei ist jetzt die alte: ' . round(filesize($logAlt) / 1048576) . ' MB');
$vorher = memory_get_usage();
memory_reset_peak_usage();
$daten = (string)oderNull(static fn () => $p->UIDownloadLog());
$spitze = memory_get_peak_usage() - $vorher;
$text = str_starts_with($daten, $praefix) ? (string)base64_decode(substr($daten, strlen($praefix))) : '';
pruefe($spitze < 30 * 1048576 && strlen($text) <= 3 * 1048576 + filesize($logAktuell) && str_ends_with($text, (string)file_get_contents($logAktuell)) && str_starts_with($text, date('Y')), 'Download: vom alten Teil nur die letzten 3 MB, ganze Zeilen, der neue ganz: ' . round($spitze / 1048576, 1) . ' MB Spitze, ' . round(strlen($text) / 1048576, 1) . ' MB Text');
unset($daten, $text);
$vorher = memory_get_usage();
memory_reset_peak_usage();
oderNull(static fn () => $p->UIShowLog());
$spitze = memory_get_peak_usage() - $vorher;
pruefe($spitze < 20 * 1048576, 'Anzeigen liest nicht beide Dateien ganz: ' . round($spitze / 1048576, 1) . ' MB');
oderNull(static fn () => $p->UIClearLog());

// Zeilen ohne Anmeldung (Review 14.10.2026): Origin, OAuth-Pfade und abgewiesene Anmeldungen schrieb das Modul vor der
// Anmeldung bei jeder Anfrage. Wer die Adresse kennt, verdrängte so die echten Zeilen aus beiden Dateien. Jetzt je Absender
// höchstens zehn Zeilen in zehn Minuten und ein Hinweis, Abrufe der OAuth-Metadaten gar nicht.
$eigenschaft('LogLines', 1000);
IPS_ApplyChanges($pid);
$anfrage = static function (array $server) use ($pHook, $p, $pid): void {
    $_SERVER = $server + ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.9'];
    HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    ob_start();
    try {
        $pHook->invoke($p);
    } finally {
        ob_end_clean();
        HomeAgentHarness::$body = '';
    }
};
for ($i = 0; $i < 30; $i++) {
    $anfrage(['HTTP_ORIGIN' => 'https://boese.example']);
    $anfrage(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hook/homeagent/' . $pid . '/.well-known/oauth-authorization-server', 'REMOTE_ADDR' => '192.0.2.10']);
}
$ruf('get_state', ['room' => 'Küche']);
$inhalt = (string)@file_get_contents($logAktuell);
pruefe(substr_count($inhalt, ' | origin rejected | ') === 10 && substr_count($inhalt, 'further lines from 192.0.2.9 suppressed') === 1, 'dreißig fremde Origins eines Absenders: zehn Zeilen und ein Hinweis: ' . substr_count($inhalt, ' | origin rejected | ') . ' / ' . substr_count($inhalt, 'suppressed'));
pruefe(!str_contains($inhalt, '.well-known'), 'Abrufe der OAuth-Metadaten stehen nicht im Protokoll');
pruefe(str_contains($inhalt, ' | Claude-App | get_state | room=Küche'), 'ein angemeldeter Aufruf danach steht weiter drin');
oderNull(static fn () => $p->UIClearLog());
(new ReflectionMethod($p, 'SetBuffer'))->invoke($p, 'LogQuiet', '');
$eigenschaft('LogLines', 10);
IPS_ApplyChanges($pid);
// Nach 2026-07-28 trägt jede Anfrage den Client; die Zeile nennt ihn mit Name und Version (Burkhard 12.10.2026)
$_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.5', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pToken,
    'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28', 'HTTP_MCP_METHOD' => 'tools/call', 'HTTP_MCP_NAME' => 'get_state'];
HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_state', 'arguments' => ['room' => 'Küche'],
    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => ['name' => 'Anthropic/ClaudeAI', 'version' => '1.0.0'], 'io.modelcontextprotocol/clientCapabilities' => (object)[]]]]);
ob_start();
try {
    $pHook->invoke($p);
} finally {
    ob_end_clean();
    HomeAgentHarness::$body = '';
}
$inhalt = (string)@file_get_contents($logAktuell);
pruefe(str_contains($inhalt, ' | Claude-App | get_state | Anthropic/ClaudeAI 1.0.0 | room=Küche | '), 'der Werkzeugaufruf nennt den Client: ' . $inhalt);
// Ein Werkzeugaufruf, den schon das Protokoll abweist (unbekanntes Werkzeug, Kopfzeilen passen nicht), stand nirgends.
// Anlass 13.10.2026: Die Karten meldeten „Tool call failed“, im Protokoll war kein Aufruf zu sehen. Die Zeile nennt den
// Client mit Fähigkeiten.
$_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.5', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pToken,
    'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28', 'HTTP_MCP_METHOD' => 'tools/call', 'HTTP_MCP_NAME' => 'switch_all'];
HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'switch_all', 'arguments' => ['device' => '1', 'command' => 'on'],
    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => ['name' => 'claude-ai', 'version' => '0.1.0'], 'io.modelcontextprotocol/clientCapabilities' => (object)[]]]]);
ob_start();
try {
    $pHook->invoke($p);
} finally {
    ob_end_clean();
    HomeAgentHarness::$body = '';
}
$inhalt = (string)@file_get_contents($logAktuell);
pruefe(preg_match('/ \| Claude-App \| tools\/call \| claude-ai 0\.1\.0 \| HTTP 200 \| -32602 Unknown tool: switch_all/u', $inhalt) === 1, 'abgewiesener Werkzeugaufruf mit Client und Fehler im Protokoll: ' . $inhalt);
$_SERVER['HTTP_MCP_NAME'] = 'get_state';
HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'control_device', 'arguments' => (object)[],
    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => ['name' => 'claude-ai', 'version' => '0.1.0']]]]);
ob_start();
try {
    $pHook->invoke($p);
} finally {
    ob_end_clean();
    HomeAgentHarness::$body = '';
}
$inhalt = (string)@file_get_contents($logAktuell);
pruefe(preg_match('/ \| Claude-App \| tools\/call \| claude-ai 0\.1\.0 \| HTTP 400 \| -32020 Header mismatch/u', $inhalt) === 1, 'Kopfzeile passt nicht: HTTP 400 im Protokoll: ' . $inhalt);
// Gemessen am nuc 13.10.2026 12:59: claude.ai schickt den Aufruf von der Karte ohne Client und ohne Fähigkeiten
// („tools/call | ? | -32602 Unknown tool: control_device“); um 10:29 kam er noch als „claude-ai 0.1.0“. Die App-Werkzeuge
// stehen weiter nur in der Liste für Clients mit Apps, aufrufen lassen sie sich aber immer (die Rechte des Zugangs gelten).
$_SERVER['HTTP_MCP_NAME'] = 'control_device';
HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'control_device', 'arguments' => ['device' => '1', 'command' => 'on'],
    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]]);
ob_start();
try {
    $pHook->invoke($p);
    $ausgabe = (string)ob_get_contents();
} finally {
    ob_end_clean();
    HomeAgentHarness::$body = '';
}
$inhalt = (string)@file_get_contents($logAktuell);
pruefe(!str_contains($ausgabe, 'Unknown tool') && preg_match('/ \| Claude-App \| control_device \| device=1 command=on \| /u', $inhalt) === 1, 'Aufruf von der Karte ohne Client und Fähigkeiten erreicht control_device: ' . substr($ausgabe, 0, 200) . ' / ' . substr($inhalt, -300));
// Werkzeugliste je Zugang (Burkhard 12.10.2026): holt der Assistent die Liste, merkt sich das Modul ihren Fingerabdruck;
// ändert sich danach die Freigabeliste so, dass sich die Werkzeuge ändern, zeigt die Spalte „veraltet“.
$listeHolen = static function () use ($pHook, $p, $pid, $pToken): void {
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.5', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pToken];
    HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
    ob_start();
    try {
        $pHook->invoke($p);
    } finally {
        ob_end_clean();
        HomeAgentHarness::$body = '';
    }
};
$werkzeugStand = static fn (): string => (string)(($pElement('Accesses')['values'][0] ?? [])['Tools'] ?? '');
pruefe($werkzeugStand() === 'not fetched yet', 'noch nicht geholt: ' . $werkzeugStand());
$listeHolen();
pruefe(str_starts_with($werkzeugStand(), 'current, fetched '), 'nach tools/list aktuell: ' . $werkzeugStand());
$geraeteVorher = IPS_GetProperty($pid, 'Devices');
$rollo = variable(VARIABLETYPE_INTEGER, 0);
IPS_SetProperty($pid, 'Devices', json_encode(array_merge(json_decode($geraeteVorher, true), [['Room' => 'Küche', 'Name' => 'Rollladen', 'Aliases' => '', 'Type' => 'blind', 'StateVariableID' => 0, 'LevelVariableID' => $rollo, 'Invert' => false]])));
IPS_ApplyChanges($pid);
pruefe(str_starts_with($werkzeugStand(), 'outdated, fetched '), 'ein Rollladen bringt open und close: veraltet: ' . $werkzeugStand());
$listeHolen();
pruefe(str_starts_with($werkzeugStand(), 'current, fetched '), 'erneut geholt: wieder aktuell: ' . $werkzeugStand());
IPS_SetProperty($pid, 'Devices', $geraeteVorher);
IPS_ApplyChanges($pid);
$eigenschaft('LogEnabled', false);
IPS_ApplyChanges($pid);

/* --- Origin-Prüfung (MCP, Schutz vor DNS-Rebinding, 12.10.2026) ------------------------------------- */

// Gemessen am nuc (Protokoll 12.10.2026): Claude Code, Home Assistant und die Claude-App über claude.ai schicken keine
// Origin-Kopfzeile. Schickt ein Browser eine, muss sie auf diese Anlage zeigen (Host der Anfrage oder Connect-Adresse),
// sonst 403, bevor das Token geprüft wird; ein Fehlversuch ist das nicht.
$origin = static function (string $herkunft, string $host = '192.168.178.86:3777') use ($pHook, $p, $pid, $pToken): array {
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.5', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pToken, 'HTTP_HOST' => $host];
    if ($herkunft !== '') {
        $_SERVER['HTTP_ORIGIN'] = $herkunft;
    }
    HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $p->response = null;
    ob_start();
    $pHook->invoke($p);
    ob_end_clean();
    HomeAgentHarness::$body = '';
    return $p->response ?? ['status' => 0, 'body' => ''];
};
HomeAgentHarness::$connect = 'https://beispiel.ipmagic.de';
pruefe($origin('')['status'] === 200, 'ohne Origin wie bisher');
pruefe($origin('http://192.168.178.86:3777')['status'] === 200, 'Origin auf den Host der Anfrage geht durch');
pruefe($origin('https://beispiel.ipmagic.de')['status'] === 200, 'Origin auf die Connect-Adresse geht durch');
$a = $origin('https://boese.example');
pruefe($a['status'] === 403 && str_contains($a['body'], 'Origin'), 'fremde Origin: 403: ' . $a['status'] . ' ' . $a['body']);
pruefe($origin('http://192.168.178.86:3777.boese.example')['status'] === 403, 'ein Host, der nur so anfängt, zählt nicht');
pruefe($origin('null')['status'] === 403, 'Origin „null“ (Datei, Sandbox) zählt als fremd');
$merke = $p->logs();
$origin('https://boese.example');
pruefe(!str_contains(implode("\n", array_column(array_slice($p->logs(), count($merke)), 'Message')), 'Rejected sign-in'), 'eine fremde Origin ist kein Fehlversuch beim Anmelden');
// DNS-Rebinding (Review 14.10.2026): Die Seite des Angreifers lässt ihren Namen auf die Anlage zeigen, Host und Origin
// sind dann beide dieser Name. Verglichen wird deshalb mit den eigenen Namen der Anlage, nicht mit der Host-Kopfzeile.
pruefe($origin('http://boese.example:3777', 'boese.example:3777')['status'] === 403, 'Rebinding: Origin gleich Host, aber fremder Name: 403');
pruefe($origin('http://nuc:3777', 'nuc:3777')['status'] === 200 && $origin('http://nuc.fritz.box:3777', 'nuc.fritz.box:3777')['status'] === 200 && $origin('http://NUC.local:3777', 'nuc.local:3777')['status'] === 200, 'der Rechnername, auch mit .fritz.box und .local, geht durch');
pruefe($origin('http://nuc.boese.example:3777', 'nuc.boese.example:3777')['status'] === 403, 'der Rechnername vor einer fremden Domain zählt nicht');
pruefe($origin('http://localhost:3777', 'localhost:3777')['status'] === 200 && $origin('http://192.168.178.86:82', '192.168.178.86:82')['status'] === 200 && $origin('http://[::1]:3777', '[::1]:3777')['status'] === 200, 'localhost, ::1 und die eigene IP auch an einem anderen Port');
HomeAgentHarness::$connect = '';

// Formular (Review 14.10.2026): Die Spalte „Werkzeugliste“ baut je Zugang die Werkzeugliste, und jede prüfte die Listen
// erneut (über 30 volle Prüfungen bei vier Zugängen; am nuc 523 bis 542 ms je Öffnen). Jetzt einmal je Formular.
HomeAgentHarness::$problemChecks = 0;
$p->GetConfigurationForm();
pruefe(HomeAgentHarness::$problemChecks === 1, 'das Formular prüft die Listen einmal: ' . HomeAgentHarness::$problemChecks . '-mal');

/* --- Protokoll 2026-07-28 am Hook (Backlog B62) -------------------------------------------------- */

// Das Modul reicht die Kopfzeilen der Anfrage an die Protokollschicht weiter; Symcon legt sie als HTTP_* in $_SERVER.
$discover = fixture('claude_code_2026-10-12_mcp_2026-07-28.json')['anfragen'][0];
$_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.5', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pToken,
    'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28', 'HTTP_MCP_METHOD' => 'server/discover'];
HomeAgentHarness::$body = (string)json_encode($discover['koerper'], JSON_UNESCAPED_SLASHES);
$p->response = null;
ob_start();
$pHook->invoke($p);
ob_end_clean();
HomeAgentHarness::$body = '';
$antwort = json_decode((string)($p->response['body'] ?? ''), true);
pruefe(($p->response['status'] ?? 0) === 200 && ($antwort['result']['supportedVersions'][0] ?? null) === '2026-07-28', 'server/discover über den Hook: ' . ($p->response['status'] ?? 0) . ' ' . ($p->response['body'] ?? ''));
pruefe(str_contains((string)($antwort['result']['instructions'] ?? ''), 'smart home'), 'mit der Anleitung des Zugangs: ' . ($antwort['result']['instructions'] ?? ''));
unset($_SERVER['HTTP_MCP_METHOD']);
HomeAgentHarness::$body = (string)json_encode($discover['koerper'], JSON_UNESCAPED_SLASHES);
ob_start();
$pHook->invoke($p);
ob_end_clean();
HomeAgentHarness::$body = '';
pruefe(($p->response['status'] ?? 0) === 400 && str_contains((string)($p->response['body'] ?? ''), '-32020'), 'ohne Mcp-Method abgewiesen, die Kopfzeilen kommen also an: ' . ($p->response['body'] ?? ''));

/* --- show_chart nur für Clients mit MCP Apps (Backlog B63) ----------------------------------------- */

// Die Claude-App meldet „io.modelcontextprotocol/ui“ an (Protokoll am nuc 12.10.2026). Nur ihr bietet das Modul
// show_chart an, und nur, wenn ein freigegebenes Gerät einen Verlauf haben kann.
$kuecheTemp = variable(VARIABLETYPE_FLOAT, 21.5);
IPS_SetProperty($pid, 'Devices', json_encode([
    ['Room' => 'Küche', 'Name' => 'Deckenlicht', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $kueche, 'Invert' => false],
    ['Room' => 'Küche', 'Name' => 'Temperatur', 'Aliases' => '', 'Type' => 'temperature', 'StateVariableID' => $kuecheTemp, 'Invert' => false],
]));
IPS_ApplyChanges($pid);
$zugangP = ['Name' => 'Claude-App', 'Token' => $pToken, 'DefaultRoom' => '', 'ReadOnly' => true, 'InPath' => true];
$werkzeugNamen = static fn (bool $apps): array => array_column(oderNull(static fn () => \Closure::bind(fn () => $this->tools($zugangP, $apps), $p, HomeAgent::class)()) ?? [], null, 'name');
$mit = $werkzeugNamen(true);
// Die Adresse der Seite trägt einen Teil ihres SHA-256: claude.ai nahm am 13.10.2026 eine Seite aus dem Zwischenspeicher,
// die über eine halbe Stunde alt war (kein resources/read trotz ttlMs 300000). Eine geänderte Seite hat so eine neue Adresse.
$chartUri = 'ui://homeagent/chart/' . substr(hash_file('sha256', dirname(__DIR__) . '/HomeAgent/chart.html'), 0, 8);
$kartenUri = 'ui://homeagent/devices/' . substr(hash_file('sha256', dirname(__DIR__) . '/HomeAgent/devices.html'), 0, 8);
pruefe(isset($mit['show_chart']) && ($mit['show_chart']['_meta']['ui']['resourceUri'] ?? null) === $chartUri, 'mit Apps: show_chart mit Verweis auf die Seite des Diagramms samt Prüfsumme: ' . json_encode($mit['show_chart']['_meta'] ?? array_keys($mit)));
pruefe(($mit['show_chart']['annotations']['readOnlyHint'] ?? null) === true && !isset($mit['show_chart']['inputSchema']['properties']['active_only']), 'liest nur, ohne active_only: ' . json_encode($mit['show_chart']['inputSchema']['properties'] ?? null));
pruefe(!in_array('now', $mit['show_chart']['inputSchema']['properties']['period']['enum'] ?? ['now'], true), 'period ohne now');
// Kosten in Euro (B72, 13.10.2026): das Feld costs gibt es nur mit Strompreis in der Energiebilanz
pruefe(!isset($mit['show_chart']['inputSchema']['properties']['costs']), 'ohne Strompreis kein costs in show_chart');
pruefe(!isset($mit['show_chart']['inputSchema']['properties']['part']), 'ohne Bilanz kein part in show_chart');
$formular = (string)file_get_contents(dirname(__DIR__) . '/HomeAgent/form.json');
pruefe(str_contains($formular, '"name": "PriceVariableID"') && str_contains($formular, '"name": "FeedInTariff"') && str_contains($formular, 'PriceVariableID (electricity price'), 'Formular und Hinweistext nennen Strompreis und Einspeisevergütung');
// Summenkurve (B73, 13.10.2026)
pruefe(($mit['show_chart']['inputSchema']['properties']['cumulative']['type'] ?? '') === 'boolean' && str_contains($mit['show_chart']['description'] ?? '', 'cumulative=true'), 'show_chart bietet cumulative an');
// Heatmap (B71, 13.10.2026)
pruefe(($mit['show_chart']['inputSchema']['properties']['heatmap']['type'] ?? '') === 'boolean' && str_contains($mit['show_chart']['description'] ?? '', 'heatmap=true'), 'show_chart bietet heatmap an: ' . json_encode($mit['show_chart']['inputSchema']['properties']['heatmap'] ?? null));
// Punktwolke (B70, 13.10.2026)
pruefe(isset($mit['show_chart']['inputSchema']['properties']['versus']) && str_contains($mit['show_chart']['description'] ?? '', 'versus gives a scatter chart'), 'show_chart bietet versus für die Punktwolke an: ' . json_encode($mit['show_chart']['inputSchema']['properties']['versus'] ?? null));
// Vergleich zweier Zeiträume (B69, 13.10.2026)
pruefe(($mit['show_chart']['inputSchema']['properties']['compare']['enum'] ?? null) === ['previous', 'year_before'], 'show_chart bietet compare an: ' . json_encode($mit['show_chart']['inputSchema']['properties']['compare'] ?? null));
pruefe(str_contains($mit['show_chart']['inputSchema']['properties']['room']['description'] ?? '', 'several rooms separated by commas'), 'room nimmt mehrere Räume für einen Vergleich: ' . ($mit['show_chart']['inputSchema']['properties']['room']['description'] ?? ''));
pruefe(!isset($werkzeugNamen(false)['show_chart']), 'ohne Apps kein show_chart');
// Claude-App 12.10.2026: „Zeig mir den Hausverbrauch von gestern als Diagramm“ ergab get_state und eine selbst gezeichnete
// SVG-Datei statt show_chart. Mit Apps verweist get_state deshalb für Diagramme auf show_chart, ohne Apps nicht.
pruefe(str_contains($mit['get_state']['description'] ?? '', 'use show_chart') && !str_contains($werkzeugNamen(false)['get_state']['description'] ?? 'show_chart', 'show_chart'), 'get_state verweist für Diagramme auf show_chart: ' . ($mit['get_state']['description'] ?? ''));
// Claude-App 14.10.2026: „Zeig mir den Hausverbrauch vom letzten Monat als Heatmap“ ergab 32 Aufrufe von get_state, je Tag
// einer, und eine selbst gezeichnete Heatmap aus Tageswerten. Der Verweis nennt deshalb die Diagrammarten beim Namen.
pruefe(str_contains($mit['get_state']['description'] ?? '', 'heatmap, cumulative curve or scatter plot, use show_chart instead'), 'get_state nennt Heatmap, Summenkurve und Punktwolke beim Verweis auf show_chart');
pruefe(str_contains($mit['get_state']['description'] ?? '', 'one call instead of one per day'), 'get_state sagt, dass show_chart einen Aufruf statt einen je Tag braucht');
$nurLicht = json_encode([['Room' => 'Küche', 'Name' => 'Deckenlicht', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $kueche, 'Invert' => false]]);
IPS_SetProperty($pid, 'Devices', $nurLicht);
IPS_ApplyChanges($pid);
// Seit den Bändern (B69) hat auch ein Licht eine Zeitleiste fürs Diagramm
pruefe(str_contains($werkzeugNamen(true)['show_chart']['description'] ?? '', 'Devices with on/off, open/closed or a selection appear as bands showing when each state lasted.'), 'nur ein Licht: show_chart mit Bändern: ' . json_encode(array_keys($werkzeugNamen(true))));
IPS_SetProperty($pid, 'Devices', json_encode([['Room' => 'Küche', 'Name' => 'Rollladen', 'Aliases' => '', 'Type' => 'blind', 'LevelVariableID' => $kuecheTemp, 'Invert' => false]]));
IPS_ApplyChanges($pid);
pruefe(!isset($werkzeugNamen(true)['show_chart']), 'ohne Gerät mit Verlauf oder Zustand kein show_chart, auch mit Apps');
// Nur ein Gerät mit Zähler: show_chart mit type=energy für den Verbrauch je Gerät (Burkhard 12.10.2026)
$lichtZaehler = variable(VARIABLETYPE_FLOAT, 1234.5);
IPS_SetProperty($pid, 'Devices', json_encode([['Room' => 'Küche', 'Name' => 'Deckenlicht', 'Aliases' => '', 'Type' => 'light', 'StateVariableID' => $kueche, 'EnergyVariableID' => $lichtZaehler, 'Invert' => false]]));
IPS_ApplyChanges($pid);
$nurZaehler = $werkzeugNamen(true);
pruefe(($nurZaehler['show_chart']['inputSchema']['properties']['type']['enum'] ?? null) === ['energy'] && str_contains($nurZaehler['show_chart']['description'] ?? '', 'type=energy'), 'mit Zähler: show_chart mit type=energy: ' . json_encode($nurZaehler['show_chart'] ?? null, JSON_UNESCAPED_UNICODE));
IPS_SetProperty($pid, 'Devices', $nurLicht);
IPS_ApplyChanges($pid);
$seite = oderNull(static fn () => \Closure::bind(fn () => $this->readResource('ui://homeagent/chart'), $p, HomeAgent::class)());
pruefe(is_string($seite) && str_starts_with(ltrim($seite), '<!DOCTYPE html>') && str_contains($seite, 'ui/initialize') && str_contains($seite, 'ui/notifications/tool-result'), 'die Seite des Diagramms meldet sich beim Client an und nimmt das Ergebnis entgegen');
pruefe(is_string($seite) && str_contains($seite, 'labels.band') && str_contains($seite, 'type="checkbox"') === false && str_contains($seite, '"checkbox"'), 'die Seite hat einen Schalter für das Band mit der Beschriftung vom Modul');
pruefe(oderNull(static fn () => \Closure::bind(fn () => $this->readResource('ui://homeagent/anderes'), $p, HomeAgent::class)()) === null, 'andere Ressourcen gibt es nicht');
pruefe(oderNull(static fn () => \Closure::bind(fn () => $this->readResource($chartUri), $p, HomeAgent::class)()) === $seite, 'die Adresse mit Prüfsumme liefert die Seite');
pruefe(oderNull(static fn () => \Closure::bind(fn () => $this->readResource('ui://homeagent/chart/0123abcd'), $p, HomeAgent::class)()) === $seite, 'eine ältere Prüfsumme aus einer zwischengespeicherten Werkzeugliste liefert die heutige Seite');
pruefe(oderNull(static fn () => \Closure::bind(fn () => $this->readResource('ui://homeagent/chart/x'), $p, HomeAgent::class)()) === null, 'eine Adresse ohne gültige Prüfsumme gibt es nicht');

/* --- Gerätekarten (MCP App, Burkhard 12.10.2026) ------------------------------------------------- */

// Nur das Licht ist freigegeben. show_devices für jeden Zugang mit Apps, control_device nur zum Schalten und nur für die
// Seite (_meta.ui.visibility ["app"]: der Host zeigt es dem Modell nicht).
$zugangS = ['Name' => 'Claude-App schalten', 'Token' => $pToken, 'DefaultRoom' => '', 'ReadOnly' => false, 'InPath' => true];
$schalten = array_column(oderNull(static fn () => \Closure::bind(fn () => $this->tools($zugangS, true), $p, HomeAgent::class)()) ?? [], null, 'name');
pruefe(isset($schalten['show_devices']) && ($schalten['show_devices']['_meta']['ui']['resourceUri'] ?? null) === $kartenUri, 'show_devices mit der Seite der Karten samt Prüfsumme: ' . json_encode($schalten['show_devices']['_meta'] ?? array_keys($schalten)));
pruefe(isset($schalten['show_devices']['inputSchema']['properties']['room'], $schalten['show_devices']['inputSchema']['properties']['active_only']) && !isset($schalten['show_devices']['inputSchema']['properties']['period']), 'show_devices filtert wie get_state, ohne Zeitraum: ' . json_encode(array_keys($schalten['show_devices']['inputSchema']['properties'] ?? [])));
pruefe(($schalten['control_device']['_meta']['ui']['visibility'] ?? null) === ['app'] && ($schalten['control_device']['_meta']['ui']['resourceUri'] ?? null) === $kartenUri, 'control_device nur für die Seite: ' . json_encode($schalten['control_device']['_meta'] ?? null));
pruefe(($schalten['control_device']['inputSchema']['required'] ?? null) === ['device'] && in_array('on', $schalten['control_device']['inputSchema']['properties']['command']['enum'] ?? [], true), 'control_device braucht den Schlüssel der Karte: ' . json_encode($schalten['control_device']['inputSchema'] ?? null));
pruefe(($schalten['control_device']['inputSchema']['properties']['mode']['type'] ?? null) === 'string' && ($schalten['control_device']['inputSchema']['properties']['fan_speed']['type'] ?? null) === 'string', 'control_device nimmt mode und fan_speed als Name: ' . json_encode(array_keys($schalten['control_device']['inputSchema']['properties'] ?? [])));
$lesen = $werkzeugNamen(true);
// Jedes Feld, das die Karte schickt und controlDevice annimmt, steht im Schema (Review 14.10.2026: volume fehlte bei
// additionalProperties false; ein Host, der Aufrufe der App gegen das Schema prüft, hätte die Lautstärke abgewiesen).
$felder = array_keys($schalten['control_device']['inputSchema']['properties'] ?? []);
sort($felder);
// Seit 16.10.2026 mit color_temperature und color; maßgeblich ist DeviceIndex::CARD_FIELDS
$kartenFelder = defined(DeviceIndex::class . '::CARD_FIELDS') ? array_merge(DeviceIndex::CARD_FIELDS, ['device']) : [];
sort($kartenFelder);
pruefe($felder === $kartenFelder && in_array('color', $felder, true) &&($schalten['control_device']['inputSchema']['properties']['volume']['type'] ?? null) === 'number', 'control_device nennt alle Felder der Karte, auch volume: ' . json_encode($felder));
pruefe(isset($lesen['show_devices']) && !isset($lesen['control_device']), 'Nur-Lese-Zugang: Karten ja, Bedienen nein: ' . json_encode(array_keys($lesen)));
pruefe(!isset($werkzeugNamen(false)['show_devices']), 'ohne Apps keine Karten');
// Claude-App 12.10.2026: „Zeig mir die Lichter im Wohnzimmer“ ergab eine Tabelle über get_state statt der Karten. Mit Apps
// verweist get_state deshalb fürs Zeigen auf show_devices, und show_devices nennt „show me“ ausdrücklich.
pruefe(str_contains($schalten['get_state']['description'] ?? '', 'use show_devices') && !str_contains($werkzeugNamen(false)['get_state']['description'] ?? 'show_devices', 'show_devices'), 'mit Apps verweist get_state fürs Zeigen auf die Karten, ohne Apps nicht: ' . ($schalten['get_state']['description'] ?? ''));
pruefe(str_contains($schalten['show_devices']['description'] ?? '', '"show me'), 'show_devices nennt „show me“: ' . ($schalten['show_devices']['description'] ?? ''));
$karten = oderNull(static fn () => \Closure::bind(fn () => $this->readResource('ui://homeagent/devices'), $p, HomeAgent::class)());
pruefe(is_string($karten) && oderNull(static fn () => \Closure::bind(fn () => $this->readResource($kartenUri), $p, HomeAgent::class)()) === $karten, 'die Karten auch unter der Adresse mit Prüfsumme');
pruefe(is_string($karten) && str_contains($karten, 'ui/initialize') && str_contains($karten, '"control_device"') && str_contains($karten, 'ui/update-model-context'), 'die Seite der Karten bedient über control_device und sagt dem Modell, was geschah');
// Auswahl für Modus und Lüfterstufe auf den Karten (13.10.2026)
pruefe(is_string($karten) && str_contains($karten, 'c.options') && str_contains($karten, 'node("select"'), 'die Seite zeichnet eine Auswahl je Eintrag in controls.options');
pruefe(is_string($karten) && str_contains($karten, 'structuredContent.problem'), 'die Karte zeigt die Antwort nur bei einem Problem');
// Eine Antwort ersetzt nur ihre eigene Karte, die Sperre hängt am Schlüssel, eine bediente Karte wartet (Review
// 14.10.2026; das Verhalten selbst prüft ein Chrome-Prüfstand außerhalb der CI, siehe ENTWICKLUNG.md „Nachführen“)
pruefe(is_string($karten) && str_contains($karten, 'el.replaceWith(cardElement(') && str_contains($karten, 'busy[card.key]') && str_contains($karten, 'function inUse(') && preg_match('/function replace\(fresh\) \{(?:(?!\n  \}).)*\n    draw\(\);\n  \}/s', $karten) !== 1, 'eine Antwort zeichnet nur ihre Karte neu, gesperrt bleibt sie bis zur Antwort');
// Die Seiten der Apps laden nichts nach (CSP der Apps): kein Skript oder Stylesheet von außen, kein @import, keine Bilder
// über http(s), kein fetch oder XMLHttpRequest. Das ist die eigentliche Regel. Die Größe ist nur eine Bremse gegen eine
// versehentlich eingebettete Bibliothek (Burkhard 13.10.2026, Weg 1): Die Grenze wuchs vorher mit jeder Diagrammart
// (20, 30, 40, 50 KB) und hatte damit ihren Zweck verloren. Die Seiten gehen an die App, nicht ans Sprachmodell.
$nachladen = '/<script[^>]+src=|<link[^>]+href=["\']https?:|@import|url\(\s*["\']?https?:|\bfetch\s*\(|XMLHttpRequest/i';
foreach (['Karten' => $karten, 'Diagramme' => $seite] as $was => $html) {
    pruefe(is_string($html) && !preg_match($nachladen, $html), 'die Seite der ' . $was . ' lädt nichts nach');
    pruefe(strlen((string)$html) < 100000, 'die Seite der ' . $was . ' bettet keine Bibliothek ein (unter 100 KB): ' . strlen((string)$html) . ' Bytes');
}
// Screenshot der Claude-App 13.10.2026: Achse bis -0,5 bei fast keiner Einspeisung, ein grüner Punkt für
// „Einspeisung 0 kWh“, und rechts blieb ein Drittel frei
pruefe(is_string($seite) && str_contains($seite, 'if (-low < high * 0.03) { low = 0; }'), 'kaum Einspeisung: kein Bereich unter der Nulllinie');
pruefe(is_string($seite) && str_contains($seite, 'if (share >= 0.01) {'), 'Bögen unter 1 % fallen weg');
// Burkhard 14.10.2026 auf dem iPhone: „der verlauf der linien und des bandes könnte etwas weicher sein“. Monotone Kurve
// (Steffen), die zwischen zwei Punkten nie über sie hinausschießt: Minimum und Maximum bleiben echte Werte
// Hardcopy iPhone 14.10.2026: Die Zeichnung (640 breit) wird auf rund 380 px verkleinert, die Achsen standen bei 6 bis 7 px.
// Die Schrift in der Zeichnung wächst deshalb mit der Verkleinerung (--k, höchstens 1,6), Ringe behalten ihre Größen
pruefe(is_string($seite) && str_contains($seite, 'svg text { fill: var(--muted); font-size: calc(11px * var(--k, 1)); }'), 'die Schrift der Achsen wächst mit der Verkleinerung der Zeichnung');
pruefe(is_string($seite) && str_contains($seite, 'function axisScale(') && str_contains($seite, 'Math.min(1.6, Math.max(1, 640 / width))') && str_contains($seite, 'window.addEventListener("resize", axisScale)') && str_contains($seite, "lastData = data;\n    axisScale();"), 'der Faktor folgt der Breite, auch nach dem Drehen');
// pitti 14.10.2026: Die Ersatzwerte folgen den Theme-Farben der Kachel-Visu (Inhalt #000000/#ffffff, Karte #ffffff/#333438).
foreach (['chart.html', 'devices.html'] as $datei) {
    $quelle = (string)file_get_contents(dirname(__DIR__) . '/HomeAgent/' . $datei);
    pruefe(str_contains($quelle, '--fg: var(--color-text-primary, #000000);') && substr_count($quelle, '--fg: var(--color-text-primary, #ffffff);') === 2, "$datei: Textfarbe wie das Theme der Kachel-Visu");
    pruefe(substr_count($quelle, '--bg: var(--color-background-primary, #333438);') === 2 && !str_contains($quelle, '#1e1e1e'), "$datei: dunkler Hintergrund wie die Karte im Theme der Kachel-Visu");
}
pruefe(is_string($seite) && str_contains($seite, 'function smoothPath(') && str_contains($seite, 'Math.min(Math.abs(s0), Math.abs(s1), 0.5 * Math.abs(p1))'), 'die Seite hat eine monotone Kurve');
pruefe(is_string($seite) && substr_count($seite, 'el("path", { d: smoothPath(') >= 2 && str_contains($seite, 'smoothPath(top, true) + " L" + smoothPath(bottom, false)'), 'Mittel, Vergleich und Band im Verlauf werden als Kurve gezeichnet');
pruefe(is_string($seite) && str_contains($seite, '.ring svg { max-height: 270px; }') && !str_contains($seite, 'max-width: 320px'), 'die Ringkarten füllen die Breite');

/* --- Zähler mit „gemessen von“ (B82) ------------------------------------------------------------- */

// Tageswerte vom nuc (Fixture nuc_2026-10-17_zaehler.json, September 2026): Schalterleiste 16,73 kWh, Waschmaschine
// 7,34 kWh und Kühltruhe 10,63 kWh. Hängen beide Geräte an der Leiste, liegt darunter mehr als der Zähler misst.
$z = neueInstanz();
$zid = (int)(new ReflectionProperty(IPSModuleStrict::class, 'InstanceID'))->getValue($z);
$leisteE = variable(VARIABLETYPE_FLOAT, 842.86);
IPS_SetName($leisteE, 'Gesamt Energie');
$waschE = variable(VARIABLETYPE_FLOAT, 188.6);
$truheE = variable(VARIABLETYPE_FLOAT, 33.2);
$truheS = variable(VARIABLETYPE_BOOLEAN, true);
$waschS = variable(VARIABLETYPE_BOOLEAN, true);
$gestern = (int)strtotime('yesterday');
foreach ([[$leisteE, 16.73], [$waschE, 7.34], [$truheE, 10.63]] as [$var, $kwh]) {
    AC_SetLoggingStatus($archiv, $var, true);
    AC_SetAggregationType($archiv, $var, 1);
    AC_StubsAddAggregatedValues($archiv, $var, 1, [['TimeStamp' => $gestern, 'Duration' => 86400, 'Avg' => $kwh, 'Min' => 0, 'Max' => 0, 'MinTime' => 0, 'MaxTime' => 0]]);
}
$zugangZ = ['Name' => 'Test', 'Token' => str_repeat('c', 32), 'DefaultRoom' => '', 'ReadOnly' => true, 'InPath' => false];
IPS_SetProperty($zid, 'Accesses', json_encode([$zugangZ]));
$leisteZeile = ['Room' => 'Wohnzimmer', 'Name' => 'Schalterleiste', 'Aliases' => '', 'Type' => 'meter', 'EnergyVariableID' => $leisteE, 'MeteredBy' => 0];
$waschZeile = ['Room' => 'Keller', 'Name' => 'Waschmaschine', 'Aliases' => '', 'Type' => 'switch', 'StateVariableID' => $waschS, 'EnergyVariableID' => $waschE, 'MeteredBy' => $leisteE];
$truheZeile = ['Room' => 'Keller', 'Name' => 'Kühltruhe', 'Aliases' => '', 'Type' => 'switch', 'StateVariableID' => $truheS, 'EnergyVariableID' => $truheE, 'MeteredBy' => $leisteE];
IPS_SetProperty($zid, 'Devices', json_encode([$leisteZeile, $waschZeile, $truheZeile]));
IPS_ApplyChanges($zid);
pruefe(IPS_GetInstance($zid)['InstanceStatus'] === IS_ACTIVE, 'Zähler mit Geräten darunter: aktiv');
$testZ = $z->RunSelfTest();
pruefe(str_contains($testZ, 'Note: meter Wohnzimmer Schalterleiste: 18 kWh are measured below it in the last 30 days, more than its own 16.7 kWh; check "Measured by"'), 'Selbsttest: darunter mehr als der Zähler (B82): ' . $testZ);
$zeilenZ = explode("\n", $z->QueryState('', 'meter', '', false));
pruefe(($zeilenZ[1] ?? '') === 'Wohnzimmer | Schalterleiste | meter | 842.86', 'QueryState mit type=meter: ' . implode(' ‖ ', $zeilenZ));
$werkzeugeZ = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(), $z, HomeAgent::class)()), true), null, 'name');
pruefe(str_contains($werkzeugeZ['get_state']['inputSchema']['properties']['period']['description'] ?? '', 'A meter (type meter) never counts in a device total; its line names what is measured below it and the rest that is not metered.'), 'period erklärt die Zähler: ' . ($werkzeugeZ['get_state']['inputSchema']['properties']['period']['description'] ?? ''));

// Dialog: Auswahl „gemessen von“ mit den Zählern der Liste, Wert der Zeile
$dialogZ = array_column(json_decode((string)oderNull(static fn () => $z->UIDeviceForm('switch', '', $leisteE)), true) ?? [], null, 'name');
$auswahl = $dialogZ['MeteredBy'] ?? [];
pruefe(($auswahl['type'] ?? '') === 'Select' && ($auswahl['visible'] ?? false) === true && ($auswahl['caption'] ?? '') === 'Measured by (meter above it)' && ($auswahl['options'] ?? []) === [['caption' => '-', 'value' => 0], ['caption' => 'Schalterleiste (Wohnzimmer)', 'value' => $leisteE]] && ($auswahl['value'] ?? null) === $leisteE, 'Dialog: Auswahl mit „-“ und dem Zähler: ' . json_encode($auswahl, JSON_UNESCAPED_UNICODE));
$fremdZ = array_column(json_decode((string)oderNull(static fn () => $z->UIDeviceForm('switch', '', 99999)), true) ?? [], null, 'name')['MeteredBy'] ?? [];
pruefe(array_last($fremdZ['options'] ?? [0]) === ['caption' => '#99999 (no meter in the list)', 'value' => 99999] && ($fremdZ['value'] ?? null) === 99999, 'Dialog: ein Wert ohne Zähler bekommt einen eigenen Eintrag: ' . json_encode($fremdZ['options'] ?? null, JSON_UNESCAPED_UNICODE));
pruefe((array_column(json_decode((string)oderNull(static fn () => $z->UIDeviceForm('door')), true) ?? [], null, 'name')['MeteredBy']['visible'] ?? null) === false, 'Dialog: bei der Tür ohne Zähler unsichtbar');
// Klimagerät und Receiver nehmen Strom auf (B85): Leistung, Zähler und „gemessen von“ im Dialog
foreach (['thermostat', 'media'] as $typB85) {
    $dialogB85 = array_column(json_decode((string)oderNull(static fn () => $z->UIDeviceForm($typB85)), true) ?? [], null, 'name');
    pruefe(($dialogB85['PowerVariableID']['visible'] ?? null) === true && ($dialogB85['EnergyVariableID']['visible'] ?? null) === true && ($dialogB85['MeteredBy']['visible'] ?? null) === true, 'Dialog ' . $typB85 . ': Leistung, Zähler und „gemessen von“ sichtbar');
}

// Verweis ins Leere und Kreis sperren die Zeile
IPS_SetProperty($zid, 'Devices', json_encode([$leisteZeile, ['MeteredBy' => $truheE] + $waschZeile, ['MeteredBy' => 99999] + $truheZeile]));
IPS_ApplyChanges($zid);
$testZ = $z->RunSelfTest();
pruefe(str_contains($testZ, 'Device "Kühltruhe" (Keller): "Measured by" names no meter of the list'), 'Selbsttest: Verweis ins Leere: ' . $testZ);
pruefe(str_contains($testZ, 'Device "Waschmaschine" (Keller): "Measured by" names no meter of the list') && !str_contains($testZ, 'Device "Schalterleiste"'), 'auch die Truhe als Oberzähler ist kein Zähler, die Leiste ist fehlerfrei: ' . $testZ);
$kreis = ['Room' => '', 'Name' => 'Haushalt', 'Aliases' => '', 'Type' => 'meter', 'EnergyVariableID' => $truheE, 'MeteredBy' => $leisteE];
IPS_SetProperty($zid, 'Devices', json_encode([['MeteredBy' => $truheE] + $leisteZeile, $kreis]));
IPS_ApplyChanges($zid);
$testZ = $z->RunSelfTest();
pruefe(str_contains($testZ, 'Device "Schalterleiste" (Wohnzimmer): the meters in "Measured by" measure each other in a circle') && str_contains($testZ, 'Device "Haushalt": the meters in "Measured by" measure each other in a circle'), 'Selbsttest: Kreis: ' . $testZ);

/* --- Quellen der Energiebilanz (B82, Stufe b, als einzige Liste seit 17.10.2026) ----------------- */

// Burkhard 17.10.2026 („Wie kann ich mir Listen und Felder vorstellen? Ist das nicht verwirrend“): ein Ort für alle Quellen,
// jede mit Namen, der Dialog zeigt je Art die passenden Felder wie der Gerätedialog.
$q = neueInstanz();
$qid = (int)(new ReflectionProperty(IPSModuleStrict::class, 'InstanceID'))->getValue($q);
$formularQ = json_decode($q->GetConfigurationForm(), true);
$bilanzBereich = array_values(array_filter($formularQ['elements'], static fn (array $e): bool => ($e['name'] ?? '') === 'PanelBalance'))[0] ?? [];
$quellenListe = array_values(array_filter(flach($bilanzBereich['items'] ?? []), static fn (array $e): bool => ($e['name'] ?? '') === 'Sources'))[0] ?? [];
$spaltenQ = array_column($quellenListe['columns'] ?? [], null, 'name');
pruefe(($quellenListe['type'] ?? '') === 'List' && array_keys($spaltenQ) === ['Type', 'Name', 'EnergyLabel', 'PowerLabel', 'EnergyIn', 'EnergyOut', 'PowerIn', 'PowerOut', 'Invert'], 'Liste „Quellen“ mit Textspalten und versteckten IDs: ' . json_encode(array_keys($spaltenQ)));
pruefe(array_keys(array_filter($spaltenQ, static fn (array $c): bool => ($c['visible'] ?? true) !== false)) === ['Type', 'Name', 'EnergyLabel', 'PowerLabel', 'Invert'], 'sichtbar sind Art, Name, Zähler, Leistung und Umkehr: ' . json_encode(array_keys(array_filter($spaltenQ, static fn (array $c): bool => ($c['visible'] ?? true) !== false))));
pruefe(array_column($spaltenQ['Type']['edit']['options'] ?? [], 'value') === ['grid', 'solar', 'battery'], 'Arten grid, solar, battery');
pruefe(($quellenListe['form'] ?? null) === "return json_decode(HAGT_UISourceForm(\$id, \$Sources['Type'] ?? 'grid', \$Sources['PowerOut'] ?? 0), true);", 'der Dialog entsteht je Zeile über das Modul: ' . json_encode($quellenListe['form'] ?? null));
$dialogQ = static fn (string $art): array => array_column(json_decode((string)(oderNull(static fn () => $q->UISourceForm($art)) ?? '[]'), true) ?? [], null, 'name');
$pv = $dialogQ('solar');
pruefe(($pv['EnergyIn']['caption'] ?? '') === 'Solar production (energy meter)' && ($pv['PowerIn']['caption'] ?? '') === 'Solar power'
    && ($pv['EnergyOut']['visible'] ?? true) === false && ($pv['PowerOut']['visible'] ?? true) === false && ($pv['Invert']['visible'] ?? true) === false, 'Dialog PV: nur Ertrag und Leistung: ' . json_encode(array_map(static fn (array $f): array => [$f['caption'] ?? '', $f['visible'] ?? true], $pv)));
$akku = $dialogQ('battery');
pruefe(($akku['EnergyIn']['caption'] ?? '') === 'Battery discharge (energy meter)' && ($akku['EnergyOut']['caption'] ?? '') === 'Battery charge (energy meter)'
    && ($akku['Invert']['visible'] ?? false) === true && str_contains($akku['Invert']['caption'] ?? '', 'positive = charging'), 'Dialog Batterie: entladen, geladen, Umkehr: ' . json_encode(array_map(static fn (array $f): array => [$f['caption'] ?? '', $f['visible'] ?? true], $akku)));
$netz = $dialogQ('grid');
pruefe(($netz['EnergyIn']['caption'] ?? '') === 'Grid import (energy meter)' && ($netz['EnergyOut']['caption'] ?? '') === 'Grid export (energy meter)' && str_contains($netz['Invert']['caption'] ?? '', 'positive = grid export')
    && str_contains($netz['Type']['onChange'] ?? '', 'HAGT_UISourceChanged($id, $Type, $EnergyIn, $EnergyOut, $PowerIn, $PowerOut);') && array_column($netz['Type']['options'] ?? [], 'value') === ['grid', 'solar', 'battery'], 'Dialog Netz: Bezug und Einspeisung, Wechsel der Art meldet sich: ' . json_encode($netz['Type'] ?? null));
// Ein unbekannter Typ (per Skript gesetzt) bekommt einen eigenen Eintrag, sonst verweigert die Konsole das Übernehmen
// („Aktueller Wert ist nicht verfügbar“; Review 18.10.2026, N9)
$wind = $dialogQ('wind');
pruefe(in_array('wind', array_column($wind['Type']['options'] ?? [], 'value'), true) && str_contains((string)(array_column($wind['Type']['options'] ?? [], 'caption', 'value')['wind'] ?? ''), 'wind'), 'unbekannter Typ hat einen Eintrag: ' . json_encode($wind['Type']['options'] ?? null));
pruefe(array_column($netz['Type']['options'] ?? [], 'value') === ['grid', 'solar', 'battery'], 'bekannter Typ: keine zusätzlichen Einträge');
// Mit zweiter Leistung hat „Umgekehrt“ keine Wirkung und ist ausgeblendet (N10)
$zweiLeistungen = array_column(json_decode((string)(oderNull(static fn () => $q->UISourceForm('grid', 12345)) ?? '[]'), true) ?? [], null, 'name');
pruefe(($zweiLeistungen['Invert']['visible'] ?? true) === false, 'Netz mit zwei Leistungen: kein Haken „Umgekehrt“');
$q->formUpdates = [];
oderNull(static fn () => $q->UISourceChanged('battery', 0, 0, 12345, 23456));
pruefe(in_array(['Invert', 'visible', false], $q->formUpdates, true) && in_array(['Invert', 'value', false], $q->formUpdates, true), 'zweite Leistung im offenen Dialog: Umkehr ausgeblendet und aus: ' . json_encode(array_values(array_filter($q->formUpdates, static fn (array $u): bool => $u[0] === 'Invert'))));
$q->formUpdates = [];
oderNull(static fn () => $q->UISourceChanged('battery', 0, 0, 12345, 0));
pruefe(in_array(['Invert', 'visible', true], $q->formUpdates, true), 'eine Leistung mit Vorzeichen: Umkehr sichtbar');
// Wechsel auf PV im offenen Dialog: Felder folgen der Art, die zweiten Werte fallen weg, die Textspalten folgen
$q->formUpdates = [];
$bezugQ = variable(VARIABLETYPE_FLOAT, 2000.0);
IPS_SetName($bezugQ, 'Bezug');
oderNull(static fn () => $q->UISourceChanged('solar', $bezugQ, $bezugQ, 0, 0));
pruefe(in_array(['EnergyOut', 'visible', false], $q->formUpdates, true) && in_array(['EnergyOut', 'value', 0], $q->formUpdates, true) && in_array(['EnergyIn', 'caption', 'Solar production (energy meter)'], $q->formUpdates, true)
    && in_array(['EnergyLabel', 'value', 'Bezug'], $q->formUpdates, true), 'Wechsel auf PV: ' . json_encode($q->formUpdates));

$netzQ = variable(VARIABLETYPE_FLOAT, 1.077);
$pvQ = variable(VARIABLETYPE_FLOAT, 1504.0);
$batterieQ = variable(VARIABLETYPE_FLOAT, 1112.0);
IPS_SetName($batterieQ, 'Battery Power');
IPS_SetProperty($qid, 'Accesses', json_encode([['Name' => 'Test', 'Token' => str_repeat('d', 32), 'DefaultRoom' => '', 'ReadOnly' => true, 'InPath' => false]]));
IPS_SetProperty($qid, 'Sources', json_encode([$bilanzZeile('grid', 'Netz', 0, 0, $netzQ), $bilanzZeile('solar', 'Dach', 0, 0, $pvQ), $bilanzZeile('battery', 'Marstek', 0, 0, $batterieQ, 0, true)]));
IPS_ApplyChanges($qid);
// Werte der betreuten Anlage (Fixture betreut_2026-10-14_batterie.json, Zeitpunkt „laden“): Netz 1.077 W, PV 1504 W,
// Batterie 1112 W (positiv = laden).
$indexQ = oderNull(static fn () => \Closure::bind(fn () => $this->deviceIndex(), $q, HomeAgent::class)());
$jetztQ = $indexQ instanceof DeviceIndex ? explode("\n", $indexQ->getState(['period' => 'now'])['text'])[0] : '';
pruefe($jetztQ === 'House power 393 W (all devices, period=now): grid import 1, solar 1504, battery discharge 0, battery charge 1112 W.', 'die Quellen zählen in der Hausleistung, die Batterie umgekehrt: ' . $jetztQ);
$testQ = $q->RunSelfTest();
pruefe(str_contains($testQ, 'Energy balance: battery discharge power "Marstek" Battery Power reads 1112 W now and counts as battery charge 1112 W, reversed by the checkbox'), 'der Selbsttest nennt die Quelle mit Namen: ' . $testQ);
$referenzen = IPS_GetInstance($qid)['References'] ?? oderNull(static fn () => \Closure::bind(fn () => $this->GetReferenceList(), $q, HomeAgent::class)());
pruefe(is_array($referenzen) && in_array($batterieQ, $referenzen, true), 'die Variable der Liste ist Referenz: ' . json_encode($referenzen));
$werteQ = array_values(array_filter(flach(json_decode($q->GetConfigurationForm(), true)['elements']), static fn (array $e): bool => ($e['name'] ?? '') === 'Sources'))[0]['values'] ?? [];
// Eine einzelne Leistung mit Vorzeichen ist vollständig, ein „/ -“ sah nach einem fehlenden Feld aus (Burkhard 17.10.2026)
pruefe(($werteQ[2]['PowerLabel'] ?? '') === 'Battery Power (signed)' && ($werteQ[1]['EnergyLabel'] ?? '') === '-', 'die Textspalten nennen die Variablen, eine Leistung mit Vorzeichen ohne „/ -“: ' . json_encode($werteQ));
// Nur eine PV-Anlage: show_chart bietet part=pv an
$zaehlerQ = variable(VARIABLETYPE_FLOAT, 110100.0);
IPS_SetProperty($qid, 'Sources', json_encode([$bilanzZeile('solar', 'Garage', $zaehlerQ)]));
IPS_ApplyChanges($qid);
$werkzeugeQ = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(null, true), $q, HomeAgent::class)()), true), null, 'name');
pruefe(in_array('pv', $werkzeugeQ['show_chart']['inputSchema']['properties']['part']['enum'] ?? [], true), 'eine PV-Anlage in der Liste: part=pv: ' . json_encode($werkzeugeQ['show_chart']['inputSchema']['properties']['part'] ?? null));
// Fehler: PV mit zweitem Zähler, gelöschte Variable; dann ist die Bilanz gesperrt
IPS_SetProperty($qid, 'Sources', json_encode([$bilanzZeile('solar', 'Garage', $zaehlerQ, $zaehlerQ), $bilanzZeile('grid', 'Tibber', 69997)]));
IPS_ApplyChanges($qid);
$testQ = $q->RunSelfTest();
pruefe(str_contains($testQ, 'Source "Garage": a solar plant has only its production, no second meter or power'), 'Selbsttest: PV mit zweitem Zähler: ' . $testQ);
pruefe(str_contains($testQ, 'Source "Tibber": variable for grid import does not exist'), 'Selbsttest: gelöschte Variable mit dem Teil der Bilanz: ' . $testQ);
$werkzeugeQ = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(null, true), $q, HomeAgent::class)()), true), null, 'name');
pruefe(!isset($werkzeugeQ['get_state']['inputSchema']['properties']['period']), 'eine fehlerhafte Quelle sperrt die Bilanz: ' . json_encode(array_keys($werkzeugeQ['get_state']['inputSchema']['properties'] ?? [])));
// Dieselbe Variable in zwei Zeilen (Review 18.10.2026, M4): Meldung an der späteren Zeile, die Bilanz ist gesperrt
IPS_SetProperty($qid, 'Sources', json_encode([$bilanzZeile('solar', 'Garage', $zaehlerQ), $bilanzZeile('solar', 'Garage nochmal', $zaehlerQ)]));
IPS_ApplyChanges($qid);
$testQ = $q->RunSelfTest();
pruefe(str_contains($testQ, 'Source "Garage nochmal": a variable of this row is already used in the balance, it would count twice'), 'Selbsttest: doppelte Variable: ' . $testQ);
$werkzeugeQ = array_column(json_decode((string)json_encode(\Closure::bind(fn () => $this->tools(null, true), $q, HomeAgent::class)()), true), null, 'name');
pruefe(!isset($werkzeugeQ['get_state']['inputSchema']['properties']['period']), 'eine doppelte Variable sperrt die Bilanz: ' . json_encode(array_keys($werkzeugeQ['get_state']['inputSchema']['properties'] ?? [])));

// Übernahme der alten Felder: Beim Übernehmen wandern sie als benannte Zeilen vor die Liste, die Felder werden leer.
// Orakel ist die Rechnung der Bibliothek mit den Feldern (Konstruktor wie bis build 191); die Zahlen müssen gleich sein.
$u = neueInstanz();
$uid = (int)(new ReflectionProperty(IPSModuleStrict::class, 'InstanceID'))->getValue($u);
$f = ['GridImportVariableID' => variable(VARIABLETYPE_FLOAT, 100.0), 'GridExportVariableID' => variable(VARIABLETYPE_FLOAT, 50.0), 'SolarVariableID' => variable(VARIABLETYPE_FLOAT, 300.0),
    'BatteryChargeVariableID' => variable(VARIABLETYPE_FLOAT, 20.0), 'BatteryDischargeVariableID' => variable(VARIABLETYPE_FLOAT, 10.0),
    'GridImportPowerVariableID' => variable(VARIABLETYPE_FLOAT, -500.0), 'SolarPowerVariableID' => variable(VARIABLETYPE_FLOAT, 1500.0),
    'BatteryChargePowerVariableID' => variable(VARIABLETYPE_FLOAT, 200.0), 'BatteryDischargePowerVariableID' => variable(VARIABLETYPE_FLOAT, 0.0)];
$garage = $bilanzZeile('solar', 'Garage', 0, 0, variable(VARIABLETYPE_FLOAT, 700.0));
$orakel = new DeviceIndex([], $u->testBackend(), null, '', array_combine(array_keys(DeviceIndex::BALANCE), array_map(static fn (string $p): int => $f[$p] ?? 0, DeviceIndex::BALANCE)),
    array_combine(array_keys(DeviceIndex::BALANCE_POWER), array_map(static fn (string $p): int => $f[$p] ?? 0, DeviceIndex::BALANCE_POWER)));
$orakel->setInvertedPower(['import']);
$orakel->addSources([$garage]);
$zahlen = static fn (string $text): string => (string)preg_replace('/ \(of this [^)]*\)/', '', (string)strtok($text, "\n"));
$erwartet = $zahlen($orakel->getState(['period' => 'now'])['text']);
IPS_SetProperty($uid, 'Accesses', json_encode([['Name' => 'Test', 'Token' => str_repeat('e', 32), 'DefaultRoom' => '', 'ReadOnly' => true, 'InPath' => false]]));
foreach ($f as $property => $variableId) {
    IPS_SetProperty($uid, $property, $variableId);
}
IPS_SetProperty($uid, 'InvertGridPower', true);
IPS_SetProperty($uid, 'Sources', json_encode([$garage]));
IPS_ApplyChanges($uid);
$uebernommen = json_decode((string)IPS_GetProperty($uid, 'Sources'), true);
pruefe($uebernommen === [
    $bilanzZeile('grid', 'Grid', $f['GridImportVariableID'], $f['GridExportVariableID'], $f['GridImportPowerVariableID'], 0, true),
    $bilanzZeile('solar', 'Solar', $f['SolarVariableID'], 0, $f['SolarPowerVariableID']),
    $bilanzZeile('battery', 'Battery', $f['BatteryDischargeVariableID'], $f['BatteryChargeVariableID'], $f['BatteryDischargePowerVariableID'], $f['BatteryChargePowerVariableID']),
    $garage,
], 'Übernahme: die Felder stehen als Zeilen vor der Liste: ' . json_encode($uebernommen));
$leer = array_filter(array_map(static fn (string $p): mixed => IPS_GetProperty($uid, $p), array_merge(array_keys($f), ['InvertGridPower', 'InvertBatteryPower'])));
pruefe($leer === [], 'Übernahme: die alten Felder sind danach leer: ' . json_encode($leer));
$indexU = oderNull(static fn () => \Closure::bind(fn () => $this->deviceIndex(), $u, HomeAgent::class)());
$gerechnet = $indexU instanceof DeviceIndex ? $zahlen($indexU->getState(['period' => 'now'])['text']) : '';
pruefe($gerechnet === $erwartet && $erwartet !== '', 'Übernahme ändert die Hausleistung nicht: ' . $gerechnet . ' / Orakel ' . $erwartet);
IPS_ApplyChanges($uid);
pruefe(count(json_decode((string)IPS_GetProperty($uid, 'Sources'), true)) === 4, 'ein zweites Übernehmen übernimmt nichts doppelt');
pruefe(IPS_GetInstance($uid)['InstanceStatus'] === IS_ACTIVE, 'nach der Übernahme aktiv: ' . IPS_GetInstance($uid)['InstanceStatus']);

/* --- Vorlagen am Modul (Prompts, Backlog B63) ---------------------------------------------------- */

// Nur das Licht ist freigegeben (siehe oben), der Zugang darf nur abfragen.
$katalogP = oderNull(static fn () => \Closure::bind(fn () => $this->promptCatalog($zugangP), $p, HomeAgent::class)());
pruefe($katalogP instanceof PromptCatalog && array_column($katalogP->list(), 'name') === ['leave_home', 'good_night'], 'Vorlagen nach Freigabeliste, ohne Zähler kein Energiebericht: ' . json_encode($katalogP instanceof PromptCatalog ? array_column($katalogP->list(), 'name') : null));
$nacht = $katalogP instanceof PromptCatalog ? (string)($katalogP->get('good_night')['messages'][0]['content']['text'] ?? '') : '';
pruefe(str_contains($nacht, 'type=light') && !str_contains($nacht, 'ask whether'), 'Nur-Lese-Zugang: keine Frage nach dem Schalten: ' . $nacht);
$_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook/homeagent/' . $pid, 'REMOTE_ADDR' => '192.0.2.5', 'HTTP_AUTHORIZATION' => 'Bearer ' . $pToken];
HomeAgentHarness::$body = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list']);
$p->response = null;
ob_start();
$pHook->invoke($p);
ob_end_clean();
HomeAgentHarness::$body = '';
pruefe(str_contains((string)($p->response['body'] ?? ''), '"leave_home"'), 'prompts/list über den Hook: ' . ($p->response['body'] ?? ''));

ende('Modul');
