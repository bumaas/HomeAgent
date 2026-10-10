<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/McpProtocol.php';
require_once __DIR__ . '/../libs/DeviceIndex.php';
require_once __DIR__ . '/../libs/RoomIndex.php';
require_once __DIR__ . '/../libs/SceneIndex.php';
require_once __DIR__ . '/../libs/AlexaImport.php';
require_once __DIR__ . '/../libs/SignInGuard.php';
require_once __DIR__ . '/../libs/SymconDeviceBackend.php';

/**
 * Home Agent: ein MCP-Server über einen WebHook, durch den KI-Assistenten das Haus bedienen.
 *
 * Der Assistent sieht nur die Geräte der Freigabeliste und wenige, einfache Werkzeuge.
 * Endpunkt: /hook/homeagent/<InstanzID>, Anmeldung per „Authorization: Bearer <Token>“.
 * Jeder Assistent hat seinen eigenen Zugang (Token, Standardraum, nur lesen); die Geräteliste
 * gibt es einmal.
 */
class HomeAgent extends IPSModuleStrict
{
    private const STATUS_NO_TOKEN = 104;
    private const STATUS_LIST_INVALID = 201;

    /** So viele Szenennamen nennt die Werkzeugbeschreibung höchstens; jede kostet den Assistenten Token. */
    private const SCENES_IN_DESCRIPTION = 30;

    /** So lange bleibt ein gemeldeter Fehlschlag abholbar; die Werkzeuge warten höchstens 2 Sekunden. */
    private const FAILURE_KEEP_SECONDS = 60;

    /** So lange und so viele Fahrtmerker hält der Buffer „Travels“ (wie DeviceIndex: zwei Minuten). */
    private const TRAVEL_KEEP_MS = 120000;
    private const TRAVEL_MAX_ENTRIES = 50;

    /** Höchstzahl gemerkter Fehlschläge und Länge ihres Texts; mehr hält der Buffer nicht sinnvoll. */
    private const FAILURE_MAX_ENTRIES = 50;
    private const FAILURE_TEXT_LENGTH = 300;

    /** Kürzere Tokens sind ein Fehler der Zugangsliste; der Knopf „Token erzeugen“ liefert 64 Zeichen. */
    private const TOKEN_MIN_LENGTH = 24;

    /** Satz für Instructions und get_state beim Nur-Lese-Zugang. */
    private const READ_ONLY_NOTE = 'This access can only read the house, it cannot switch or set anything.';

    /** Farben für Fehler im Formular: „Rot“ der Doku für Listenzeilen (#FFC0C0), dazu ein dunkles Rot für Text. */
    private const ROW_COLOR_ERROR = '#FFC0C0';

    /** Aufklappbare Bereiche des Formulars, deren Zustand das Modul sich merkt. */
    private const PANELS = ['PanelAccesses', 'PanelRooms', 'PanelDevices', 'PanelScenes', 'PanelBalance'];

    private const LABEL_COLOR_ERROR = 0xC00000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Accesses', '[]');
        $this->RegisterPropertyString('Rooms', '[]');
        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyString('Scenes', '[]');
        // Aufgeklappte Bereiche des Formulars, damit sie beim nächsten Öffnen wieder so stehen.
        $this->RegisterAttributeString('ExpandedPanels', '[]');
        foreach (array_merge(array_values(DeviceIndex::BALANCE), array_values(DeviceIndex::BALANCE_POWER)) as $property) {
            $this->RegisterPropertyInteger($property, 0);
        }

        $this->RegisterHook($this->hookPath());
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetSummary($this->GetEndpoint());

        if (!$this->hasAccessWithToken()) {
            $this->SetStatus(self::STATUS_NO_TOKEN);
            return;
        }

        $errors = $this->validateDeviceList();
        if ($errors !== []) {
            $this->SetStatus(self::STATUS_LIST_INVALID);
            $this->LogMessage($this->Translate('The lists have errors, the endpoint stays closed until they are corrected') . ': ' . implode('; ', $errors), KL_ERROR);
            return;
        }

        if ($this->GetStatus() !== IS_ACTIVE && $this->GetStatus() !== IS_CREATING) {
            $this->LogMessage($this->Translate('Configuration is valid again, the endpoint is ready'), KL_MESSAGE);
        }
        $this->SetStatus(IS_ACTIVE);
    }

    /**
     * Liefert dasselbe wie das Werkzeug „get_state“, für Skripte und zum Prüfen ohne Assistent.
     * Leere Parameter bedeuten „ohne Einschränkung“.
     */
    public function QueryState(string $Room, string $Type, string $Name, bool $ActiveOnly): string
    {
        return $this->deviceIndex()->getState(['room' => $Room, 'type' => $Type, 'name' => $Name, 'active_only' => $ActiveOnly])['text'];
    }

    /**
     * Schaltet die passenden Geräte ein oder aus, wie „set_state“ mit on oder off. Mindestens einer
     * der drei Filter muss gesetzt sein; ein Name muss genau ein Gerät treffen.
     */
    public function SwitchDevices(string $Room, string $Type, string $Name, bool $On): string
    {
        $arguments = ['room' => $Room, 'type' => $Type, 'name' => $Name, 'on' => $On];
        return $this->logged('SwitchDevices', $arguments, $this->deviceIndex()->switchDevices($arguments))['text'];
    }

    /**
     * Stellt Helligkeit oder Position in Prozent (0 bis 100), wie „set_state“ mit percent.
     */
    public function SetLevel(string $Room, string $Type, string $Name, float $Percent): string
    {
        $arguments = ['room' => $Room, 'type' => $Type, 'name' => $Name, 'percent' => $Percent];
        return $this->logged('SetLevel', $arguments, $this->deviceIndex()->setLevel($arguments))['text'];
    }

    /**
     * Startet wie das Werkzeug „run_scene“ genau eine Szene der Szenenliste. Ohne Namen wird nichts
     * gestartet, die Antwort nennt dann die Szenen. Der Raum darf leer bleiben.
     */
    public function RunScene(string $Room, string $Name): string
    {
        return $this->callTool('run_scene', ['room' => $Room, 'name' => $Name])['text'];
    }

    /**
     * Die Instanz hat keine eigenen Variablen. Einzige Aktion ist die Rückmeldung eines
     * Aktionsskripts, dass das Schalten eines Geräts gescheitert ist (siehe SymconDeviceBackend).
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'ActionFailed') {
            trigger_error(sprintf($this->Translate('Unknown action "%s". This instance has no variables of its own; devices are controlled with HAGT_SwitchDevices, HAGT_SetLevel and HAGT_RunScene.'), $Ident), E_USER_WARNING);
            return;
        }
        $report = json_decode((string)$Value, true);
        // Der Schlüssel kommt aus SymconDeviceBackend::request (12 Hex-Zeichen). Aufrufen kann die
        // Aktion jedes Skript der Anlage; deshalb Schlüssel prüfen und den Text begrenzen.
        if (!is_array($report) || !is_string($report['key'] ?? null) || preg_match('/^[0-9a-f]{12}$/', $report['key']) !== 1 || !is_string($report['error'] ?? null)) {
            trigger_error($this->Translate('ActionFailed expects a JSON object with key, variable and error.'), E_USER_WARNING);
            return;
        }
        $error = DeviceIndex::singleLine($report['error'], self::FAILURE_TEXT_LENGTH);
        // Ins Log geht jeder Fehlschlag, auch wenn der Assistent seine Antwort längst hat.
        $this->LogMessage(sprintf($this->Translate('Action on variable %d failed: %s'), (int)($report['variable'] ?? 0), $error), KL_WARNING);
        $this->storeFailure($report['key'], $error);
    }

    /**
     * Prüft ohne Nebenwirkung, ob der Endpunkt benutzbar ist: Token, Freigabeliste, lesbare Variablen.
     * Erste Zeile „OK“ oder „ERROR“, danach je Punkt eine Zeile.
     */
    public function RunSelfTest(): string
    {
        $lines = [];
        $ok = true;
        if (!$this->hasAccessWithToken()) {
            $ok = false;
            $lines[] = $this->Translate('No access with a token: add an access with a token so that the assistant can log in');
        }
        foreach ($this->validateDeviceList() as $error) {
            $ok = false;
            $lines[] = $error;
        }
        $list = $this->deviceList();
        $count = count($list);
        $sceneCount = count($this->sceneList());
        if ($count === 0 && $sceneCount === 0) {
            $ok = false;
            $lines[] = $this->Translate('The device list is empty, the assistant sees no devices');
        }
        $backend = $this->backend();
        foreach ($list as $entry) {
            // Ein Gerät mit „nur abfragen“ soll gar nicht geschaltet werden, ihm fehlt also nichts.
            if (!in_array((string)($entry['Type'] ?? ''), DeviceIndex::CONTROLLABLE, true) || ($entry['ReadOnly'] ?? false) === true) {
                continue;
            }
            // Geschaltet wird der Zustand, gestellt die Stufe: Fehlt an einer davon die Aktion, kann der Assistent sie nur lesen.
            // Der Fahrstatus wird nur gelesen und braucht keine Aktion (Homematic WORKING hat keine).
            // Beim Thermostat wird der Sollwert gestellt; die Ist-Temperatur ist ein Messwert.
            if ((string)($entry['Type'] ?? '') === 'thermostat') {
                $setpoint = DeviceIndex::rolesOf($entry)['setpoint'] ?? 0;
                if ($setpoint > 0 && !$backend->hasAction($setpoint)) {
                    $lines[] = sprintf($this->Translate('Note: %s has no action on its setpoint, the assistant can read it but not set it'), trim((string)($entry['Room'] ?? '') . ' ' . (string)($entry['Name'] ?? '?')));
                }
                continue;
            }
            foreach (array_intersect_key(DeviceIndex::rolesOf($entry), ['state' => true, 'level' => true]) as $variableId) {
                if (!$backend->hasAction($variableId)) {
                    // Mit Raum, sonst ist der Hinweis bei gleichnamigen Geräten nicht eindeutig.
                    $lines[] = sprintf($this->Translate('Note: %s has no action on its state variable, the assistant can read it but not switch it'), trim((string)($entry['Room'] ?? '') . ' ' . (string)($entry['Name'] ?? '?')));
                    break;
                }
            }
        }
        // Verbrauch über Zeiträume gibt es nur aus einem Zähler, den das Archiv als Zähler aggregiert.
        foreach ($list as $entry) {
            $meter = DeviceIndex::rolesOf($entry)['energy'] ?? 0;
            $counter = $meter > 0 && IPS_VariableExists($meter) ? $backend->counterSum($meter, 4, 0, 0) : false;
            if ($counter === null) {
                $lines[] = sprintf($this->Translate('Note: %s: the energy meter %s is not archived as a counter, so there is no consumption over periods'), trim((string)($entry['Room'] ?? '') . ' ' . (string)($entry['Name'] ?? '?')), IPS_GetName($meter));
            } elseif (is_array($counter) && !DeviceIndex::isEnergyUnit($counter['unit'])) {
                $lines[] = sprintf($this->Translate('Note: %s: the energy meter %s counts in %s, not in kWh, so it is left out of the consumption'), trim((string)($entry['Room'] ?? '') . ' ' . (string)($entry['Name'] ?? '?')), IPS_GetName($meter), trim($counter['unit']));
            }
        }
        // Energiebilanz: Jeder Zähler muss als Zähler archiviert sein, sonst gibt es keinen Hausverbrauch.
        foreach ($this->balance() as $key => $variableId) {
            $counter = IPS_VariableExists($variableId) ? $backend->counterSum($variableId, 4, 0, 0) : false;
            if ($counter === null) {
                $lines[] = sprintf($this->Translate('Note: energy balance: %s %s is not archived as a counter, so there is no house consumption'), $this->balanceCaption($key), IPS_GetName($variableId));
            } elseif (is_array($counter) && !DeviceIndex::isEnergyUnit($counter['unit'])) {
                $lines[] = sprintf($this->Translate('Note: energy balance: %s %s counts in %s, not in kWh, so there is no house consumption'), $this->balanceCaption($key), IPS_GetName($variableId), trim($counter['unit']));
            }
        }
        // Leistungen der Bilanz: Ein Zähler im Leistungsfeld ergäbe keine Hausleistung.
        foreach ($this->powerBalance() as $key => $variableId) {
            $unit = $backend->unit($variableId);
            if (IPS_VariableExists($variableId) && !DeviceIndex::isPowerUnit($unit)) {
                $lines[] = sprintf($this->Translate('Note: energy balance: %s %s is in %s, not a power value, so there is no house power'), $this->powerCaption($key), IPS_GetName($variableId), $unit);
            }
        }
        // Raumliste: Ein Tippfehler im Raum eines Geräts fällt hier auf, denn der Raum bleibt freier Text.
        $rooms = $this->roomIndex();
        if ($rooms->names() !== []) {
            $unlisted = [];
            foreach (array_merge($list, $this->sceneList()) as $entry) {
                $room = trim((string)($entry['Room'] ?? ''));
                if ($room !== '' && !$rooms->isListed($room)) {
                    $unlisted[$room] = ($unlisted[$room] ?? 0) + (isset($entry['ScriptID']) ? 0 : 1);
                }
            }
            foreach ($unlisted as $room => $devices) {
                $lines[] = sprintf($this->Translate('Note: room "%s" (%d device(s)) is not in the room list, so it has neither aliases nor a floor'), $room, $devices);
            }
            foreach ($rooms->names() as $name) {
                if (!array_any(array_merge($list, $this->sceneList()), static fn (array $entry): bool => DeviceIndex::normalize((string)($entry['Room'] ?? '')) === DeviceIndex::normalize($name))) {
                    $lines[] = sprintf($this->Translate('Note: room "%s" of the room list has no device'), $name);
                }
            }
        }
        // Rollläden: welcher Rohwert offen und welcher geschlossen ist, und woher das Modul es weiß.
        // Hier sieht der Anwender, ob auf und zu richtig herum erkannt sind.
        foreach ($list as $entry) {
            $level = (string)($entry['Type'] ?? '') === 'blind' ? (DeviceIndex::rolesOf($entry)['level'] ?? 0) : 0;
            if ($level === 0) {
                continue;
            }
            $label = trim((string)($entry['Room'] ?? '') . ' ' . (string)($entry['Name'] ?? ''));
            $inverted = (bool)($entry['Invert'] ?? false);
            $range = $backend->range($level);
            $travel = DeviceIndex::travel($range, $inverted);
            if ($range === null || $travel === null) {
                $lines[] = sprintf($this->Translate('Note: blind %s has no known value range, the assistant cannot set it'), $label);
                continue;
            }
            $source = match ($range['source']) {
                'presentation' => $this->Translate('from the blind presentation'),
                'rotation'     => $this->Translate('from the blind presentation, rotation'),
                'reversed'     => sprintf($this->Translate('from the profile name %s'), $range['profile']),
                default        => $this->Translate('default: the smallest value is open'),
            };
            if ($inverted) {
                $source .= $this->Translate(', reversed by the checkbox');
            }
            $number = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
            $lines[] = sprintf($this->Translate('Blind %s: open at %s, closed at %s (%s)'), $label, $number($travel['open']), $number($travel['closed']), $source);
            $moving = DeviceIndex::rolesOf($entry)['moving'] ?? 0;
            if ($moving > 0 && IPS_VariableExists($moving)) {
                $lines[] = sprintf($this->Translate('Blind %s reports its movement through %s'), $label, IPS_GetName($moving));
            }
            // Anhalten: über die Stoppvariable oder über das Modul des Rollladens.
            $stop = DeviceIndex::rolesOf($entry)['stop'] ?? 0;
            $stopVia = $backend->stopMethod($level, $stop);
            if ($stop > 0 && IPS_VariableExists($stop) && $stopVia === '') {
                $lines[] = sprintf($this->Translate('Note: blind %s: the stop variable has no entry "Stop", the assistant cannot stop it'), $label);
            } elseif ($stopVia === 'variable') {
                $lines[] = sprintf($this->Translate('Blind %s stops through %s'), $label, IPS_GetName($stop));
            } elseif ($stopVia !== '') {
                $lines[] = sprintf($this->Translate('Blind %s stops through the %s module'), $label, $stopVia);
            }
        }
        // Thermostate: in welchem Bereich und mit welcher Schrittweite der Assistent stellen darf.
        foreach ($list as $entry) {
            $setpoint = (string)($entry['Type'] ?? '') === 'thermostat' ? (DeviceIndex::rolesOf($entry)['setpoint'] ?? 0) : 0;
            if ($setpoint === 0 || !IPS_VariableExists($setpoint)) {
                continue;
            }
            $label = trim((string)($entry['Room'] ?? '') . ' ' . (string)($entry['Name'] ?? ''));
            $range = $backend->setpointRange($setpoint);
            if ($range === null) {
                $lines[] = sprintf($this->Translate('Note: thermostat %s has no known value range, temperatures are sent unchecked'), $label);
                continue;
            }
            $unit = trim($backend->unit($setpoint));
            $number = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
            $lines[] = sprintf($this->Translate('Thermostat %s: %s to %s %s in steps of %s'), $label, $number($range['min']), $number($range['max']), $unit === '' ? '°C' : $unit, $number($range['step']));
        }
        foreach ($this->accessList() as $access) {
            if ($access['InPath'] && $access['Token'] !== '') {
                $lines[] = sprintf($this->Translate('Note: access "%s" also accepts its token as the last part of the address, for clients that cannot send a login'), $access['Name']);
            }
        }
        $lines[] = sprintf($this->Translate('%d released device(s), %d scene(s), endpoint %s'), $count, $sceneCount, $this->GetEndpoint());
        return implode("\n", array_merge([$ok ? 'OK' : 'ERROR'], $lines));
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        $problems = $this->validateLists();
        $failedRows = ['Accesses' => [], 'Rooms' => [], 'Devices' => [], 'Scenes' => []];
        foreach ($problems as $problem) {
            $failedRows[$problem['list']][$problem['index']] = true;
        }
        // Die Listen stecken in aufklappbaren Bereichen; angepasst wird jedes Element, auch verschachtelt.
        $adjust = function (array &$element) use (&$adjust, $failedRows): void {
            if (($element['name'] ?? '') === 'Endpoint') {
                $element['caption'] = $this->Translate('Endpoint') . ': http://<address>:3777' . $this->GetEndpoint();
            }

            if (($element['name'] ?? '') === 'Devices') {
                // Der Dialog einer Zeile hängt vom Gerätetyp ab und entsteht deshalb bei jedem Öffnen
                // neu; die Vorlage dafür steht als „form“ der Liste in der form.json.
                $element['form'] = "return json_decode(HAGT_UIDeviceForm(\$id, \$Devices['Type'] ?? 'light'), true);";
                // Die sichtbaren Spalten zu den Variablen sind Text, den das Modul füllt: der Name der
                // Variable oder „-“. Eine Variablenauswahl als Spalte zeigte für jedes leere Feld „Kein(e)“.
                $list = json_decode($this->ReadPropertyString('Devices'), true);
                $element['values'] = array_map(fn (array $row): array => $this->deviceLabels($row), is_array($list) ? array_values($list) : []);
            }

            // Fehlerhafte Zeilen rot. Die Konsole ergänzt die gespeicherten Zeilen der Reihe nach um
            // die Werte aus „values“, auch um die Farbe; die Sortierung in der Ansicht stört das nicht.
            if (isset($failedRows[$element['name'] ?? ''])) {
                $count = count($this->listProperty($element['name']));
                for ($index = 0; $index < $count; $index++) {
                    $element['values'][$index] = ($element['values'][$index] ?? []) + ['rowColor' => isset($failedRows[$element['name']][$index]) ? self::ROW_COLOR_ERROR : ''];
                }
            }
            foreach (array_keys($element['items'] ?? []) as $child) {
                if (is_array($element['items'][$child])) {
                    $adjust($element['items'][$child]);
                }
            }
        };
        foreach (array_keys($form['elements']) as $index) {
            $adjust($form['elements'][$index]);
        }
        // Jeder Bereich steht so, wie der Anwender ihn zuletzt gelassen hat; anfangs sind alle zu
        // (Burkhard, 10.10.2026: einheitlich, und den Zustand merken).
        $expanded = $this->expandedPanels();
        foreach ($form['elements'] as &$panel) {
            if (($panel['type'] ?? '') === 'ExpansionPanel') {
                $panel['expanded'] = in_array($panel['name'] ?? '', $expanded, true);
            }
        }
        unset($panel);

        // Vorschlag aus Alexa nur, wenn es eine Alexa-Instanz gibt; die erste ist vorgewählt.
        // Auswahl und Knopf stehen zusammen in einer Zeile (RowLayout).
        $alexas = IPS_GetInstanceListByModuleID(AlexaImport::MODULE_ID);
        foreach ($form['actions'] as &$row) {
            foreach ($row['items'] ?? [] as $index => $action) {
                if (($action['name'] ?? '') === 'AlexaInstance') {
                    $row['items'][$index]['options'] = array_map(static fn (int $id): array => ['caption' => IPS_GetName($id) . ' (#' . $id . ')', 'value' => $id], $alexas);
                    $row['items'][$index]['value'] = $alexas[0] ?? 0;
                }
                if (in_array($action['name'] ?? '', ['AlexaInstance', 'ImportAlexa'], true)) {
                    $row['items'][$index]['visible'] = $alexas !== [];
                }
            }
        }
        unset($row);

        // Die Fehler stehen oben im Formular, nicht nur im Log.
        if ($problems !== []) {
            $labels = [['type' => 'Label', 'name' => 'ListErrors', 'caption' => $this->Translate('The lists have errors, the endpoint stays closed until they are corrected') . ':', 'bold' => true, 'color' => self::LABEL_COLOR_ERROR]];
            foreach ($problems as $number => $problem) {
                $labels[] = ['type' => 'Label', 'name' => 'ListError' . $number, 'caption' => $problem['text'], 'color' => self::LABEL_COLOR_ERROR];
            }
            array_splice($form['elements'], 0, 0, $labels);
        }
        return (string)json_encode($form);
    }

    /**
     * Nur für das Konfigurationsformular: liefert den Bearbeitungsdialog einer Zeile der
     * Freigabeliste als JSON. Sichtbar sind die Fähigkeiten, die der Gerätetyp haben kann.
     */
    public function UIDeviceForm(string $Type): string
    {
        $type = array_key_exists($Type, DeviceIndex::TYPES) ? $Type : array_key_first(DeviceIndex::TYPES);
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        $dialog = [];
        $typeOptions = [];
        foreach (self::allElements($form['elements']) as $element) {
            if (($element['name'] ?? '') === 'Devices') {
                $dialog = $element['form'];
                $typeOptions = array_column($element['columns'], 'edit', 'name')['Type']['options'];
            }
        }
        $fields = $this->deviceDialogFields($type);
        foreach ($dialog as &$element) {
            $name = (string)($element['name'] ?? '');
            if ($name === 'Type') {
                // Die Typen stehen nur einmal in der form.json, bei der Spalte der Liste.
                $element['options'] = $typeOptions;
            }
            if (isset($fields[$name])) {
                $element['visible'] = $fields[$name]['visible'];
                $element['caption'] = $fields[$name]['caption'];
            } elseif (isset($element['caption'])) {
                $element['caption'] = $this->Translate($element['caption']);
            }
            foreach ($element['options'] ?? [] as $index => $option) {
                $element['options'][$index]['caption'] = $this->Translate($option['caption']);
            }
        }
        unset($element);
        return (string)json_encode($dialog);
    }

    /**
     * Nur für das Konfigurationsformular, Knopf „Geräte aus Alexa vorschlagen“: öffnet das
     * Vorschlagsfenster mit einem Haken je Zeile. Die Freigabeliste bleibt unberührt; die Vorschläge
     * liegen im Buffer, bis UIApplyAlexa die angehakten übernimmt. Gibt die Meldung zurück.
     */
    public function UIImportAlexa(int $AlexaID): string
    {
        if ($AlexaID <= 0 || !IPS_InstanceExists($AlexaID) || IPS_GetInstance($AlexaID)['ModuleInfo']['ModuleID'] !== AlexaImport::MODULE_ID) {
            return $this->Translate('Choose an Alexa instance.');
        }
        $config = json_decode(IPS_GetConfiguration($AlexaID), true);
        $info = static function (int $id): ?array {
            if (!IPS_VariableExists($id)) {
                return null;
            }
            // Das Profil verrät einen Rollladen, der nur Fahrbefehle kennt (~ShutterMoveStop).
            $variable = IPS_GetVariable($id);
            $profile = $variable['VariableCustomProfile'] !== '' ? $variable['VariableCustomProfile'] : $variable['VariableProfile'];
            if ($profile === '') {
                $profile = (string)(IPS_GetVariablePresentation($id)['PROFILE'] ?? '');
            }
            return ['parent' => IPS_GetParent($id), 'path' => IPS_GetLocation($id), 'profile' => $profile];
        };
        $result = AlexaImport::propose(is_array($config) ? $config : [], $info, $this->deviceList(), $this->roomIndex(), fn (string $text): string => $this->Translate($text));
        $this->SetBuffer('AlexaProposals', (string)json_encode($result['rows']));
        // Etage und Alias der Räume aus dem Objektbaum, für die Raumliste beim Freigeben.
        $this->SetBuffer('AlexaRooms', (string)json_encode($result['rooms']));
        // Burkhard, 10.10.2026: „Übernehmen“ in der Liste hieß alles oder nichts; im Fenster wählt
        // der Anwender je Zeile.
        $this->UpdateFormField('AlexaProposals', 'values', (string)json_encode($this->proposalValues($result['rows'], true)));
        $this->UpdateFormField('AlexaSummary', 'caption', $result['message']);
        $this->UpdateFormField('AlexaPopup', 'visible', true);
        return $result['message'];
    }

    /**
     * Nur für das Vorschlagsfenster, Knopf „Ausgewählte freigeben“: hängt die angehakten Vorschläge
     * an die Freigabeliste an, speichert sie und lädt das Formular neu.
     *
     * @param string $Selected JSON-Liste der Nummern der angehakten Vorschläge
     */
    public function UIApplyAlexa(string $Selected): string
    {
        $rows = json_decode($this->GetBuffer('AlexaProposals'), true);
        if (!is_array($rows) || $rows === []) {
            return $this->Translate('Propose devices first.');
        }
        // Je angehakter Zeile die Nummer und der Raum aus dem Fenster; eine bloße Nummer behält den Raum.
        $selection = json_decode($Selected, true);
        $rooms = $this->roomIndex();
        $chosen = [];
        $newRooms = [];
        foreach (is_array($selection) ? $selection : [] as $item) {
            [$index, $room] = is_array($item) ? [$item[0] ?? null, $item[1] ?? null] : [$item, null];
            if (!is_int($index) || !isset($rows[$index])) {
                continue;
            }
            $row = $rows[$index];
            if (is_string($room)) {
                // Ein Alias wird zum Namen der Raumliste, sonst hielte der Selbsttest den Raum für unbekannt.
                $room = trim($room);
                $row['Room'] = $rooms->nameOf($room) ?? $room;
                if ($row['Room'] !== '' && $rooms->nameOf($row['Room']) === null) {
                    $newRooms[DeviceIndex::normalize($row['Room'])] ??= $row['Room'];
                }
            }
            $chosen[] = $row;
        }
        if ($chosen === []) {
            return $this->Translate('Nothing was selected.');
        }
        IPS_SetProperty($this->InstanceID, 'Devices', (string)json_encode(array_merge($this->deviceList(), $chosen)));
        if ($newRooms !== []) {
            $proposed = [];
            foreach (json_decode($this->GetBuffer('AlexaRooms'), true) ?: [] as $room) {
                $proposed[DeviceIndex::normalize((string)$room['Name'])] = $room;
            }
            $added = array_map(static fn (string $name): array => [
                'Name'    => $name,
                'Aliases' => (string)($proposed[DeviceIndex::normalize($name)]['Aliases'] ?? ''),
                'Floor'   => (string)($proposed[DeviceIndex::normalize($name)]['Floor'] ?? ''),
            ], array_values($newRooms));
            IPS_SetProperty($this->InstanceID, 'Rooms', (string)json_encode(array_merge($this->listProperty('Rooms'), $added)));
        }
        IPS_ApplyChanges($this->InstanceID);
        $this->SetBuffer('AlexaProposals', '');
        $this->UpdateFormField('AlexaPopup', 'visible', false);
        $this->ReloadForm();
        // Ein Popup-Knopf zeigt jede Ausgabe rot im Popup, außer sie beginnt mit „MESSAGE:“ (Doku
        // PopupAlert, buttons). Absagen bleiben rot im offenen Fenster, der Erfolg schließt es.
        return 'MESSAGE:' . ($newRooms === []
            ? sprintf($this->Translate('%d device(s) released.'), count($chosen))
            : sprintf($this->Translate('%d device(s) released, %d room(s) added to the room list.'), count($chosen), count($newRooms)));
    }

    /**
     * Nur für das Vorschlagsfenster, Knöpfe „Alle anhaken“ und „Keine anhaken“: setzt den Haken
     * jeder Zeile neu.
     */
    public function UISetAlexaTakes(bool $Take): void
    {
        $rows = json_decode($this->GetBuffer('AlexaProposals'), true);
        if (is_array($rows) && $rows !== []) {
            $this->UpdateFormField('AlexaProposals', 'values', (string)json_encode($this->proposalValues($rows, $Take)));
        }
    }

    /**
     * Zeilen des Vorschlagsfensters: Haken, Raum, Name, Typ in der Sprache der Anlage, Alias, Nummer.
     *
     * @param list<array<string, mixed>> $rows Vorschläge aus AlexaImport
     * @return list<array<string, mixed>>
     */
    private function proposalValues(array $rows, bool $take): array
    {
        $captions = $this->typeCaptions();
        $values = [];
        foreach ($rows as $index => $row) {
            $values[] = ['Take' => $take, 'Room' => $row['Room'], 'Name' => $row['Name'], 'Type' => $captions[$row['Type']] ?? $row['Type'], 'Aliases' => $row['Aliases'], 'Index' => $index];
        }
        return $values;
    }

    /**
     * Alle Elemente eines Formulars, auch die in aufklappbaren Bereichen (ExpansionPanel) und Zeilen.
     *
     * @param list<array<string, mixed>> $elements
     * @return list<array<string, mixed>>
     */
    private static function allElements(array $elements): array
    {
        $all = [];
        foreach ($elements as $element) {
            $all[] = $element;
            if (is_array($element['items'] ?? null)) {
                $all = array_merge($all, self::allElements($element['items']));
            }
        }
        return $all;
    }

    /** @return list<string> Namen der aufgeklappten Bereiche; vor dem Neuladen der Bibliothek fehlt das Attribut */
    private function expandedPanels(): array
    {
        $raw = $this->ReadAttributeString('ExpandedPanels');
        $list = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($list) ? array_values(array_intersect(self::PANELS, $list)) : [];
    }

    /** @return array<string, string> Gerätetyp => Beschriftung aus der Typauswahl der form.json, übersetzt */
    private function typeCaptions(): array
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        foreach (self::allElements($form['elements']) as $element) {
            if (($element['name'] ?? '') === 'Devices') {
                $options = array_column($element['columns'], 'edit', 'name')['Type']['options'];
                return array_map(fn (string $caption): string => $this->Translate($caption), array_column($options, 'caption', 'value'));
            }
        }
        return [];
    }

    /**
     * Nur für das Konfigurationsformular: ein Bereich wurde auf- oder zugeklappt. Die Konsole meldet
     * nur den Klick, deshalb wechselt der gemerkte Zustand jedes Mal.
     */
    public function UITogglePanel(string $Panel): void
    {
        if (!in_array($Panel, self::PANELS, true)) {
            return;
        }
        $expanded = $this->expandedPanels();
        $expanded = in_array($Panel, $expanded, true) ? array_values(array_diff($expanded, [$Panel])) : array_merge($expanded, [$Panel]);
        $this->WriteAttributeString('ExpandedPanels', (string)json_encode($expanded));
    }

    /**
     * Nur für das Konfigurationsformular, Knopf „Token erzeugen“: zeigt ein neues Zufallstoken an.
     * Es wird nicht gespeichert; der Anwender kopiert es in die Spalte Token eines Zugangs und in
     * die Einrichtung des Assistenten.
     */
    public function UIGenerateToken(): void
    {
        $this->UpdateFormField('GeneratedToken', 'value', bin2hex(random_bytes(32)));
        $this->UpdateFormField('GeneratedToken', 'visible', true);
    }

    /**
     * Nur für das Konfigurationsformular: passt den geöffneten Bearbeitungsdialog an, wenn dort
     * ein anderer Gerätetyp oder eine andere Variable gewählt wird. Die Felder folgen dem Typ, und
     * die Texte für die Spalten der Liste folgen den gewählten Variablen.
     */
    public function UIDeviceChanged(string $Type, int $StateVariableID, int $LevelVariableID, int $MovingVariableID = 0, int $PowerVariableID = 0, int $EnergyVariableID = 0, int $StopVariableID = 0, int $SetpointVariableID = 0): void
    {
        $type = array_key_exists($Type, DeviceIndex::TYPES) ? $Type : array_key_first(DeviceIndex::TYPES);
        foreach ($this->deviceDialogFields($type) as $name => $field) {
            $this->UpdateFormField($name, 'visible', $field['visible']);
            $this->UpdateFormField($name, 'caption', $field['caption']);
        }
        foreach ($this->deviceLabels(['Type' => $type, 'StateVariableID' => $StateVariableID, 'LevelVariableID' => $LevelVariableID, 'MovingVariableID' => $MovingVariableID, 'PowerVariableID' => $PowerVariableID, 'EnergyVariableID' => $EnergyVariableID, 'StopVariableID' => $StopVariableID, 'SetpointVariableID' => $SetpointVariableID]) as $name => $label) {
            $this->UpdateFormField($name, 'value', $label);
        }
    }

    /** Adresse des Endpunkts relativ zum Webserver, z. B. /hook/homeagent/12345. */
    public function GetEndpoint(): string
    {
        return '/hook/' . $this->hookPath();
    }

    protected function ProcessHookData(): void
    {
        $ready = $this->GetStatus() === IS_ACTIVE;
        // Nicht bereit ist kein Anmeldefehler: Bei 401 sucht ein Client wie HA nach OAuth, und der
        // Anwender nach einem falschen Token. Einzelheiten stehen im Log, nicht in der Antwort.
        if (!$ready) {
            $this->SendDebug('Hook', 'rejected: endpoint not ready (status ' . $this->GetStatus() . ')', 0);
            $this->send(['status' => 503, 'headers' => ['Retry-After' => '60'] + McpProtocol::BASE_HEADERS, 'body' => (string)json_encode(['error' => 'Endpoint not ready, check the configuration of the Symcon instance'])]);
            return;
        }
        // Gesperrt wird vor der Anmeldung: Auch ein richtiges Token kommt dann nicht durch, sonst
        // ginge das Raten weiter und ein Treffer wäre an der Antwort zu erkennen.
        $blocked = SignInGuard::blockedFor($this->signInState(), time());
        if ($blocked > 0) {
            $this->SendDebug('Hook', 'rejected: endpoint blocked for ' . $blocked . ' s after too many rejected sign-ins', 0);
            $this->send(['status' => 429, 'headers' => ['Retry-After' => (string)$blocked] + McpProtocol::BASE_HEADERS, 'body' => (string)json_encode(['error' => 'Too many rejected sign-ins, retry after ' . $blocked . ' s'])]);
            return;
        }
        // Die Anmeldung bestimmt den Zugang, und der Zugang bestimmt Werkzeugliste, Standardraum und
        // Anleitung. Die Adresse geht nie ins Debug oder Log: Bei Zugängen mit Token im Pfad trägt
        // sie das Token.
        $accesses = $this->accessList();
        $pathToken = McpProtocol::tokenFromPath($_SERVER['REQUEST_URI'] ?? '', $this->GetEndpoint());
        $index = McpProtocol::findAccess($_SERVER['HTTP_AUTHORIZATION'] ?? '', $pathToken, $accesses);
        $access = $index === null ? null : $accesses[$index];
        $response = McpProtocol::handleHttp(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $access !== null,
            static fn (): string => (string)file_get_contents('php://input'),
            fn (): array => $this->tools($access),
            fn (string $name, array $arguments): array => $this->callTool($name, $arguments, $access),
            $this->serverInfo($access)
        );

        if ($access === null) {
            $this->SendDebug('Hook', 'rejected: authorization missing or wrong', 0);
            $this->recordSignInFailure((string)($_SERVER['REMOTE_ADDR'] ?? '?'));
        } else {
            $this->SendDebug('Request', ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . implode(', ', $response['methods'] ?? []) . ' → HTTP ' . $response['status'] . ' (' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ', access ' . $access['Name'] . ')', 0);
        }
        $this->send($response);
    }

    /** @param array{status: int, headers: array<string, string>, body: string} $response */
    private function send(array $response): void
    {
        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $response['body'];
    }

    /**
     * Werkzeugliste für tools/list. Namen, Parameter und Beschreibung bleiben englisch, wie bei
     * MCP üblich; übersetzt werden nur die Antworttexte der Werkzeuge.
     *
     * @return list<array<string, mixed>>
     */
    private function tools(?array $access = null): array
    {
        // Der Standardraum des Zugangs und die Etagen der Raumliste stehen in den Beschreibungen,
        // damit der Assistent sie ohne eigenen Aufruf kennt. Ohne Etagen gibt es den Parameter nicht.
        $defaultRoom = (string)($access['DefaultRoom'] ?? '');
        $readOnly = ($access['ReadOnly'] ?? false) === true;
        $floors = $this->roomIndex()->floors();
        $floor = $floors === [] ? [] : ['floor' => ['type' => 'string', 'description' => 'Floor, for all rooms of a floor. Known floors: ' . implode(', ', $floors) . '.']];
        $defaultRule = $defaultRoom === '' ? '' : 'Without room, name and floor the room ' . $defaultRoom . ' is meant; "all" means the whole house.';
        // HA Assist am nuc (10.10.2026): „Rollladen im Bad oben“ wurde zu room=Bad, weil das Modell
        // die Raumnamen nicht kannte. Räume sind Vokabular wie die Etagen.
        $knownRooms = array_values(array_unique(array_filter(array_merge(
            array_map(static fn (array $row): string => trim((string)($row['Room'] ?? '')), $this->deviceList()),
            $this->roomIndex()->names()
        ), static fn (string $room): bool => $room !== '')));
        sort($knownRooms, SORT_STRING | SORT_FLAG_CASE);
        $roomsText = $knownRooms === [] ? '' : 'Known rooms: ' . implode(', ', $knownRooms) . '. ';
        // Verbrauch über Zeiträume nur, wenn ein freigegebenes Gerät einen Zähler hat oder die
        // Energiebilanz eingetragen ist. Ohne Bilanz muss die Beschreibung die Grenze nennen: HA Assist
        // gab die Summe der Geräte sonst als Hausverbrauch aus (09.10.2026).
        $hasMeters = array_any($this->deviceList(), static fn (array $row): bool => isset(DeviceIndex::rolesOf($row)['energy']));
        $hasBalance = $this->balance() !== [];
        // Die Leistung jetzt (period=now) mit einem Gerät mit Leistung oder der Leistungsbilanz.
        $hasPower = $this->powerBalance() !== [] || array_any($this->deviceList(), static fn (array $row): bool => isset(DeviceIndex::rolesOf($row)['power']));
        // Verlauf von Messwerten (Temperatur, Feuchte …) über dieselben Zeiträume.
        $hasHistory = array_any($this->deviceList(), static fn (array $row): bool => DeviceIndex::isMeasured($row));
        $consumption = $hasMeters || $hasBalance
            ? 'Energy consumption over this period instead of the state, in kWh; the first line gives the total. '
                . ($hasBalance
                    ? 'For the whole house (no room, type or name) the first line gives the house consumption from grid, solar and battery.'
                    : 'Covers only devices with their own energy meter, not the whole house.')
            : '';
        $period = $hasMeters || $hasBalance || $hasPower || $hasHistory ? ['period' => ['type' => 'string', 'enum' => array_merge($hasPower ? [DeviceIndex::NOW] : [], DeviceIndex::PERIODS), 'description' => trim($consumption
            . ($hasPower ? ' "now" gives the current power in W instead.' : '')
            . ($hasHistory ? ' For measured values such as temperature or humidity: the history per hour, day or month (minimum / average / maximum).' : ''))]] : [];
        // Freie Zeiträume für Verbrauch und Verlauf; das heutige Datum braucht das Modell für „im Januar“.
        if ($hasMeters || $hasBalance || $hasHistory) {
            $period['from'] = ['type' => 'string', 'description' => 'Instead of period: first day, YYYY-MM-DD. Today is ' . date('Y-m-d') . '.'];
            $period['to'] = ['type' => 'string', 'description' => 'Last day, inclusive; without it only the day given in from.'];
        }
        $tools = [[
            'name'        => 'get_state',
            'title'       => 'Get state',
            'description' => 'Returns the current state of released devices, filtered by room, type and name (all optional). '
                . 'The first line gives the count, then one line per device: room | name | type | state. '
                . 'Example: "Is any light still on?" → type=light, active_only=true. "Are all windows closed?" → type=window, active_only=true. '
                . ($hasMeters ? '"How much did the washing machine use last month?" → name=washing machine, period=last_month. '
                    . '"How much did the washing machine use in January?" → name=washing machine, from=' . date('Y') . '-01-01, to=' . date('Y') . '-01-31. ' : '')
                . ($hasBalance ? '"How much electricity did we use yesterday?" → period=yesterday. ' : '')
                . ($hasPower ? '"How much power are we using right now?" → period=now. ' : '')
                . ($hasHistory ? '"How warm was it in the guest room over the last 30 days?" → room=Gast, type=temperature, from=' . date('Y-m-d', strtotime('-29 days')) . ', to=' . date('Y-m-d') . '. ' : '')
                . 'If nothing matches, the answer lists the known rooms and types.'
                . ($readOnly ? ' ' . self::READ_ONLY_NOTE : ''),
            'inputSchema' => [
                'type'                 => 'object',
                'properties'           => [
                    'room'        => ['type' => 'string', 'description' => 'Room as the user calls it, e.g. Küche. ' . $roomsText . ($defaultRule === '' ? 'Empty = all rooms.' : $defaultRule)],
                ] + $floor + [
                    'type'        => ['type' => 'string', 'enum' => array_keys(DeviceIndex::TYPES), 'description' => 'Device type. Empty = all types.'],
                    'name'        => ['type' => 'string', 'description' => 'Device name or alias, a partial word is enough.'],
                    'active_only' => ['type' => 'boolean', 'description' => 'Only devices that are on, open or report presence.'],
                ] + $period,
                'additionalProperties' => false,
            ],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ]];

        // Ein Nur-Lese-Zugang bekommt allein die Abfrage; alles Schreibende kennt er nicht.
        if (($access['ReadOnly'] ?? false) === true) {
            return $tools;
        }

        // Ein Werkzeug für alles, was schaltet oder stellt. Sein Schema nennt nur, was mindestens
        // ein freigegebenes Gerät kann: ohne Rollladen kein open und close, ohne Stufe kein percent,
        // ohne schaltbares Gerät gar kein Werkzeug. Ein neues Feld (Uhrzeit, Sollwert, Farbe) kommt
        // hier als weitere Eigenschaft dazu, sobald DeviceIndex::abilitiesOf() es kennt. Die
        // Einträge der Freigabeliste tragen den Stoppweg, den erst die Anlage kennt.
        $devices = $this->deviceIndex()->entries();
        $offered = DeviceIndex::offeredAbilities($devices);
        if ($offered !== []) {
            $commands = array_values(array_intersect(DeviceIndex::COMMANDS, $offered));
            $hasPercent = in_array('percent', $offered, true);
            $hasTemperature = in_array('temperature', $offered, true);
            $hasBlinds = in_array('open', $offered, true);
            $hasStop = in_array('stop', $offered, true);
            $hasTiltWindows = array_any($devices, static fn (array $row): bool => ($row['Type'] ?? '') === 'window' && ($row['Tilt'] ?? false) === true && DeviceIndex::abilitiesOf($row) !== []);
            $types = array_values(array_intersect(array_keys(DeviceIndex::TYPES), array_column(array_filter($devices, static fn (array $row): bool => DeviceIndex::abilitiesOf($row) !== []), 'Type')));
            $properties = [
                'room' => ['type' => 'string', 'description' => rtrim('Room as the user calls it, e.g. Küche. ' . $roomsText . $defaultRule)],
            ] + $floor + [
                'type' => ['type' => 'string', 'enum' => $types, 'description' => 'Device type, for groups such as all lights of a room.'],
                'name' => ['type' => 'string', 'description' => 'Name or alias of one single device, a partial word is enough.'],
            ];
            if ($commands !== []) {
                $properties['command'] = ['type' => 'string', 'enum' => $commands, 'description' => match (true) {
                    $hasStop   => 'on/off switch a device, open/close move a blind or window all the way, stop halts a moving blind or window.',
                    $hasBlinds => 'on/off switch a device, open/close move a blind or window all the way.',
                    default    => 'on/off switch a device.',
                }];
            }
            if ($hasPercent) {
                $properties['percent'] = ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => $hasBlinds ? 'Brightness of a light, or how far a blind or window is open (0 closed, 100 open).' : 'Brightness of a light, 0 switches it off.'];
            }
            if ($hasTemperature) {
                $properties['temperature'] = ['type' => 'number', 'description' => 'Target temperature in °C for a thermostat (heating setpoint).'];
            }
            // Was der Assistent angeben kann, in der Reihenfolge des Schemas.
            $fields = array_values(array_filter([$commands !== [] ? 'a command' : '', $hasPercent ? 'percent' : '', $hasTemperature ? 'temperature' : '']));
            $give = count($fields) > 1 ? implode(', ', array_slice($fields, 0, -1)) . ' or ' . array_last($fields) : $fields[0];
            $tools[] = [
                'name'        => 'set_state',
                'title'       => 'Switch or set',
                'description' => 'Switches or sets released devices: give ' . $give . '. '
                    . 'Give at least one of ' . ($floors === [] ? 'room, type and name' : 'room, floor, type and name') . '. A name must match exactly one device, otherwise nothing is changed and the answer lists the candidates; for groups use room and type. '
                    . 'Devices of a group that do not understand the command are left out. '
                    . 'The answer waits up to 2 seconds for the devices to confirm: first line the count, then one line per device: room | name | type | result. '
                    . ($hasBlinds ? 'Blinds are slow: "moving" or "command sent" is a normal answer, not a failure. ' : '')
                    // HA Assist am nuc (09.10.2026): „Kipp das Fenster“ lehnte das Modell sonst ab.
                    . ($hasTiltWindows ? 'Tilt windows open by tilting: "tilt the window" means command=open. ' : '')
                    . 'Example: "Lights off in the living room" → room=Wohnzimmer, type=light, command=off.'
                    . ($hasPercent ? ' "Dim the ceiling light to a third" → name=Deckenlicht, percent=33.' : '')
                    . ($hasTemperature ? ' "Set the heating in the guest room to 21 degrees" → room=Gast, temperature=21.' : ''),
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => $properties,
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ];
        }

        // Das Szenen-Werkzeug gibt es nur mit Szenen. Ihre Namen stehen in der Beschreibung, damit
        // der Assistent sie ohne eigenen Aufruf kennt.
        $scenes = $this->sceneIndex()->labels();
        if ($scenes !== []) {
            $shown = array_slice($scenes, 0, self::SCENES_IN_DESCRIPTION);
            $more = count($scenes) - count($shown);
            $tools[] = [
                'name'        => 'run_scene',
                'title'       => 'Start a scene',
                'description' => 'Starts one released scene, a prepared script of the home such as "movie night". '
                    . 'The name must match exactly one scene, otherwise nothing is started and the answer lists the candidates. '
                    . 'Without a name nothing is started and the answer lists all scenes. The answer confirms the start, not the effect. '
                    . 'Scenes: ' . implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '') . '.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'name' => ['type' => 'string', 'description' => 'Name or alias of the scene, a partial word is enough.'],
                        'room' => ['type' => 'string', 'description' => 'Room of the scene, only needed if several scenes share a name.'],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            ];
        }
        return $tools;
    }

    /**
     * Angaben für „initialize“. Ein Zugang mit Standardraum bekommt gesagt, wo er steht.
     *
     * @return array{name: string, title: string, version: string, instructions: string}
     */
    private function serverInfo(?array $access = null): array
    {
        $instructions = $this->Translate('You control a smart home by voice. Answer briefly and in the language of the user. Use the room names and types from the tool answers. Do not count yourself, the first line of every answer gives the count.');
        // HA Assist am nuc (09.10.2026): Nach „fährt“ meldete das Modell „ist jetzt ganz aufgemacht“.
        $instructions .= ' ' . $this->Translate('"moving" or "command sent" means the device has not arrived yet; then say that it is still moving.');
        // Claude-App (10.10.2026): Über den Nur-Lese-Zugang begründete Claude eine Absage mit einem
        // fehlenden Werkzeug statt mit dem Zugang.
        if (($access['ReadOnly'] ?? false) === true) {
            $instructions .= ' ' . $this->Translate(self::READ_ONLY_NOTE);
        }
        $defaultRoom = (string)($access['DefaultRoom'] ?? '');
        if ($defaultRoom !== '') {
            $instructions .= ' ' . sprintf($this->Translate('You are in room %s. Commands and questions without a room mean this room; use room=all for the whole house.'), $defaultRoom);
        }
        return [
            // Mit dem Namen der Instanz: HA zeigt den Servernamen in der Liste beim Agenten, und zwei
            // Instanzen hießen dort vorher beide „symcon-voicecontrol“.
            'name'         => 'Symcon ' . IPS_GetName($this->InstanceID),
            'title'        => $this->Translate('Symcon Home Agent'),
            'version'      => $this->version(),
            'instructions' => $instructions,
        ];
    }

    /**
     * @param null|array<string, mixed> $access Zugang, über den der Aufruf kam; null bei den Skriptfunktionen
     * @return array{text: string, isError: bool}
     */
    private function callTool(string $name, array $arguments, ?array $access = null): array
    {
        $readOnly = ($access['ReadOnly'] ?? false) === true;
        $defaultRoom = (string)($access['DefaultRoom'] ?? '');
        $outcome = match (true) {
            $name === 'get_state'                  => $this->deviceIndex($defaultRoom)->getState($arguments),
            $readOnly                              => ['text' => 'Unknown tool ' . $name, 'isError' => true],
            $name === 'set_state'                  => $this->deviceIndex($defaultRoom)->setState($arguments),
            $name === 'run_scene'                  => $this->sceneIndex()->runScene($arguments),
            default                                => ['text' => 'Unknown tool ' . $name, 'isError' => true],
        };
        return $this->logged($name, $arguments, $outcome, $access);
    }

    /**
     * Schreibt Aufruf und Antwort ins Debug der Instanz, für Werkzeuge wie für Skriptfunktionen.
     *
     * @param array{text: string, isError: bool} $outcome
     * @param null|array<string, mixed> $access
     * @return array{text: string, isError: bool}
     */
    private function logged(string $name, array $arguments, array $outcome, ?array $access = null): array
    {
        $this->SendDebug('Tool', ($access === null ? '' : '[' . $access['Name'] . '] ') . $name . ' ' . json_encode($arguments, JSON_UNESCAPED_UNICODE) . ' → ' . ($outcome['isError'] ? 'ERROR: ' : '') . str_replace("\n", ' ‖ ', $outcome['text']), 0);
        return $outcome;
    }

    private function deviceIndex(string $defaultRoom = ''): DeviceIndex
    {
        return new DeviceIndex($this->deviceList(), $this->backend(), $this->roomIndex(), $defaultRoom, $this->balance(), $this->powerBalance());
    }

    private function sceneIndex(): SceneIndex
    {
        return new SceneIndex($this->sceneList(), $this->backend(), $this->roomIndex());
    }

    private function roomIndex(): RoomIndex
    {
        return new RoomIndex($this->listProperty('Rooms'));
    }

    /**
     * Zähler der Energiebilanz, die eingetragen sind: Schlüssel aus DeviceIndex::BALANCE => Variable.
     *
     * @return array<string, int>
     */
    private function balance(): array
    {
        return $this->balanceOf(DeviceIndex::BALANCE);
    }

    /**
     * Leistungen der Energiebilanz, die eingetragen sind: Schlüssel aus DeviceIndex::BALANCE_POWER => Variable.
     *
     * @return array<string, int>
     */
    private function powerBalance(): array
    {
        return $this->balanceOf(DeviceIndex::BALANCE_POWER);
    }

    /**
     * @param array<string, string> $properties Schlüssel => Property
     * @return array<string, int>
     */
    private function balanceOf(array $properties): array
    {
        $balance = [];
        foreach ($properties as $key => $property) {
            // Vor dem Neuladen der Bibliothek gibt es die Property bei bestehenden Instanzen noch nicht.
            $id = (int)@$this->ReadPropertyInteger($property);
            if ($id > 1) {
                $balance[$key] = $id;
            }
        }
        return $balance;
    }

    /** Beschriftung einer Leistung der Energiebilanz in Selbsttest und Log, in der Sprache der Anlage. */
    private function powerCaption(string $key): string
    {
        return $this->Translate(match ($key) {
            'import'    => 'grid import power',
            'export'    => 'grid export power',
            'solar'     => 'solar power',
            'charge'    => 'battery charge power',
            default     => 'battery discharge power',
        });
    }

    /** Beschriftung eines Bilanzzählers in Selbsttest und Log, in der Sprache der Anlage. */
    private function balanceCaption(string $key): string
    {
        return $this->Translate(match ($key) {
            'import'    => 'grid import',
            'export'    => 'grid export',
            'solar'     => 'solar production',
            'charge'    => 'battery charge',
            default     => 'battery discharge',
        });
    }

    /** @return list<array<string, mixed>> */
    private function deviceList(): array
    {
        return $this->listProperty('Devices');
    }

    /**
     * Zugänge in fester Form: je Assistent ein Token, dazu Standardraum, „nur lesen“ und ob das
     * Token auch in der Adresse stehen darf.
     *
     * @return list<array{Name: string, Token: string, DefaultRoom: string, ReadOnly: bool, InPath: bool}>
     */
    private function accessList(): array
    {
        return array_map(static fn (array $row): array => [
            'Name'        => trim((string)($row['Name'] ?? '')),
            // Beim Einfügen mitkopierte Leerzeichen am Rand gehören nicht zum Token
            'Token'       => trim((string)($row['Token'] ?? '')),
            'DefaultRoom' => trim((string)($row['DefaultRoom'] ?? '')),
            'ReadOnly'    => (bool)($row['ReadOnly'] ?? false),
            'InPath'      => (bool)($row['InPath'] ?? false),
        ], $this->listProperty('Accesses'));
    }

    private function hasAccessWithToken(): bool
    {
        return array_any(array_column($this->accessList(), 'Token'), static fn (string $token): bool => $token !== '');
    }

    /** @return list<array<string, mixed>> Zeilen einer Listen-Property */
    private function listProperty(string $name): array
    {
        // Vor dem Neuladen der Bibliothek gibt es eine neue Property bei bestehenden Instanzen noch nicht.
        $list = json_decode((string)@$this->ReadPropertyString($name), true);
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    private function sceneList(): array
    {
        return $this->listProperty('Scenes');
    }

    private function backend(): SymconDeviceBackend
    {
        return new SymconDeviceBackend(
            fn (string $text): string => $this->Translate($text),
            $this->InstanceID,
            fn (string $key): ?string => $this->readFailure($key),
            fn (int $variable): ?array => $this->readTravel($variable),
            fn (int $variable, ?array $travel) => $this->storeTravel($variable, $travel)
        );
    }

    /**
     * Gemerkte Fahrt eines Rollladens. Der Buffer überlebt die einzelne Anfrage: Ein späteres
     * get_state soll das Ziel nennen, solange der Rollladen fährt.
     *
     * @return null|array{target: int|float, since: int}
     */
    private function readTravel(int $variable): ?array
    {
        $travels = json_decode((string)$this->GetBuffer('Travels'), true);
        $travel = is_array($travels) ? ($travels[(string)$variable] ?? null) : null;
        return is_array($travel) && isset($travel['target'], $travel['since']) ? ['target' => $travel['target'], 'since' => (int)$travel['since']] : null;
    }

    /** Schreibt oder löscht einen Fahrtmerker; alte Merker fallen dabei heraus, höchstens 50 bleiben. */
    private function storeTravel(int $variable, ?array $travel): void
    {
        $lock = 'HAGT_Travel_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return;
        }
        try {
            $travels = json_decode((string)$this->GetBuffer('Travels'), true);
            $limit = (int)round(microtime(true) * 1000) - self::TRAVEL_KEEP_MS;
            $travels = array_filter(is_array($travels) ? $travels : [], static fn (mixed $t): bool => is_array($t) && (int)($t['since'] ?? 0) > $limit);
            unset($travels[(string)$variable]);
            if ($travel !== null) {
                $travels[(string)$variable] = $travel;
            }
            $travels = array_slice($travels, -self::TRAVEL_MAX_ENTRIES, null, true);
            $this->SetBuffer('Travels', (string)json_encode($travels));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /** Merkt den Fehlschlag einer Aktion, bis das wartende Werkzeug ihn abholt. */
    private function storeFailure(string $key, string $error): void
    {
        $lock = 'HAGT_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return;
        }
        try {
            $failures = json_decode((string)$this->GetBuffer('ActionFailures'), true);
            $failures = array_filter(is_array($failures) ? $failures : [], static fn (array $f): bool => $f['time'] > time() - self::FAILURE_KEEP_SECONDS);
            $failures[$key] = ['error' => $error, 'time' => time()];
            $failures = array_slice($failures, -self::FAILURE_MAX_ENTRIES, null, true);
            $this->SetBuffer('ActionFailures', (string)json_encode($failures, JSON_INVALID_UTF8_SUBSTITUTE));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function signInState(): array
    {
        $state = json_decode((string)$this->GetBuffer('SignInFailures'), true);
        return is_array($state) ? $state : [];
    }

    /** Zählt einen abgewiesenen Anmeldeversuch; ins Log kommt höchstens zweimal je Minute etwas (SignInGuard). */
    private function recordSignInFailure(string $sender): void
    {
        $lock = 'HAGT_SignIn_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return;
        }
        try {
            $step = SignInGuard::failure($this->signInState(), time());
            $this->SetBuffer('SignInFailures', (string)json_encode($step['state']));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
        foreach ($step['events'] as $event) {
            $text = match ($event[0]) {
                'first'   => sprintf($this->Translate('Rejected sign-in at the endpoint (token missing or wrong, from %s).'), $sender),
                'summary' => sprintf($this->Translate('%d rejected sign-ins at the endpoint in the minute from %s.'), $event[1], date('H:i:s', $event[2])),
                'blocked' => sprintf($this->Translate('Too many rejected sign-ins, the endpoint is blocked for %d s.'), SignInGuard::BLOCK_SECONDS),
            };
            $this->LogMessage($text, KL_WARNING);
        }
    }

    private function readFailure(string $key): ?string
    {
        $failures = json_decode((string)$this->GetBuffer('ActionFailures'), true);
        return is_array($failures) && is_string($failures[$key]['error'] ?? null) ? $failures[$key]['error'] : null;
    }

    /** @return list<string> Klartext je fehlerhaftem Eintrag */
    /** @return list<string> Klartext je Fehler der Listen, die Zeile über ihren Namen benannt */
    private function validateDeviceList(): array
    {
        return array_column($this->validateLists(), 'text');
    }

    /**
     * Prüft Zugänge, Räume, Geräte und Szenen. Die Zeile wird über ihren Namen benannt, nicht über
     * ihre Nummer: Die Listen lassen sich in der Konsole nach Spalten sortieren.
     *
     * @return list<array{list: string, index: int, text: string}> index = Zeile in der gespeicherten Liste
     */
    private function validateLists(): array
    {
        $list = $this->deviceList();
        $rooms = $this->roomIndex();
        $errors = [];
        $add = function (string $listName, int $index, string $row, string $problem) use (&$errors): void {
            $errors[] = ['list' => $listName, 'index' => $index, 'text' => $row . ': ' . $problem];
        };

        $tokens = [];
        foreach ($this->accessList() as $index => $access) {
            $row = $this->rowLabel('Access', $access['Name']);
            if ($access['Name'] === '') {
                $add('Accesses', $index, $row, $this->Translate('name is missing'));
            }
            if ($access['Token'] === '') {
                $add('Accesses', $index, $row, $this->Translate('token is missing'));
            } elseif (strlen($access['Token']) < self::TOKEN_MIN_LENGTH) {
                $add('Accesses', $index, $row, sprintf($this->Translate('token is shorter than %d characters'), self::TOKEN_MIN_LENGTH));
            } elseif (in_array($access['Token'], $tokens, true)) {
                $add('Accesses', $index, $row, $this->Translate('token is used twice'));
            }
            $tokens[] = $access['Token'];
            // Der Standardraum bleibt freier Text; ein Tippfehler würde ihn still wirkungslos machen.
            $known = $rooms->isKnown($access['DefaultRoom'])
                || array_any($list, static fn (array $entry): bool => DeviceIndex::normalize((string)($entry['Room'] ?? '')) === DeviceIndex::normalize($access['DefaultRoom']));
            if ($access['DefaultRoom'] !== '' && !$known) {
                $add('Accesses', $index, $row, sprintf($this->Translate('default room "%s" is neither in the room list nor the room of a device'), $access['DefaultRoom']));
            }
        }

        $seen = [];
        foreach ($this->listProperty('Rooms') as $index => $room) {
            $row = $this->rowLabel('Room', trim((string)($room['Name'] ?? '')));
            $key = DeviceIndex::normalize((string)($room['Name'] ?? ''));
            if ($key === '') {
                $add('Rooms', $index, $row, $this->Translate('name is missing'));
            } elseif (isset($seen[$key])) {
                $add('Rooms', $index, $row, $this->Translate('name is used twice'));
            }
            $seen[$key] = true;
        }

        foreach ($list as $index => $entry) {
            $row = $this->rowLabel('Device', trim((string)($entry['Name'] ?? '')), trim((string)($entry['Room'] ?? '')));
            if (trim((string)($entry['Name'] ?? '')) === '') {
                $add('Devices', $index, $row, $this->Translate('name is missing'));
            }
            $type = (string)($entry['Type'] ?? '');
            if (!array_key_exists($type, DeviceIndex::TYPES)) {
                $add('Devices', $index, $row, $this->Translate('unknown type'));
                continue;
            }
            // Geprüft werden nur die Fähigkeiten des Typs; was nach einem Typwechsel sonst noch in der Zeile steht, zählt nicht.
            $captions = $this->roleCaptions($type);
            $roles = DeviceIndex::rolesOf($entry);
            $required = DeviceIndex::TYPE_ROLES[$type]['required'];
            if (array_intersect($required, array_keys($roles)) === []) {
                $add('Devices', $index, $row, sprintf($this->Translate('no variable selected for %s'), implode(' / ', array_map(static fn (string $role): string => $captions[$role], $required))));
            }
            foreach ($roles as $role => $variableId) {
                if (!IPS_VariableExists($variableId)) {
                    $add('Devices', $index, $row, sprintf($this->Translate('variable for %s does not exist'), $captions[$role]));
                }
            }
        }
        foreach ($this->balance() as $key => $variableId) {
            if (!IPS_VariableExists($variableId)) {
                $errors[] = ['list' => 'Balance', 'index' => 0, 'text' => sprintf($this->Translate('Energy balance: variable for %s does not exist'), $this->balanceCaption($key))];
            }
        }
        foreach ($this->powerBalance() as $key => $variableId) {
            if (!IPS_VariableExists($variableId)) {
                $errors[] = ['list' => 'Balance', 'index' => 0, 'text' => sprintf($this->Translate('Energy balance: variable for %s does not exist'), $this->powerCaption($key))];
            }
        }
        foreach ($this->sceneList() as $index => $entry) {
            $row = $this->rowLabel('Scene', trim((string)($entry['Name'] ?? '')));
            if (trim((string)($entry['Name'] ?? '')) === '') {
                $add('Scenes', $index, $row, $this->Translate('name is missing'));
            }
            if (!IPS_ScriptExists((int)($entry['ScriptID'] ?? 0))) {
                $add('Scenes', $index, $row, $this->Translate('script does not exist'));
            }
        }
        return $errors;
    }

    /** „Zugang „Küche““, „Gerät „Rollo“ (Küche)“ bzw. „Zugang ohne Namen“, in der Sprache der Anlage. */
    private function rowLabel(string $kind, string $name, string $room = ''): string
    {
        $label = $name === '' ? $this->Translate($kind . ' without a name') : sprintf($this->Translate($kind . ' "%s"'), $name);
        return $room === '' ? $label : $label . ' (' . $room . ')';
    }

    /**
     * Beschriftung der Fähigkeiten je Gerätetyp, in der Sprache der Anlage.
     *
     * @return array<string, string> Fähigkeit => Beschriftung
     */
    private function roleCaptions(string $type): array
    {
        $captions = match (true) {
            $type === 'light'                                  => ['state' => $this->Translate('Switching state'), 'level' => $this->Translate('Brightness')],
            $type === 'blind'                                  => ['level' => $this->Translate('Position'), 'moving' => $this->Translate('Moving state'), 'stop' => $this->Translate('Stop')],
            $type === 'window'                                 => ['state' => $this->Translate('Contact'), 'level' => $this->Translate('Drive (opening)'), 'moving' => $this->Translate('Moving state'), 'stop' => $this->Translate('Stop')],
            $type === 'door'                                   => ['state' => $this->Translate('Contact')],
            $type === 'thermostat'                             => ['state' => $this->Translate('Current temperature'), 'setpoint' => $this->Translate('Setpoint')],
            in_array($type, ['presence', 'water_leak'], true)  => ['state' => $this->Translate('Detector')],
            DeviceIndex::TYPES[$type] === null                 => ['state' => $this->Translate('Measured value')],
            default                                            => ['state' => $this->Translate('Switching state')],
        };
        // Fähigkeiten, die der Typ nicht hat, bleiben im Dialog unsichtbar und behalten ihren allgemeinen Namen.
        return $captions + ['state' => $this->Translate('State or measured value'), 'level' => $this->Translate('Brightness/position'), 'moving' => $this->Translate('Moving state'),
            'stop' => $this->Translate('Stop'), 'power' => $this->Translate('Power'), 'energy' => $this->Translate('Energy meter (archived as counter)'), 'setpoint' => $this->Translate('Setpoint')];
    }

    /**
     * Texte für die Spalten der Geräteliste: je Fähigkeit der Name der gewählten Variable, sonst „-“.
     * Fähigkeiten, die der Typ nicht hat, zählen nicht, auch wenn die Zeile noch eine Variable trägt.
     *
     * @return array<string, string> Spaltenname (StateLabel, LevelLabel) => Text
     */
    private function deviceLabels(array $row): array
    {
        $roles = DeviceIndex::rolesOf($row);
        $labels = [];
        foreach (DeviceIndex::ROLES as $role => $key) {
            $variableId = $roles[$role] ?? 0;
            $label = '-';
            if ($variableId > 0 && IPS_VariableExists($variableId)) {
                $parent = IPS_GetParent($variableId);
                $label = IPS_GetName($variableId) . ($parent > 0 ? ' (' . IPS_GetLocation($parent) . ')' : '');
            } elseif ($variableId > 0) {
                $label = '#' . $variableId . ' ?';
            }
            $labels[str_replace('VariableID', 'Label', $key)] = $label;
        }
        return $labels;
    }

    /**
     * Felder des Bearbeitungsdialogs, die vom Gerätetyp abhängen.
     *
     * @return array<string, array{visible: bool, caption: string}> Feldname => Sichtbarkeit und Beschriftung
     */
    private function deviceDialogFields(string $type): array
    {
        $captions = $this->roleCaptions($type);
        $fields = [];
        foreach (DeviceIndex::ROLES as $role => $key) {
            $fields[$key] = ['visible' => in_array($role, DeviceIndex::TYPE_ROLES[$type]['roles'], true), 'caption' => $captions[$role]];
        }
        // „Umgekehrt“ dreht bei Schaltern und Meldern den Wahrheitswert, beim Rollladen die Skala; ein Messwert hat nichts zu drehen.
        $invert = match (true) {
            $type === 'blind'                                 => $this->Translate('Reverse direction (if open and closed are swapped)'),
            in_array($type, ['window', 'door'], true)         => $this->Translate('Inverted (true = closed)'),
            in_array($type, ['presence', 'water_leak'], true) => $this->Translate('Inverted (true = nothing detected)'),
            DeviceIndex::TYPES[$type] === null                => null,
            default                                           => $this->Translate('Inverted (true = off)'),
        };
        $fields['Invert'] = ['visible' => $invert !== null, 'caption' => $invert ?? $this->Translate('Inverted')];
        // Ein Kippfenster heißt ganz offen „gekippt“.
        $fields['Tilt'] = ['visible' => $type === 'window', 'caption' => $this->Translate('Tilt window (fully open means tilted)')];
        // „Nur abfragen“ ergibt nur bei Typen Sinn, die sich sonst schalten ließen.
        $fields['ReadOnly'] = ['visible' => in_array($type, DeviceIndex::CONTROLLABLE, true) || $type === 'window', 'caption' => $this->Translate('Query only (the assistant must not switch this device)')];
        return $fields;
    }

    private function hookPath(): string
    {
        return 'homeagent/' . $this->InstanceID;
    }

    private function version(): string
    {
        $library = json_decode((string)file_get_contents(__DIR__ . '/../library.json'), true);
        return ($library['version'] ?? '?') . '.' . ($library['build'] ?? '?');
    }
}
