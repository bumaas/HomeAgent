<?php

declare(strict_types=1);

require_once __DIR__ . '/DeviceBackend.php';
require_once __DIR__ . '/RoomIndex.php';

/**
 * Freigabeliste des Home Agent: findet Geräte nach Raum, Typ und Name, beschreibt ihren
 * Zustand in kurzen Textzeilen und schaltet sie. Ohne Symcon-Abhängigkeit; Werte, Aktionen
 * und Übersetzungen kommen über das DeviceBackend, damit Tests mit echten Mitschnitten
 * arbeiten können.
 */
final class DeviceIndex
{
    /** Gerätetypen der Freigabeliste mit den Wörtern für aktiv/inaktiv (null = Messwert). */
    public const TYPES = [
        'light'       => ['on', 'off'],
        'switch'      => ['on', 'off'],
        'socket'      => ['on', 'off'],
        'appliance'   => ['on', 'off'],
        'blind'       => null,
        'thermostat'  => null,
        'media'       => ['on', 'off'],
        'humidifier'  => ['on', 'off'],
        'fan'         => ['on', 'off'],
        'window'      => ['open', 'closed'],
        'door'        => ['open', 'closed'],
        'presence'    => ['detected', 'not detected'],
        'water_leak'  => ['leak detected', 'no leak'],
        'temperature' => null,
        'humidity'    => null,
        'air_quality' => null,
        'measurement' => null,
        'meter'       => null,
    ];

    /** Nur diese Typen lassen sich schalten oder stellen; alle anderen sind Melder und Messwerte. */
    public const CONTROLLABLE = ['light', 'switch', 'socket', 'appliance', 'blind', 'thermostat', 'media', 'humidifier', 'fan'];

    /**
     * Fähigkeiten eines Geräts und der Schlüssel, unter dem ihre Variable in der Zeile steht.
     * „state“ ist der Zustand (an/aus, offen/zu, erkannt) oder bei Messwerten der Wert selbst,
     * „level“ die Helligkeit oder Position, „power“ die aktuelle Leistung, „energy“ der Zähler,
     * der im Archiv als Zähler aggregiert wird, „stop“ eine eigene Stoppvariable (ohne sie sucht
     * das Backend einen Weg über das Modul des Geräts), „setpoint“ der Sollwert eines Thermostats
     * (beim Thermostat ist „state“ die Ist-Temperatur). Eine neue Fähigkeit kostet einen Eintrag hier,
     * einen in TYPE_ROLES und ein Feld im Bearbeitungsdialog des Moduls.
     */
    public const ROLES = [
        'state'  => 'StateVariableID',
        'level'  => 'LevelVariableID',
        'moving' => 'MovingVariableID',
        'stop'   => 'StopVariableID',
        'power'  => 'PowerVariableID',
        'energy'   => 'EnergyVariableID',
        'setpoint' => 'SetpointVariableID',
        'colortemp' => 'ColorTempVariableID',
        'color'     => 'ColorVariableID',
        'playback'  => 'PlaybackVariableID',
        'volume'    => 'VolumeVariableID',
        'mute'      => 'MuteVariableID',
        'title'     => 'TitleVariableID',
        'artist'    => 'ArtistVariableID',
        'humidity'  => 'HumidityVariableID',
        'mode'      => 'ModeVariableID',
        'fan'       => 'FanVariableID',
    ];

    /**
     * Farben, die „set_state“ mit „color“ annimmt, als RGB (0xRRGGBB). Eine feste Liste wie bei Home
     * Assistant: Namen trifft ein Sprachmodell zuverlässiger als Zahlen (Entscheidung Burkhard, 10.10.2026).
     */
    public const COLORS = [
        'red'       => 0xFF0000,
        'orange'    => 0xFF8000,
        'yellow'    => 0xFFFF00,
        'green'     => 0x00FF00,
        'turquoise' => 0x00FFFF,
        'blue'      => 0x0000FF,
        'purple'    => 0x8000FF,
        'pink'      => 0xFF00FF,
        'white'     => 0xFFFFFF,
    ];

    /**
     * Werte einer Wiedergabevariable je Profil (an der Anlage gelesen, nuc 10.10.2026). Die Profile
     * ~Playback… nennt die Doku für die Darstellung Media Player; der eingebaute Media Player von Symcon
     * nutzt ~PlayerStatus und zählt anders. Ein anderes Profil gilt als unbekannt.
     */
    private const PLAYBACK_CODES = [
        '~Playback'                   => ['stop' => 1, 'play' => 2, 'pause' => 3],
        '~PlaybackNoStop'             => ['play' => 2, 'pause' => 3],
        '~PlaybackPreviousNext'       => ['previous' => 0, 'stop' => 1, 'play' => 2, 'pause' => 3, 'next' => 4],
        '~PlaybackPreviousNextNoStop' => ['previous' => 0, 'play' => 2, 'pause' => 3, 'next' => 4],
        '~PlayerStatus'               => ['previous' => 0, 'play' => 1, 'pause' => 2, 'stop' => 3, 'next' => 4],
    ];

    /**
     * Zustandswörter, die das HomeAssistant-Modul in seinen Statustext schreibt (die Zustände eines
     * media_player bei Home Assistant, am nuc gelesen: playing, paused): Wiedergabephase, aktiv, Wort.
     */
    private const MEDIA_STATES = [
        'playing'   => ['play', true, 'playing'],
        'paused'    => ['pause', false, 'paused'],
        'idle'      => ['stop', false, 'idle'],
        'buffering' => [null, true, 'buffering'],
        'standby'   => [null, false, 'standby'],
        'on'        => [null, true, 'on'],
        'off'       => [null, false, 'off'],
    ];

    /** Deutsche Namen der Farben in normalisierter Form, falls ein Assistent sie unübersetzt schickt. */
    private const COLOR_WORDS = [
        'rot' => 'red', 'gelb' => 'yellow', 'gruen' => 'green', 'tuerkis' => 'turquoise', 'blau' => 'blue',
        'lila' => 'purple', 'violett' => 'purple', 'rosa' => 'pink', 'weiss' => 'white',
    ];

    /**
     * Je Typ die Fähigkeiten, die ein Gerät haben kann („roles“), und die, von denen mindestens
     * eine gesetzt sein muss („required“). Was nicht zum Typ gehört, wird beim Einlesen verworfen.
     */
    public const TYPE_ROLES = [
        'light'       => ['roles' => ['state', 'level', 'power', 'energy', 'colortemp', 'color'], 'required' => ['state', 'level']],
        'switch'      => ['roles' => ['state', 'power', 'energy'], 'required' => ['state']],
        'socket'      => ['roles' => ['state', 'power', 'energy'], 'required' => ['state']],
        'appliance'   => ['roles' => ['state', 'power', 'energy'], 'required' => ['state', 'power']],
        'blind'       => ['roles' => ['level', 'moving', 'stop'], 'required' => ['level']],
        // Klimagerät und AV-Receiver nehmen Strom auf (B85): Leistung und Zähler wahlweise
        'thermostat'  => ['roles' => ['state', 'setpoint', 'mode', 'fan', 'power', 'energy'], 'required' => ['setpoint']],
        'media'       => ['roles' => ['state', 'playback', 'volume', 'mute', 'title', 'artist', 'power', 'energy'], 'required' => ['state', 'playback', 'volume']],
        'humidifier'  => ['roles' => ['state', 'humidity', 'setpoint', 'mode', 'fan', 'power', 'energy'], 'required' => ['state', 'setpoint']],
        'fan'         => ['roles' => ['state', 'mode', 'fan', 'power', 'energy'], 'required' => ['state', 'fan']],
        'window'      => ['roles' => ['state', 'level', 'moving', 'stop'], 'required' => ['state', 'level']],
        'door'        => ['roles' => ['state'], 'required' => ['state']],
        'presence'    => ['roles' => ['state'], 'required' => ['state']],
        'water_leak'  => ['roles' => ['state'], 'required' => ['state']],
        'temperature' => ['roles' => ['state'], 'required' => ['state']],
        'humidity'    => ['roles' => ['state'], 'required' => ['state']],
        'air_quality' => ['roles' => ['state'], 'required' => ['state']],
        'measurement' => ['roles' => ['state'], 'required' => ['state']],
        // Zähler (B82): Unterverteilung, Wallbox, Wohnungszähler. Zählt nie in einer Gerätesumme; was er misst, hängt über
        // „gemessen von“ (MeteredBy) an ihm. Nur Verbrauch, eine Erzeugung gehört in die Energiebilanz.
        'meter'       => ['roles' => ['energy', 'power'], 'required' => ['energy']],
    ];

    /**
     * Zähler der Energiebilanz und die Property, in der das Modul ihre Variable hält. Den
     * Hausverbrauch rechnet das Werkzeug daraus wie Home Assistant: Bezug + PV-Ertrag + Batterie
     * entladen − Einspeisung − Batterie geladen. Ohne PV und Batterie ist der Bezug der Hausverbrauch.
     */
    public const BALANCE = [
        'import'    => 'GridImportVariableID',
        'export'    => 'GridExportVariableID',
        'solar'     => 'SolarVariableID',
        'charge'    => 'BatteryChargeVariableID',
        'discharge' => 'BatteryDischargeVariableID',
    ];

    /**
     * Leistungen der Energiebilanz für „period=now“, gleiche Schlüssel und Rechnung wie BALANCE. Ein
     * einzelner Netzsensor mit Vorzeichen geht auch (negativ = Einspeisung), ebenso ein
     * Batteriesensor (negativ = laden), wie bei Home Assistant.
     */
    public const BALANCE_POWER = [
        'import'    => 'GridImportPowerVariableID',
        'export'    => 'GridExportPowerVariableID',
        'solar'     => 'SolarPowerVariableID',
        'charge'    => 'BatteryChargePowerVariableID',
        'discharge' => 'BatteryDischargePowerVariableID',
    ];

    /**
     * Einträge, die neben dem Wort für aktiv bzw. inaktiv nichts sagen und deshalb nicht in Klammern stehen. Ein
     * Eintrag des anderen Zustands bleibt stehen: „offen (Geschlossen)“ zeigt den fehlenden Haken „Umgekehrt“.
     */
    private const PLAIN_CAPTIONS = [['open', 'opened', 'offen', 'geöffnet', 'auf', 'on', 'an'], ['closed', 'geschlossen', 'zu', 'off', 'aus']];

    /** Sensoren mit Vorzeichen der Leistungsbilanz und das Feld, in das ihr negativer Wert fällt. */
    private const SIGNED_POWER = ['import' => 'export', 'discharge' => 'charge'];

    /** Zeitraum „jetzt“: die aktuelle Leistung statt des Verbrauchs. */
    public const NOW = 'now';

    /** Leistungseinheiten (Groß- und Kleinschreibung egal) und ihr Faktor zu W; ohne Einheit gilt W. */
    private const POWER_UNITS = ['' => 1.0, 'w' => 1.0, 'kw' => 1000.0, 'mw' => 1000000.0];

    /** Reihenfolge in der Antwort, Vorzeichen in der Rechnung und Wort in der Antwort. */
    private const BALANCE_TERMS = [
        'import'    => [1, 'grid import'],
        'solar'     => [1, 'solar'],
        'discharge' => [1, 'battery discharge'],
        'export'    => [-1, 'grid export'],
        'charge'    => [-1, 'battery charge'],
    ];

    /** Einheiten eines Energiezählers (Groß- und Kleinschreibung egal); ohne Einheit gilt kWh. */
    private const ENERGY_UNITS = ['', 'wh', 'kwh', 'mwh'];

    /** Typen, deren Zeitraum einen Verlauf statt eines Verbrauchs meint. */
    private const HISTORY_TYPES = ['temperature', 'humidity', 'air_quality', 'measurement', 'thermostat'];

    /** Höchstens so viele Messwerte je Verlauf, sonst wird die Antwort zu lang. */
    private const HISTORY_MAX = 5;

    /**
     * type=power (B84) ist kein Gerätetyp: mit Zeitraum der Verlauf der Leistung, sonst die Leistung jetzt. Ein Verlauf der
     * Leistung nennt je Gerät nur Minimum und Maximum (die Tabelle nur bei einem), deshalb mehr Geräte als beim Messwert.
     */
    public const POWER_TYPE = 'power';
    private const POWER_HISTORY_MAX = 20;

    /** Aggregationsstufe des Archivs (Doku AC_GetAggregatedValues) => Wort und Datumsformat je Zeile. */
    private const HISTORY_LEVELS = [0 => ['hour', 'Y-m-d H:i'], 1 => ['day', 'Y-m-d'], 3 => ['month', 'Y-m']];

    /** Fürs ganze Haus nennt die Antwort nur so viele Geräte, die größten zuerst; der Rest wird zusammengefasst. */
    private const TOP_DEVICES = 5;

    /** Zeitleiste (B69): so viele Abschnitte stehen einzeln da, sonst nur die Summen mit Anzahl. */
    private const TIMELINE_SECTIONS = 12;

    /** Abschnitte nur, wenn höchstens so viele Geräte eine Zeitleiste haben und der Zeitraum höchstens zwei Tage ist (Tokens). */
    private const TIMELINE_DETAIL = 5;

    /** Höchstens so viele Bänder in einem Diagramm. */
    private const TIMELINE_CHART_MAX = 10;

    /** Vergleich in show_chart (B69): der Zeitraum davor oder derselbe ein Jahr früher. */
    public const COMPARES = ['previous', 'year_before'];

    /** Teile der Energiebilanz für show_chart (part, B74) und ihr Zähler in der Bilanz. */
    public const PARTS = ['grid' => 'import', 'feed_in' => 'export', 'pv' => 'solar', 'battery_charge' => 'charge', 'battery_discharge' => 'discharge'];

    /** Zeiträume für den Verbrauch („period“ von get_state), in der Reihenfolge des Schemas. */
    public const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year'];

    /** Verben des Werkzeugs „set_state“, in der Reihenfolge, in der das Schema sie nennt. */
    public const COMMANDS = ['on', 'off', 'open', 'close', 'stop', 'play', 'pause', 'next', 'previous'];

    /** So lange wartet ein schaltendes Werkzeug höchstens auf die Rückmeldung der Geräte. */
    private const CONFIRM_TIMEOUT_MS = 2000;

    /** So lange setzt „play“ ohne Raum die per Werkzeug pausierten Mediaplayer fort (wie Home Assistant). */
    public const PAUSE_MEMORY_MS = 3600000;

    /** Längster Zustandstext je Antwortzeile, damit ein von außen gefüllter Text die Antwort nicht aufbläht. */
    private const VALUE_TEXT_LENGTH = 200;
    private const CONFIRM_STEP_MS = 50;

    /** So lange gilt das gemerkte Ziel einer Fahrt, wenn es nicht erreicht wird. */
    private const TRAVEL_KEEP_MS = 120000;

    /** Steht ein Rollladen mit Fahrstatus so lange, ist seine Fahrt vorbei (etwa am Wandtaster angehalten). */
    private const TRAVEL_STALE_MS = 3000;

    /** Texte eines Fahrstatus, die „steht“ bedeuten (Groß- und Kleinschreibung egal). */
    private const NOT_MOVING_TEXTS = ['', 'stop', 'stopped', 'idle'];

    /** Diese Raumangaben meinen ausdrücklich das ganze Haus und heben den Standardraum auf. */
    private const ALL_ROOMS = ['all', '*'];

    /** @var list<array{Name: string, Aliases: string, Room: string, Type: string, Invert: bool, ReadOnly: bool, roles: array<string, int>}> */
    private array $entries;

    private DeviceBackend $backend;

    private RoomIndex $rooms;

    /** Raum des Zugangs: gilt, wenn ein Aufruf weder Raum noch Name noch Etage nennt ('' = keiner). */
    private string $defaultRoom;

    /** @var array<string, int> Zähler der Energiebilanz: Schlüssel aus BALANCE => Variablen-ID */
    private array $balance;

    /** @var array<string, int> Leistungen der Energiebilanz: Schlüssel aus BALANCE_POWER => Variablen-ID */
    private array $powerBalance;

    /** @var list<string> Sensoren mit Vorzeichen, deren Vorzeichen umgekehrt gilt (B77): 'import', 'discharge' */
    private array $invertedPower = [];

    /**
     * Quellen der Bilanz (B82): Art => Schlüssel des ersten und des zweiten Zählers bzw. der Leistung
     * (Netz: Bezug und Einspeisung, Batterie: entladen und geladen, PV hat nur den ersten).
     */
    public const SOURCE_KEYS = ['grid' => ['import', 'export'], 'solar' => ['solar', null], 'battery' => ['discharge', 'charge']];

    /** @var array<string, list<array{variable: int, name: string}>> alle Zähler je Schlüssel der Bilanz, das Feld zuerst (Name '') */
    private array $balanceVars = [];

    /** @var array<string, list<array{variable: int, name: string, signed: ?bool, inverted: bool}>> alle Leistungen je Schlüssel; signed null = Feld der Bilanz */
    private array $powerVars = [];

    /** @var array<string, int> die Leistungsfelder der Bilanz ohne die Liste: ob ein Sensor ein Gegenfeld hat */
    private array $powerFields = [];

    /** Art des Problems der letzten Anfrage, null = alles erreicht (problem()). */
    private ?string $problem = null;

    /** Während chart(): hier sammelt history() die Daten fürs Diagramm statt der Tabellenzeilen. */
    private ?array $chart = null;

    /** Während controlDevice(): nur das Gerät mit diesem Kartenschlüssel ist zu finden. */
    private ?string $onlyKey = null;

    /** Hat blindText() zuletzt eine laufende Fahrt gemeldet? Für `moving` der Karte. */
    private bool $blindMoving = false;

    /** Höchstens so viele Karten je Aufruf; die übrigen nennt die Seite nur als Anzahl. */
    private const CARDS_MAX = 30;

    /**
     * Gruppe einer Karte je Typ (Gestaltung nach pitti, 13.10.2026). Die Reihenfolge von CARD_GROUP_LABELS ist die der
     * Überschriften auf der Seite.
     */
    private const CARD_GROUPS = [
        'blind' => 'cover', 'light' => 'light', 'switch' => 'power', 'socket' => 'power', 'appliance' => 'power',
        'thermostat' => 'climate', 'humidifier' => 'climate', 'fan' => 'climate', 'temperature' => 'climate',
        'humidity' => 'climate', 'air_quality' => 'climate', 'measurement' => 'measure', 'window' => 'opening',
        'door' => 'opening', 'presence' => 'sensor', 'water_leak' => 'sensor', 'media' => 'media', 'meter' => 'measure',
    ];
    private const CARD_GROUP_LABELS = [
        'cover' => 'Blinds', 'light' => 'Lights', 'power' => 'Switches and sockets', 'climate' => 'Climate',
        'measure' => 'Measurements', 'opening' => 'Windows and doors', 'sensor' => 'Sensors', 'media' => 'Media',
    ];

    /** Während chart() mit type=energy: Verbrauch je Gerät statt Verlauf bzw. Hausstapel. */
    private bool $chartByDevice = false;

    /** Während chart() mit cumulative=true: fester Raster (0 Stunde, 1 Tag) statt der Stufe aus dem Zeitraum (B73). */
    private ?int $forceLevel = null;

    /** Während chart() mit costs=true: der Hausverbrauch als Kosten in Euro (B72). */
    private bool $chartCosts = false;

    /** Während chart() mit part: nur dieser Zähler der Bilanz (Schlüssel aus PARTS) statt des Hausstapels (B74). */
    private string $chartPart = '';

    /** Teile der Bilanz je Abschnitt aus dem letzten Hausstapel; compareChart rechnet daraus die Ringe des Vergleichs. */
    private ?array $chartParts = null;

    /** Strompreis (Variable in ct/kWh oder €/kWh, archiviert) und Einspeisevergütung in ct/kWh; 0 = keine (B72). */
    private int $priceVariable = 0;
    private float $feedIn = 0.0;

    /** So viele Geräte bekommen im Stapeldiagramm einen eigenen Stapel, der Rest heißt „übrige Geräte“. */
    private const CHART_DEVICES = 5;

    /**
     * @param list<array<string, mixed>> $entries Zeilen der Formularliste „Devices“
     * @param null|RoomIndex $rooms Raumliste mit Aliasen und Etagen
     * @param string $defaultRoom Standardraum des Zugangs, über den die Anfrage kam
     * @param array<string, int> $balance Zähler der Energiebilanz (Schlüssel aus BALANCE); IDs bis 1 gelten als nicht gesetzt
     * @param array<string, int> $powerBalance Leistungen der Energiebilanz (Schlüssel aus BALANCE_POWER)
     */
    public function __construct(array $entries, DeviceBackend $backend, ?RoomIndex $rooms = null, string $defaultRoom = '', array $balance = [], array $powerBalance = [])
    {
        $this->balance = array_filter(array_map('intval', array_intersect_key($balance, self::BALANCE)), static fn (int $id): bool => $id > 1);
        $this->powerBalance = array_filter(array_map('intval', array_intersect_key($powerBalance, self::BALANCE_POWER)), static fn (int $id): bool => $id > 1);
        $this->powerFields = $this->powerBalance;
        foreach ($this->balance as $key => $id) {
            $this->balanceVars[$key] = [['variable' => $id, 'name' => '']];
        }
        foreach ($this->powerBalance as $key => $id) {
            $this->powerVars[$key] = [['variable' => $id, 'name' => '', 'signed' => null, 'inverted' => false]];
        }
        $this->entries = array_map(static fn (array $e): array => [
            'Name'     => trim((string)($e['Name'] ?? '')),
            'Aliases'  => (string)($e['Aliases'] ?? ''),
            'Room'     => trim((string)($e['Room'] ?? '')),
            'Type'     => (string)($e['Type'] ?? 'measurement'),
            'Invert'   => (bool)($e['Invert'] ?? false),
            'ReadOnly' => (bool)($e['ReadOnly'] ?? false),
            'Tilt'     => (bool)($e['Tilt'] ?? false),
            'roles'    => self::rolesOf($e),
            // Zähler, an dem das Gerät hängt (B82): seine Energievariable, 0 = keiner
            'MeteredBy' => (int)($e['MeteredBy'] ?? 0),
        ], $entries);
        // Stelle in der Liste, damit ein Treffer aus find() seinen Knoten im Zählerbaum wiederfindet
        foreach ($this->entries as $index => &$entry) {
            $entry['Index'] = $index;
        }
        unset($entry);
        // Wie sich ein Rollladen oder Fenster anhalten lässt, weiß erst die Anlage.
        foreach ($this->entries as &$entry) {
            $entry['StopVia'] = self::moves($entry) && isset($entry['roles']['level'])
                ? $backend->stopMethod($entry['roles']['level'], $entry['roles']['stop'] ?? 0)
                : '';
        }
        unset($entry);
        $this->backend = $backend;
        $this->rooms = $rooms ?? new RoomIndex([]);
        $this->defaultRoom = trim($defaultRoom);
    }

    /**
     * Die Geräte, wie die Werkzeuge sie sehen: mit Fähigkeiten („roles“) und Stoppweg („StopVia“).
     * Daraus baut das Modul das Schema.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Fähigkeiten einer Zeile der Freigabeliste: Fähigkeit => Variablen-ID. Es zählt nur, was zum
     * Typ der Zeile gehört; IDs bis 1 bedeuten „nicht gesetzt“ (1 ist die leere Objektauswahl).
     *
     * @param array<string, mixed> $row
     * @return array<string, int>
     */
    public static function rolesOf(array $row): array
    {
        $roles = [];
        foreach (self::TYPE_ROLES[(string)($row['Type'] ?? 'measurement')]['roles'] ?? [] as $role) {
            $id = (int)($row[self::ROLES[$role]] ?? 0);
            if ($id > 1) {
                $roles[$role] = $id;
            }
        }
        return $roles;
    }

    /**
     * Werkzeug „get_state“: Zustand der passenden Geräte, erste Zeile mit der Anzahl.
     *
     * @return array{text: string, isError: bool}
     */
    public function getState(array $arguments): array
    {
        $room = trim((string)($arguments['room'] ?? ''));
        $floor = trim((string)($arguments['floor'] ?? ''));
        $type = trim((string)($arguments['type'] ?? ''));
        $name = trim((string)($arguments['name'] ?? ''));
        $activeOnly = (bool)($arguments['active_only'] ?? false);
        $period = trim((string)($arguments['period'] ?? ''));
        $fromText = trim((string)($arguments['from'] ?? ''));
        $toText = trim((string)($arguments['to'] ?? ''));

        // Freier Zeitraum: erster und letzter Tag, beide eingeschlossen; ohne „to“ nur der eine Tag. Mit Uhrzeit
        // (Burkhard 12.10.2026: „nicht stundengenau“) ganze Stunden: „from“ abgerundet, „to“ aufgerundet und das Ende,
        // ohne „to“ bis jetzt, höchstens 48 Stunden. $range = [Stufe, Beginn, Ende], $rangeLabel für die Kopfzeile.
        $range = null;
        $rangeLabel = '';
        $rangeLevel = 1;
        if ($fromText !== '' || $toText !== '') {
            if ($period !== '') {
                return $this->fail($this->t('Give either period or from and to, not both.'));
            }
            if ($fromText === '') {
                return $this->fail($this->t('Parameter "to" needs "from".'));
            }
            $first = self::parseMoment($fromText, false);
            $last = $toText === '' ? null : self::parseMoment($toText, true);
            if ($first === null || ($toText !== '' && $last === null)) {
                return $this->fail(sprintf($this->t('Parameter "%s" must be a date like 2026-01-31.'), $first === null ? 'from' : 'to'));
            }
            $now = $this->backend->timestamp();
            if (!$first['hourly'] && !($last['hourly'] ?? false)) {
                $lastDay = $last['time'] ?? $first['time'];
                if ($lastDay < $first['time']) {
                    return $this->fail($this->t('Parameter "to" must not be before "from".'));
                }
                $range = self::rangeBounds($first['time'], $lastDay, $now);
                $rangeLabel = ', from=' . date('Y-m-d', $first['time']) . ', to=' . date('Y-m-d', $lastDay);
                $rangeLevel = $this->chartLevel('', $range[1], $range[2]);
            } else {
                $start = $first['time'];
                // Ein Tag ohne Uhrzeit als Ende zählt ganz mit
                $end = $last === null ? null : ($last['hourly'] ? $last['time'] : (int)strtotime('+1 day', $last['time']));
                if ($end !== null && $end <= $start) {
                    return $this->fail($this->t('Parameter "to" must not be before "from".'));
                }
                if (($end ?? $now) - $start > 48 * 3600) {
                    return $this->fail($this->t('With a time of day the period may be at most 48 hours. Leave out the times for whole days.'));
                }
                $range = [0, $start, $end === null || $end > $now ? 0 : $end - 1];
                $rangeLabel = ', from=' . date('Y-m-d H:i', $start) . ($end === null ? '' : ', to=' . date('Y-m-d H:i', $end));
                $rangeLevel = 0;
            }
        }

        $powerMode = $type === self::POWER_TYPE;
        if ($type !== '' && !$powerMode && !array_key_exists($type, self::TYPES)) {
            return $this->fail(sprintf($this->t('Unknown type "%s". Allowed: %s.'), $type, implode(', ', array_keys(self::TYPES))));
        }
        if ($period !== '' && $period !== self::NOW && !in_array($period, self::PERIODS, true)) {
            return $this->fail(sprintf($this->t('Unknown period "%s". Allowed: %s.'), $period, implode(', ', self::PERIODS)));
        }
        if ($floor !== '' && !$this->rooms->knowsFloor($floor)) {
            return $this->fail($this->unknownFloor($floor));
        }

        $room = $this->effectiveRoom($room, $name, $floor);
        $filter = $this->describeFilter($room, $type, $name, $floor);
        $matches = $this->find($room, $powerMode ? '' : $type, $name, $floor);
        if ($powerMode) {
            return $this->powerState($matches, $room === '' && $floor === '' && $name === '', $filter, $range, $rangeLabel, $rangeLevel, $period);
        }
        // Ein Haus mit Energiebilanz braucht für den Hausverbrauch über einen Zeitraum keine freigegebenen Geräte (gefunden
        // am Mitschnitt nur mit Bilanzzählern, 13.10.2026)
        // Ebenso die Hausleistung jetzt mit einer Leistungsbilanz (B77, Test mit einer Bilanz ohne Geräte).
        $houseOnly = $room === '' && $floor === '' && $type === '' && $name === ''
            && (($this->balance !== [] && ($range !== null || ($period !== '' && $period !== self::NOW)))
                || ($this->powerBalance !== [] && $range === null && $period === self::NOW));
        if ($matches === [] && !$houseOnly) {
            $this->noMatch($room, $name);
            return $this->fail(sprintf($this->t('No released device matches %s.'), $filter) . ' ' . $this->knownRoomsAndTypes());
        }
        // Die Bilanz gilt nur fürs ganze Haus; nach Raum, Etage, Typ oder Name zählen die Geräte.
        $wholeHouse = $room === '' && $floor === '' && $type === '' && $name === '';
        // Ein Zeitraum für Messwerte meint ihren Verlauf (Claude-App, 10.10.2026: „Diagramm der
        // Temperaturen im Gästezimmer der letzten 30 Tage“). Wo Zähler sind und fürs ganze Haus bleibt es beim Verbrauch.
        if (($range !== null || ($period !== '' && $period !== self::NOW)) && !$wholeHouse
            && (($this->chart !== null && !$this->chartByDevice
                    && (array_any($matches, static fn (array $e): bool => self::isMeasured($e)) || !array_any($matches, static fn (array $e): bool => isset($e['roles']['energy']))))
                || in_array($type, self::HISTORY_TYPES, true)
                || (!array_any($matches, static fn (array $e): bool => isset($e['roles']['energy'])) && array_any($matches, fn (array $e): bool => self::isMeasured($e) || $this->isTimed($e))))) {
            if ($range !== null) {
                [, $from, $to] = $range;
                return $this->history($matches, $rangeLevel, $from, $to, $filter . $rangeLabel);
            }
            [, $from, $to] = self::periodBounds($period, $this->backend->timestamp());
            return $this->history($matches, $this->chartLevel($period, $from, $to), $from, $to, $filter . ', period=' . $period);
        }
        if ($range !== null) {
            return $this->consumption($matches, $range, $filter . $rangeLabel, $wholeHouse, $rangeLevel);
        }
        if ($period !== '') {
            if ($period === self::NOW) {
                return $this->power($matches, $filter . ', period=' . $period, $wholeHouse);
            }
            $bounds = self::periodBounds($period, $this->backend->timestamp());
            return $this->consumption($matches, $bounds, $filter . ', period=' . $period, $wholeHouse, $this->chartLevel($period, $bounds[1], $bounds[2]));
        }

        $lines = [];
        $active = 0;
        $checked = 0;
        $uncounted = [];
        $windows = 0;
        $driven = 0;
        foreach ($matches as $entry) {
            $state = $this->stateOf($entry);
            if ($state['active'] !== null) {
                $checked++;
                if ($state['active']) {
                    $active++;
                }
            } else {
                $uncounted[] = $entry['Type'];
            }
            if ($activeOnly && $state['active'] !== true) {
                continue;
            }
            // Fenster, die set_state fahren kann, sonst sehen alle Fensterzeilen gleich aus (Claude-App
            // 11.10.2026: „Welche Fenster kannst du öffnen?“ blieb unbeantwortet).
            if ($entry['Type'] === 'window') {
                $windows++;
            }
            if ($entry['Type'] === 'window' && in_array('open', self::abilitiesOf($entry), true)) {
                $driven++;
                $drive = $this->t('with drive');
                $state['text'] = str_ends_with($state['text'], ')') ? substr($state['text'], 0, -1) . ', ' . $drive . ')' : $state['text'] . ' (' . $drive . ')';
            }
            $lines[] = $this->line($entry, $state['text']);
        }

        if ($activeOnly) {
            $head = sprintf($this->t('%d of %d checked devices active (%s).'), $active, $checked, $filter);
            // Rollläden und Messwerte kennen kein „an“; sie still wegzulassen ließ offen, welche
            // Geräte gezählt wurden (Blindtest 08.10.2026). Die Typen bleiben wie die Filterangabe
            // unübersetzt, damit der Assistent mit ihnen weiterfragen kann.
            if ($uncounted !== []) {
                $lines[] = sprintf($this->t('%d device(s) without on/off were not counted (%s).'), count($uncounted), implode(', ', array_unique($uncounted)));
            }
        } else {
            $head = sprintf($this->t('%d device(s) found (%s).'), count($matches), $filter);
        }
        // Das Werkzeug zählt, nicht das Modell: Die Claude-App schrieb zweimal „fünf Fenster mit Antrieb“ und
        // zählte dann sechs auf (11.10.2026). Nur bei gemischten Treffern, sonst sagt die Zeile nichts Neues.
        if ($driven > 0 && $driven < $windows) {
            $lines[] = sprintf($this->t('%d of %d windows have a drive.'), $driven, $windows);
        }
        return ['text' => implode("\n", array_merge([$head], $lines)), 'isError' => false];
    }

    /**
     * Verbrauch der passenden Geräte über einen Zeitraum, aus den Aggregaten des Archivs. Die erste
     * Zeile nennt die Summe, damit der Assistent nicht selbst rechnet; Geräte ohne Zähler bleiben
     * außen vor und werden am Ende gezählt. Fürs ganze Haus steht davor der Hausverbrauch aus der
     * Energiebilanz, die Geräte sind ein Teil davon und werden nie dazuaddiert.
     *
     * @param list<array<string, mixed>> $matches
     * @param array{0: int, 1: int, 2: int} $bounds Stufe, Beginn, Ende (0 = bis jetzt)
     * @return array{text: string, isError: bool}
     */
    /**
     * Jede Zähler- bzw. Leistungsvariable nur einmal, unter dem ersten Gerät der Liste. Eine
     * Messsteckdose und das Gerät dahinter dürfen dieselbe Variable tragen (Luftentfeuchter im Keller,
     * 11.10.2026), ohne dass Raum oder Haus sie doppelt summieren. Wie beim Verlauf (history()).
     *
     * @param list<array<string, mixed>> $matches
     * @return list<array<string, mixed>>
     */
    private static function firstPerVariable(array $matches, string $role): array
    {
        $seen = [];
        return array_values(array_filter($matches, static function (array $entry) use ($role, &$seen): bool {
            $id = $entry['roles'][$role] ?? 0;
            if ($id === 0) {
                return true;
            }
            if (isset($seen[$id])) {
                return false;
            }
            return $seen[$id] = true;
        }));
    }

    /**
     * Fehler in „gemessen von“ je Zeile der Freigabeliste (B82). MeteredBy ist die Energievariable eines Zählers der
     * Liste; ein Verweis auf etwas anderes (unknown_meter) und ein Kreis über Zähler (cycle, auch auf sich selbst)
     * sperren die Zeile. Home Assistant übergeht solche Verweise still, dann stimmt der Rest „nicht erfasst“ nicht mehr.
     *
     * @param list<array<string, mixed>> $rows Zeilen der Formularliste „Devices“
     * @return array<int, string> Zeile => unknown_meter | cycle
     */
    public static function meterProblems(array $rows): array
    {
        $meters = [];
        foreach ($rows as $index => $row) {
            $energy = self::rolesOf($row)['energy'] ?? 0;
            if ((string)($row['Type'] ?? '') === 'meter' && $energy > 0) {
                $meters[$energy] = $index;
            }
        }
        $problems = [];
        foreach ($rows as $index => $row) {
            $parent = (int)($row['MeteredBy'] ?? 0);
            if ($parent <= 1) {
                continue;
            }
            if (!isset($meters[$parent])) {
                $problems[$index] = 'unknown_meter';
                continue;
            }
            // Der Kette nach oben folgen, bis sie endet oder wieder bei dieser Zeile ankommt
            $seen = [$index => true];
            $at = $meters[$parent];
            while (!isset($seen[$at])) {
                $seen[$at] = true;
                $next = (int)($rows[$at]['MeteredBy'] ?? 0);
                if (!isset($meters[$next])) {
                    break;
                }
                $at = $meters[$next];
            }
            if ($at === $index) {
                $problems[$index] = 'cycle';
            }
        }
        ksort($problems);
        return $problems;
    }

    /**
     * Zählerbaum für eine Rolle (energy oder power, B82): alle Zähler und jedes Gerät mit dieser Variable, jede Variable
     * einmal (wie firstPerVariable), über „gemessen von“ an ihren Zähler gehängt. Je Knoten der eigene Wert, die Summe der
     * direkten Kinder und der Wert, mit dem er oben zählt: der eigene, ohne ihn die Summe darunter (Haushaltszähler ohne
     * Leistung). Ein Verweis auf einen Zähler, den es nicht gibt (gesperrt), macht die Zeile zur obersten.
     *
     * @param callable(array<string, mixed>): (float|string|null) $valueOf Wert des Knotens, ein Text bei einem Fehler
     * @return array{own: array<int, float|string|null>, below: array<int, ?float>, count: array<int, int>, value: array<int, ?float>, top: list<int>, children: array<int, list<int>>}
     */
    private function meterTree(string $role, callable $valueOf): array
    {
        $meterOf = [];
        foreach ($this->entries as $index => $entry) {
            if ($entry['Type'] === 'meter' && isset($entry['roles']['energy'])) {
                $meterOf[$entry['roles']['energy']] = $index;
            }
        }
        $nodes = [];
        $seen = [];
        foreach ($this->entries as $index => $entry) {
            $id = $entry['roles'][$role] ?? 0;
            if ($entry['Type'] !== 'meter' && ($id === 0 || isset($seen[$id]))) {
                continue;
            }
            if ($id !== 0) {
                $seen[$id] = true;
            }
            $nodes[$index] = $entry;
        }
        $children = [];
        $top = [];
        foreach ($nodes as $index => $entry) {
            $parent = $meterOf[$entry['MeteredBy']] ?? null;
            if ($parent !== null && $parent !== $index && isset($nodes[$parent])) {
                $children[$parent][] = $index;
            } else {
                $top[] = $index;
            }
        }
        $own = [];
        foreach ($nodes as $index => $entry) {
            $own[$index] = isset($entry['roles'][$role]) ? $valueOf($entry) : null;
        }
        $value = [];
        $below = [];
        $count = [];
        $resolve = static function (int $index, array $path) use (&$resolve, &$value, &$below, &$count, $own, $children): ?float {
            if (array_key_exists($index, $value)) {
                return $value[$index];
            }
            // Ein Kreis sperrt die Zeilen schon in der Prüfung der Liste; hier nur, damit nichts endlos läuft
            if (isset($path[$index])) {
                return null;
            }
            $path[$index] = true;
            $sum = null;
            $valued = 0;
            foreach ($children[$index] ?? [] as $child) {
                $childValue = $resolve($child, $path);
                if ($childValue !== null) {
                    $sum = ($sum ?? 0.0) + $childValue;
                    $valued++;
                }
            }
            $below[$index] = $sum;
            $count[$index] = $valued;
            return $value[$index] = is_float($own[$index]) ? $own[$index] : $sum;
        };
        foreach (array_keys($nodes) as $index) {
            $resolve($index, []);
        }
        return ['own' => $own, 'below' => $below, 'count' => $count, 'value' => $value, 'top' => $top, 'children' => $children];
    }

    /**
     * Zeile eines Zählers: sein Wert, was darunter gemessen ist, und der Rest „nicht erfasst“, nur wenn er positiv ist.
     * null, wenn der Zähler diese Variable nicht hat (ein Zähler ohne Leistung bei period=now).
     *
     * @param callable(float): string $format
     */
    private function meterLine(array $meter, array $tree, callable $format): ?string
    {
        $own = $tree['own'][$meter['Index']] ?? null;
        if (!is_float($own)) {
            return is_string($own) ? $this->line($meter, $own) : null;
        }
        $below = $tree['below'][$meter['Index']] ?? null;
        if ($below === null) {
            return $this->line($meter, $format($own));
        }
        $count = $tree['count'][$meter['Index']];
        return $this->line($meter, $own - $below > 0
            ? sprintf($this->t('%s, of this %s measured below it by %d meter(s) or device(s), %s not metered'), $format($own), $format($below), $count, $format($own - $below))
            : sprintf($this->t('%s, of this %s measured below it by %d meter(s) or device(s)'), $format($own), $format($below), $count));
    }

    /** Gibt es in der Freigabeliste einen Zähler? Dann zählt fürs Haus die oberste Ebene (B82). */
    private function hasMeters(): bool
    {
        return array_any($this->entries, static fn (array $entry): bool => $entry['Type'] === 'meter');
    }

    /**
     * Satz „davon“ fürs ganze Haus mit Zählern: die oberste Ebene (Zähler und Geräte ohne „gemessen von“) und der Rest.
     *
     * @param callable(float): string $format
     */
    private function topLevelLine(array $tree, float $house, string $text, callable $format): ?string
    {
        $sum = 0.0;
        $meters = 0;
        $devices = 0;
        foreach ($tree['top'] as $index) {
            $value = $tree['value'][$index];
            if ($value === null) {
                continue;
            }
            $sum += $value;
            $this->entries[$index]['Type'] === 'meter' ? $meters++ : $devices++;
        }
        return $meters + $devices === 0 ? null : sprintf($this->t($text), $format($sum), $meters, $devices, $format(max(0.0, $house - $sum)));
    }

    /**
     * Für den Selbsttest (B82): Zähler, unter denen mehr gemessen ist als 105 % ihres eigenen Werts. Dann hängt ein Gerät
     * am falschen Zähler oder ein Zähler zählt in einer anderen Einheit.
     *
     * @return list<array{room: string, name: string, meter: float, below: float}> kWh im Zeitraum
     */
    public function meterOverruns(int $from, int $to): array
    {
        $tree = $this->meterTree('energy', function (array $entry) use ($from, $to): ?float {
            $counter = $this->backend->counterSum($entry['roles']['energy'], 1, $from, $to);
            return $counter !== null && self::isEnergyUnit($counter['unit']) ? self::kilowattHours($counter) : null;
        });
        $overruns = [];
        foreach ($this->entries as $index => $entry) {
            $own = $tree['own'][$index] ?? null;
            $below = $tree['below'][$index] ?? null;
            if ($entry['Type'] === 'meter' && is_float($own) && $below !== null && $below > 1.05 * $own) {
                $overruns[] = ['room' => $entry['Room'], 'name' => $entry['Name'], 'meter' => $own, 'below' => $below];
            }
        }
        return $overruns;
    }

    /** Ein Monat fällt auf, wenn das Archiv mehr zählt als der Zuwachs des Zählerstands: mindestens so viele kWh … */
    private const JUMP_KWH = 2.0;

    /**
     * … und mindestens dieser Anteil eines üblichen Monats (Median aller Monate). Gemessen am nuc: Die Kühltruhe lag im Juli
     * 2020 nach einer Rücksetzung 4,2 kWh über dem Zuwachs bei 23 kWh im Median, die Wallbox im Mai 2026 27,2 kWh bei 110 kWh.
     */
    private const JUMP_SHARE = 0.2;

    /** … und die Summe des Monats liegt um mindestens diesen Anteil der Abweichung über dem Median. */
    private const JUMP_ABOVE = 0.75;

    /** Ein Zählerstand unter so vielen üblichen Monaten gilt als neu angefangen (Rücksetzung), der Monat als ungeprüft. */
    private const RESTART_MONTHS = 2;

    /**
     * Für den Selbsttest (B78): Zähler der Bilanz und der Geräte, deren Archiv in einem Monat mehr zählt, als der
     * Zählerstand gestiegen ist. Das Archiv summiert nur positive Sprünge (Doku AC_GetAggregatedValues), ein Fehlwert nach
     * oben und zurück zählt also als Verbrauch, ebenso der Zuwachs nach einem fallenden Zählerstand. Anlass: ein ModBus-Fehlwert
     * in einer betreuten Anlage ergab in der Punktwolke 32,8 Mio. kWh. Erst eine Probe über das ganze Archiv (Summe der Jahre
     * gegen den Zuwachs seit dem ersten Satz), nur bei einer Abweichung die Monate. Jede Variable einmal, die der Bilanz zuerst.
     *
     * @return list<array{variable: int, key: string, label: string, months: array<int, float>}> key ist der Teil der Bilanz
     *         ('' bei Geräten), label der Name der Quelle bzw. Raum und Name des Geräts, months Monatsbeginn => kWh zu viel
     */
    public function counterJumps(): array
    {
        $counters = [];
        foreach ($this->balanceVars as $key => $vars) {
            foreach ($vars as $var) {
                $counters[$var['variable']] ??= [$key, $var['name']];
            }
        }
        foreach ($this->entries as $entry) {
            $id = $entry['roles']['energy'] ?? 0;
            if ($id > 0) {
                $counters[$id] ??= ['', trim($entry['Room'] . ' ' . $entry['Name'])];
            }
        }
        $jumps = [];
        foreach ($counters as $id => [$key, $label]) {
            $months = $this->counterExcess($id);
            if ($months !== []) {
                $jumps[] = ['variable' => $id, 'key' => $key, 'label' => $label, 'months' => $months];
            }
        }
        return $jumps;
    }

    /**
     * Monate, in denen das Archiv eines Zählers mehr zählt als der Zuwachs des Zählerstands, Monatsbeginn => kWh zu viel.
     * Das erste Aggregat trägt den Zeitpunkt des ersten Satzes (siehe counterRows), der Zählerstand davor ist also dieser
     * Satz selbst; deshalb hier die Reihe des Backends unverändert. Ein Monat ohne Zählerstand am Anfang (erster Satz 0 bei
     * „Nullen ignorieren“) bleibt ungeprüft.
     *
     * @return array<int, float>
     */
    private function counterExcess(int $variableId): array
    {
        $now = $this->backend->timestamp();
        $years = $this->backend->counterSeries($variableId, 4, 0, 0);
        if ($years === null || $years['rows'] === [] || !self::isEnergyUnit($years['unit'])) {
            return [];
        }
        $kwh = static fn (float $value): float => (float)self::kilowattHours(['sum' => $value, 'unit' => $years['unit']]);
        $first = (int)$years['rows'][0]['time'] + 1;
        $total = $this->backend->counterReadings($variableId, [$first, $now + 1]);
        $sum = array_sum(array_column($years['rows'], 'sum'));
        if ($total !== null && $total[$first] !== null && $total[$now + 1] !== null && $kwh($sum - ($total[$now + 1] - $total[$first])) <= self::JUMP_KWH) {
            return [];
        }
        $rows = $this->backend->counterSeries($variableId, 3, 0, 0)['rows'] ?? [];
        // Mit „Nullen ignorieren“ und einem ersten Satz 0 gibt es vor dem ersten Satz keinen Stand über 0; dann beginnt die
        // Probe mit dem zweiten Monat (den ersten prüft auch die Monatsprüfung nicht). Alter Einspeisezähler einer betreuten
        // Anlage: Seine Sommermonate liegen weit über dem Median, die Monatsprüfung kostete 1,9 s (18.10.2026).
        if ($total !== null && $total[$first] === null && $total[$now + 1] !== null && count($rows) > 1) {
            $second = (int)$rows[1]['time'];
            $start = $this->backend->counterReadings($variableId, [$second])[$second] ?? null;
            if ($start !== null && $kwh($sum - (float)$rows[0]['sum'] - ($total[$now + 1] - $start)) <= self::JUMP_KWH) {
                return [];
            }
        }
        $sums = array_map(static fn (array $row): float => $kwh((float)$row['sum']), $rows);
        $sorted = $sums;
        sort($sorted);
        $median = $sorted === [] ? 0.0 : $sorted[intdiv(count($sorted), 2)];
        // Auffallen kann nur ein Monat, dessen Summe um mehr als JUMP_ABOVE mal die Schwelle über dem Median liegt (folgt aus
        // den beiden Bedingungen unten). Nur dessen Grenzen werden abgelesen: In einer betreuten Anlage kostete jede Ablesung
        // bei einem Zähler, der alle paar Sekunden schreibt, bis zu 35 ms, der Selbsttest brauchte 8,2 s (18.10.2026).
        $threshold = max(self::JUMP_KWH, self::JUMP_SHARE * $median);
        $bounds = [];
        foreach ($rows as $i => $row) {
            if ($sums[$i] - $median > self::JUMP_ABOVE * $threshold) {
                $bounds[$i] = [$i === 0 ? (int)$row['time'] + 1 : (int)$row['time'], isset($rows[$i + 1]) ? (int)$rows[$i + 1]['time'] : $now + 1];
            }
        }
        if ($bounds === []) {
            return [];
        }
        $readings = $this->backend->counterReadings($variableId, array_values(array_unique(array_merge(...array_values($bounds))))) ?? [];
        $months = [];
        foreach ($rows as $i => $row) {
            if (!isset($bounds[$i])) {
                continue;
            }
            $start = $readings[$bounds[$i][0]] ?? null;
            $end = $readings[$bounds[$i][1]] ?? null;
            if ($start === null || $end === null) {
                continue;
            }
            $rise = $kwh($end - $start);
            $excess = $sums[$i] - $rise;
            // Ein Fehlwert hebt die Summe des Monats um etwa die Abweichung über einen üblichen Monat. Eine Rücksetzung
            // (Homematic ENERGY_COUNTER nach einem Stromausfall, Zählertausch) lässt sie normal: Das Archiv zählt dann richtig,
            // nur der Zählerstand fiel (nuc: Kühltruhe 34 Monate mit Rücksetzung, jeder mit 21 bis 27 kWh).
            // Ein Zählerstand unter zwei üblichen Monaten hat kurz vorher neu angefangen; auch dann zählt das Archiv richtig
            // (nuc: Steckdose Fernseher im August 2023, Rücksetzungen am 31.07. und 01.08. bei doppeltem Sommerverbrauch).
            $restarted = min($kwh($start), $kwh($end)) < self::RESTART_MONTHS * $median;
            if (!$restarted && $excess > $threshold && $sums[$i] - $median >= self::JUMP_ABOVE * $excess) {
                $months[self::sectionStart((int)$row['time'], 3)] = $excess;
            }
        }
        return $months;
    }

    /**
     * Ist das Gerät ein Messwert mit Verlauf? Temperatur, Feuchte, Luftqualität und sonstige Messwerte,
     * dazu die Ist-Temperatur eines Thermostats.
     *
     * @param array<string, mixed> $row Zeile der Freigabeliste
     */
    public static function isMeasured(array $row): bool
    {
        $roles = is_array($row['roles'] ?? null) ? $row['roles'] : self::rolesOf($row);
        return in_array((string)($row['Type'] ?? ''), self::HISTORY_TYPES, true) && isset($roles['state']);
    }

    /**
     * Hat das Gerät einen Zustand mit Wörtern (an/aus, offen/zu, erkannt …)? Dann liefert ein Zeitraum eine Zeitleiste
     * (B69). Ein Messwert mit Einträgen (Batteriemodus) zählt auch dazu, das weiß aber erst das Backend (isTimed()).
     *
     * @param array<string, mixed> $row Zeile der Freigabeliste
     */
    public static function hasTimeline(array $row): bool
    {
        $roles = is_array($row['roles'] ?? null) ? $row['roles'] : self::rolesOf($row);
        return isset($roles['state']) && (self::TYPES[(string)($row['Type'] ?? '')] ?? null) !== null;
    }

    private function isTimed(array $entry): bool
    {
        return self::hasTimeline($entry)
            || ($entry['Type'] === 'measurement' && isset($entry['roles']['state']) && $this->backend->options($entry['roles']['state']) !== null);
    }

    /**
     * Zeitleisten der Geräte, deren Zustand archiviert ist; die anderen fallen weg. Abschnitte gibt es nur bei höchstens
     * TIMELINE_DETAIL Geräten, sonst die Summen mit Anzahl.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array{entry: array<string, mixed>, summary: string, sections: ?string}>
     */
    private function archivedTimelines(array $entries, int $from, int $to): array
    {
        $items = [];
        foreach ($entries as $entry) {
            $timeline = $this->timelineOf($entry, $from, $to);
            if ($timeline !== null) {
                $items[] = ['entry' => $entry] + $timeline;
            }
        }
        if (count($items) > self::TIMELINE_DETAIL) {
            $items = array_map(static fn (array $item): array => ['summary' => $item['brief'], 'sections' => null] + $item, $items);
        }
        return $items;
    }

    /**
     * Bänder fürs Diagramm (show_chart): je Gerät alle Abschnitte, auch wenn der Text nur die Summen nennt. Die Werte gehen
     * nur an die Seite, nicht ans Modell.
     *
     * @param list<array<string, mixed>> $timed Ergebnis von archivedTimelines()
     */
    private function chartBands(array $timed): void
    {
        if ($this->chart === null) {
            return;
        }
        foreach ($timed as $item) {
            $this->chart['bands'][] = $item['band'];
        }
    }

    /**
     * Zeilen der Zeitleiste: Kopfzeile, je Gerät die Summen und, wenn es wenige sind, die Abschnitte.
     *
     * @param list<array{entry: array<string, mixed>, summary: string, sections: ?string}> $timed
     * @return list<string>
     */
    private function timelineLines(array $timed, string $filter): array
    {
        $lines = [sprintf($this->t('Timeline of %d device(s) (%s).'), count($timed), $filter)];
        foreach ($timed as $item) {
            $lines[] = $this->line($item['entry'], $item['summary']);
            if ($item['sections'] !== null) {
                $lines[] = $item['sections'];
            }
        }
        return $lines;
    }

    /**
     * Zeitleiste eines Zustands aus den Rohwerten des Archivs: Abschnitte vom Wert vor dem Zeitraum bis zum Ende bzw.
     * bis jetzt, gleiche Zustände zusammengefasst (der Bewegungsmelder schreibt aus und an in derselben Sekunde).
     * Summen je Zustand, bei Wörtern der aktive zuerst, sonst der längste. Einzeln stehen bei Wörtern nur die aktiven
     * Abschnitte („an 07:29-07:35, …“), bei einer Auswahl alle; mehr als TIMELINE_SECTIONS ergeben die Anzahl je
     * Zustand. „brief“ ist die Fassung ohne Abschnitte, für viele Geräte. null = nicht archiviert.
     *
     * „band“ trägt alle Abschnitte fürs Diagramm.
     *
     * @return null|array{summary: string, sections: ?string, brief: string, band: array<string, mixed>}
     */
    private function timelineOf(array $entry, int $from, int $to): ?array
    {
        $variable = $entry['roles']['state'];
        $series = $this->backend->stateSeries($variable, $from, $to);
        if ($series === null) {
            return null;
        }
        $now = $this->backend->timestamp();
        $untilNow = $to === 0 || $to >= $now;
        $end = $untilNow ? $now : $to + 1;
        $words = self::TYPES[$entry['Type']] ?? null;
        $options = $words === null ? ($this->backend->options($variable) ?? []) : [];
        $label = function (mixed $value) use ($words, $options, $entry): string {
            if ($words !== null) {
                $active = self::activeValue($entry, $value);
                return $active === null ? (string)$value : $this->t($active ? $words[0] : $words[1]);
            }
            foreach ($options as [$optionValue, $caption]) {
                if ((string)$optionValue === (string)$value) {
                    return $caption;
                }
            }
            return is_int($value) || is_float($value) ? self::formatNumber((float)$value) : (string)$value;
        };
        $sections = [];
        $add = static function (string $state, int $begin, int $finish) use (&$sections): void {
            $last = count($sections) - 1;
            if ($last >= 0 && $sections[$last][0] === $state) {
                $sections[$last][2] = $finish;
            } else {
                $sections[] = [$state, $begin, $finish];
            }
        };
        $known = $series['start'] !== null;
        $value = $series['start'];
        $begin = $from;
        foreach ($series['changes'] as [$time, $next]) {
            if ($known && $time > $begin) {
                $add($label($value), $begin, $time);
            }
            $value = $next;
            $known = true;
            $begin = max($begin, $time);
        }
        if ($known && $end > $begin) {
            $add($label($value), $begin, $end);
        }
        if ($sections === []) {
            return ['summary' => $this->t('no values in this period'), 'sections' => null, 'brief' => $this->t('no values in this period')];
        }

        $active = $words === null ? null : $this->t($words[0]);
        $sums = [];
        $counts = [];
        foreach ($sections as [$state, $b, $e]) {
            $sums[$state] = ($sums[$state] ?? 0) + $e - $b;
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }
        // Fürs Band: bei Wörtern der aktive Zustand zuerst, bei einer Auswahl die Reihenfolge der Einträge, damit die Farben
        // jeden Tag dieselben sind.
        $present = array_keys($sums);
        $labels = $words !== null ? [$this->t($words[0]), $this->t($words[1])] : array_column($options, 1);
        $states = array_values(array_unique(array_merge(array_values(array_intersect($labels, $present)), $present)));
        $index = array_flip($states);
        $band = [
            'room'     => $entry['Room'],
            'name'     => $entry['Name'],
            'from'     => $from,
            'to'       => $end,
            'states'   => array_map(fn (string $state): array => ['label' => $state, 'active' => $words === null ? null : $state === $this->t($words[0])], $states),
            'sections' => array_map(static fn (array $sec): array => [$index[$sec[0]], $sec[1], $sec[2]], $sections),
        ];
        // Bei Wörtern der aktive Zustand zuerst, sonst der längste; gleich lange in der Reihenfolge des Auftretens.
        $order = array_keys($sums);
        usort($order, static fn (string $x, string $y): int => [$y === $active, $sums[$y]] <=> [$x === $active, $sums[$x]]);
        $listed = $active === null ? $sections : array_values(array_filter($sections, static fn (array $sec): bool => $sec[0] === $active));
        $show = count($sections) > 1 && $end - $from <= 2 * 86400 && count($listed) <= self::TIMELINE_SECTIONS && $listed !== [];
        $summarize = fn (bool $withCount): string => implode(', ', array_map(fn (string $state): string => $state . ' ' . self::duration($sums[$state])
            . ($withCount ? ' (' . sprintf($this->t('%d times'), $counts[$state]) . ')' : ''), $order));
        $brief = $summarize(count($sections) > 1 && $listed !== []);
        if (!$show) {
            return ['summary' => $brief, 'sections' => null, 'brief' => $brief, 'band' => $band];
        }
        $format = date('Y-m-d', $from) === date('Y-m-d', $end - 1) ? 'H:i' : 'd.m. H:i';
        $parts = [];
        foreach ($listed as [$state, $b, $e]) {
            $start = date($format, $b);
            if ($untilNow && $e === $end) {
                $span = sprintf($this->t('since %s'), $start);
            } else {
                $stop = $format === 'H:i' && date('H:i', $e) === '00:00' && $e > $b ? '24:00' : date($format, $e);
                $span = $stop === $start ? $start : $start . '-' . $stop;
            }
            $parts[] = ($active === null ? $state . ' ' : '') . $span;
        }
        return ['summary' => $summarize(false), 'sections' => ($active === null ? '' : $active . ' ') . implode(', ', $parts), 'brief' => $brief, 'band' => $band];
    }

    /** Dauer in Stunden und Minuten: „3 h 10 min“, „24 h“, „6 min“, unter einer halben Minute „< 1 min“. */
    private static function duration(int $seconds): string
    {
        $minutes = (int)round($seconds / 60);
        if ($minutes === 0) {
            return '< 1 min';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return $hours === 0 ? $rest . ' min' : $hours . ' h' . ($rest === 0 ? '' : ' ' . $rest . ' min');
    }

    /**
     * Verlauf der Messwerte je Stunde, Tag oder Monat aus den Aggregaten des Archivs: Minimum, Mittel
     * und Maximum. Geräte ohne Messwert werden ausgelassen und gezählt.
     *
     * @param list<array<string, mixed>> $matches
     * @return array{text: string, isError: bool}
     */
    private function history(array $matches, int $level, int $from, int $to, string $filter): array
    {
        // Zustände (an/aus, offen/zu, Auswahl wie der Batteriemodus) bekommen eine Zeitleiste statt Minimum, Mittel und
        // Maximum: „05:00 1 / 1.8 / 2“ sagte niemandem etwas (B69, 13.10.2026).
        // Ein Zustand ohne Archiv wird wie bisher ausgelassen und gezählt.
        $candidates = array_values(array_filter($matches, fn (array $e): bool => $this->isTimed($e)));
        $timed = $this->archivedTimelines($candidates, $from, $to);
        $measured = array_values(array_filter($matches, fn (array $e): bool => self::isMeasured($e) && !$this->isTimed($e)));
        $skipped = count($matches) - count($measured) - count($timed);
        if ($measured === [] && $candidates !== []) {
            if ($timed === []) {
                return $this->fail(sprintf($this->t('None of the matching devices measures energy or has an archived state (%s).'), $filter));
            }
            $lines = $this->timelineLines($timed, $filter);
            if ($this->chart !== null) {
                $this->chart += ['kind' => 'timeline', 'title' => $lines[0], 'filter' => $filter];
                $this->chartBands($timed);
            }
            if ($skipped > 0) {
                $lines[] = sprintf($this->t('%d device(s) without measured value were left out.'), $skipped);
            }
            return ['text' => implode("\n", $lines), 'isError' => false];
        }
        // Liest ein Thermostat den Raumfühler als Ist-Temperatur, wäre derselbe Verlauf zweimal da (nuc,
        // Gästezimmer): Jede Variable kommt nur einmal, unter dem ersten Gerät der Liste.
        $seen = [];
        $measured = array_values(array_filter($measured, static function (array $e) use (&$seen): bool {
            $id = $e['roles']['state'];
            if (isset($seen[$id])) {
                return false;
            }
            return $seen[$id] = true;
        }));
        if (count($measured) > self::HISTORY_MAX) {
            return $this->fail(sprintf($this->t('Too many measured values (%d) for a history (%s). Give a room or a name, at most %d at a time.'), count($measured), $filter, self::HISTORY_MAX));
        }
        [$per, $format] = self::HISTORY_LEVELS[$level];
        $lines = [sprintf($this->t('History of %d measured value(s) (%s), per %s: minimum / average / maximum.'), count($measured), $filter, $this->t($per))];
        $this->historyChart($lines[0], $per, $filter);
        foreach ($measured as $entry) {
            $lines = array_merge($lines, $this->historyLines($entry, $this->historyRows($entry['roles']['state'], $level, $from, $to), $format, true));
        }
        if ($timed !== []) {
            $lines = array_merge($lines, $this->timelineLines($timed, $filter));
            $this->chartBands($timed);
        }
        if ($skipped > 0) {
            $lines[] = sprintf($this->t('%d device(s) without measured value were left out.'), $skipped);
        }
        return ['text' => implode("\n", $lines), 'isError' => false];
    }

    private function consumption(array $matches, array $bounds, string $filter, bool $wholeHouse = false, int $chartLevel = 1): array
    {
        // Ein Zähler zählt nie in einer Gerätesumme, er hat seine eigene Zeile (B82)
        $meters = array_values(array_filter($matches, static fn (array $entry): bool => $entry['Type'] === 'meter'));
        $matches = self::firstPerVariable(array_values(array_filter($matches, static fn (array $entry): bool => $entry['Type'] !== 'meter')), 'energy');
        $metered = array_values(array_filter($matches, static fn (array $entry): bool => isset($entry['roles']['energy'])));
        $balance = $wholeHouse ? $this->balance : [];
        if ($metered === [] && $balance === [] && $meters === []) {
            return $this->fail(sprintf($this->t('None of the matching devices measures energy (%s).'), $filter));
        }
        [$level, $from, $to] = $bounds;
        $tree = $meters === [] && !($wholeHouse && $this->hasMeters()) ? null : $this->meterTree('energy', function (array $entry) use ($level, $from, $to): float|string|null {
            $counter = $this->backend->counterSum($entry['roles']['energy'], $level, $from, $to);
            if ($counter !== null && !self::isEnergyUnit($counter['unit'])) {
                return sprintf($this->t('not an energy meter (unit %s)'), trim($counter['unit']));
            }
            return self::kilowattHours($counter) ?? $this->t('no history archived');
        });
        $kwhText = static fn (float $kwh): string => self::formatNumber($kwh) . ' kWh';
        $meterLines = $tree === null ? [] : array_values(array_filter(array_map(fn (array $meter): ?string => $this->meterLine($meter, $tree, $kwhText), $meters)));
        $total = 0.0;
        $withHistory = 0;
        $rows = [];
        // Ein Gerät mit Zähler und Zustand bekommt beides in einer Zeile: „wann war das Licht an?“ ist keine Frage nach
        // dem Verbrauch (Burkhard 13.10.2026). Fürs ganze Haus nicht, das wären zu viele Zeilen.
        $timed = $wholeHouse ? [] : $this->archivedTimelines(array_values(array_filter($matches, fn (array $e): bool => $this->isTimed($e))), $from, $to);
        $timeOf = [];
        foreach ($timed as $item) {
            $timeOf[self::cardKey($item['entry'])] = ', ' . $item['summary'] . ($item['sections'] === null ? '' : "\n" . $item['sections']);
        }
        foreach ($metered as $entry) {
            $counter = $this->backend->counterSum($entry['roles']['energy'], $level, $from, $to);
            // Ein Wasser- oder Gaszähler ist kein Energiezähler; vorher stand er als kWh in der Summe.
            if ($counter !== null && !self::isEnergyUnit($counter['unit'])) {
                $rows[] = ['line' => $this->line($entry, sprintf($this->t('not an energy meter (unit %s)'), trim($counter['unit']))), 'value' => null];
                continue;
            }
            $kwh = self::kilowattHours($counter);
            if ($kwh === null) {
                $rows[] = ['line' => $this->line($entry, $this->t('no history archived')), 'value' => null];
                continue;
            }
            $total += $kwh;
            $withHistory++;
            $rows[] = ['line' => $this->line($entry, self::formatNumber($kwh) . ' kWh') . ($timeOf[self::cardKey($entry)] ?? ''), 'value' => $kwh];
        }
        $lines = $this->rankedLines($rows, $wholeHouse, static fn (float $kwh): string => self::formatNumber($kwh) . ' kWh');
        $unmetered = array_values(array_filter($timed, static fn (array $item): bool => !isset($item['entry']['roles']['energy'])));
        foreach ($unmetered as $item) {
            $lines[] = $this->line($item['entry'], $item['summary']);
            if ($item['sections'] !== null) {
                $lines[] = $item['sections'];
            }
        }
        $skipped = count($matches) - count($metered) - count($unmetered);
        if ($skipped > 0) {
            $lines[] = sprintf($this->t('%d device(s) without energy meter were left out.'), $skipped);
        }
        $head = [sprintf($this->t('%s kWh consumed by %d of %d metered device(s) (%s).'), self::formatNumber($total), $withHistory, count($metered), $filter)];
        if ($metered === [] && $meters !== []) {
            // Nur Zähler: Einer kann den anderen enthalten, eine Summe wäre doppelt
            $head = [sprintf($this->t('%d meter(s) (%s). Meters are not added up, one can contain another.'), count($meters), $filter)];
        }
        if ($balance !== []) {
            $house = $this->houseConsumption($balance, $level, $from, $to, $filter);
            if (is_string($house)) {
                // Ein Teil der Bilanz fehlt: kein Hausverbrauch, die Geräte wie ohne Bilanz.
                $head = array_merge([$house], $metered === [] ? [] : $head);
            } else {
                $head = [sprintf($this->t('House consumption %s kWh (%s): %s kWh.'), self::formatNumber($house['total']), $filter, implode(', ', $house['parts']))];
                $shares = $this->selfSufficiency($house['values'], $house['total']);
                if ($shares !== null) {
                    $head[] = $shares;
                }
                $costs = $this->costLine($from, $to);
                if ($costs !== null) {
                    $head[] = $costs;
                }
                $topLine = $tree === null ? null : $this->topLevelLine($tree, $house['total'], 'Of this, %s by %d meter(s) and %d device(s) at the top level, %s not metered.', $kwhText);
                if ($topLine !== null) {
                    $head[] = $topLine;
                } elseif ($metered !== []) {
                    $head[] = sprintf($this->t('Of this, %s kWh by %d of %d metered device(s), %s kWh not metered.'), self::formatNumber($total), $withHistory, count($metered), self::formatNumber(max(0.0, $house['total'] - $total)));
                }
                if ($this->chart !== null && !$this->chartByDevice) {
                    $this->houseChart($balance, $chartLevel, $from, $to, $head[0], $filter);
                }
            }
        }
        if ($this->chart !== null && ($this->chartByDevice || !$wholeHouse)) {
            // Mit Zählern (B82 Stufe c): ein einzelner Zähler zeigt, was unter ihm hängt, und den Rest; das Haus die oberste
            // Ebene. Sonst stünden Geräte unter einem Zähler und der Zähler selbst doppelt im Stapel.
            $entryOf = fn (int $index): array => $this->entries[$index];
            if ($tree !== null && $metered === [] && count($meters) === 1) {
                $meter = $meters[0];
                $own = $this->counterRows($meter['roles']['energy'], $chartLevel, $from, $to);
                $total = [];
                foreach ($own === null ? [] : $own['rows'] as $row) {
                    $total[$row['time']] = (float)self::kilowattHours(['sum' => $row['sum'], 'unit' => $own['unit']]);
                }
                $this->deviceChart(array_map($entryOf, $tree['children'][$meter['Index']] ?? []), $chartLevel, $from, $to, $head[0], $filter, [], $total);
            } elseif ($tree !== null && $wholeHouse) {
                $this->deviceChart(array_map($entryOf, $tree['top']), $chartLevel, $from, $to, $head[0], $filter, $balance);
            } else {
                $this->deviceChart($metered, $chartLevel, $from, $to, $head[0], $filter, $balance);
            }
        }
        return ['text' => implode("\n", array_merge($head, $meterLines, $lines)), 'isError' => false];
    }

    /**
     * Stufe der Punkte bzw. Balken eines Diagramms: heute und gestern je Stunde, Jahre je Monat, sonst je Tag; ein
     * freier Zeitraum ($period leer) nach seiner Länge (bis 2 Tage je Stunde, bis 100 Tage je Tag).
     */
    private function chartLevel(string $period, int $from, int $to): int
    {
        if ($this->forceLevel !== null) {
            return $this->forceLevel;
        }
        if ($period === '') {
            $span = ($to === 0 ? $this->backend->timestamp() : $to) - $from;
            return $span <= 2 * 86400 ? 0 : ($span <= 100 * 86400 ? 1 : 3);
        }
        return match ($period) {
            'today', 'yesterday'     => 0,
            'this_year', 'last_year' => 3,
            default                  => 1,
        };
    }

    /**
     * Hausverbrauch als Stapeldiagramm (Burkhard 12.10.2026): je Stunde, Tag oder Monat ein Balken aus Netz, PV direkt
     * (PV − Einspeisung − Batterie geladen) und Batterie (entladen), die Einspeisung darunter. Die Stapel ergeben
     * zusammen genau den Hausverbrauch der Bilanz: Lädt die Batterie aus dem Netz, ist PV direkt 0 und der Rest kommt
     * aus dem Netz (nuc 11.10.2026 14 Uhr). Die Werte gehen nur an die Seite, nicht ans Modell.
     *
     * @param array<string, int> $balance
     */
    private function houseChart(array $balance, int $level, int $from, int $to, string $title, string $filter): void
    {
        if ($this->chartCosts) {
            $this->costChart($level, $from, $to, $title, $filter);
            return;
        }
        $values = $this->balanceParts($balance, $level, $from, $to);
        if ($values === null) {
            return;
        }
        if ($this->chartPart !== '') {
            $key = self::PARTS[$this->chartPart];
            $name = $this->t(self::BALANCE_TERMS[$key][1]);
            [$per] = self::HISTORY_LEVELS[$level];
            $series = [['key' => $this->chartPart, 'name' => $name, 'values' => array_map(static fn (array $parts): float => round($parts[$key] ?? 0.0, 3), array_values($values))]];
            // Mehrere Quellen dieses Teils (B82 Stufe c): eine Reihe je Quelle in Abstufungen der Farbe
            $sources = $this->sourceSeries($key, $level, $from, $to);
            if ($sources !== null && count($sources) > 1) {
                $series = [];
                foreach ($sources as $shade => $source) {
                    $series[] = ['key' => $this->chartPart, 'name' => $source['name'] === '' ? $name : $source['name'], 'source' => $source['name'], 'shade' => $shade,
                        'values' => array_map(static fn (int $time): float => round($source['values'][$time] ?? 0.0, 3), array_keys($values))];
                }
            }
            $this->chart = ['kind' => 'consumption', 'title' => $title, 'per' => $per, 'unit' => 'kWh', 'filter' => $filter, 'times' => array_keys($values),
                'series' => $series, 'labels' => ['per' => $this->t($per), 'total' => $name]];
            return;
        }
        $stacks = ['grid' => [], 'solar' => [], 'battery' => [], 'export' => []];
        foreach ($values as $parts) {
            $house = 0.0;
            foreach (self::BALANCE_TERMS as $key => [$sign]) {
                $house += $sign * ($parts[$key] ?? 0.0);
            }
            $house = max(0.0, $house);
            $solar = isset($balance['solar']) ? max(0.0, min($house, ($parts['solar'] ?? 0.0) - ($parts['export'] ?? 0.0) - ($parts['charge'] ?? 0.0))) : 0.0;
            $battery = isset($balance['discharge']) ? max(0.0, min($house - $solar, $parts['discharge'] ?? 0.0)) : 0.0;
            $export = $parts['export'] ?? 0.0;
            $stacks['grid'][] = round(max(0.0, $house - $solar - $battery), 3);
            $stacks['solar'][] = round($solar, 3);
            $stacks['battery'][] = round($battery, 3);
            $stacks['export'][] = $export > 0 ? -round($export, 3) : 0.0;
        }
        $present = ['grid' => true, 'solar' => isset($balance['solar']), 'battery' => isset($balance['discharge']), 'export' => isset($balance['export'])];
        $names = ['grid' => 'grid', 'solar' => 'solar (used directly)', 'battery' => 'battery', 'export' => 'grid export'];
        // Mehrere PV-Anlagen (B82 Stufe c): PV direkt je Anlage im Verhältnis ihres Ertrags im Abschnitt. Welche Anlage
        // einspeist oder die Batterie lädt, misst niemand.
        $solarSources = $present['solar'] ? $this->sourceSeries('solar', $level, $from, $to) : null;
        $series = [];
        foreach ($stacks as $key => $list) {
            if (!$present[$key]) {
                continue;
            }
            if ($key === 'solar' && $solarSources !== null && count($solarSources) > 1) {
                $times = array_keys($values);
                foreach ($solarSources as $shade => $source) {
                    $split = [];
                    foreach ($times as $i => $time) {
                        $all = array_sum(array_map(static fn (array $s): float => $s['values'][$time] ?? 0.0, $solarSources));
                        $split[] = $all > 0 ? round($list[$i] * ($source['values'][$time] ?? 0.0) / $all, 3) : 0.0;
                    }
                    $series[] = ['key' => 'solar', 'name' => $this->t($names['solar']) . ($source['name'] === '' ? '' : ' · ' . $source['name']), 'source' => $source['name'], 'shade' => $shade, 'values' => $split];
                }
                continue;
            }
            $series[] = ['key' => $key, 'name' => $this->t($names[$key]), 'values' => $list];
        }
        [$per] = self::HISTORY_LEVELS[$level];
        $this->chart = ['kind' => 'consumption', 'title' => $title, 'per' => $per, 'unit' => 'kWh', 'filter' => $filter,
            'times' => array_keys($values), 'series' => $series, 'labels' => ['per' => $this->t($per)]];
        $totals = [];
        foreach ($values as $parts) {
            foreach ($parts as $key => $kwh) {
                $totals[$key] = ($totals[$key] ?? 0.0) + $kwh;
            }
        }
        $rings = $this->shareRings($totals, 'kWh', self::hasBattery($balance));
        if ($rings !== []) {
            $this->chart['rings'] = $rings;
        }
        $this->chartParts = $values;
    }

    /** Hat die Bilanz eine Batterie (Zähler bzw. Leistung für Laden oder Entladen)? */
    private static function hasBattery(array $balance): bool
    {
        return isset($balance['charge']) || isset($balance['discharge']);
    }

    /**
     * Ringe für Autarkie und Eigenverbrauch (Anregung pitti, Forum t/144633, Burkhard 13.10.2026). Autarkie teilt den
     * Hausverbrauch in PV direkt (PV − Einspeisung − Batterie geladen, nicht unter 0), Batterie (entladen) und Netz,
     * Eigenverbrauch teilt die PV in direkt, in die Batterie geladen und eingespeist. Die Zahl in der Mitte rechnet
     * selfSufficiency(), damit Bild und Text des Modells übereinstimmen. Ein Ring fehlt, wenn sich seine Zahl nicht
     * rechnen lässt. Gilt für kWh über einen Zeitraum wie für W jetzt. Ohne Batterie in der Bilanz fehlt ihre Zeile, wie
     * im Stapel (Neustadt 15.10.2026: „Batterie 0 kWh“, der Marstek stand nur als Leistungssensor drin).
     *
     * @param array<string, float> $values Teile der Bilanz je Schlüssel
     * @return list<array<string, mixed>>
     */
    private function shareRings(array $values, string $unit, bool $battery = true): array
    {
        $rings = $this->buildShareRings($values, $unit);
        if (!$battery) {
            foreach ($rings as &$ring) {
                $ring['parts'] = array_values(array_filter($ring['parts'], static fn (array $part): bool => $part['key'] !== 'battery'));
            }
            unset($ring);
        }
        return $rings;
    }

    /**
     * @param array<string, float> $values
     * @return list<array<string, mixed>>
     */
    private function buildShareRings(array $values, string $unit): array
    {
        $house = 0.0;
        foreach (self::BALANCE_TERMS as $key => [$sign]) {
            $house += $sign * ($values[$key] ?? 0.0);
        }
        $house = max(0.0, $house);
        $shares = $this->shares($values, $house);
        $solar = $values['solar'] ?? 0.0;
        $export = $values['export'] ?? 0.0;
        $direct = max(0.0, $solar - $export - ($values['charge'] ?? 0.0));
        $rings = [];
        if ($shares['autarky'] !== null) {
            $battery = min(max(0.0, $house - $direct), $values['discharge'] ?? 0.0);
            $rings[] = ['key' => 'autarky', 'title' => $this->t('Self-sufficiency'), 'label' => $this->t('Self-sufficiency'),
                'share' => $shares['autarky'], 'unit' => $unit, 'total' => round($house, 3), 'parts' => [
                    ['key' => 'solar', 'name' => $this->t('solar (used directly)'), 'value' => round(min($house, $direct), 3)],
                    ['key' => 'battery', 'name' => $this->t('battery'), 'value' => round($battery, 3)],
                    ['key' => 'grid', 'name' => $this->t('grid'), 'value' => round($values['import'] ?? 0.0, 3)],
                ]];
        }
        if ($shares['solar'] !== null) {
            $rings[] = ['key' => 'self_use', 'title' => $this->t('Self-consumption'), 'label' => $this->t('Self-consumption'),
                'share' => $shares['solar'], 'unit' => $unit, 'total' => round($solar, 3), 'parts' => [
                    ['key' => 'solar', 'name' => $this->t('solar (used directly)'), 'value' => round($direct, 3)],
                    ['key' => 'battery', 'name' => $this->t('battery'), 'value' => round(max(0.0, $solar - $export - $direct), 3)],
                    ['key' => 'export', 'name' => $this->t('grid export'), 'value' => round($export, 3)],
                ]];
        }
        return $rings;
    }

    /**
     * Teile der Energiebilanz je Stunde, Tag oder Monat in kWh, aufsteigend nach Zeit; null, wenn ein Zähler keinen
     * Verlauf hat.
     *
     * @param array<string, int> $balance
     * @return null|array<int, array<string, float>>
     */
    private function balanceParts(array $balance, int $level, int $from, int $to): ?array
    {
        $values = [];
        foreach (array_keys(self::BALANCE_TERMS) as $key) {
            if (!isset($balance[$key])) {
                continue;
            }
            // Mehrere Quellen einer Art (B82 Stufe b) zählen je Abschnitt zusammen
            foreach ($this->balanceVars[$key] ?? [['variable' => $balance[$key]]] as $counter) {
                $series = $this->counterRows($counter['variable'], $level, $from, $to);
                if ($series === null) {
                    return null;
                }
                foreach ($series['rows'] as $row) {
                    $values[$row['time']][$key] = ($values[$row['time']][$key] ?? 0.0) + (float)self::kilowattHours(['sum' => $row['sum'], 'unit' => $series['unit']]);
                }
            }
        }
        ksort($values);
        return $values;
    }

    /**
     * Abschnitte eines Zählers wie counterSeries, aber jeder Zeitpunkt auf den Beginn seines Abschnitts gesetzt. Das erste
     * Aggregat trägt den Zeitpunkt des ersten Satzes (Hoymiles: Monat ab 14.09.2025 11:00 statt 01.09.), und mehrere
     * Zähler übereinander bekamen dann eigene Säulen (Hardcopy Burkhard 17.10.2026: 13 Monate, der September geteilt).
     *
     * @return null|array{rows: list<array{time: int, sum: float}>, unit: string}
     */
    private function counterRows(int $variableId, int $level, int $from, int $to): ?array
    {
        $series = $this->backend->counterSeries($variableId, $level, $from, $to);
        if ($series === null) {
            return null;
        }
        $rows = [];
        foreach ($series['rows'] as $row) {
            $time = self::sectionStart((int)$row['time'], $level);
            $rows[$time] = ['time' => $time, 'sum' => ($rows[$time]['sum'] ?? 0.0) + (float)$row['sum']];
        }
        ksort($rows);
        return ['rows' => array_values($rows), 'unit' => $series['unit']];
    }

    /** Verlauf wie history, die Zeitpunkte auf den Beginn des Abschnitts gesetzt (wie counterRows). */
    /** Art, Titel und Beschriftungen eines Verlaufsdiagramms (Messwerte und Leistung). */
    private function historyChart(string $title, string $per, string $filter): void
    {
        if ($this->chart !== null) {
            $this->chart += ['kind' => 'history', 'title' => $title, 'per' => $per, 'filter' => $filter,
                'labels' => ['minimum' => $this->t('minimum'), 'average' => $this->t('average'), 'maximum' => $this->t('maximum'), 'per' => $this->t($per), 'band' => $this->t('band from minimum to maximum')]];
        }
    }

    /**
     * Zeilen eines Verlaufs: Minimum und Maximum über den ganzen Zeitraum mit Zeitpunkt, mit $table dazu die Werte je
     * Abschnitt. Mit Diagramm gehen die Werte an die Seite, nicht ans Modell.
     *
     * @param array<string, mixed> $entry Gerät oder eine Reihe des Hauses (Room, Name, Type)
     * @return list<string>
     */
    private function historyLines(array $entry, ?array $history, string $format, bool $table): array
    {
        if ($history === null) {
            return [$this->line($entry, $this->t('no history archived'))];
        }
        $unit = trim($history['unit']);
        $lines = [];
        if ($history['rows'] === []) {
            $lines[] = $this->line($entry, $unit === '' ? '-' : $unit);
            $lines[] = $this->t('no values in this period');
        } else {
            // Minimum und Maximum über den ganzen Zeitraum mit Zeitpunkt, damit das Modell nicht selbst in der
            // Tabelle sucht (Bedienungs-Blindtest 12.10.2026, Leitbild „Das Werkzeug zählt“). Bei gleichen
            // Werten zählt der erste.
            $low = $high = $history['rows'][0];
            foreach ($history['rows'] as $row) {
                $low = $row['min'] < $low['min'] ? $row : $low;
                $high = $row['max'] > $high['max'] ? $row : $high;
            }
            // Der Zeitpunkt aus MinTime/MaxTime der Aggregate (B84), sonst der Beginn des Abschnitts
            $when = static fn (array $row, string $key): string => isset($row[$key]) ? date('Y-m-d H:i', (int)$row[$key]) : date($format, $row['time']);
            $suffix = $unit === '' ? '' : ' ' . $unit;
            $lines[] = $this->line($entry, sprintf(
                $this->t('minimum %s (%s), maximum %s (%s)'),
                self::formatNumber($low['min']) . $suffix,
                $when($low, 'minTime'),
                self::formatNumber($high['max']) . $suffix,
                $when($high, 'maxTime')
            ));
        }
        if ($this->chart !== null) {
            if ($history['rows'] !== []) {
                $this->chart['series'][] = ['room' => $entry['Room'], 'name' => $entry['Name'], 'unit' => $unit,
                    'points' => array_map(static fn (array $row): array => [$row['time'], $row['min'], $row['avg'], $row['max']], $history['rows'])];
            }
            return $lines;
        }
        if ($table) {
            foreach ($history['rows'] as $row) {
                $lines[] = date($format, $row['time']) . ' ' . self::formatNumber($row['min']) . ' / ' . self::formatNumber($row['avg']) . ' / ' . self::formatNumber($row['max']);
            }
        }
        return $lines;
    }

    /**
     * get_state mit type=power (B84, Wunsch Micha87 im Forum 17.10.2026: „min und max Werte der Leistung“): ohne Zeitraum
     * die Leistung jetzt, mit Zeitraum der Verlauf der Leistung. Fürs ganze Haus mit Energiebilanz die Hausleistung und
     * die Netzleistung, sonst die Geräte mit Leistung.
     *
     * @param null|array{0: int, 1: int, 2: int} $range
     * @return array{text: string, isError: bool}
     */
    private function powerState(array $matches, bool $wholeHouse, string $filter, ?array $range, string $rangeLabel, int $rangeLevel, string $period): array
    {
        $now = $range === null && ($period === '' || $period === self::NOW);
        $house = $wholeHouse && ($now ? $this->powerBalance !== [] : ($this->balance !== [] || $this->powerVars !== []));
        $matches = array_values(array_filter($matches, static fn (array $entry): bool => isset($entry['roles']['power'])));
        if ($matches === [] && !$house) {
            $this->problem = 'not_understood';
            return $this->fail(sprintf($this->t('None of the matching devices measures power (%s).'), $filter));
        }
        if ($now) {
            return $this->power($matches, $filter . ', period=' . self::NOW, $wholeHouse);
        }
        if ($range !== null) {
            [, $from, $to] = $range;
            return $this->powerHistory($matches, $rangeLevel, $from, $to, $filter . $rangeLabel, $house);
        }
        [, $from, $to] = self::periodBounds($period, $this->backend->timestamp());
        return $this->powerHistory($matches, $this->chartLevel($period, $from, $to), $from, $to, $filter . ', period=' . $period, $house);
    }

    /**
     * Verlauf der Leistung (B84): je Gerät Minimum und Maximum aus den Aggregaten der Leistung, wie beim Messwert, die
     * Tabelle nur bei einer Reihe. Fürs Haus die Hausleistung im Stundenmittel aus den Zählern der Bilanz (gleichzeitige
     * Spitzen mehrerer Sensoren liefert kein Aggregat; 15-Minuten-Aggregate scheiterten am nuc schon über 17 Tage an
     * „Zu viele Werte (>150000)“, der Spezialschalter ArchiveRecordLimit, Standard 50000) und die Netzleistung je Richtung mit dem Zeitpunkt der Rohwerte.
     *
     * @return array{text: string, isError: bool}
     */
    private function powerHistory(array $matches, int $level, int $from, int $to, string $filter, bool $house): array
    {
        [$per, $format] = self::HISTORY_LEVELS[$level];
        $series = [];
        if ($house) {
            $title = sprintf($this->t('House power (%s), per %s: minimum / average / maximum.'), $filter, $this->t($per));
            if ($this->balance !== []) {
                $series[] = [['Room' => '', 'Name' => $this->t('house power (hourly mean)'), 'Type' => 'house'], $this->housePowerRows($level, $from, $to)];
            }
            foreach (['import' => 'grid import', 'export' => 'grid export'] as $key => $word) {
                $variables = $this->powerVars[$key] ?? [];
                foreach ($variables as $variable) {
                    [$signed, $inverted] = $this->signedOf($key, $variable);
                    $label = $this->t($word) . ($signed ? ' ' . $this->t('(export negative)') : '') . (count($variables) > 1 && $variable['name'] !== '' ? ' ' . $variable['name'] : '');
                    $history = $this->historyRows($variable['variable'], $level, $from, $to);
                    $series[] = [['Room' => '', 'Name' => $label, 'Type' => 'grid'], $history === null ? null : self::kilowattHistory($history, $inverted)];
                }
            }
        } else {
            // Teilen sich zwei Geräte eine Leistung (Messsteckdose und Gerät dahinter), zählt sie einmal, unter dem ersten
            $seen = [];
            foreach ($matches as $entry) {
                $variableId = $entry['roles']['power'];
                if (!isset($seen[$variableId])) {
                    $seen[$variableId] = true;
                    $series[] = [$entry, $this->historyRows($variableId, $level, $from, $to)];
                }
            }
            if (count($series) > self::POWER_HISTORY_MAX) {
                return $this->fail(sprintf($this->t('Too many devices (%d) for a power history (%s). Give a room or a name, at most %d at a time.'), count($series), $filter, self::POWER_HISTORY_MAX));
            }
            $title = sprintf($this->t('Power of %d device(s) (%s), per %s: minimum / average / maximum.'), count($series), $filter, $this->t($per));
        }
        $this->historyChart($title, $per, $filter);
        $lines = [$title];
        foreach ($series as [$entry, $history]) {
            $lines = array_merge($lines, $this->historyLines($entry, $history, $format, count($series) === 1));
            if (($history['inconsistent'] ?? 0) > 0) {
                $lines[] = sprintf($this->t('%d hour(s) with inconsistent meters (house consumption below 0) left out.'), $history['inconsistent']);
            }
        }
        return ['text' => implode("\n", $lines), 'isError' => false];
    }

    /**
     * Hausleistung je Abschnitt aus den Zählern der Bilanz: je Stunde der Hausverbrauch in kWh, also das Stundenmittel in
     * kW, je Abschnitt Minimum, Mittel und Maximum mit der Stunde. Die laufende Stunde fehlt, ihr Mittel wäre zu niedrig.
     * Ebenso Stunden mit negativem Hausverbrauch: Dort passen die Zähler nicht zusammen (betreute Anlage, Oktober 2026: an
     * 15 Vormittagen zählte der Ladezähler der Batterie mehr, als PV und Netz lieferten), auf 0 gekappt wären sie eine
     * falsches Minimum. Ihre Anzahl steht in „inconsistent“.
     *
     * @return null|array{rows: list<array{time: int, min: float, avg: float, max: float, minTime: int, maxTime: int}>, unit: string, inconsistent: int}
     */
    private function housePowerRows(int $level, int $from, int $to): ?array
    {
        $parts = $this->balanceParts($this->balance, 0, $from, $to);
        if ($parts === null) {
            return null;
        }
        $now = $this->backend->timestamp();
        $sections = [];
        $inconsistent = 0;
        foreach ($parts as $time => $part) {
            if ($time + 3600 > $now) {
                continue;
            }
            $value = 0.0;
            foreach (self::BALANCE_TERMS as $key => [$sign]) {
                $value += $sign * ($part[$key] ?? 0.0);
            }
            if ($value < 0) {
                $inconsistent++;
                continue;
            }
            $sections[self::sectionStart($time, $level)][] = [$time, $value];
        }
        $rows = [];
        foreach ($sections as $start => $hours) {
            $low = $high = $hours[0];
            foreach ($hours as $hour) {
                $low = $hour[1] < $low[1] ? $hour : $low;
                $high = $hour[1] > $high[1] ? $hour : $high;
            }
            $rows[] = ['time' => $start, 'min' => $low[1], 'avg' => array_sum(array_column($hours, 1)) / count($hours), 'max' => $high[1], 'minTime' => $low[0], 'maxTime' => $high[0]];
        }
        return ['rows' => $rows, 'unit' => 'kW', 'inconsistent' => $inconsistent];
    }

    /** Verlauf einer Netzleistung in kW (W und MW umgerechnet); umgekehrt gezählt mit gedrehtem Vorzeichen. */
    private static function kilowattHistory(array $history, bool $inverted): array
    {
        $factor = match (strtolower(trim($history['unit']))) {
            'w'     => 0.001,
            'kw'    => 1.0,
            'mw'    => 1000.0,
            default => null,
        };
        $sign = $inverted ? -1.0 : 1.0;
        $scale = ($factor ?? 1.0) * $sign;
        $history['rows'] = array_map(static function (array $row) use ($scale, $inverted): array {
            $min = $row['min'] * $scale;
            $max = $row['max'] * $scale;
            $out = ['time' => $row['time'], 'min' => $inverted ? $max : $min, 'avg' => $row['avg'] * $scale, 'max' => $inverted ? $min : $max];
            $minTime = $inverted ? ($row['maxTime'] ?? null) : ($row['minTime'] ?? null);
            $maxTime = $inverted ? ($row['minTime'] ?? null) : ($row['maxTime'] ?? null);
            return $out + array_filter(['minTime' => $minTime, 'maxTime' => $maxTime], static fn (?int $time): bool => $time !== null);
        }, $history['rows']);
        $history['unit'] = $factor === null ? $history['unit'] : 'kW';
        return $history;
    }

    private function historyRows(int $variableId, int $level, int $from, int $to): ?array
    {
        $history = $this->backend->history($variableId, $level, $from, $to);
        if ($history === null) {
            return null;
        }
        $rows = [];
        foreach ($history['rows'] as $row) {
            $row['time'] = self::sectionStart((int)$row['time'], $level);
            $rows[$row['time']] = $row;
        }
        ksort($rows);
        $history['rows'] = array_values($rows);
        return $history;
    }

    /** Beginn des Abschnitts (Stunde, Tag, Woche ab Montag, Monat, Jahr) in der Zeitzone der Anlage. */
    private static function sectionStart(int $time, int $level): int
    {
        [$y, $m, $d, $h, $w] = array_map('intval', explode(' ', date('Y n j G N', $time)));
        return match ($level) {
            0       => (int)mktime($h, 0, 0, $m, $d, $y),
            1       => (int)mktime(0, 0, 0, $m, $d, $y),
            2       => (int)mktime(0, 0, 0, $m, $d - $w + 1, $y),
            3       => (int)mktime(0, 0, 0, $m, 1, $y),
            4       => (int)mktime(0, 0, 0, 1, 1, $y),
            default => $time,
        };
    }

    /**
     * kWh je Abschnitt für jede Quelle eines Teils der Bilanz (B82 Stufe c), Quellen mit gleichem Namen zusammen, in der
     * Reihenfolge der Liste; null, wenn ein Zähler keinen Verlauf hat.
     *
     * @return null|list<array{name: string, values: array<int, float>}>
     */
    private function sourceSeries(string $key, int $level, int $from, int $to): ?array
    {
        $sources = [];
        foreach ($this->balanceVars[$key] ?? [] as $counter) {
            $series = $this->counterRows($counter['variable'], $level, $from, $to);
            if ($series === null) {
                return null;
            }
            $name = (string)($counter['name'] ?? '');
            $sources[$name] ??= ['name' => $name, 'values' => []];
            foreach ($series['rows'] as $row) {
                $sources[$name]['values'][$row['time']] = ($sources[$name]['values'][$row['time']] ?? 0.0) + (float)self::kilowattHours(['sum' => $row['sum'], 'unit' => $series['unit']]);
            }
        }
        return array_values($sources);
    }

    /**
     * Verbrauch je Gerät als Stapeldiagramm (Burkhard 12.10.2026): die CHART_DEVICES größten Geräte über den Zeitraum mit
     * eigenem Stapel, der Rest als „übrige Geräte“. Mit Energiebilanz fürs ganze Haus dazu „nicht erfasst“ (Haus minus
     * Geräte je Zeitraum, nicht unter 0). Geräte ohne archivierten Zähler oder mit einem Zähler für Wasser oder Gas
     * fehlen im Diagramm, die Textzeilen nennen sie.
     *
     * Unter einem Zähler (B82 Stufe c) bekommt der Stapel statt der Bilanz den Zähler selbst als Ganzes ($total).
     *
     * @param list<array<string, mixed>> $metered
     * @param array<string, int> $balance leer = kein „nicht erfasst“
     * @param null|array<int, float> $total kWh je Abschnitt des Zählers darüber
     */
    private function deviceChart(array $metered, int $level, int $from, int $to, string $title, string $filter, array $balance, ?array $total = null): void
    {
        $devices = [];
        $times = [];
        foreach ($metered as $entry) {
            $series = $this->counterRows($entry['roles']['energy'], $level, $from, $to);
            if ($series === null || !self::isEnergyUnit($series['unit'])) {
                continue;
            }
            $values = [];
            foreach ($series['rows'] as $row) {
                $values[$row['time']] = (float)self::kilowattHours(['sum' => $row['sum'], 'unit' => $series['unit']]);
                $times[$row['time']] = true;
            }
            // Ein Zähler ohne Raum (Unterverteilung, Haushalt) nur mit Namen
            $devices[] = ['name' => ($entry['Room'] === '' ? '' : $entry['Room'] . ' · ') . $entry['Name'], 'values' => $values, 'total' => array_sum($values)];
        }
        // Die größten zuerst; bei Gleichstand bleibt die Reihenfolge der Liste
        usort($devices, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $house = [];
        if ($total !== null) {
            $house = $total;
            foreach (array_keys($total) as $time) {
                $times[$time] = true;
            }
        } elseif ($balance !== []) {
            foreach ($this->balanceParts($balance, $level, $from, $to) ?? [] as $time => $parts) {
                $sum = 0.0;
                foreach (self::BALANCE_TERMS as $key => [$sign]) {
                    $sum += $sign * ($parts[$key] ?? 0.0);
                }
                $house[$time] = max(0.0, $sum);
                $times[$time] = true;
            }
        }
        ksort($times);
        $times = array_keys($times);
        $column = static fn (array $values): array => array_map(static fn (int $time): float => round($values[$time] ?? 0.0, 3), $times);
        $series = [];
        foreach (array_slice($devices, 0, self::CHART_DEVICES) as $device) {
            $series[] = ['key' => 'device', 'name' => $device['name'], 'values' => $column($device['values'])];
        }
        $rest = array_slice($devices, self::CHART_DEVICES);
        if ($rest !== []) {
            $other = [];
            foreach ($rest as $device) {
                foreach ($device['values'] as $time => $kwh) {
                    $other[$time] = ($other[$time] ?? 0.0) + $kwh;
                }
            }
            $series[] = ['key' => 'other', 'name' => $this->t('other devices'), 'values' => $column($other)];
        }
        if ($devices === []) {
            return;
        }
        if ($house !== []) {
            $unmetered = [];
            foreach ($house as $time => $kwh) {
                $unmetered[$time] = max(0.0, $kwh - array_sum(array_map(static fn (array $device): float => $device['values'][$time] ?? 0.0, $devices)));
            }
            $series[] = ['key' => 'unmetered', 'name' => $this->t('not metered'), 'values' => $column($unmetered)];
        }
        [$per] = self::HISTORY_LEVELS[$level];
        $this->chart = ['kind' => 'consumption', 'title' => $title, 'per' => $per, 'unit' => 'kWh', 'filter' => $filter,
            'times' => $times, 'series' => $series, 'labels' => ['per' => $this->t($per)]];
    }

    /**
     * Hausverbrauch aus der Energiebilanz, mit den Teilen in Worten. Fehlt einem Zähler der Verlauf
     * oder zählt er keine Energie, kommt die Zeile zurück, die das sagt.
     *
     * @param array<string, int> $balance
     * @return array{total: float, parts: list<string>, values: array<string, float>}|string
     */
    private function houseConsumption(array $balance, int $level, int $from, int $to, string $filter): array|string
    {
        $total = 0.0;
        $parts = [];
        $values = [];
        foreach (self::BALANCE_TERMS as $key => [$sign, $word]) {
            if (!isset($balance[$key])) {
                continue;
            }
            $kwh = 0.0;
            $named = [];
            $counters = $this->balanceVars[$key] ?? [['variable' => $balance[$key], 'name' => '']];
            foreach ($counters as $meter) {
                $counter = $this->backend->counterSum($meter['variable'], $level, $from, $to);
                if ($counter !== null && !self::isEnergyUnit($counter['unit'])) {
                    return sprintf($this->t('House consumption unknown (%s): %s is not an energy counter (unit %s).'), $filter, $this->t($word), trim($counter['unit']));
                }
                $part = self::kilowattHours($counter);
                if ($part === null) {
                    return sprintf($this->t('House consumption unknown (%s): no history archived for %s.'), $filter, $this->t($word));
                }
                $kwh += $part;
                if ($meter['name'] !== '') {
                    $named[$meter['name']] = ($named[$meter['name']] ?? 0.0) + $part;
                }
            }
            $total += $sign * $kwh;
            $values[$key] = $kwh;
            $parts[] = $this->partText($word, $kwh, $named, count($counters), static fn (float $v): string => self::formatNumber($v));
        }
        return ['total' => $total, 'parts' => $parts, 'values' => $values];
    }

    /**
     * Autarkie und Eigenverbrauch der PV wie im Energie-Dashboard von Home Assistant: Autarkie
     * = 1 − Netzbezug / Hausverbrauch (nur mit PV oder Batterie, sonst wäre sie immer 0 %),
     * Eigenverbrauch = (PV − Einspeisung) / PV. null, wenn sich keins von beiden rechnen lässt.
     *
     * @param array<string, float> $values kWh je Schlüssel der Bilanz
     */
    private function selfSufficiency(array $values, float $house): ?string
    {
        ['autarky' => $autarky, 'solar' => $solar] = $this->shares($values, $house);
        return match (true) {
            $autarky !== null && $solar !== null => sprintf($this->t('Self-sufficiency %d %%, self-consumed solar %d %%.'), $autarky, $solar),
            $autarky !== null                    => sprintf($this->t('Self-sufficiency %d %%.'), $autarky),
            $solar !== null                      => sprintf($this->t('Self-consumed solar %d %%.'), $solar),
            default                              => null,
        };
    }

    /**
     * Autarkie und Eigenverbrauch in ganzen Prozent, je null, wenn sie sich nicht rechnen lassen.
     *
     * @param array<string, float> $values
     * @return array{autarky: ?int, solar: ?int}
     */
    private function shares(array $values, float $house): array
    {
        $percent = static fn (float $share): int => (int)round(max(0.0, min(1.0, $share)) * 100);
        return [
            'autarky' => isset($values['import']) && (isset($values['solar']) || isset($values['discharge'])) && $house > 0
                ? $percent(1 - $values['import'] / $house) : null,
            'solar'   => isset($values['solar'], $values['export']) && $values['solar'] > 0
                ? $percent(($values['solar'] - $values['export']) / $values['solar']) : null,
        ];
    }

    /**
     * Gerätezeilen. Fürs ganze Haus nach Wert sortiert, die größten fünf, der Rest in einer Zeile
     * zusammengefasst; Zeilen ohne Wert (kein Verlauf, keine Leistung) stehen danach. Sonst in der
     * Reihenfolge der Liste.
     *
     * @param list<array{line: string, value: ?float}> $rows
     * @param callable(float): string $format
     * @return list<string>
     */
    private function rankedLines(array $rows, bool $wholeHouse, callable $format): array
    {
        if (!$wholeHouse) {
            return array_column($rows, 'line');
        }
        $measured = array_values(array_filter($rows, static fn (array $row): bool => $row['value'] !== null));
        $other = array_values(array_filter($rows, static fn (array $row): bool => $row['value'] === null));
        usort($measured, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $lines = array_column(array_slice($measured, 0, self::TOP_DEVICES), 'line');
        $rest = array_slice($measured, self::TOP_DEVICES);
        if ($rest !== []) {
            $lines[] = sprintf($this->t('%d more device(s) together %s.'), count($rest), $format(array_sum(array_column($rest, 'value'))));
        }
        return array_merge($lines, array_column($other, 'line'));
    }

    /**
     * Leistung der passenden Geräte jetzt, in W; aufgebaut wie der Verbrauch. Fürs ganze Haus steht
     * davor die Hausleistung aus der Energiebilanz, die Geräte sind ein Teil davon.
     *
     * @param list<array<string, mixed>> $matches
     * @return array{text: string, isError: bool}
     */
    private function power(array $matches, string $filter, bool $wholeHouse): array
    {
        $meters = array_values(array_filter($matches, static fn (array $entry): bool => $entry['Type'] === 'meter'));
        $matches = self::firstPerVariable(array_values(array_filter($matches, static fn (array $entry): bool => $entry['Type'] !== 'meter')), 'power');
        $measured = array_values(array_filter($matches, static fn (array $entry): bool => isset($entry['roles']['power'])));
        $balance = $wholeHouse ? $this->powerBalance : [];
        $tree = $meters === [] && !($wholeHouse && $this->hasMeters()) ? null : $this->meterTree('power', function (array $entry): float|string {
            $watts = $this->wattsOf($entry['roles']['power']);
            if (!is_array($watts)) {
                return $watts;
            }
            return $watts['unit'] === null ? sprintf($this->t('not available (variable %d is missing)'), $entry['roles']['power']) : sprintf($this->t('not a power value (unit %s)'), $watts['unit']);
        });
        $wattText = static fn (float $watts): string => self::formatWatts($watts) . ' W';
        $meterLines = $tree === null ? [] : array_values(array_filter(array_map(fn (array $meter): ?string => $this->meterLine($meter, $tree, $wattText), $meters)));
        if ($measured === [] && $balance === [] && $meterLines === []) {
            return $this->fail(sprintf($this->t('None of the matching devices measures power (%s).'), $filter));
        }
        $total = 0.0;
        $withValue = 0;
        $rows = [];
        foreach ($measured as $entry) {
            $watts = $this->wattsOf($entry['roles']['power']);
            if (is_array($watts)) {
                $rows[] = ['line' => $this->line($entry, $watts['unit'] === null
                    ? sprintf($this->t('not available (variable %d is missing)'), $entry['roles']['power'])
                    : sprintf($this->t('not a power value (unit %s)'), $watts['unit'])), 'value' => null];
                continue;
            }
            $total += $watts;
            $withValue++;
            $rows[] = ['line' => $this->line($entry, self::formatWatts($watts) . ' W'), 'value' => $watts];
        }
        $lines = $this->rankedLines($rows, $wholeHouse, static fn (float $watts): string => self::formatWatts($watts) . ' W');
        $skipped = count($matches) - count($measured);
        if ($skipped > 0) {
            $lines[] = sprintf($this->t('%d device(s) without power measurement were left out.'), $skipped);
        }
        $head = [sprintf($this->t('%s W used by %d of %d device(s) with power measurement (%s).'), self::formatWatts($total), $withValue, count($measured), $filter)];
        if ($measured === [] && $meters !== []) {
            $head = [sprintf($this->t('%d meter(s) (%s). Meters are not added up, one can contain another.'), count($meters), $filter)];
        }
        if ($balance !== []) {
            $house = $this->housePower($balance, $filter);
            if (is_string($house)) {
                $head = array_merge([$house], $measured === [] ? [] : $head);
            } else {
                $head = [sprintf($this->t('House power %s W (%s): %s W.'), self::formatWatts(max(0.0, $house['total'])), $filter, implode(', ', $house['parts']))];
                $topLine = $tree === null ? null : $this->topLevelLine($tree, $house['total'], 'Of this, %s by %d meter(s) and %d device(s) at the top level, %s not metered.', $wattText);
                if ($topLine !== null) {
                    $head[] = $topLine;
                } elseif ($measured !== []) {
                    $head[] = sprintf($this->t('Of this, %s W by %d of %d device(s) with power measurement, %s W not metered.'), self::formatWatts($total), $withValue, count($measured), self::formatWatts(max(0.0, $house['total'] - $total)));
                }
            }
        }
        return ['text' => implode("\n", array_merge($head, $meterLines, $lines)), 'isError' => false];
    }

    /**
     * Hausleistung aus der Energiebilanz. Ein Netzsensor mit Vorzeichen ohne eigenes Einspeisefeld
     * zählt negativ als Einspeisung, ein Batteriesensor ohne eigenes Ladefeld negativ als Laden; mit
     * dem Haken „Vorzeichen umkehren“ (setInvertedPower) jeweils positiv.
     *
     * @param array<string, int> $balance
     * @return array{total: float, parts: list<string>}|string
     */
    private function housePower(array $balance, string $filter): array|string
    {
        $values = [];
        $named = [];
        $counts = [];
        foreach (self::BALANCE_TERMS as $key => [, $word]) {
            if (!isset($balance[$key])) {
                continue;
            }
            $values[$key] ??= 0.0;
            foreach ($this->powerVars[$key] ?? [['variable' => $balance[$key], 'name' => '', 'signed' => null, 'inverted' => false]] as $sensor) {
                $watts = $this->wattsOf($sensor['variable']);
                if (is_array($watts)) {
                    return $watts['unit'] === null
                        ? sprintf($this->t('House power unknown (%s): %s is not available.'), $filter, $this->t($word))
                        : sprintf($this->t('House power unknown (%s): %s is not a power value (unit %s).'), $filter, $this->t($word), $watts['unit']);
                }
                // Ein Sensor mit Vorzeichen ohne Gegenfeld: negativ zählt er als Einspeisung bzw. Laden
                [$signed, $inverted] = $this->signedOf($key, $sensor);
                $target = $key;
                if ($signed) {
                    $watts = $inverted ? -$watts : $watts;
                    if ($watts < 0) {
                        $target = self::SIGNED_POWER[$key];
                        $watts = -$watts;
                    }
                }
                $values[$target] = ($values[$target] ?? 0.0) + $watts;
                $counts[$target] = ($counts[$target] ?? 0) + 1;
                if ($sensor['name'] !== '') {
                    $named[$target][$sensor['name']] = ($named[$target][$sensor['name']] ?? 0.0) + $watts;
                }
            }
        }
        $total = 0.0;
        $parts = [];
        foreach (self::BALANCE_TERMS as $key => [$sign, $word]) {
            if (isset($values[$key])) {
                $total += $sign * $values[$key];
                $parts[] = $this->partText($word, $values[$key], $named[$key] ?? [], $counts[$key] ?? 0, static fn (float $v): string => self::formatWatts($v));
            }
        }
        return ['total' => $total, 'parts' => $parts, 'values' => $values];
    }

    /**
     * show_chart mit period=now fürs ganze Haus: Ringe aus den Leistungen der Bilanz, zuerst die Hausleistung aus PV,
     * Batterie (entladen) und Netz auf einer Skala in ganzen kW, dann Autarkie und Eigenverbrauch wie über einen
     * Zeitraum. Das Modell bekommt die Antwort von get_state und die Prozentzahlen.
     *
     * @return array{text: string, isError: bool, structuredContent?: array<string, mixed>}
     */
    private function powerRings(array $arguments): array
    {
        $state = $this->getState($arguments);
        $house = $this->housePower($this->powerBalance, '');
        if ($state['isError'] || is_string($house)) {
            return $state['isError'] ? $state : $this->fail((string)strtok($state['text'], "\n"));
        }
        $values = $house['values'];
        $total = max(0.0, $house['total']);
        $largest = max($total, $values['solar'] ?? 0.0, $values['discharge'] ?? 0.0, $values['import'] ?? 0.0);
        $rings = [['key' => 'power', 'title' => $this->t('Current power'), 'label' => $this->t('house'), 'unit' => 'W',
            'total' => round($total, 1), 'max' => max(1000.0, ceil($largest / 1000) * 1000), 'parts' => [
                // Kurze Namen wie in den Ringen Autarkie und Eigenverbrauch daneben (Layoutvergleich der Diagramme 13.10.2026)
                ['key' => 'solar', 'name' => $this->t('PV'), 'value' => round($values['solar'] ?? 0.0, 1)],
                ['key' => 'battery', 'name' => $this->t('battery'), 'value' => round($values['discharge'] ?? 0.0, 1)],
                ['key' => 'grid', 'name' => $this->t('grid'), 'value' => round($values['import'] ?? 0.0, 1)],
            ]]];
        $battery = self::hasBattery($this->powerBalance);
        if (!$battery) {
            $rings[0]['parts'] = array_values(array_filter($rings[0]['parts'], static fn (array $part): bool => $part['key'] !== 'battery'));
        }
        $rings = array_merge($rings, $this->shareRings($values, 'W', $battery));
        $lines = [$state['text']];
        $shares = $this->selfSufficiency($values, $total);
        if ($shares !== null) {
            $lines[] = $shares;
        }
        $lines[] = $this->t('The chart is shown in the chat.');
        return ['text' => implode("\n", $lines), 'isError' => false,
            'structuredContent' => ['kind' => 'rings', 'title' => (string)strtok($state['text'], "\n"), 'at' => $this->backend->timestamp(), 'rings' => $rings]];
    }

    /**
     * Leistung einer Variable in W. Bei einem Fehler die Einheit, die nicht passt, oder null, wenn
     * die Variable fehlt oder keine Zahl ist.
     *
     * @return float|array{unit: ?string}
     */
    private function wattsOf(int $variableId): float|array
    {
        $reading = $this->backend->read($variableId);
        if ($reading === null || (!is_int($reading['value']) && !is_float($reading['value']))) {
            return ['unit' => null];
        }
        $unit = trim($this->backend->unit($variableId));
        $factor = self::POWER_UNITS[strtolower($unit)] ?? null;
        return $factor === null ? ['unit' => $unit] : $reading['value'] * $factor;
    }

    /** Zählt eine Variable mit dieser Einheit Leistung (W, kW, MW oder ohne Einheit)? */
    public static function isPowerUnit(string $unit): bool
    {
        return array_key_exists(strtolower(trim($unit)), self::POWER_UNITS);
    }

    /** Leistung auf ganze W gerundet. */
    private static function formatWatts(float $watts): string
    {
        return (string)(int)round($watts);
    }

    /**
     * Aggregationsstufe und Grenzen eines Zeitraums in der Zeitzone der Anlage. Laufende Zeiträume
     * enden mit 0 („bis jetzt“), damit das Archiv den angefangenen Tag, die Woche usw. mitzählt.
     *
     * @return array{0: int, 1: int, 2: int} Stufe (1 Tag, 2 Woche, 3 Monat, 4 Jahr), Beginn, Ende
     */
    private static function periodBounds(string $period, int $now): array
    {
        $today = (int)strtotime('today', $now);
        $week = (int)strtotime('monday this week', $now);
        $month = (int)strtotime('first day of this month 00:00', $now);
        $year = (int)mktime(0, 0, 0, 1, 1, (int)date('Y', $now));
        return match ($period) {
            'today'      => [1, $today, 0],
            'yesterday'  => [1, (int)strtotime('-1 day', $today), $today - 1],
            'this_week'  => [2, $week, 0],
            'last_week'  => [2, (int)strtotime('-1 week', $week), $week - 1],
            'this_month' => [3, $month, 0],
            'last_month' => [3, (int)strtotime('first day of last month 00:00', $now), $month - 1],
            'this_year'  => [4, $year, 0],
            default      => [4, (int)mktime(0, 0, 0, 1, 1, (int)date('Y', $now) - 1), $year - 1],
        };
    }

    /** Zählt ein Zähler mit dieser Einheit Energie (Wh, kWh, MWh oder ohne Einheit)? */
    public static function isEnergyUnit(string $unit): bool
    {
        return in_array(strtolower(trim($unit)), self::ENERGY_UNITS, true);
    }

    /** Ein Tag als JJJJ-MM-TT, 0 Uhr in der Zeitzone der Anlage; null, wenn es kein gültiges Datum ist. */
    private static function parseDay(string $text): ?int
    {
        $day = DateTime::createFromFormat('!Y-m-d', $text);
        return $day === false || $day->format('Y-m-d') !== $text ? null : $day->getTimestamp();
    }

    /**
     * Tag oder Tag mit Uhrzeit (JJJJ-MM-TT, JJJJ-MM-TT HH:MM, auch mit T und Sekunden). Mit Uhrzeit auf die ganze Stunde:
     * ein Beginn abgerundet, ein Ende ($end) aufgerundet; 24:00 ist der nächste Tag. null, wenn es nichts davon ist.
     *
     * @return null|array{time: int, hourly: bool}
     */
    private static function parseMoment(string $text, bool $end): ?array
    {
        if (preg_match('/^(\d{4}-\d\d-\d\d)(?:[ T](\d\d?):(\d\d)(?::\d\d)?)?$/', $text, $m) !== 1) {
            return null;
        }
        $day = self::parseDay($m[1]);
        if ($day === null) {
            return null;
        }
        if (!isset($m[2])) {
            return ['time' => $day, 'hourly' => false];
        }
        $hour = (int)$m[2];
        $minute = (int)$m[3];
        if ($minute > 59 || $hour > 24 || ($hour === 24 && $minute > 0)) {
            return null;
        }
        if ($end && $minute > 0) {
            $hour++;
        }
        // Über mktime, damit die Umstellung auf Sommer- oder Winterzeit stimmt
        return ['time' => (int)mktime($hour, 0, 0, (int)date('n', $day), (int)date('j', $day), (int)date('Y', $day)), 'hourly' => true];
    }

    /**
     * Stufe und Grenzen eines freien Zeitraums: ganze Jahre aus Jahreswerten, ganze Monate aus
     * Monatswerten, sonst Tage. Reicht er bis heute oder weiter, endet er mit 0 („bis jetzt“).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rangeBounds(int $first, int $last, int $now): array
    {
        $level = match (true) {
            date('m-d', $first) === '01-01' && date('m-d', $last) === '12-31' => 4,
            date('d', $first) === '01' && date('d', $last) === date('t', $last) => 3,
            default                                                            => 1,
        };
        $end = (int)strtotime('+1 day', $last) - 1;
        return [$level, $first, $end >= (int)strtotime('today', $now) ? 0 : $end];
    }

    /**
     * Zählersumme in kWh. Homematic zählt in Wh; ohne erkennbare Einheit gilt kWh.
     *
     * @param null|array{sum: float, unit: string} $counter
     */
    private static function kilowattHours(?array $counter): ?float
    {
        if ($counter === null) {
            return null;
        }
        return match (strtolower(trim($counter['unit']))) {
            'wh'    => $counter['sum'] / 1000,
            'mwh'   => $counter['sum'] * 1000,
            default => $counter['sum'],
        };
    }

    /**
     * Werkzeug „set_state“: schaltet oder stellt die passenden Geräte. Entweder ein Verb (on, off,
     * open, close) oder ein Wert (percent); on darf einen Wert über 0 begleiten. Geräte einer
     * Gruppe, die den Befehl nicht verstehen, werden ausgelassen; ein benanntes Gerät, das ihn
     * nicht versteht, ergibt eine Absage. Ein neues Feld (Uhrzeit, Sollwert, Farbe) kommt als
     * weitere Fähigkeit in abilitiesOf() und als weiterer Zweig hier dazu.
     *
     * @return array{text: string, isError: bool}
     */
    public function setState(array $arguments): array
    {
        $command = trim((string)($arguments['command'] ?? ''));
        $percent = $arguments['percent'] ?? null;
        $temperature = $arguments['temperature'] ?? null;
        $kelvin = $arguments['color_temperature'] ?? null;
        $color = $arguments['color'] ?? null;
        $volume = $arguments['volume'] ?? null;
        $volumeChange = $arguments['volume_change'] ?? null;
        $mute = $arguments['mute'] ?? null;
        if ($volume !== null && ((!is_int($volume) && !is_float($volume)) || $volume < 0 || $volume > 100)) {
            return $this->fail($this->t('Parameter "volume" must be a number from 0 to 100.'));
        }
        if ($volumeChange !== null && ((!is_int($volumeChange) && !is_float($volumeChange)) || $volumeChange < -100 || $volumeChange > 100 || round((float)$volumeChange) == 0)) {
            return $this->fail($this->t('Parameter "volume_change" must be a number from -100 to 100 other than 0.'));
        }
        if ($mute !== null && !is_bool($mute)) {
            return $this->fail($this->t('Parameter "mute" must be true or false.'));
        }
        $sound = array_filter([$volume, $volumeChange, $mute], static fn (mixed $v): bool => $v !== null);
        // Lautstärke darf mit play, pause oder stop kommen: spart dem Sprachmodell eine Runde (Leitbild „schnell vor
        // günstig“, Bedienungs-Blindtest 12.10.2026). Stumm bleibt allein.
        $withPlayback = $sound !== [] && $mute === null && in_array($command, ['play', 'pause', 'stop'], true);
        if ($sound !== [] && (count($sound) > 1 || ($command !== '' && !$withPlayback) || $percent !== null || $temperature !== null || $kelvin !== null || $color !== null)) {
            return $this->fail($this->t('Nothing was changed. Give volume or volume_change alone or with command play, pause or stop, and mute alone.'));
        }
        // Auswahl per Name: Betriebsart und Lüfterstufe, ein Weg für beide (runOption).
        $mode = $arguments['mode'] ?? null;
        $fanSpeed = $arguments['fan_speed'] ?? null;
        foreach (['mode' => $mode, 'fan_speed' => $fanSpeed] as $field => $value) {
            if ($value !== null && (!is_string($value) || trim($value) === '')) {
                return $this->fail(sprintf($this->t('Parameter "%s" must be the name of an entry.'), $field));
            }
        }
        $choice = array_filter([$mode, $fanSpeed], static fn (mixed $v): bool => $v !== null);
        $humidity = $arguments['humidity'] ?? null;
        if ($humidity !== null && ((!is_int($humidity) && !is_float($humidity)) || $humidity < 0 || $humidity > 100)) {
            return $this->fail($this->t('Parameter "humidity" must be a number from 0 to 100.'));
        }
        if ($humidity !== null && ($command !== '' || $percent !== null || $temperature !== null || $kelvin !== null || $color !== null || $sound !== [] || $choice !== [])) {
            return $this->fail($this->t('Nothing was changed. Give humidity alone, without command, percent or temperature.'));
        }
        if ($choice !== [] && (count($choice) > 1 || $command !== '' || $percent !== null || $temperature !== null || $kelvin !== null || $color !== null || $sound !== [])) {
            return $this->fail($this->t('Nothing was changed. Give mode or fan_speed alone, without another field.'));
        }
        if ($color !== null) {
            $key = DeviceIndex::normalize((string)$color);
            $key = self::COLOR_WORDS[$key] ?? $key;
            if (!isset(self::COLORS[$key])) {
                return $this->fail(sprintf($this->t('Unknown colour "%s". Allowed: %s.'), (string)$color, implode(', ', array_keys(self::COLORS))));
            }
            if ($command !== '' || $percent !== null || $temperature !== null || $kelvin !== null) {
                return $this->fail($this->t('Nothing was changed. Give color alone, without command, percent, temperature or color_temperature.'));
            }
            $color = $key;
        }
        if ($kelvin !== null && ((!is_int($kelvin) && !is_float($kelvin)) || $kelvin < 1000 || $kelvin > 20000)) {
            return $this->fail($this->t('Parameter "color_temperature" must be a number in Kelvin from 1000 to 20000.'));
        }
        if ($kelvin !== null && ($command !== '' || $percent !== null || $temperature !== null)) {
            return $this->fail($this->t('Nothing was changed. Give color_temperature alone, without command, percent or temperature.'));
        }
        if ($temperature !== null && !is_int($temperature) && !is_float($temperature)) {
            return $this->fail($this->t('Parameter "temperature" must be a number in °C.'));
        }
        if ($temperature !== null && ($command !== '' || $percent !== null)) {
            return $this->fail($this->t('Nothing was changed. Give only one of command, percent and temperature.'));
        }
        if ($command !== '' && !in_array($command, self::COMMANDS, true)) {
            return $this->fail(sprintf($this->t('Unknown command "%s". Allowed: %s.'), $command, implode(', ', self::COMMANDS)));
        }
        if ($percent !== null && ((!is_int($percent) && !is_float($percent)) || $percent < 0 || $percent > 100)) {
            return $this->fail($this->t('Parameter "percent" must be a number from 0 to 100.'));
        }
        if ($command === '' && $percent === null && $temperature === null && $kelvin === null && $color === null && $sound === [] && $humidity === null && $choice === []) {
            return $this->fail(sprintf($this->t('Nothing was changed: give a command (%s), a percent value or a temperature.'), implode(', ', self::COMMANDS)));
        }
        // Nur „on“ verträgt einen Wert, und dann einen über 0: „an und 0 %“ wäre zugleich aus.
        if ($percent !== null && $command !== '' && ($command !== 'on' || (float)$percent <= 0.0)) {
            return $this->fail(sprintf($this->t('Nothing was changed: command "%s" and percent %s contradict each other. Give only one of them.'), $command, self::formatNumber((float)$percent)));
        }

        // „Weiter“ ohne Raum wie HassMediaUnpause: die Mediaplayer, die in der letzten Stunde per
        // Werkzeug pausiert wurden. Ohne Merker bleibt es bei der Absage ohne Auswahl.
        $selection = $command === 'play' ? $this->recentlyPaused($arguments) : null;
        $selection ??= $this->selectControllable($arguments);
        if (isset($selection['error'])) {
            return $this->fail($selection['error']);
        }
        $filter = $selection['filter'];
        $name = trim((string)($arguments['name'] ?? ''));
        $type = trim((string)($arguments['type'] ?? ''));

        // Was verlangt ist, heißt wie das Feld des Werkzeugs; so kann der Assistent es korrigieren.
        $ability = $mode !== null ? 'mode' : ($fanSpeed !== null ? 'fan_speed' : ($mute !== null ? 'mute' : ($volumeChange !== null ? 'volume_change' : ($volume !== null ? 'volume' : ($color !== null ? 'color' : ($kelvin !== null ? 'color_temperature' : ($humidity !== null ? 'humidity' : ($temperature !== null ? 'temperature' : ($percent !== null ? 'percent' : $command)))))))));
        $understand = array_filter($selection['entries'], static fn (array $e): bool => in_array($ability, self::abilitiesOf($e), true) && (!$withPlayback || in_array($command, self::abilitiesOf($e), true)));
        $skipped = count($selection['entries']) - count($understand) + ($selection['windowsWithoutDrive'] ?? 0);
        if ($understand === []) {
            $this->problem = 'not_understood';
            $known = self::offeredAbilities($selection['entries']);
            $list = $known === [] ? $this->t('(none)') : implode(', ', $known);
            if ($name !== '') {
                $entry = $selection['entries'][0];
                $label = $entry['Name'] . ($entry['Room'] !== '' ? ' (' . $entry['Room'] . ')' : '');
                return $this->fail(sprintf($this->t('Nothing was changed: %s cannot do "%s". It understands: %s.'), $label, $ability, $list));
            }
            return $this->fail(sprintf($this->t('Nothing was changed: no device matching %s understands "%s". They understand: %s.'), $filter, $ability, $list));
        }
        // „Wohnzimmer auf 50 %“ meint Helligkeit oder Öffnung, nicht beides auf einmal. Rollläden und
        // Fenster zählen beide als Öffnungsgrad und dürfen zusammen gestellt werden.
        if ($percent !== null && $type === '' && $name === '') {
            $types = array_column($understand, 'Type');
            if (in_array('light', $types, true) && in_array('blind', $types, true)) {
                return $this->fail(sprintf($this->t('Nothing was changed: percent would set lights and blinds at once (%s). Give the type.'), $filter));
            }
            if (in_array('light', $types, true) && in_array('window', $types, true)) {
                return $this->fail(sprintf($this->t('Nothing was changed: percent would set lights and windows at once (%s). Give the type.'), $filter));
            }
        }

        $closing = $skipped > 0 ? [sprintf($this->t('%d device(s) of the selection do not understand "%s" and were left out.'), $skipped, $ability)] : [];
        if ($skipped > 0) {
            $this->problem = 'left_out';
        }
        if ($color !== null) {
            $result = $this->runColor($understand, self::COLORS[$color]);
            $value = $this->t($color);
            $head = $result['pending'] > 0
                ? sprintf($this->t('%d of %d devices set to %s, %d not confirmed yet (%s).'), $result['reached'], $result['total'], $value, $result['pending'], $filter)
                : sprintf($this->t('%d of %d devices set to %s (%s).'), $result['reached'], $result['total'], $value, $filter);
            return $this->answer($head, $result, $closing);
        }
        if ($kelvin !== null) {
            return $this->setpointAnswer($this->runColorTemperature($understand, (float)$kelvin), $filter, $closing);
        }
        if ($choice !== []) {
            $result = $this->runOption($understand, $mode !== null ? 'mode' : 'fan', (string)($mode ?? $fanSpeed));
            $head = $result['pending'] > 0
                ? sprintf($this->t('%d of %d devices set to %s, %d not confirmed yet (%s).'), $result['reached'], $result['total'], $result['caption'], $result['pending'], $filter)
                : sprintf($this->t('%d of %d devices set to %s (%s).'), $result['reached'], $result['total'], $result['caption'], $filter);
            return $this->answer($head, $result, $closing);
        }
        if ($humidity !== null) {
            return $this->setpointAnswer($this->runSetpoint($understand, (float)$humidity), $filter, $closing);
        }
        if ($temperature !== null) {
            return $this->setpointAnswer($this->runSetpoint($understand, (float)$temperature), $filter, $closing);
        }
        if ($percent !== null) {
            return $this->levelAnswer($this->runLevel($understand, (float)$percent), (float)$percent, $filter, $closing);
        }
        if ($mute !== null) {
            return $this->switchAnswer($this->runMute($understand, $mute), $this->t($mute ? 'muted' : 'unmuted'), $filter, $closing);
        }
        if ($withPlayback) {
            // Erst die Lautstärke, damit „leise an“ nicht kurz laut beginnt, dann der Befehl.
            $result = self::combineResults($this->runVolume($understand, $volume, $volumeChange), $this->runPlayback($understand, $command));
            $word = $this->t(['play' => 'playing back', 'pause' => 'paused', 'stop' => 'stopped'][$command]) . ($volume !== null
                ? ' ' . sprintf($this->t('at volume %s'), self::formatNumber(round((float)$volume)) . ' %')
                : ', ' . $this->t($volumeChange > 0 ? 'louder' : 'quieter'));
            return $this->switchAnswer($result, $word, $filter, $closing);
        }
        if ($volume !== null || $volumeChange !== null) {
            $word = $volume !== null
                ? sprintf($this->t('at volume %s'), self::formatNumber(round((float)$volume)) . ' %')
                : $this->t($volumeChange > 0 ? 'louder' : 'quieter');
            return $this->switchAnswer($this->runVolume($understand, $volume, $volumeChange), $word, $filter, $closing);
        }
        if ($command === 'stop') {
            // Rollläden halten an, Mediaplayer beenden die Wiedergabe; beides in einer Antwort.
            $media = array_filter($understand, static fn (array $e): bool => $e['Type'] === 'media');
            $moving = array_diff_key($understand, $media);
            $result = $moving === [] ? null : $this->runStop($moving);
            if ($media !== []) {
                $result = self::mergeResults($result, $this->runPlayback($media, 'stop'));
            }
            return $this->switchAnswer($result, $this->t('stopped'), $filter, $closing);
        }
        if (in_array($command, ['play', 'pause', 'next', 'previous'], true)) {
            $word = ['play' => 'playing back', 'pause' => 'paused', 'next' => 'at the next track', 'previous' => 'at the previous track'][$command];
            return $this->switchAnswer($this->runPlayback($understand, $command), $this->t($word), $filter, $closing);
        }
        if ($command === 'open' || $command === 'close') {
            // Ganz auf und ganz zu als Öffnungsgrad; welchen Rohwert das am Gerät meint, regelt die Richtungserkennung.
            $result = $this->runLevel($understand, $command === 'open' ? 100.0 : 0.0);
            return $this->switchAnswer($result, $this->t($command === 'open' ? 'open' : 'closed'), $filter, $closing);
        }
        // on und off: Geräte mit Schaltzustand werden geschaltet. Ein Licht, das nur eine Stufe
        // hat, geht auf 100 % bzw. 0 %.
        $on = $command === 'on';
        $word = $this->t($on ? 'on' : 'off');
        // Ein Klimagerät geht über den Modus aus; seine Rolle „state“ ist die Ist-Temperatur (Burkhard 13.10.2026).
        $climate = array_filter($understand, static fn (array $e): bool => $e['Type'] === 'thermostat');
        $understand = array_diff_key($understand, $climate);
        $result = $climate === [] ? null : $this->runOption($climate, 'mode', 'off');
        $withState = array_filter($understand, static fn (array $e): bool => isset($e['roles']['state']));
        $levelOnly = array_diff_key($understand, $withState);
        if ($withState !== []) {
            $result = self::mergeResults($result, $this->runSwitch($withState, $on));
        }
        if ($levelOnly !== []) {
            // Ein Licht ohne Schalter mit Farbe (RGBW-Leiste) leuchtet mit Stufe 0 weiter, solange die
            // Farbe nicht schwarz ist; „aus“ setzt sie deshalb mit auf 0. Erst nach der Stufe: Das
            // IPS-868-Modul schreibt bei jedem Weißbefehl den alten Farbwert mit (nuc, 10.10.2026), ein
            // vorher gesendetes Schwarz wäre wieder weg. Steht die Stufe schon auf 0, geht sie nicht hinaus.
            $colored = [];
            if (!$on) {
                foreach ($levelOnly as $index => $entry) {
                    $rgb = isset($entry['roles']['color']) ? ($this->backend->read($entry['roles']['color'])['value'] ?? null) : null;
                    if (is_int($rgb) && $rgb !== 0 && $this->backend->hasAction($entry['roles']['color'])) {
                        $colored[$index] = $entry;
                    }
                }
            }
            $dark = array_filter($colored, fn (array $e): bool => ($this->backend->read($e['roles']['level'])['value'] ?? null) == 0);
            $withLevel = array_diff_key($levelOnly, $dark);
            if ($withLevel !== []) {
                $result = self::mergeResults($result, $this->runLevel($withLevel, $on ? 100.0 : 0.0, $word));
            }
            if ($colored !== []) {
                // Ein Farbbefehl, der sich nicht auslösen ließ, ist ein Fehlschlag (Code-Review 12.10.2026).
                $errors = [];
                foreach ($colored as $index => $entry) {
                    $error = $this->backend->request($entry['roles']['color'], 0);
                    if ($error !== null) {
                        $errors[$index] = $error;
                    }
                }
                $black = fn (array $e): bool => ($this->backend->read($e['roles']['color'])['value'] ?? null) === 0;
                $this->waitUntil(function () use ($colored, $errors, $black): bool {
                    foreach ($colored as $index => $entry) {
                        if (!isset($errors[$index]) && !$black($entry) && $this->backend->failure($entry['roles']['color']) === null) {
                            return false;
                        }
                    }
                    return true;
                });
                // Aus ist die Leiste erst, wenn Weißkanal und Farbe bestätigt sind: Eine Zeile „aus“ aus
                // runLevel gilt nur, solange die Farbe mitzieht, sonst ersetzt sie die Zeile der Farbe
                // (Code-Review 12.10.2026, vorher meldete „aus“ eine Leiste, die noch farbig leuchtete).
                $lines = ['lines' => [], 'total' => count($dark), 'reached' => 0, 'pending' => 0, 'failed' => 0];
                foreach ($colored as $index => $entry) {
                    $levelStatus = $result['status'][$index] ?? null;
                    if ($levelStatus !== null && $levelStatus !== 'reached') {
                        continue;
                    }
                    $error = $errors[$index] ?? $this->backend->failure($entry['roles']['color']);
                    $done = $error === null && $black($entry);
                    $status = $error !== null ? 'failed' : ($done ? 'reached' : 'pending');
                    $text = $this->stateOf($entry)['text'];
                    $line = $this->line($entry, match ($status) {
                        'failed'  => sprintf($this->t('failed: %s'), $error),
                        'reached' => $text,
                        default   => sprintf($this->t('sent, but the device has not confirmed yet (currently %s)'), $text),
                    });
                    if ($levelStatus === 'reached') {
                        $result['reached']--;
                        $result[$status]++;
                        $result['status'][$index] = $status;
                        $result['lines'][$index] = $line;
                    } else {
                        $lines[$status]++;
                        $lines['status'][$index] = $status;
                        $lines['lines'][$index] = $line;
                    }
                }
                $result = $result === null ? $lines : self::mergeResults($result, $lines);
            }
        }
        return $this->switchAnswer($result, $word, $filter, $closing);
    }

    /**
     * Was ein Gerät versteht, in den Wörtern des Werkzeugs „set_state“: Verben und „percent“. Es
     * ergibt sich allein aus Typ und Fähigkeiten der Zeile; ob die Variable eine Aktion hat, zeigt
     * erst die Antwort. Ein Gerät mit „nur abfragen“ versteht nichts.
     *
     * @param array<string, mixed> $row Zeile der Freigabeliste
     * @return list<string>
     */
    public static function abilitiesOf(array $row): array
    {
        $type = (string)($row['Type'] ?? '');
        if (($row['ReadOnly'] ?? false) === true) {
            return [];
        }
        $roles = is_array($row['roles'] ?? null) ? $row['roles'] : self::rolesOf($row);
        // Rollläden und Fenster mit Antrieb fahren: ganz auf, ganz zu oder auf einen Öffnungsgrad.
        // Anhalten nur mit einem Stoppweg, den die Freigabeliste als „StopVia“ einträgt.
        if ($type === 'blind' || $type === 'window') {
            if (!isset($roles['level'])) {
                return [];
            }
            return ($row['StopVia'] ?? '') !== '' ? ['open', 'close', 'stop', 'percent'] : ['open', 'close', 'percent'];
        }
        // Ein Thermostat stellt man auf eine Temperatur; ein Klimagerät hat dazu Modus und Lüfterstufe per Name
        // (B19). „off“ setzt dort den Modus „off“; „on“ gibt es nicht, Heizen, Kühlen und Auto wären alle möglich.
        if ($type === 'thermostat') {
            return isset($roles['setpoint']) ? array_merge(['temperature'], isset($roles['mode']) ? ['mode'] : [], isset($roles['fan']) ? ['fan_speed'] : [], isset($roles['mode']) ? ['off'] : []) : [];
        }
        // Ein Luftentfeuchter schaltet und stellt seine Zielfeuchte; eine Lüftung schaltet, Modus und Lüfterstufe
        // gehen bei beiden per Name.
        if ($type === 'humidifier' || $type === 'fan') {
            return array_merge(isset($roles['state']) ? ['on', 'off'] : [], isset($roles['setpoint']) ? ['humidity'] : [], isset($roles['mode']) ? ['mode'] : [], isset($roles['fan']) ? ['fan_speed'] : []);
        }
        // Ein Mediaplayer versteht die Wiedergabebefehle; welche das Gerät kennt, sagt erst sein Profil.
        if ($type === 'media') {
            // Ein/Aus nur mit Schaltzustand; ein Statustext ohne Aktion (HomeAssistant-Modul) wird beim Schalten abgewiesen.
            return array_merge(
                isset($roles['state']) ? ['on', 'off'] : [],
                isset($roles['playback']) ? ['stop', 'play', 'pause', 'next', 'previous'] : [],
                isset($roles['volume']) ? ['volume', 'volume_change'] : [],
                isset($roles['mute']) ? ['mute'] : []
            );
        }
        if (!in_array($type, self::CONTROLLABLE, true)) {
            return [];
        }
        // Ohne Schaltzustand und ohne Stufe (Wärmepumpe nur mit Leistung) gibt es nichts zu schalten.
        if (!isset($roles['state']) && !isset($roles['level'])) {
            return [];
        }
        $abilities = isset($roles['level']) ? ['on', 'off', 'percent'] : ['on', 'off'];
        if ($type === 'light' && isset($roles['colortemp'])) {
            $abilities[] = 'color_temperature';
        }
        if ($type === 'light' && isset($roles['color'])) {
            $abilities[] = 'color';
        }
        return $abilities;
    }

    /**
     * Was die Geräte einer Liste zusammen verstehen, in fester Reihenfolge. Daraus baut das Modul
     * das Schema von „set_state“: angeboten wird nur, was es gibt.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public static function offeredAbilities(array $rows): array
    {
        $found = [];
        foreach ($rows as $row) {
            $found = array_merge($found, self::abilitiesOf($row));
        }
        return array_values(array_intersect(array_merge(self::COMMANDS, ['percent', 'temperature', 'humidity', 'color_temperature', 'color', 'volume', 'volume_change', 'mute', 'mode', 'fan_speed']), $found));
    }

    /**
     * Schaltet die passenden Geräte ein oder aus und meldet je Gerät, was daraus geworden ist. Der
     * Weg hinter „set_state“ mit on und off und die Skriptfunktion HAGT_SwitchDevices. Ein Name
     * muss genau ein Gerät treffen; Gruppen laufen über Raum und Typ.
     *
     * @return array{text: string, isError: bool}
     */
    public function switchDevices(array $arguments): array
    {
        if (!is_bool($arguments['on'] ?? null)) {
            return $this->fail($this->t('Parameter "on" is required: true switches on, false switches off.'));
        }
        // Derselbe Weg wie set_state: Lichter nur mit Stufe gehen auf 100 % bzw. 0 %, Rollläden fallen heraus.
        $command = ['command' => $arguments['on'] ? 'on' : 'off'];
        return $this->setState(array_intersect_key($arguments, array_flip(['room', 'floor', 'type', 'name'])) + $command);
    }

    /**
     * Stellt Helligkeit oder Position in Prozent. 0 % schaltet ein Gerät mit eigenem Schalter aus;
     * ein ausgeschaltetes Gerät wird beim Stellen eingeschaltet. Der Weg hinter „set_state“ mit
     * percent, open und close und die Skriptfunktion HAGT_SetLevel.
     *
     * @return array{text: string, isError: bool}
     */
    public function setLevel(array $arguments): array
    {
        $percent = $arguments['percent'] ?? null;
        if ((!is_int($percent) && !is_float($percent)) || $percent < 0 || $percent > 100) {
            return $this->fail($this->t('Parameter "percent" is required and must be a number from 0 to 100.'));
        }
        $selection = $this->selectControllable($arguments);
        if (isset($selection['error'])) {
            return $this->fail($selection['error']);
        }
        return $this->levelAnswer($this->runLevel($selection['entries'], (float)$percent), (float)$percent, $selection['filter']);
    }

    /**
     * Schaltet die Geräte und wartet gemeinsam auf ihre Rückmeldung.
     *
     * @param array<int, array<string, mixed>> $entries Geräte, die Schlüssel bestimmen die Reihenfolge der Zeilen
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private function runSwitch(array $entries, bool $on): array
    {
        // Erst alle Aktionen auslösen, dann gemeinsam auf die Rückmeldungen warten. Die Aktionen
        // laufen nebenher; ein Gerät, dessen Aktion lange braucht, hält die Antwort nicht auf.
        $jobs = [];
        foreach ($entries as $index => $entry) {
            // Geschaltet wird die Fähigkeit „state“. Ein Gerät ohne sie (Rollladen, Dimmer nur mit
            // Stufe) ist nicht schaltbar; fehlt seine einzige Variable, wird das gemeldet.
            $state = $entry['roles']['state'] ?? 0;
            $job = ['entry' => $entry, 'variable' => $state, 'target' => $on !== $entry['Invert'], 'note' => null, 'done' => false];
            $current = $this->backend->read($this->mainVariable($entry));
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $this->mainVariable($entry));
            } elseif ($state === 0 || !is_bool($current['value']) || !$this->backend->hasAction($state)) {
                $job['note'] = $this->t('cannot be switched (no switchable state variable)');
            } elseif ($current['value'] === $job['target']) {
                $job['note'] = sprintf($this->t('already %s'), $this->t($on ? 'on' : 'off'));
                $job['done'] = true;
            } else {
                $error = $this->backend->request($state, $job['target']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                }
            }
            $jobs[$index] = $job;
        }

        $this->waitUntil(function () use ($jobs): bool {
            foreach ($jobs as $job) {
                if ($job['note'] === null
                    && ($this->backend->read($job['variable'])['value'] ?? null) !== $job['target']
                    && $this->backend->failure($job['variable']) === null) {
                    return false;
                }
            }
            return true;
        });

        $result = ['lines' => [], 'total' => count($jobs), 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($jobs as $index => $job) {
            if ($job['note'] === null) {
                $confirmed = ($this->backend->read($job['variable'])['value'] ?? null) === $job['target'];
                $error = $confirmed ? null : $this->backend->failure($job['variable']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                    $result['failed']++;
                } else {
                    $job['done'] = $confirmed;
                    $job['note'] = $confirmed ? $this->t($on ? 'on' : 'off') : $this->t('sent, but the device has not confirmed yet');
                    $result['pending'] += $confirmed ? 0 : 1;
                }
            } elseif (!$job['done']) {
                $result['failed']++;
            }
            if ($job['done']) {
                $result['reached']++;
            }
            $result['lines'][$index] = $this->line($job['entry'], $job['note']);
        }
        return $result;
    }

    /**
     * Stellt die Geräte auf einen Prozentwert und wartet gemeinsam auf ihre Rückmeldung.
     *
     * @param array<int, array<string, mixed>> $entries Geräte, die Schlüssel bestimmen die Reihenfolge der Zeilen
     * @param null|string $reachedWord Wort für ein Gerät am Ziel, wenn nicht die Stufe genannt werden soll („an“, „aus“)
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private function runLevel(array $entries, float $percent, ?string $reachedWord = null): array
    {
        // Ein ausgeschaltetes Gerät mit eigenem Schalter wird gleich nach dem Stellen eingeschaltet,
        // ohne erst auf die Bestätigung der Stufe zu warten: Manche Dimmer nehmen die Stufe im
        // ausgeschalteten Zustand nicht an, dann gingen sonst 2 s verloren. Die Reihenfolge bleibt
        // erst Stufe, dann Einschalten. Ein Dimmer, der beim Stellen selbst einschaltet, bekommt
        // damit einen zweiten, harmlosen Befehl.
        $jobs = [];
        $switching = [];
        foreach ($entries as $index => $entry) {
            $jobs[$index] = $this->startLevelJob($entry, $percent);
            if ($jobs[$index]['note'] !== null) {
                continue;
            }
            $state = $this->stateToSwitchOn($jobs[$index]);
            if ($state === 0) {
                continue;
            }
            $error = $this->backend->request($state, !$entry['Invert']);
            if ($error !== null) {
                $jobs[$index]['note'] = sprintf($this->t('failed: %s'), $error);
            } else {
                $switching[$index] = $state;
            }
        }

        // Gemeinsam warten, bis jede Stufe und jedes Einschalten bestätigt oder gescheitert ist. Ein
        // Rollladen, der sichtbar fährt, wird nicht bis zum Ziel abgewartet.
        $this->waitUntil(function () use ($jobs, $switching): bool {
            foreach ($jobs as $index => $job) {
                if ($job['note'] === null && !$this->levelReached($job) && !$this->isMoving($job) && $this->backend->failure($job['variable']) === null) {
                    return false;
                }
                if (isset($switching[$index]) && !$this->isSwitchedOn($job, $switching[$index]) && $this->backend->failure($switching[$index]) === null) {
                    return false;
                }
            }
            return true;
        });

        $result = ['lines' => [], 'total' => count($jobs), 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($jobs as $index => $job) {
            if ($job['note'] === null && self::moves($job['entry'])) {
                // Das Ziel merken, solange die Fahrt läuft: Viele Rollläden melden ihre Position erst
                // am Ende, eine Abfrage zwischendurch soll das Ziel nennen (Befund 4 des Blindtests).
                $underway = !$this->levelReached($job) && $this->backend->failure($job['variable']) === null;
                $this->backend->rememberTravel($job['variable'], $underway ? ['target' => $job['target'], 'since' => $this->backend->now()] : null);
            }
            if ($job['note'] === null) {
                $error = $this->levelReached($job) ? null : $this->backend->failure($job['variable']);
                $switchedOn = !isset($switching[$index]) || $this->isSwitchedOn($job, $switching[$index]);
                if ($error === null && !$switchedOn) {
                    $error = $this->backend->failure($switching[$index]);
                }
                $now = $this->backend->read($job['variable']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                    $result['failed']++;
                    $result['status'][$index] = 'failed';
                } elseif ($this->levelReached($job) && $switchedOn) {
                    $job['note'] = $job['off'] ? $this->t('off') : ($reachedWord ?? $this->levelText($job['entry'], $now));
                    $result['reached']++;
                    $result['status'][$index] = 'reached';
                } elseif ($this->levelReached($job)) {
                    // Die Stufe stimmt, das Einschalten ist aber noch nicht bestätigt.
                    $result['pending']++;
                    $result['status'][$index] = 'pending';
                    $job['note'] = sprintf($this->t('sent, but the device has not confirmed yet (currently %s)'), $this->backend->read($switching[$index])['formatted'] ?? '?');
                } else {
                    $result['pending']++;
                    $result['status'][$index] = 'pending';
                    if (self::moves($job['entry'])) {
                        $result['underway'] = ($result['underway'] ?? 0) + 1;
                    }
                    // Viele Rollläden melden ihre Position erst am Ende der Fahrt (Velux KLF200), andere
                    // unterwegs. Beides ist kein Fehlschlag und bekommt deshalb einen eigenen Text.
                    $text = match (true) {
                        !self::moves($job['entry'])       => 'sent, but the device has not confirmed yet (currently %s)',
                        $this->isMoving($job)             => 'moving (currently %s)',
                        default                           => 'command sent, no position report yet (currently %s)',
                    };
                    $job['note'] = sprintf($this->t($text), $this->levelText($job['entry'], $now, '?'));
                }
            } else {
                $result['failed']++;
                $result['status'][$index] = 'failed';
            }
            $result['lines'][$index] = $this->line($job['entry'], $job['note']);
        }
        return $result;
    }

    /**
     * Stellt den Sollwert der Thermostate und wartet gemeinsam auf die Rückmeldung. Werte außerhalb
     * des Bereichs gehen nicht hinaus, krumme Werte gehen auf die Schrittweite. Achtung beim
     * Bestätigen: Homematic meldet den neuen Sollwert schon nach rund 0,3 s, das Thermostat selbst
     * übernimmt ihn erst beim nächsten Funkkontakt (HM-CC-TC am nuc, 10.10.2026).
     *
     * @param array<int, array<string, mixed>> $entries Geräte, die Schlüssel bestimmen die Reihenfolge der Zeilen
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int, target: float}
     */
    private function runSetpoint(array $entries, float $temperature): array
    {
        $jobs = [];
        $targets = [];
        foreach ($entries as $index => $entry) {
            $variable = $entry['roles']['setpoint'];
            $unit = $this->temperatureUnit($variable, $entry['Type']);
            $job = ['entry' => $entry, 'variable' => $variable, 'target' => $temperature, 'note' => null, 'done' => false];
            $current = $this->backend->read($variable);
            $range = $this->backend->setpointRange($variable);
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $variable);
            } elseif (!$this->backend->hasAction($variable)) {
                $job['note'] = $this->t('cannot be set (the setpoint has no action)');
            } elseif ($range !== null && ($temperature < $range['min'] - 0.001 || $temperature > $range['max'] + 0.001)) {
                $job['note'] = sprintf($this->t('not set: %s is outside %s to %s'), self::formatNumber($temperature) . ' ' . $unit, self::formatNumber($range['min']), self::formatNumber($range['max']) . ' ' . $unit);
            } else {
                if ($range !== null && $range['step'] > 0) {
                    $job['target'] = round($range['min'] + round(($temperature - $range['min']) / $range['step']) * $range['step'], 3);
                    $job['target'] = min($range['max'], max($range['min'], $job['target']));
                }
                $targets[] = $job['target'];
                if ((is_int($current['value']) || is_float($current['value'])) && abs($current['value'] - $job['target']) < 0.01) {
                    $job['note'] = sprintf($this->t('already set to %s'), self::formatNumber($job['target']) . ' ' . $unit);
                    $job['done'] = true;
                } else {
                    $error = $this->backend->request($variable, (float)$job['target']);
                    if ($error !== null) {
                        $job['note'] = sprintf($this->t('failed: %s'), $error);
                    }
                }
            }
            $jobs[$index] = $job;
        }

        $reached = function (array $job): bool {
            $value = $this->backend->read($job['variable'])['value'] ?? null;
            return (is_int($value) || is_float($value)) && abs($value - $job['target']) < 0.01;
        };
        $this->waitUntil(function () use ($jobs, $reached): bool {
            foreach ($jobs as $job) {
                if ($job['note'] === null && !$reached($job) && $this->backend->failure($job['variable']) === null) {
                    return false;
                }
            }
            return true;
        });

        $result = ['lines' => [], 'total' => count($jobs), 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($jobs as $index => $job) {
            if ($job['note'] === null) {
                $error = $reached($job) ? null : $this->backend->failure($job['variable']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                    $result['failed']++;
                } elseif ($reached($job)) {
                    $job['note'] = $this->mainStateOf($job['entry'])['text'];
                    $result['reached']++;
                } else {
                    $job['note'] = sprintf($this->t('sent, but the device has not confirmed yet (currently %s)'), $this->setpointText($job['entry']));
                    $result['pending']++;
                }
            } elseif ($job['done']) {
                $result['reached']++;
            } else {
                $result['failed']++;
            }
            $result['lines'][$index] = $this->line($job['entry'], $job['note']);
        }
        // Die Kopfzeile nennt den gesendeten Wert, wenn er für alle gleich ist, sonst den verlangten.
        $result['target'] = count(array_unique(array_map('strval', $targets))) === 1 ? (float)$targets[0] : $temperature;
        $first = $entries === [] ? null : array_first($entries);
        $result['unit'] = $first === null ? '°C' : $this->temperatureUnit((int)$first['roles']['setpoint'], $first['Type']);
        return $result;
    }

    /**
     * Stellt die Farbtemperatur der Lichter und schaltet ausgeschaltete mit eigenem Schalter ein, wie
     * beim Stellen der Helligkeit. Werte außerhalb des Bereichs gehen auf das nächste Ende. Die
     * Schrittweite der Darstellung ist nur das Raster des Schiebereglers und zählt nicht: Die Hue
     * Aurelle über Matter nahm 250 Mired an, obwohl ihr Regler in Fünfern ab 153 läuft (Testbox,
     * 10.10.2026). Das Matter-Modul setzt die Variable sofort, auch bei ausgeschalteter Lampe.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int, target: float, unit: string}
     */
    private function runColorTemperature(array $entries, float $kelvin): array
    {
        $jobs = [];
        $sent = [];
        foreach ($entries as $index => $entry) {
            $variable = $entry['roles']['colortemp'];
            $job = ['entry' => $entry, 'variable' => $variable, 'target' => null, 'note' => null];
            $current = $this->backend->read($variable);
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $variable);
            } elseif (!$this->backend->hasAction($variable)) {
                $job['note'] = $this->t('cannot be set (the colour temperature has no action)');
            } else {
                $mired = $this->isMired($variable);
                $raw = $mired ? 1000000 / $kelvin : $kelvin;
                $range = $this->backend->setpointRange($variable);
                if ($range !== null) {
                    $raw = min($range['max'], max($range['min'], $raw));
                }
                $job['target'] = (int)round($raw);
                $sent[] = $this->kelvinOf($variable, $job['target']);
                $error = $this->backend->request($variable, $job['target']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                } elseif (isset($entry['roles']['state']) && ($this->backend->read($entry['roles']['state'])['value'] ?? null) === false) {
                    $this->backend->request($entry['roles']['state'], true);
                }
            }
            $jobs[$index] = $job;
        }

        $reached = fn (array $job): bool => ($this->backend->read($job['variable'])['value'] ?? null) == $job['target'];
        $this->waitUntil(function () use ($jobs, $reached): bool {
            foreach ($jobs as $job) {
                if ($job['note'] === null && !$reached($job) && $this->backend->failure($job['variable']) === null) {
                    return false;
                }
            }
            return true;
        });

        $result = ['lines' => [], 'total' => count($jobs), 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($jobs as $index => $job) {
            if ($job['note'] === null) {
                $error = $reached($job) ? null : $this->backend->failure($job['variable']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                    $result['failed']++;
                } elseif ($reached($job)) {
                    $job['note'] = $this->stateOf($job['entry'])['text'];
                    $result['reached']++;
                } else {
                    $job['note'] = sprintf($this->t('sent, but the device has not confirmed yet (currently %s)'), $this->stateOf($job['entry'])['text']);
                    $result['pending']++;
                }
            } else {
                $result['failed']++;
            }
            $result['lines'][$index] = $this->line($job['entry'], $job['note']);
        }
        $result['target'] = count(array_unique($sent)) === 1 ? (float)$sent[0] : $kelvin;
        $result['unit'] = 'K';
        return $result;
    }

    /**
     * Stellt die Farbe der Lichter als RGB-Ganzzahl und schaltet ausgeschaltete mit eigenem Schalter
     * ein. Eine Farbe als Text (Darstellung Farbe mit Kodierung) geht nicht hinaus: Die Doku nennt
     * das Textformat nicht (Doku-Feedback 10.10.2026).
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private function runColor(array $entries, int $rgb): array
    {
        $jobs = [];
        foreach ($entries as $index => $entry) {
            $variable = $entry['roles']['color'];
            $job = ['entry' => $entry, 'variable' => $variable, 'note' => null];
            $current = $this->backend->read($variable);
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $variable);
            } elseif (!is_int($current['value'])) {
                $job['note'] = $this->t('not set: colour as text is not supported yet');
            } elseif (!$this->backend->hasAction($variable)) {
                $job['note'] = $this->t('cannot be set (the colour has no action)');
            } else {
                $error = $this->backend->request($variable, $rgb);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                } elseif (isset($entry['roles']['state']) && ($this->backend->read($entry['roles']['state'])['value'] ?? null) === false) {
                    $this->backend->request($entry['roles']['state'], true);
                }
            }
            $jobs[$index] = $job;
        }

        $reached = fn (array $job): bool => ($this->backend->read($job['variable'])['value'] ?? null) === $rgb;
        $this->waitUntil(function () use ($jobs, $reached): bool {
            foreach ($jobs as $job) {
                if ($job['note'] === null && !$reached($job) && $this->backend->failure($job['variable']) === null) {
                    return false;
                }
            }
            return true;
        });

        $result = ['lines' => [], 'total' => count($jobs), 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($jobs as $index => $job) {
            if ($job['note'] === null) {
                $error = $reached($job) ? null : $this->backend->failure($job['variable']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                    $result['failed']++;
                } elseif ($reached($job)) {
                    $job['note'] = $this->stateOf($job['entry'])['text'];
                    $result['reached']++;
                } else {
                    $job['note'] = sprintf($this->t('sent, but the device has not confirmed yet (currently %s)'), $this->stateOf($job['entry'])['text']);
                    $result['pending']++;
                }
            } else {
                $result['failed']++;
            }
            $result['lines'][$index] = $this->line($job['entry'], $job['note']);
        }
        return $result;
    }

    /**
     * Name der nächstliegenden Farbe der Liste. Verglichen wird nach Aufhellen auf volle Stärke, so
     * gilt ein dunkles Rot (0x7D0000, WLED an der Testbox) als Rot. Schwarz (0) hat keinen Namen.
     */
    public static function colorName(int $rgb): ?string
    {
        $channels = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
        $max = max($channels);
        if ($max === 0) {
            return null;
        }
        $channels = array_map(static fn (int $c): float => $c * 255 / $max, $channels);
        $best = null;
        $bestDistance = INF;
        foreach (self::COLORS as $name => $value) {
            $distance = ($channels[0] - (($value >> 16) & 0xFF)) ** 2 + ($channels[1] - (($value >> 8) & 0xFF)) ** 2 + ($channels[2] - ($value & 0xFF)) ** 2;
            if ($distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /** Bereich des Reglers für die Farbtemperatur auf der Karte in Kelvin; ~TWColor reicht von 1000 bis 12000 K. */
    public const CARD_KELVIN = [2000, 6500];

    /**
     * Bedeutung eines Farbmodus (Burkhard 16.10.2026): „temperature“ (weißes Licht), „color“ oder null (unbekannt, etwa
     * HomeAssistant „brightness“ oder leer bei ausgeschaltetem Licht). Gelesen an den Anlagen: Zigbee2MQTT color_mode
     * COLOR_TEMP/XY, HomeAssistant-Modul light_color_mode color_temp/xy, Matter ColorControl_ColorMode 0 Farbton und
     * Sättigung, 1 XY, 2 Farbtemperatur (Matter-Spezifikation, Attribut ColorMode im Cluster Color Control; die Variable
     * des Matter-Moduls hat keine Einträge, gemessen ist nur 2). Alle drei Module zeigen den Modus als Wertanzeige, eine
     * Darstellung „Farbmodus“ gibt es nicht; erkennen lässt er sich nur am Ident.
     */
    public static function colorModeKind(string $ident, mixed $value): ?string
    {
        if (is_int($value)) {
            return stripos($ident, 'ColorControl') === false ? null : match ($value) {
                2       => 'temperature',
                0, 1    => 'color',
                default => null,
            };
        }
        $value = strtolower(trim((string)$value));
        return match (true) {
            in_array($value, ['color_temp', 'colortemp', 'color_temperature'], true) => 'temperature',
            in_array($value, ['xy', 'hs', 'rgb', 'rgbw', 'rgbww'], true)              => 'color',
            default                                                                  => null,
        };
    }

    /** Farbmodus eines Lichts aus der Variable neben Farbe oder Farbtemperatur; null = unbekannt. */
    private function colorModeOf(array $entry): ?string
    {
        foreach (['color', 'colortemp'] as $role) {
            $mode = isset($entry['roles'][$role]) ? $this->backend->colorMode($entry['roles'][$role]) : null;
            if ($mode !== null) {
                return self::colorModeKind($mode['ident'], $mode['value']);
            }
        }
        return null;
    }

    /** Mired statt Kelvin: ein Bereich bis 1000 (Hue über Matter 153 bis 454), ohne Bereich die Einheit. */
    private function isMired(int $variableId): bool
    {
        $range = $this->backend->setpointRange($variableId);
        if ($range !== null && $range['max'] > 0) {
            return $range['max'] <= 1000;
        }
        return stripos($this->backend->unit($variableId), 'mired') !== false;
    }

    /** Ein Rohwert der Farbtemperatur in Kelvin; aus Mired umgerechnet auf 10 K gerundet. */
    private function kelvinOf(int $variableId, float $raw): int
    {
        return $this->isMired($variableId) ? (int)(round(1000000 / $raw / 10) * 10) : (int)round($raw);
    }

    /**
     * Antwort mit der Kopfzeile „… auf 21 °C gestellt“.
     *
     * @param array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int, target: float, unit: string} $result
     * @param list<string> $closing
     * @return array{text: string, isError: bool}
     */
    private function setpointAnswer(array $result, string $filter, array $closing = []): array
    {
        $value = self::formatNumber($result['target']) . ' ' . $result['unit'];
        $head = $result['pending'] > 0
            ? sprintf($this->t('%d of %d devices set to %s, %d not confirmed yet (%s).'), $result['reached'], $result['total'], $value, $result['pending'], $filter)
            : sprintf($this->t('%d of %d devices set to %s (%s).'), $result['reached'], $result['total'], $value, $filter);
        return $this->answer($head, $result, $closing);
    }

    /** Einheit des Sollwerts aus Profil oder Darstellung, ohne Angabe °C, beim Luftentfeuchter %. */
    private function temperatureUnit(int $variableId, string $type = 'thermostat'): string
    {
        $unit = trim($this->backend->unit($variableId));
        return $unit !== '' ? $unit : ($type === 'humidifier' ? '%' : '°C');
    }

    /** „Soll 21 °C“, außerhalb des Bereichs „Sonder-Sollwert 100 °C außerhalb von 6 bis 30 °C“. */
    private function setpointText(array $entry): string
    {
        $variable = $entry['roles']['setpoint'] ?? 0;
        $setpoint = $this->backend->read($variable);
        if ($setpoint === null) {
            return sprintf($this->t('not available (variable %d is missing)'), $variable);
        }
        if (!is_int($setpoint['value']) && !is_float($setpoint['value'])) {
            return sprintf($this->t('setpoint %s'), $setpoint['formatted']);
        }
        $unit = $this->temperatureUnit($variable, (string)$entry['Type']);
        $text = self::formatNumber((float)$setpoint['value']) . ' ' . $unit;
        // Ein Wert außerhalb des Bereichs ist meist ein Sonderwert (HM-CC-TC: 100 = Ventil ganz offen).
        $range = $this->backend->setpointRange($variable);
        if ($range !== null && ($setpoint['value'] < $range['min'] - 0.001 || $setpoint['value'] > $range['max'] + 0.001)) {
            return sprintf($this->t('special setpoint %s outside %s to %s'), $text, self::formatNumber($range['min']), self::formatNumber($range['max']) . ' ' . $unit);
        }
        return sprintf($this->t('setpoint %s'), $text);
    }

    /**
     * Zustand eines Luftentfeuchters: an oder aus, dahinter Ist-Feuchte und Zielfeuchte. Ohne
     * Schaltzustand nur die Werte, dann zählt er bei den aktiven Geräten nicht mit.
     *
     * @return array{text: string, active: ?bool}
     */
    private function humidifierText(array $entry): array
    {
        $roles = $entry['roles'];
        $extras = [];
        if (isset($roles['humidity'])) {
            $current = $this->backend->read($roles['humidity']);
            $extras[] = $current === null ? sprintf($this->t('not available (variable %d is missing)'), $roles['humidity']) : $current['formatted'];
        }
        if (isset($roles['setpoint'])) {
            $extras[] = $this->setpointText($entry);
        }
        if (isset($roles['mode'])) {
            $extras[] = $this->backend->read($roles['mode'])['formatted'] ?? sprintf($this->t('not available (variable %d is missing)'), $roles['mode']);
        }
        if (isset($roles['fan'])) {
            $fan = $this->backend->read($roles['fan']);
            $extras[] = $fan === null ? sprintf($this->t('not available (variable %d is missing)'), $roles['fan']) : sprintf($this->t('fan %s'), $fan['formatted']);
        }
        if (isset($roles['state'])) {
            $power = $this->backend->read($roles['state']);
            if ($power === null) {
                return ['text' => sprintf($this->t('not available (variable %d is missing)'), $roles['state']), 'active' => null];
            }
            if (is_bool($power['value'])) {
                $on = $power['value'] !== $entry['Invert'];
                if (!$on) {
                    return ['text' => $this->t('off'), 'active' => false];
                }
                return ['text' => $this->t('on') . ($extras === [] ? '' : ' (' . implode(', ', $extras) . ')'), 'active' => true];
            }
            array_unshift($extras, $power['formatted']);
        }
        return ['text' => $extras === [] ? '-' : implode(', ', $extras), 'active' => null];
    }

    /** Zustand eines Thermostats: Sollwert, dahinter die Ist-Temperatur; bei einem Klimagerät Modus und Lüfterstufe. */
    private function thermostatText(array $entry): string
    {
        $roles = $entry['roles'];
        $text = $this->setpointText($entry);
        $current = isset($roles['state']) ? $this->backend->read($roles['state']) : null;
        $text = $current === null ? $text : $text . ' (' . sprintf($this->t('currently %s'), $current['formatted']) . ')';
        if (isset($roles['mode'])) {
            $text .= ', ' . ($this->backend->read($roles['mode'])['formatted'] ?? sprintf($this->t('not available (variable %d is missing)'), $roles['mode']));
        }
        if (isset($roles['fan'])) {
            $fan = $this->backend->read($roles['fan']);
            $text .= ', ' . ($fan === null ? sprintf($this->t('not available (variable %d is missing)'), $roles['fan']) : sprintf($this->t('fan %s'), $fan['formatted']));
        }
        return $text;
    }

    /**
     * Hält Rollläden und Fenster an und wartet gemeinsam auf die Bestätigung. Steht ein Gerät laut
     * Fahrstatus und ist keine Fahrt gemerkt, geht nichts hinaus; ob ein Aktor auf einen Stopp im
     * Stand antwortet, ist nicht gemessen. Der Fahrtmerker fällt in jedem Fall weg.
     *
     * @param array<int, array<string, mixed>> $entries Geräte, die Schlüssel bestimmen die Reihenfolge der Zeilen
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private function runStop(array $entries): array
    {
        $jobs = [];
        foreach ($entries as $index => $entry) {
            $variable = $entry['roles']['level'];
            $moving = $entry['roles']['moving'] ?? 0;
            $current = $this->backend->read($variable);
            $job = ['entry' => $entry, 'variable' => $variable, 'moving' => $moving, 'start' => $current['value'] ?? null, 'note' => null, 'done' => false];
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $variable);
            } elseif ($moving > 0 && !self::reportsMoving($this->backend->read($moving)['value'] ?? null) && $this->backend->rememberedTravel($variable) === null) {
                $job['note'] = sprintf($this->t('was not moving (%s)'), $this->levelText($entry, $current));
                $job['done'] = true;
            } else {
                $error = $this->backend->stop($variable, $entry['roles']['stop'] ?? 0);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                }
            }
            $this->backend->rememberTravel($variable, null);
            $jobs[$index] = $job;
        }

        $this->waitUntil(function () use ($jobs): bool {
            foreach ($jobs as $job) {
                if ($job['note'] === null && !$this->stopConfirmed($job) && $this->backend->failure($job['variable']) === null) {
                    return false;
                }
            }
            return true;
        });

        $result = ['lines' => [], 'total' => count($jobs), 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($jobs as $index => $job) {
            if ($job['note'] === null) {
                $now = $this->backend->read($job['variable']);
                $error = $this->stopConfirmed($job) ? null : $this->backend->failure($job['variable']);
                if ($this->stopConfirmed($job)) {
                    $job['note'] = sprintf($this->t('stopped (%s)'), $this->levelText($job['entry'], $now));
                    $job['done'] = true;
                } elseif ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                    $result['failed']++;
                } else {
                    $result['pending']++;
                    $job['note'] = sprintf($this->t('stop sent, not confirmed yet (currently %s)'), $this->levelText($job['entry'], $now, '?'));
                }
            } elseif (!$job['done']) {
                $result['failed']++;
            }
            if ($job['done']) {
                $result['reached']++;
            }
            $result['lines'][$index] = $this->line($job['entry'], $job['note']);
        }
        return $result;
    }

    /**
     * Bestätigt ist ein Stopp, wenn sich die Position gegenüber dem Stand beim Stopp geändert hat
     * und ein Fahrstatus „steht“ meldet. Eine bloße Aktualisierung reicht nicht: Das
     * HomeAssistant-Modul meldet zuerst den alten Wert, und der Laufstatus des Velux fällt kurz auf
     * „steht“, bevor die Position kommt (Mitschnitte nuc 09.10.2026).
     */
    private function stopConfirmed(array $job): bool
    {
        $now = $this->backend->read($job['variable'])['value'] ?? null;
        $start = $job['start'];
        if ((!is_int($now) && !is_float($now)) || (!is_int($start) && !is_float($start)) || abs($now - $start) < 0.0001) {
            return false;
        }
        return $job['moving'] === 0 || !self::reportsMoving($this->backend->read($job['moving'])['value'] ?? null);
    }

    /**
     * Fasst zwei Ergebnisse zu einem zusammen; die Zeilen stehen in der Reihenfolge der Auswahl.
     *
     * @param null|array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int} $first
     * @param array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int} $second
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    /**
     * Zwei Schritte an denselben Geräten (Lautstärke, dann Wiedergabe): Ein Gerät ist erreicht, wenn beide
     * Schritte es sind. Die Zeile kommt vom zweiten Schritt, außer der erste hat nicht geklappt.
     *
     * @return array{lines: array<int, string>, status: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private static function combineResults(array $first, array $second): array
    {
        $rank = ['reached' => 0, 'pending' => 1, 'failed' => 2];
        $result = ['lines' => [], 'status' => [], 'total' => $second['total'], 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($second['lines'] as $index => $line) {
            $a = $first['status'][$index] ?? 'reached';
            $b = $second['status'][$index] ?? 'reached';
            $status = $rank[$a] >= $rank[$b] ? $a : $b;
            $result['lines'][$index] = $a !== 'reached' ? ($first['lines'][$index] ?? $line) : $line;
            $result['status'][$index] = $status;
            $result[$status]++;
        }
        return $result;
    }

    private static function mergeResults(?array $first, array $second): array
    {
        if ($first === null) {
            return $second;
        }
        $lines = $first['lines'] + $second['lines'];
        ksort($lines);
        return [
            'lines'   => $lines,
            'status'  => ($first['status'] ?? []) + ($second['status'] ?? []),
            'total'   => $first['total'] + $second['total'],
            'reached' => $first['reached'] + $second['reached'],
            'pending' => $first['pending'] + $second['pending'],
            'failed'  => $first['failed'] + $second['failed'],
        ];
    }

    /**
     * Antwort mit der Kopfzeile „… sind jetzt an/aus/offen/geschlossen“.
     *
     * @param array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int} $result
     * @param list<string> $closing Schlusszeilen nach den Gerätezeilen
     * @return array{text: string, isError: bool}
     */
    private function switchAnswer(array $result, string $word, string $filter, array $closing = []): array
    {
        $head = $result['pending'] > 0
            ? sprintf($this->t('%d of %d devices are now %s, %d not confirmed yet (%s).'), $result['reached'], $result['total'], $word, $result['pending'], $filter)
            : sprintf($this->t('%d of %d devices are now %s (%s).'), $result['reached'], $result['total'], $word, $filter);
        return $this->answer($head, $result, $closing);
    }

    /**
     * Antwort mit der Kopfzeile „… auf N % gestellt“.
     *
     * @param array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int} $result
     * @param list<string> $closing Schlusszeilen nach den Gerätezeilen
     * @return array{text: string, isError: bool}
     */
    private function levelAnswer(array $result, float $percent, string $filter, array $closing = []): array
    {
        $head = $result['pending'] > 0
            ? sprintf($this->t('%d of %d devices set to %s %%, %d not confirmed yet (%s).'), $result['reached'], $result['total'], self::formatNumber($percent), $result['pending'], $filter)
            : sprintf($this->t('%d of %d devices set to %s %% (%s).'), $result['reached'], $result['total'], self::formatNumber($percent), $filter);
        return $this->answer($head, $result, $closing);
    }

    /**
     * @param array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int} $result
     * @param list<string> $closing
     * @return array{text: string, isError: bool}
     */
    private function answer(string $head, array $result, array $closing): array
    {
        // Ein Rollladen unterwegs zählt als „noch nicht bestätigt“, ist aber kein Problem.
        if ($result['failed'] > 0) {
            $this->problem = 'failed';
        } elseif ($result['pending'] - ($result['underway'] ?? 0) > 0) {
            $this->problem = 'not_confirmed';
        }
        return [
            'text'    => implode("\n", array_merge([$head], array_values($result['lines']), $closing)),
            'isError' => $result['failed'] > 0 && $result['reached'] === 0 && $result['pending'] === 0,
        ];
    }

    /**
     * Geräte, die zu Raum, Typ, Name und Etage passen. Der Raum darf ein Alias aus der Raumliste
     * sein, die Etage kommt nur aus der Raumliste.
     *
     * @return list<array{Name: string, Aliases: string, Room: string, Type: string, Invert: bool, ReadOnly: bool, roles: array<string, int>}>
     */
    public function find(string $room, string $type, string $name, string $floor = '', ?callable $accept = null): array
    {
        $nameKey = self::normalize($name);
        // Eine Angabe, von der nach dem Normalisieren nichts übrig bleibt („-“, „.“, NBSP), ist
        // kein fehlender Filter: Der Name trifft dann nichts, der Raum nur Geräte ohne Raum.
        if (trim($name) !== '' && $nameKey === '') {
            return [];
        }
        $byQuality = [];
        foreach ($this->entries as $entry) {
            // „Wie warm ist es?“ (type=temperature) findet auch die Ist-Temperatur der Thermostate.
            if ($type !== '' && $entry['Type'] !== $type && !($type === 'temperature' && $entry['Type'] === 'thermostat' && isset($entry['roles']['state']))) {
                continue;
            }
            if (trim($room) !== '' && !$this->rooms->matches($room, $entry['Room'])) {
                continue;
            }
            if ($floor !== '' && !$this->rooms->onFloor($floor, $entry['Room'])) {
                continue;
            }
            // Vor der Trefferstufe: Ein genauer Treffer auf ein ausgeschlossenes Gerät darf den
            // schwächeren auf ein zugelassenes nicht verdecken.
            if ($accept !== null && !$accept($entry)) {
                continue;
            }
            if ($this->onlyKey !== null && self::cardKey($entry) !== $this->onlyKey) {
                continue;
            }
            $quality = $nameKey === '' ? 3 : self::nameMatchQuality($entry['Name'], $entry['Aliases'], $nameKey);
            if ($quality > 0) {
                if ($nameKey !== '') {
                    $entry['MatchedAlias'] = self::matchedAlias($entry['Name'], $entry['Aliases'], $nameKey);
                }
                $byQuality[$quality][] = $entry;
            }
        }
        // Nur die beste Stufe zählt: „Stumme Steckdose“ meint genau dieses Gerät und nicht auch
        // jede andere Steckdose, deren Name in der Anfrage vorkommt.
        return $byQuality === [] ? [] : $byQuality[max(array_keys($byQuality))];
    }

    /** Kleinbuchstaben, Umlaute ausgeschrieben, Satzzeichen und doppelte Leerzeichen weg. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '-' => ' ', '.' => ' ', '_' => ' ']);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Auswahl für schaltende Werkzeuge. Strenger als beim Lesen: ohne Filter passiert nichts,
     * Melder, Messwerte und Geräte mit „nur abfragen“ sind ausgenommen, und ein Name muss genau
     * ein Gerät treffen.
     *
     * @return array{error: string}|array{entries: list<array<string, mixed>>, filter: string}
     */
    private function selectControllable(array $arguments): array
    {
        $room = trim((string)($arguments['room'] ?? ''));
        $floor = trim((string)($arguments['floor'] ?? ''));
        $type = trim((string)($arguments['type'] ?? ''));
        $name = trim((string)($arguments['name'] ?? ''));

        // Geprüft wird, was der Aufrufer angegeben hat. Der Standardraum allein ist keine Auswahl,
        // und „all“ ohne weitere Angabe hieße „alles im Haus“.
        if (($room === '' || self::isAllRooms($room)) && $floor === '' && $type === '' && $name === '') {
            return ['error' => $this->t('Nothing was changed: give at least a room, a floor, a type or a name.')];
        }
        if ($type !== '' && !array_key_exists($type, self::TYPES)) {
            return ['error' => sprintf($this->t('Unknown type "%s". Allowed: %s.'), $type, implode(', ', self::CONTROLLABLE))];
        }
        if ($type !== '' && !in_array($type, self::CONTROLLABLE, true) && !array_any($this->entries, fn (array $e): bool => $e['Type'] === $type && $this->isControllable($e))) {
            return ['error' => sprintf($this->t('Nothing was changed: devices of type %s can only be read. Controllable types: %s.'), $type, implode(', ', self::CONTROLLABLE))];
        }

        if ($floor !== '' && !$this->rooms->knowsFloor($floor)) {
            return ['error' => $this->unknownFloor($floor)];
        }

        $room = $this->effectiveRoom($room, $name, $floor);
        $filter = $this->describeFilter($room, $type, $name, $floor);
        $entries = $this->find($room, $type, $name, $floor, fn (array $e): bool => $this->isControllable($e));
        if ($entries === []) {
            if ($this->find($room, $type, $name, $floor) !== []) {
                $this->problem = 'read_only';
                return ['error' => sprintf($this->t('Nothing was changed: the devices matching %s can only be read.'), $filter)];
            }
            $this->noMatch($room, $name);
            return ['error' => sprintf($this->t('No released device matches %s.'), $filter) . ' ' . $this->knownRoomsAndTypes()];
        }
        if ($name !== '' && count($entries) > 1 && $this->defaultRoom !== '') {
            // Wie der Bereich des sprechenden Geräts bei Home Assistant: Liegt genau ein Kandidat im
            // Standardraum, ist der gemeint.
            $here = array_values(array_filter($entries, fn (array $e): bool => $this->rooms->matches($this->defaultRoom, $e['Room'])));
            if (count($here) === 1) {
                $entries = $here;
            }
        }
        if ($name !== '' && count($entries) > 1) {
            // Mit Zustand wie jede andere Zeile: Der Assistent fragt dann nur nach den Geräten, bei
            // denen der Befehl etwas ändert (Wiederholung des Blindtests 08.10.2026, Befund N2).
            $candidates = array_map(fn (array $e): string => $this->line($e, $this->stateOf($e)['text']), $entries);
            // Den Raum zu nennen hilft nur, wenn die Kandidaten in mehr als einem Raum liegen.
            $text = count(array_unique(array_column($entries, 'Room'))) > 1
                ? 'Nothing was changed: %d devices match %s. Ask which one is meant or add the room:'
                : 'Nothing was changed: %d devices match %s. Ask which one is meant:';
            $this->problem = 'ambiguous';
            return ['error' => sprintf($this->t($text), count($entries), $filter) . "\n" . implode("\n", $candidates)];
        }
        // Sollen Fenster mit Antrieb fahren, gehören die Fenster nur mit Kontakt derselben Auswahl zu
        // den ausgelassenen: „alle Fenster zu“ darf nicht verschweigen, dass eines offen bleiben kann.
        $windowsWithoutDrive = 0;
        if ($name === '' && array_any($entries, static fn (array $e): bool => $e['Type'] === 'window')) {
            $windowsWithoutDrive = count($this->find($room, $type, $name, $floor, static fn (array $e): bool => $e['Type'] === 'window' && !isset($e['roles']['level']) && !$e['ReadOnly']));
        }
        return ['entries' => $entries, 'filter' => $filter, 'windowsWithoutDrive' => $windowsWithoutDrive];
    }

    /**
     * Lässt sich das Gerät schalten oder stellen? Ein Fenster nur, wenn es einen Antrieb hat, ein
     * Gerät (appliance) nur mit Schaltzustand; eine Wärmepumpe nur mit Leistung lässt sich abfragen.
     */
    private function isControllable(array $entry): bool
    {
        if ($entry['ReadOnly']) {
            return false;
        }
        if ($entry['Type'] === 'appliance' && !isset($entry['roles']['state'])) {
            return false;
        }
        if ($entry['Type'] === 'thermostat' && !isset($entry['roles']['setpoint'])) {
            return false;
        }
        return in_array($entry['Type'], self::CONTROLLABLE, true) || ($entry['Type'] === 'window' && isset($entry['roles']['level']));
    }

    /** Fährt das Gerät (Rollladen, Fenster mit Antrieb)? Dann ist seine Stufe ein Öffnungsgrad. */
    private static function moves(array $entry): bool
    {
        return $entry['Type'] === 'blind' || ($entry['Type'] === 'window' && isset($entry['roles']['level']));
    }

    /**
     * Rohwerte für ganz offen und ganz geschlossen. Beim Rollladen wie bisher (Darstellung, Profil
     * mit „.Reversed“, sonst kleinster Wert offen; der Haken dreht es um). Beim Fenster ist der
     * größte Wert offen, außer die Darstellung Rollladen sagt es ausdrücklich; der Haken gehört dort
     * zum Kontakt.
     *
     * @param null|array<string, mixed> $range
     * @return null|array{open: float, closed: float}
     */
    private static function travelOf(array $entry, ?array $range): ?array
    {
        if ($entry['Type'] === 'blind' || $range === null) {
            return self::travel($range, $entry['Invert']);
        }
        if (in_array($range['source'] ?? '', ['presentation', 'rotation'], true)) {
            return self::travel($range, false);
        }
        return self::travel(['open' => $range['max'], 'closed' => $range['min'], 'min' => $range['min'], 'max' => $range['max']], false);
    }

    /** Öffnungsgrad 0 bis 100 eines fahrenden Geräts; null, wenn der Bereich unbekannt ist. */
    private function openPercent(array $entry, mixed $value): ?float
    {
        $variable = $entry['roles']['level'] ?? 0;
        if ($variable === 0 || (!is_int($value) && !is_float($value))) {
            return null;
        }
        $travel = self::travelOf($entry, $this->backend->range($variable));
        return $travel === null ? null : ($value - $travel['closed']) / ($travel['open'] - $travel['closed']) * 100.0;
    }

    /**
     * Löst das Stellen eines Geräts aus.
     *
     * @return array{entry: array<string, mixed>, variable: int, target: bool|int|float, start: int|float, updatedBefore: int, off: bool, note: ?string}
     */
    private function startLevelJob(array $entry, float $percent): array
    {
        $job = ['entry' => $entry, 'variable' => 0, 'target' => 0, 'start' => 0, 'updatedBefore' => 0, 'off' => false, 'note' => null];
        $state = $entry['roles']['state'] ?? 0;
        $levelVariable = $entry['roles']['level'] ?? 0;
        $main = $this->backend->read($this->mainVariable($entry));
        if ($main === null && $this->mainVariable($entry) > 0) {
            $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $this->mainVariable($entry));
            return $job;
        }
        // Einen eigenen Schalter hat ein Gerät, dessen Fähigkeit „state“ ein Wahrheitswert ist.
        $hasSwitch = $state > 0 && $main !== null && is_bool($main['value']);

        // 0 % heißt bei einem Gerät mit eigenem Schalter „aus“. Der kleinste Stufenwert wäre dafür
        // falsch: Ein Matter-Dimmer kennt Stufe 1 bis 254 und leuchtet auf Stufe 1 noch.
        if ($percent <= 0.0 && $hasSwitch) {
            $job['variable'] = $state;
            $job['target'] = $entry['Invert'];
            $job['off'] = true;
            if ($main['value'] !== $job['target']) {
                $error = $this->backend->hasAction($state)
                    ? $this->backend->request($state, $job['target'])
                    : $this->t('cannot be switched (no switchable state variable)');
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                }
            }
            return $job;
        }

        $level = $levelVariable > 0 ? $this->backend->read($levelVariable) : null;
        $range = $levelVariable > 0 ? $this->backend->range($levelVariable) : null;
        if ($level === null || (!is_int($level['value']) && !is_float($level['value'])) || !$this->backend->hasAction($levelVariable)) {
            $job['note'] = $this->t('has no adjustable brightness or position');
            return $job;
        }
        if ($range === null) {
            $job['note'] = $this->t('failed: the value range of the level variable is unknown');
            return $job;
        }

        if (self::moves($entry)) {
            // Nach außen heißt Prozent bei Rollläden und Fenstern „wie weit offen“ (wie Home Assistant,
            // Burkhard 09.10.2026). Welcher Rohwert offen und welcher geschlossen ist, sagt die Anlage.
            $travel = self::travelOf($entry, $range);
            if ($travel === null) {
                $job['note'] = $this->t('failed: the value range of the level variable is unknown');
                return $job;
            }
            $target = $travel['closed'] + ($travel['open'] - $travel['closed']) * $percent / 100.0;
        } else {
            // Bei einem Licht ohne eigenen Schalter dreht „Umgekehrt“ die Richtung der Skala.
            $share = (!$hasSwitch && $entry['Invert'] ? 100.0 - $percent : $percent) / 100.0;
            $target = $range['min'] + ($range['max'] - $range['min']) * $share;
            // Ohne eigenen Schalter ist das Licht nur bei Stufe 0 aus (wie stateOf es liest). Beginnt
            // die Skala über 0 (Matter 1 bis 254), leuchtet die kleinste Stufe noch: nichts senden.
            if ($percent <= 0.0 && !$hasSwitch && $target > 0) {
                $job['note'] = sprintf($this->t('cannot be switched off (smallest level is %s, no switch state)'), self::formatNumber((float)$target));
                return $job;
            }
        }
        $job['variable'] = $levelVariable;
        $job['start'] = $level['value'];
        $job['updatedBefore'] = $this->backend->updated($levelVariable);
        $job['target'] = is_int($level['value']) ? (int)round($target) : round($target, 2);
        $error = $this->backend->request($levelVariable, $job['target']);
        if ($error !== null) {
            $job['note'] = sprintf($this->t('failed: %s'), $error);
        }
        return $job;
    }

    private function levelReached(array $job): bool
    {
        $now = $this->backend->read($job['variable'])['value'] ?? null;
        if (is_bool($job['target'])) {
            return $now === $job['target'];
        }
        return (is_int($now) || is_float($now)) && abs($now - $job['target']) < 0.006;
    }

    /**
     * Ein Rollladen fährt, wenn seine Position dem Ziel näher ist als beim Absenden, wenn sein
     * Fahrstatus „fährt“ meldet, oder, ohne Fahrstatus, wenn die Positionsvariable nach dem Befehl
     * aktualisiert wurde. Das Lebenszeichen braucht es für Homematic: Der Aktor meldet beim
     * Losfahren aus einer Endlage den unveränderten Wert (Mitschnitt nuc 08.10.2026).
     */
    private function isMoving(array $job): bool
    {
        if (!self::moves($job['entry']) || is_bool($job['target'])) {
            return false;
        }
        $now = $this->backend->read($job['variable'])['value'] ?? null;
        if ((is_int($now) || is_float($now)) && abs($now - $job['target']) < abs($job['start'] - $job['target'])) {
            return true;
        }
        $moving = $job['entry']['roles']['moving'] ?? 0;
        if ($moving > 0) {
            return self::reportsMoving($this->backend->read($moving)['value'] ?? null);
        }
        return $this->backend->updated($job['variable']) > $job['updatedBefore'];
    }

    /** Meldet ein Fahrstatus „fährt“? Wahrheitswert, Zahl ungleich 0 oder ein Text wie „UP“. */
    private static function reportsMoving(mixed $value): bool
    {
        return match (true) {
            is_bool($value)                  => $value,
            is_int($value), is_float($value) => $value != 0,
            is_string($value)                => !in_array(strtolower(trim($value)), self::NOT_MOVING_TEXTS, true),
            default                          => false,
        };
    }

    /**
     * Zustand eines Rollladens. Läuft eine gemerkte Fahrt noch, nennt er Ziel und Stand. Der Merker
     * fällt weg, wenn das Ziel erreicht ist, nach zwei Minuten, oder wenn der Fahrstatus seit 3 s
     * „steht“ meldet (am Wandtaster angehalten).
     */
    private function blindText(array $entry, array $main): string
    {
        $current = $this->levelText($entry, $main);
        $variable = $entry['roles']['level'] ?? 0;
        $movingVariable = $entry['roles']['moving'] ?? 0;
        $movingNow = $movingVariable > 0 && self::reportsMoving($this->backend->read($movingVariable)['value'] ?? null);
        $travel = $variable > 0 ? $this->backend->rememberedTravel($variable) : null;
        if ($travel !== null) {
            $now = $this->backend->now();
            $value = $main['value'];
            $reached = (is_int($value) || is_float($value)) && abs($value - $travel['target']) < 0.006;
            $expired = $now - $travel['since'] > self::TRAVEL_KEEP_MS;
            $stopped = $movingVariable > 0 && !$movingNow && $now - $travel['since'] > self::TRAVEL_STALE_MS
                && $now - $this->backend->updated($movingVariable) > self::TRAVEL_STALE_MS;
            if (!$reached && !$expired && !$stopped) {
                $this->blindMoving = true;
                return sprintf($this->t('moving to %s (currently %s)'), $this->levelText($entry, ['value' => $travel['target'], 'formatted' => '']), $current);
            }
            $this->backend->rememberTravel($variable, null);
        }
        $this->blindMoving = $movingNow;
        return $movingNow ? sprintf($this->t('moving (currently %s)'), $current) : $current;
    }

    /** @return int Zustandsvariable, die nach dem Stellen noch eingeschaltet werden muss; 0 = nichts zu tun */
    private function stateToSwitchOn(array $job): int
    {
        $entry = $job['entry'];
        $state = $entry['roles']['state'] ?? 0;
        if ($job['off'] || $state === 0) {
            return 0;
        }
        $main = $this->backend->read($state);
        if ($main === null || !is_bool($main['value']) || $main['value'] === !$entry['Invert'] || !$this->backend->hasAction($state)) {
            return 0;
        }
        return $state;
    }

    private function isSwitchedOn(array $job, int $state): bool
    {
        return ($this->backend->read($state)['value'] ?? null) === !$job['entry']['Invert'];
    }

    /** Die Variable, an der sich zeigt, ob es das Gerät gibt: der Zustand, sonst die Stufe (0 = keine). */
    private function mainVariable(array $entry): int
    {
        return $entry['roles']['state'] ?? $entry['roles']['level'] ?? $entry['roles']['playback'] ?? $entry['roles']['volume'] ?? $entry['roles']['energy'] ?? 0;
    }

    /**
     * Zustand eines Mediaplayers: „aus“, sonst Wiedergabe mit Titel und Interpret, dahinter Lautstärke
     * und Stumm („spielt „Titel“ von Interpret (Lautstärke 20 %, stumm)“). Aktiv ist ein Player, der
     * spielt, oder einer, der an ist und keinen Wiedergabezustand meldet. Das HomeAssistant-Modul hat
     * nur eine Befehlsvariable (Wert -1); dann steht kein Zustandswort da, wohl aber der Titel.
     *
     * @return array{text: string, active: ?bool}
     */
    private function mediaText(array $entry): array
    {
        $roles = $entry['roles'];
        $active = null;
        $words = [];
        $playing = $this->playbackPhase($entry);
        if (isset($roles['state'])) {
            $power = $this->backend->read($roles['state']);
            if ($power === null) {
                return ['text' => sprintf($this->t('not available (variable %d is missing)'), $roles['state']), 'active' => null];
            }
            $status = is_string($power['value']) ? (self::MEDIA_STATES[strtolower(trim($power['value']))] ?? null) : null;
            if (is_bool($power['value'])) {
                $active = $power['value'] !== $entry['Invert'];
                if (!$active) {
                    return ['text' => $this->t('off'), 'active' => false];
                }
            } elseif ($status !== null) {
                // Statustext des HomeAssistant-Moduls: playing, paused, idle, off …
                if ($status[2] === 'off') {
                    return ['text' => $this->t('off'), 'active' => false];
                }
                $active = $status[1];
                if ($this->playbackPhase($entry, false) === null) {
                    $words[] = $this->t($status[2]);
                }
            } else {
                // Ein/Aus als Zahl (Sony TV: „Standby“) bleibt der Anzeigetext.
                $words[] = $power['formatted'];
            }
        }
        $fromPlayback = isset($roles['playback']) ? $this->playbackPhase($entry, false) : null;
        if ($fromPlayback !== null) {
            $words[] = $this->t(['play' => 'playing', 'pause' => 'paused', 'stop' => 'stopped'][$fromPlayback]);
            $active = $fromPlayback === 'play';
        }
        $title = isset($roles['title']) ? trim((string)($this->backend->read($roles['title'])['value'] ?? '')) : '';
        $artist = isset($roles['artist']) ? trim((string)($this->backend->read($roles['artist'])['value'] ?? '')) : '';
        if ($title !== '' && $playing !== 'stop' && ($words === [] || $playing !== null)) {
            $words[] = $artist !== '' ? sprintf($this->t('"%s" by %s'), $title, $artist) : sprintf($this->t('"%s"'), $title);
        }
        $extras = [];
        if (isset($roles['volume'])) {
            $volume = $this->backend->read($roles['volume']);
            $range = $this->backend->range($roles['volume']);
            if ($volume !== null && $range !== null && $range['max'] > $range['min'] && (is_int($volume['value']) || is_float($volume['value']))) {
                // Ein Bereich in anderer Einheit (Denon in dB) steht mit dabei, sonst fehlt der Bezug zum Gerät.
                $unit = str_contains($volume['formatted'], '%') ? '' : ' = ' . preg_replace('/\s+/u', ' ', trim($volume['formatted']));
                $extras[] = sprintf($this->t('volume %s'), self::formatNumber(round(($volume['value'] - $range['min']) / ($range['max'] - $range['min']) * 100.0)) . ' %' . $unit);
            } elseif ($volume !== null) {
                $extras[] = sprintf($this->t('volume %s'), $volume['formatted']);
            }
        }
        if (isset($roles['mute']) && ($this->backend->read($roles['mute'])['value'] ?? false) === true) {
            $extras[] = $this->t('muted');
        }
        if ($words === [] && $active === true) {
            $words[] = $this->t('on');
        }
        $text = implode(' ', $words);
        if ($extras !== []) {
            $text = trim($text . ' (' . implode(', ', $extras) . ')');
        }
        return ['text' => $text === '' ? '-' : $text, 'active' => $active];
    }

    /**
     * Wiedergabephase eines Mediaplayers: „play“, „pause“, „stop“ oder null. Zuerst der Wert der
     * Wiedergabevariable nach ihrem Profil, sonst der Statustext des HomeAssistant-Moduls (dessen
     * Wiedergabevariable ist nur ein Befehl und steht auf -1).
     */
    private function playbackPhase(array $entry, bool $withStatus = true): ?string
    {
        $roles = $entry['roles'];
        if (isset($roles['playback'])) {
            $value = $this->backend->read($roles['playback'])['value'] ?? null;
            $codes = self::PLAYBACK_CODES[(string)($this->backend->range($roles['playback'])['profile'] ?? '')] ?? [];
            $phase = is_int($value) ? array_search($value, array_intersect_key($codes, ['play' => 0, 'pause' => 0, 'stop' => 0]), true) : false;
            if ($phase !== false) {
                return $phase;
            }
        }
        if ($withStatus && isset($roles['state'])) {
            $value = $this->backend->read($roles['state'])['value'] ?? null;
            return is_string($value) ? (self::MEDIA_STATES[strtolower(trim($value))][0] ?? null) : null;
        }
        return null;
    }

    /**
     * Wiedergabebefehl an Mediaplayer: Play, Pause und Stop gelten als bestätigt, wenn die Phase
     * stimmt, Weiter und Zurück, wenn sich Wiedergabewert, Titel oder Interpret ändern. Gemessen am
     * nuc (Sonos Wintergarten, 10.10.2026): Sonos-Modul nach 0,1 s, HomeAssistant-Modul nach 0,9 s
     * (Statustext) bzw. 1,6 s (neuer Titel).
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private function runPlayback(array $entries, string $command): array
    {
        $snapshot = function (array $entry): array {
            $roles = $entry['roles'];
            return array_map(fn (int $id): mixed => $this->backend->read($id)['value'] ?? null, array_intersect_key($roles, ['playback' => 0, 'title' => 0, 'artist' => 0]));
        };
        $jobs = [];
        foreach ($entries as $index => $entry) {
            $variable = $entry['roles']['playback'];
            $job = ['entry' => $entry, 'variable' => $variable, 'note' => null, 'done' => false, 'before' => []];
            $current = $this->backend->read($variable);
            $profile = (string)($this->backend->range($variable)['profile'] ?? '');
            $codes = self::PLAYBACK_CODES[$profile] ?? null;
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $variable);
            } elseif ($codes === null) {
                $job['note'] = sprintf($this->t('cannot be controlled (unknown playback profile %s)'), $profile === '' ? '-' : $profile);
            } elseif (!$this->backend->hasAction($variable)) {
                $job['note'] = $this->t('cannot be controlled (playback has no action)');
            } elseif (!isset($codes[$command])) {
                $job['note'] = sprintf($this->t('does not support "%s"'), $command);
            } elseif (in_array($command, ['play', 'pause', 'stop'], true) && $this->playbackPhase($entry) === $command) {
                $job['note'] = $this->t(['play' => 'already playing', 'pause' => 'already paused', 'stop' => 'already stopped'][$command]);
                $job['done'] = true;
            } else {
                $job['before'] = $snapshot($entry);
                $error = $this->backend->request($variable, $codes[$command]);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                }
            }
            $jobs[$index] = $job;
        }

        if ($command === 'pause') {
            $sent = array_column(array_filter($jobs, static fn (array $job): bool => $job['note'] === null), 'variable');
            if ($sent !== []) {
                $this->backend->rememberPaused($sent);
            }
        }
        $reached = fn (array $job): bool => in_array($command, ['next', 'previous'], true)
            ? $snapshot($job['entry']) !== $job['before']
            : $this->playbackPhase($job['entry']) === $command;
        $this->waitUntil(function () use ($jobs, $reached): bool {
            foreach ($jobs as $job) {
                if ($job['note'] === null && !$reached($job) && $this->backend->failure($job['variable']) === null) {
                    return false;
                }
            }
            return true;
        });

        $result = ['lines' => [], 'status' => [], 'total' => count($jobs), 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($jobs as $index => $job) {
            if ($job['note'] === null) {
                $confirmed = $reached($job);
                $error = $confirmed ? null : $this->backend->failure($job['variable']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                    $status = 'failed';
                } else {
                    $text = $this->stateOf($job['entry'])['text'];
                    $job['done'] = $confirmed;
                    $job['note'] = $confirmed ? $text : sprintf($this->t('sent, but the device has not confirmed yet (currently %s)'), $text);
                    $status = $confirmed ? 'reached' : 'pending';
                }
            } else {
                $status = $job['done'] ? 'reached' : 'failed';
            }
            $result[$status]++;
            $result['status'][$index] = $status;
            $result['lines'][$index] = $this->line($job['entry'], $job['note']);
        }
        return $result;
    }

    /**
     * Fragt in kurzen Schritten nach, bis $done zutrifft oder die Wartezeit um ist.
     *
     * @return int gewartete Millisekunden
     */
    /**
     * Auswahl für „play“ ohne Raum, Etage und Name (Typ höchstens media): die Mediaplayer, die in der
     * letzten Stunde per Werkzeug pausiert wurden. null = keine solche Anfrage oder kein Merker.
     *
     * @return null|array{entries: list<array<string, mixed>>, filter: string}
     */
    private function recentlyPaused(array $arguments): ?array
    {
        foreach (['room', 'floor', 'name'] as $key) {
            if (trim((string)($arguments[$key] ?? '')) !== '') {
                return null;
            }
        }
        if (!in_array(trim((string)($arguments['type'] ?? '')), ['', 'media'], true)) {
            return null;
        }
        $since = $this->backend->now() - self::PAUSE_MEMORY_MS;
        $paused = array_keys(array_filter($this->backend->rememberedPaused(), static fn (int $at): bool => $at >= $since));
        $entries = array_values(array_filter($this->entries, fn (array $e): bool => $e['Type'] === 'media' && $this->isControllable($e) && in_array($e['roles']['playback'] ?? 0, $paused, true)));
        return $entries === [] ? null : ['entries' => $entries, 'filter' => $this->t('paused in the last hour')];
    }

    /**
     * Lautstärke von Mediaplayern in Prozent ihres Bereichs (Sonos-Modul 0 bis 100, HomeAssistant-
     * Modul 0 bis 1), absolut oder um Punkte geändert und an den Enden gekappt. Bestätigt, wenn die
     * Variable den Zielwert zeigt; gemessen am nuc nach 0,06 s (Sonos-Modul) bzw. 0,4 s (HA).
     *
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private function runVolume(array $entries, int|float|null $absolute, int|float|null $change): array
    {
        $jobs = [];
        foreach ($entries as $index => $entry) {
            $variable = $entry['roles']['volume'];
            $job = ['entry' => $entry, 'variable' => $variable, 'note' => null, 'done' => false, 'target' => 0.0, 'tolerance' => 0.0];
            $current = $this->backend->read($variable);
            $range = $this->backend->range($variable);
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $variable);
            } elseif ($range === null || $range['max'] <= $range['min'] || (!is_int($current['value']) && !is_float($current['value']))) {
                $job['note'] = $this->t('cannot be set (unknown volume range)');
            } elseif (!$this->backend->hasAction($variable)) {
                $job['note'] = $this->t('cannot be set (the volume has no action)');
            } else {
                $span = $range['max'] - $range['min'];
                $now = round(((float)$current['value'] - $range['min']) / $span * 100.0);
                $percent = $absolute !== null ? round((float)$absolute) : max(0.0, min(100.0, $now + round((float)$change)));
                $raw = $range['min'] + $percent / 100.0 * $span;
                // Im Raster der Variable senden: Das Denon-Modul lehnt -50.6 dB ab und nimmt -50.5 an
                // (Testbox 12.10.2026, Schritt 0.5 aus der Darstellung).
                $step = (float)($range['step'] ?? 0);
                if ($step > 0) {
                    $raw = max($range['min'], min($range['max'], $range['min'] + round(($raw - $range['min']) / $step) * $step));
                }
                $job['target'] = is_int($current['value']) ? (int)round($raw) : round($raw, 4);
                $job['tolerance'] = $span / 200.0;
                if ($percent == $now) {
                    $job['note'] = sprintf($this->t('already set to %s'), sprintf($this->t('volume %s'), self::formatNumber($percent) . ' %'));
                    $job['done'] = true;
                } else {
                    $error = $this->backend->request($variable, $job['target']);
                    if ($error !== null) {
                        $job['note'] = sprintf($this->t('failed: %s'), $error);
                    }
                }
            }
            $jobs[$index] = $job;
        }
        $reached = function (array $job): bool {
            $value = $this->backend->read($job['variable'])['value'] ?? null;
            return (is_int($value) || is_float($value)) && abs((float)$value - (float)$job['target']) < $job['tolerance'];
        };
        return $this->confirmJobs($jobs, $reached);
    }

    /**
     * Stumm ein oder aus. Die Stummvariable ist ein Wahrheitswert (Sonos-Modul „Mute“, HomeAssistant-
     * Modul „Stumm“); gemessen am nuc nach 0,03 s bzw. 0,5 s bestätigt.
     *
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private function runMute(array $entries, bool $mute): array
    {
        $jobs = [];
        foreach ($entries as $index => $entry) {
            $variable = $entry['roles']['mute'];
            $job = ['entry' => $entry, 'variable' => $variable, 'note' => null, 'done' => false];
            $current = $this->backend->read($variable);
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $variable);
            } elseif (!is_bool($current['value'])) {
                $job['note'] = $this->t('cannot be set (mute is not a switch)');
            } elseif (!$this->backend->hasAction($variable)) {
                $job['note'] = $this->t('cannot be set (the mute switch has no action)');
            } elseif ($current['value'] === $mute) {
                $job['note'] = sprintf($this->t('already %s'), $this->t($mute ? 'muted' : 'not muted'));
                $job['done'] = true;
            } else {
                $error = $this->backend->request($variable, $mute);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                }
            }
            $jobs[$index] = $job;
        }
        return $this->confirmJobs($jobs, fn (array $job): bool => ($this->backend->read($job['variable'])['value'] ?? null) === $mute);
    }

    /**
     * Setzt einen Eintrag einer Auswahlvariable per Name (Betriebsart „mode“, Lüfterstufe „fan“). Der
     * Name trifft die Beschriftung oder den Wert, ohne Groß- und Kleinschreibung: „Hoch“ und „high“
     * treffen beide `High` des HomeAssistant-Moduls. Steht der Name in der anderen Auswahl des Geräts,
     * geht nichts hinaus, und die Zeile nennt das richtige Feld. Gemessen am Luftentfeuchter des nuc:
     * Lüfterstufe nach 0,5 bis 1,3 s, Modus nach 0,5 bis 0,8 s bestätigt.
     *
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int, caption: string}
     */
    private function runOption(array $entries, string $role, string $name): array
    {
        $other = $role === 'mode' ? 'fan' : 'mode';
        $key = self::normalize($name);
        $find = function (int $variable) use ($key): ?array {
            foreach ($this->backend->options($variable) ?? [] as [$value, $caption]) {
                if (self::normalize($caption) === $key || self::normalize((string)$value) === $key) {
                    return [$value, $caption];
                }
            }
            return null;
        };
        $jobs = [];
        $captions = [];
        foreach ($entries as $index => $entry) {
            $variable = $entry['roles'][$role];
            $job = ['entry' => $entry, 'variable' => $variable, 'note' => null, 'done' => false, 'target' => null];
            $current = $this->backend->read($variable);
            $options = $this->backend->options($variable);
            $hit = $options === null ? null : $find($variable);
            if ($current === null) {
                $job['note'] = sprintf($this->t('not available (variable %d is missing)'), $variable);
            } elseif ($options === null) {
                $job['note'] = $this->t('cannot be set (no entries known)');
            } elseif (!$this->backend->hasAction($variable)) {
                $job['note'] = $this->t('cannot be set (the variable has no action)');
            } elseif ($hit === null) {
                $otherVariable = $entry['roles'][$other] ?? 0;
                $job['note'] = $otherVariable > 0 && $find($otherVariable) !== null
                    ? sprintf($this->t($other === 'mode' ? '"%s" is a mode, give it as mode' : '"%s" is a fan speed, give it as fan_speed'), $name)
                    : sprintf($this->t('unknown entry "%s", known: %s'), $name, implode(', ', array_column($options, 1)));
            } else {
                $job['target'] = $hit[0];
                $captions[] = $hit[1];
                if ((string)$current['value'] === (string)$hit[0]) {
                    $job['note'] = sprintf($this->t('already set to %s'), $hit[1]);
                    $job['done'] = true;
                } else {
                    $error = $this->backend->request($variable, $hit[0]);
                    if ($error !== null) {
                        $job['note'] = sprintf($this->t('failed: %s'), $error);
                    }
                }
            }
            $jobs[$index] = $job;
        }
        $result = $this->confirmJobs($jobs, fn (array $job): bool => (string)($this->backend->read($job['variable'])['value'] ?? '') === (string)$job['target']);
        $result['caption'] = count(array_unique($captions)) === 1 ? $captions[0] : $name;
        return $result;
    }

    /**
     * Wartet gemeinsam auf alle ausgelösten Aufträge und baut die Zeilen: bestätigt mit dem neuen
     * Zustand, sonst „noch nicht bestätigt“ oder der gemeldete Fehlschlag.
     *
     * @param array<int, array<string, mixed>> $jobs je Gerät entry, variable, note (null = gesendet), done
     * @return array{lines: array<int, string>, total: int, reached: int, pending: int, failed: int}
     */
    private function confirmJobs(array $jobs, callable $reached): array
    {
        $this->waitUntil(function () use ($jobs, $reached): bool {
            foreach ($jobs as $job) {
                if ($job['note'] === null && !$reached($job) && $this->backend->failure($job['variable']) === null) {
                    return false;
                }
            }
            return true;
        });
        $result = ['lines' => [], 'status' => [], 'total' => count($jobs), 'reached' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($jobs as $index => $job) {
            if ($job['note'] === null) {
                $confirmed = $reached($job);
                $error = $confirmed ? null : $this->backend->failure($job['variable']);
                if ($error !== null) {
                    $job['note'] = sprintf($this->t('failed: %s'), $error);
                    $status = 'failed';
                } else {
                    $text = $this->stateOf($job['entry'])['text'];
                    $job['done'] = $confirmed;
                    $job['note'] = $confirmed ? $text : sprintf($this->t('sent, but the device has not confirmed yet (currently %s)'), $text);
                    $status = $confirmed ? 'reached' : 'pending';
                }
            } else {
                $status = $job['done'] ? 'reached' : 'failed';
            }
            $result[$status]++;
            $result['status'][$index] = $status;
            $result['lines'][$index] = $this->line($job['entry'], $job['note']);
        }
        return $result;
    }

    private function waitUntil(callable $done, int $budgetMs = self::CONFIRM_TIMEOUT_MS): int
    {
        for ($waited = 0; $waited < $budgetMs && !$done(); $waited += self::CONFIRM_STEP_MS) {
            $this->backend->sleep(self::CONFIRM_STEP_MS);
        }
        return $waited;
    }

    /** 3 = Name oder Alias stimmt genau, 2 = enthält die Anfrage, 1 = kommt in der Anfrage vor, 0 = kein Treffer. */
    public static function nameMatchQuality(string $name, string $aliases, string $nameKey): int
    {
        $best = 0;
        if ($nameKey === '') {
            return 0;
        }
        foreach (array_merge([$name], explode(',', $aliases)) as $candidate) {
            $key = self::normalize($candidate);
            if ($key === '') {
                continue;
            }
            if ($key === $nameKey) {
                return 3;
            }
            if (str_contains($key, $nameKey)) {
                $best = max($best, 2);
            } elseif (preg_match('/(^| )' . preg_quote($key, '/') . '/u', $nameKey) === 1) {
                // Der Name muss in der Anfrage an einem Wortanfang stehen: „Esstischlampe“ trifft
                // „Esstisch“, „Außentemperatur“ trifft „Temperatur“ nicht (nuc, 14 Geräte).
                $best = max($best, 1);
            }
        }
        return $best;
    }

    /**
     * Der Alias, über den ein Gerät gefunden wurde, wenn er besser trifft als der Name selbst; sonst
     * null. „Probelicht“ ergibt „Probelicht“ (Testlampe), „Lampe“ beim Esstisch „Esstischlampe“, bei
     * der Testlampe nichts, weil schon der Name „Lampe“ enthält.
     */
    public static function matchedAlias(string $name, string $aliases, string $nameKey): ?string
    {
        $best = null;
        $bestQuality = self::nameMatchQuality($name, '', $nameKey);
        foreach (explode(',', $aliases) as $alias) {
            $quality = self::nameMatchQuality(trim($alias), '', $nameKey);
            if ($quality > $bestQuality) {
                $best = trim($alias);
                $bestQuality = $quality;
            }
        }
        return $best;
    }

    /** Name für die Antwortzeilen, bei einem Treffer über einen Alias mit diesem in Klammern. */
    private static function displayName(array $entry): string
    {
        $alias = $entry['MatchedAlias'] ?? null;
        if ($alias === null || $alias === '') {
            return $entry['Name'];
        }
        return $entry['Name'] . ' (' . str_replace('|', '/', self::singleLine($alias, self::VALUE_TEXT_LENGTH)) . ')';
    }

    /**
     * Zustand eines Geräts; hat es eine Leistung, steht sie in der Klammer dahinter („an (26.1 W)“,
     * „an (50 %, 12.4 W)“).
     *
     * @return array{text: string, active: ?bool}
     */
    private function stateOf(array $entry): array
    {
        // Ein Gerät nur mit Leistung (Wärmepumpe ohne Schaltzustand): die Leistung ist der Zustand. Ein Klimagerät ohne
        // Ist-Temperatur hat noch den Sollwert (B85), ein Luftentfeuchter die Zielfeuchte.
        if (isset($entry['roles']['power']) && array_diff(array_keys($entry['roles']), ['power', 'energy']) === []) {
            $power = $this->backend->read($entry['roles']['power']);
            return ['text' => $power === null ? sprintf($this->t('not available (variable %d is missing)'), $entry['roles']['power']) : $power['formatted'], 'active' => null];
        }
        $state = $this->mainStateOf($entry);
        $append = static function (array $state, string $text): array {
            $state['text'] = str_ends_with($state['text'], ')')
                ? substr($state['text'], 0, -1) . ', ' . $text . ')'
                : $state['text'] . ' (' . $text . ')';
            return $state;
        };
        // Die Farbtemperatur nur bei eingeschaltetem Licht; ausgeschaltet meldet etwa das
        // HomeAssistant-Modul 0 K. Mit bekanntem Farbmodus nur der gültige Wert: Ein weißes Licht nannte sonst die
        // umgerechnete Farbe („2732 K, gelb“), ein farbiges eine alte Farbtemperatur (betreute Anlage 16.10.2026).
        $colorMode = $state['active'] === true && $entry['Type'] === 'light' ? $this->colorModeOf($entry) : null;
        if ($state['active'] === true && isset($entry['roles']['colortemp']) && $colorMode !== 'color') {
            $raw = $this->backend->read($entry['roles']['colortemp'])['value'] ?? null;
            if ((is_int($raw) || is_float($raw)) && $raw > 0) {
                $state = $append($state, $this->kelvinOf($entry['roles']['colortemp'], (float)$raw) . ' K');
            }
        }
        if ($state['active'] === true && isset($entry['roles']['color']) && $colorMode !== 'temperature') {
            $rgb = $this->backend->read($entry['roles']['color'])['value'] ?? null;
            $name = is_int($rgb) ? self::colorName($rgb) : null;
            if ($name !== null) {
                $state = $append($state, $this->t($name));
            }
        }
        // Fehlt die Hauptvariable, steht das schon im Zustand; ein Klimagerät ohne Ist-Temperatur hat keine (B85)
        $main = $this->mainVariable($entry);
        $power = isset($entry['roles']['power']) && ($main === 0 || $this->backend->read($main) !== null) ? $this->backend->read($entry['roles']['power']) : null;
        if ($power === null || $power['formatted'] === '') {
            return $state;
        }
        return $append($state, $power['formatted']);
    }

    /**
     * Aktiv (an, offen, erkannt) bei einem Wahrheitswert oder einer Zahl über 0. Der Haken „Umgekehrt“ dreht den
     * Wahrheitswert, bei Zahlen nur an Meldern: Dann ist 0 aktiv (Türstatus von Matter, 0 = Geöffnet). Ein Dimmer
     * ohne Schalter bleibt bei 0 aus. null = weder Wahrheitswert noch Zahl.
     */
    private static function activeValue(array $entry, mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value !== $entry['Invert'];
        }
        if (is_int($value) || is_float($value)) {
            return ($value > 0) !== ($entry['Invert'] && !in_array($entry['Type'], self::CONTROLLABLE, true));
        }
        return null;
    }

    /** @return array{text: string, active: ?bool} */
    private function mainStateOf(array $entry): array
    {
        if ($entry['Type'] === 'thermostat') {
            return ['text' => $this->thermostatText($entry), 'active' => null];
        }
        if ($entry['Type'] === 'media') {
            return $this->mediaText($entry);
        }
        // Die Lüftung hat dieselbe Zeile wie der Luftentfeuchter, nur ohne Feuchte.
        if ($entry['Type'] === 'humidifier' || $entry['Type'] === 'fan') {
            return $this->humidifierText($entry);
        }
        $main = $this->backend->read($this->mainVariable($entry));
        if ($main === null) {
            return ['text' => sprintf($this->t('not available (variable %d is missing)'), $this->mainVariable($entry)), 'active' => null];
        }
        $words = self::TYPES[$entry['Type']] ?? null;
        // Die Stufe steht nur dann neben dem Zustand, wenn das Gerät beide Fähigkeiten hat.
        $level = isset($entry['roles']['state'], $entry['roles']['level']) ? $this->backend->read($entry['roles']['level']) : null;

        // Fenster nur mit Antrieb: offen, sobald es nicht ganz geschlossen ist.
        if ($entry['Type'] === 'window' && !isset($entry['roles']['state']) && isset($entry['roles']['level'])) {
            $open = $this->openPercent($entry, $main['value']);
            return ['text' => $this->blindText($entry, $main), 'active' => $open === null ? null : $open >= 0.5];
        }
        if ($words !== null && is_bool($main['value'])) {
            $active = $main['value'] !== $entry['Invert'];
            $text = $this->t($active ? $words[0] : $words[1]);
            // Die Stufe nur, wenn sie etwas sagt: „offen (50 % gekippt)“, aber nicht „offen (offen)“.
            $levelText = $active && $level !== null ? $this->levelText($entry, $level) : '';
            if ($levelText !== '' && $levelText !== $text) {
                $text .= ' (' . $levelText . ')';
            }
            return ['text' => $text, 'active' => $active];
        }
        if ($words !== null && (is_int($main['value']) || is_float($main['value']))) {
            // Gerät nur mit Stufe (z. B. Dimmer ohne eigenen Schalter): an, sobald die Stufe über 0 liegt.
            $active = (bool)self::activeValue($entry, $main['value']);
            // Melder mit Einträgen (Türstatus von Matter: 0 Geöffnet, 1 Geschlossen, 5 Angelehnt …): der Eintrag in
            // Klammern, wenn er mehr sagt als das Wort, in beiden Zuständen.
            if (!in_array($entry['Type'], self::CONTROLLABLE, true) && $this->backend->options($this->mainVariable($entry)) !== null) {
                $text = $this->t($active ? $words[0] : $words[1]);
                $caption = trim($main['formatted']);
                $plain = array_merge(self::PLAIN_CAPTIONS[$active ? 0 : 1], [mb_strtolower($text)]);
                return ['text' => $caption === '' || in_array(mb_strtolower($caption), $plain, true) ? $text : $text . ' (' . $caption . ')', 'active' => $active];
            }
            // Eine RGBW-Leiste ohne Schalter leuchtet auch mit Weißkanal 0, wenn ihre Farbe nicht schwarz
            // ist (IPS-868 am nuc nach color=blue: White 0, Color 255, die Zeile sagte „aus“).
            if (!$active && isset($entry['roles']['color'])) {
                $rgb = $this->backend->read($entry['roles']['color'])['value'] ?? null;
                if (is_int($rgb) && self::colorName($rgb) !== null) {
                    return ['text' => $this->t($words[0]), 'active' => true];
                }
            }
            return ['text' => ($active ? $this->t($words[0]) . ' (' . $this->levelText($entry, $main) . ')' : $this->t($words[1])), 'active' => $active];
        }
        return ['text' => $entry['Type'] === 'blind' ? $this->blindText($entry, $main) : $main['formatted'], 'active' => null];
    }

    /**
     * Rohwerte, zwischen denen ein Rollladen fährt. Der Haken „Umgekehrt“ der Zeile dreht die von
     * der Anlage erkannte Richtung um.
     *
     * @param null|array<string, mixed> $range Ergebnis von DeviceBackend::range()
     * @return null|array{open: float, closed: float}
     */
    public static function travel(?array $range, bool $invert): ?array
    {
        if ($range === null) {
            return null;
        }
        $open = (float)($range['open'] ?? $range['min']);
        $closed = (float)($range['closed'] ?? $range['max']);
        if ($open === $closed) {
            return null;
        }
        return $invert ? ['open' => $closed, 'closed' => $open] : ['open' => $open, 'closed' => $closed];
    }

    /**
     * Stufe in Worten. Ein Rollladen rechnet selbst: „offen“, „geschlossen“ oder „70 % geschlossen“,
     * damit die Antwort zu dem passt, was verlangt wurde. Der Anzeigetext der Anlage taugt dafür
     * nicht, er zählt je Modul anders herum. Ein Licht rechnet ebenfalls selbst und rundet: Symcon
     * schneidet ab (Stufe 84 von 1 bis 254 sind 32,8 %, angezeigt „32 %“), und nach „auf 33 %“
     * stünde sonst „32 %“ in der Antwort. Ohne bekannten Bereich bleibt der Anzeigetext.
     *
     * @param null|array{value: mixed, formatted: string} $reading
     */
    private function levelText(array $entry, ?array $reading, string $unknown = ''): string
    {
        $formatted = $reading['formatted'] ?? $unknown;
        $value = $reading['value'] ?? null;
        $variable = $entry['roles']['level'] ?? 0;
        if ($variable === 0 || (!is_int($value) && !is_float($value))) {
            return $formatted;
        }
        $range = $this->backend->range($variable);
        if (!self::moves($entry)) {
            if ($range === null || $range['max'] <= $range['min']) {
                return $formatted;
            }
            $percent = ($value - $range['min']) / ($range['max'] - $range['min']) * 100.0;
            // Wie beim Stellen: „Umgekehrt“ dreht die Skala nur bei einem Licht ohne eigenen Schalter.
            if (!isset($entry['roles']['state']) && $entry['Invert']) {
                $percent = 100.0 - $percent;
            }
            return self::formatNumber(round(max(0.0, min(100.0, $percent)))) . ' %';
        }
        $travel = self::travelOf($entry, $range);
        if ($travel === null) {
            return $formatted;
        }
        // Öffnungsgrad: dieselbe Zahl, die ein Befehl mit percent nennt.
        $percent = ($value - $travel['closed']) / ($travel['open'] - $travel['closed']) * 100.0;
        if (($range['source'] ?? '') === 'rotation') {
            // Lamellen drehen zwischen außen und innen, „offen“ und „geschlossen“ passen dort nicht.
            return self::formatNumber(round($percent)) . ' %';
        }
        $tilt = $entry['Type'] === 'window' && $entry['Tilt'];
        if ($percent < 0.5) {
            return $this->t('closed');
        }
        if ($percent > 99.5) {
            return $this->t($tilt ? 'tilted' : 'open');
        }
        return sprintf($this->t($tilt ? '%s %% tilted' : '%s %% open'), self::formatNumber(round($percent)));
    }

    private function line(array $entry, string $text): string
    {
        // Der Text kommt aus der Anlage (Anzeigetexte, Warnungen von Modulen) und kann von außen
        // gefüllt sein. Er darf das Format „eine Zeile je Gerät“ nicht zerbrechen.
        return ($entry['Room'] !== '' ? $entry['Room'] : '-') . ' | ' . self::displayName($entry) . ' | ' . $entry['Type'] . ' | ' . str_replace('|', '/', self::singleLine($text, self::VALUE_TEXT_LENGTH));
    }

    /** Steuerzeichen werden zu Leerzeichen, mehrere Leerzeichen zu einem; zu lange Texte enden mit „…“. */
    public static function singleLine(string $text, int $maxLength): string
    {
        $text = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
        $text = (string)preg_replace('/ {2,}/', ' ', $text);
        return mb_strlen($text) > $maxLength ? rtrim(mb_substr($text, 0, $maxLength - 1)) . '…' : $text;
    }

    /** Ist die Raumangabe eine der Angaben für „das ganze Haus“? */
    public static function isAllRooms(string $room): bool
    {
        return in_array(self::normalize($room), self::ALL_ROOMS, true);
    }

    /**
     * Die Raumangabe, wie sie gilt: „all“ hebt jede Einschränkung auf; nennt der Aufruf weder
     * Raum noch Name noch Etage, gilt der Standardraum des Zugangs.
     */
    private function effectiveRoom(string $room, string $name, string $floor): string
    {
        if (self::isAllRooms($room)) {
            return '';
        }
        return $room === '' && $name === '' && $floor === '' ? $this->defaultRoom : $room;
    }

    private function unknownFloor(string $floor): string
    {
        $this->problem = 'unknown_floor';
        $floors = $this->rooms->floors();
        return sprintf($this->t('Unknown floor "%s". Known floors: %s.'), $floor, $floors === [] ? $this->t('(none)') : implode(', ', $floors));
    }

    /** Die Filterangabe nennt die Parameternamen des Werkzeugs und bleibt deshalb unübersetzt. */
    private function describeFilter(string $room, string $type, string $name, string $floor = ''): string
    {
        $parts = [];
        foreach (['room' => $room, 'floor' => $floor, 'type' => $type, 'name' => $name] as $key => $value) {
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }
        return $parts === [] ? $this->t('all devices') : implode(', ', $parts);
    }

    private function knownRoomsAndTypes(): string
    {
        $rooms = array_values(array_unique(array_filter(array_column($this->entries, 'Room'))));
        $types = array_values(array_unique(array_column($this->entries, 'Type')));
        sort($rooms);
        sort($types);
        $none = $this->t('(none)');
        $known = sprintf(
            $this->t('Known rooms: %s. Existing types: %s.'),
            $rooms === [] ? $none : implode(', ', $rooms),
            $types === [] ? $none : implode(', ', $types)
        );
        $floors = $this->rooms->floors();
        return $floors === [] ? $known : $known . ' ' . sprintf($this->t('Known floors: %s.'), implode(', ', $floors));
    }

    /** @return array{text: string, isError: bool} */
    private function fail(string $text): array
    {
        $this->problem ??= 'invalid';
        return ['text' => $text, 'isError' => true];
    }

    /**
     * Was bei der letzten Anfrage nicht geklappt hat, für die Liste im Formular (Burkhard 12.10.2026):
     * unknown_room, unknown_name, unknown_floor, no_match, ambiguous, read_only, not_understood, left_out,
     * not_confirmed, failed, invalid; null, wenn alles erreicht ist. Ein Rollladen, der noch fährt, ist kein
     * Problem.
     */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * Werkzeug „show_chart“ (Backlog B63): der Verlauf von Messwerten als Diagramm. Das Modell bekommt die Kurzfassung
     * (Kopfzeile, je Messwert Minimum und Maximum), die Werte je Stunde, Tag oder Monat stehen in structuredContent für
     * die Seite des Diagramms (MCP Apps) und kosten keine Tokens. Ohne Zeitraum gilt heute.
     *
     * @return array{text: string, isError: bool, structuredContent?: array<string, mixed>}
     */
    public function chart(array $arguments): array
    {
        // Die Seite beschriftet Datum und Uhrzeit in der Zeitzone der Anlage; sie rechnet auch die Tagesgrenzen (Review
        // 14.10.2026: unterwegs standen Achsen und Heatmap-Zeilen in der Zone des Browsers)
        $result = $this->chartAny($arguments);
        if (isset($result['structuredContent'])) {
            $result['structuredContent']['timeZone'] = date_default_timezone_get();
        }
        return $result;
    }

    private function chartAny(array $arguments): array
    {
        // Summenkurve (B73): dasselbe Verbrauchsdiagramm je Tag (bis zwei Tage je Stunde), aufaddiert; ohne Zeitraum das
        // laufende Jahr
        $cumulative = ($arguments['cumulative'] ?? false) === true;
        unset($arguments['cumulative']);
        if (!$cumulative) {
            return $this->chartOf($arguments);
        }
        if (trim((string)($arguments['period'] ?? '')) === '' && trim((string)($arguments['from'] ?? '')) === '') {
            $arguments['period'] = 'this_year';
        }
        $span = trim((string)($arguments['period'] ?? '')) === self::NOW ? null : $this->periodSpan($arguments);
        if ($span === null) {
            return $this->fail($this->t('A cumulative chart needs a period or from and to.'));
        }
        $this->forceLevel = $span[1] - $span[0] <= 2 * 86400 ? 0 : 1;
        $basis = null;
        try {
            $result = $this->chartOf($arguments);
            // Für die Hochrechnung eines laufenden Zeitraums derselbe Zeitraum ein Jahr früher (Burkhard 13.10.2026: linear
            // unterschätzt über ein Jahr den Winter); mit compare=year_before steht er schon im Diagramm.
            if (!$result['isError'] && $span[1] > $this->backend->timestamp()) {
                if (trim((string)($arguments['compare'] ?? '')) === 'year_before') {
                    $basis = $result['structuredContent']['compare']['values'] ?? null;
                } else {
                    $before = $this->chartOf(['compare' => 'year_before'] + $arguments);
                    $basis = $before['isError'] ? null : ($before['structuredContent']['compare']['values'] ?? null);
                }
            }
        } finally {
            $this->forceLevel = null;
        }
        return $result['isError'] ? $result : $this->cumulativeOf($result, $span, $basis);
    }

    /**
     * Macht aus einem Verbrauchsdiagramm die Summenkurve: Summe je Abschnitt (alle positiven Teile, wie die Marke des
     * Vergleichs), aufaddiert, künftige Abschnitte leer. Läuft der Zeitraum noch, rechnet das Modul mit dem bisherigen
     * Mittel bis zum Ende hoch; ein Vergleich wird ebenso aufaddiert. Das Modell bekommt Stand, Hochrechnung und den
     * Vergleich zur selben Zeit.
     *
     * @param array{0: int, 1: int} $span
     * @param null|list<float> $basis Summe je Abschnitt desselben Zeitraums ein Jahr früher, für die Hochrechnung
     */
    private function cumulativeOf(array $result, array $span, ?array $basis = null): array
    {
        $chart = $result['structuredContent'] ?? [];
        if (($chart['kind'] ?? '') !== 'consumption') {
            return $this->fail($this->t('A cumulative chart needs a meter or the house consumption.'));
        }
        [$from, $end] = $span;
        $now = $this->backend->timestamp();
        $running = $end > $now;
        $unit = (string)($chart['unit'] ?? 'kWh');
        $format = static fn (float $v): string => $unit === '€' ? number_format($v, 2, '.', '') : self::formatNumber($v);
        $totals = static function (array $series, int $count): array {
            $sums = array_fill(0, $count, 0.0);
            foreach ($series as $s) {
                foreach ($s['values'] as $i => $v) {
                    $sums[$i] += max(0.0, (float)$v);
                }
            }
            return $sums;
        };
        $times = $chart['times'] ?? [];
        $values = [];
        $sum = 0.0;
        $last = -1;
        $sections = $totals($chart['series'] ?? [], count($times));
        foreach ($sections as $i => $v) {
            if ($running && $times[$i] > $now) {
                $values[$i] = null;
                continue;
            }
            $sum += $v;
            $values[$i] = round($sum, 3);
            $last = $i;
        }
        // Hochrechnung: mit dem Verlauf des Vorjahres, wenn es dort für mindestens 80 % der Abschnitte bis heute Werte gibt
        // (Stand × Vorjahr gesamt ÷ Vorjahr bis zur selben Zeit, der laufende Abschnitt anteilig; die Kurve folgt dem
        // Vorjahr), sonst linear mit dem bisherigen Mittel.
        $projection = null;
        $method = null;
        if ($running && $now > $from && $last >= 0) {
            $step = count($times) > 1 ? $times[1] - $times[0] : 86400;
            // Abschnitte ohne Vergleich (null) zählen weder im Vorjahr noch im Stand: Das Verhältnis kommt nur aus
            // Abschnitten, die beide Zeiträume haben (Review 14.10.2026: ein fehlender Tag verschob die Hochrechnung).
            $known = $basis === null ? [] : array_map(static fn (?float $v): bool => $v !== null, $basis);
            $basis = $basis === null ? null : array_map(static fn (?float $v): float => $v ?? 0.0, $basis);
            $covered = $basis === null ? 0 : count(array_filter(array_slice($basis, 0, $last + 1), static fn (float $v): bool => $v > 0));
            if ($basis !== null && $covered >= 0.8 * ($last + 1) && count($basis) >= count($times) - 1) {
                $before = [];
                $acc = 0.0;
                foreach ($basis as $i => $v) {
                    $acc += $v;
                    $before[$i] = $acc;
                }
                $share = min(1.0, max(0.0, ($now - $times[$last]) / $step));
                $then = ($before[$last - 1] ?? 0.0) + ($basis[$last] ?? 0.0) * $share;
                $thenKnown = ($basis[$last] ?? 0.0) * $share;
                $sumKnown = ($known[$last] ?? false) ? $sections[$last] : 0.0;
                for ($i = 0; $i < $last; $i++) {
                    if ($known[$i] ?? false) {
                        $thenKnown += $basis[$i];
                        $sumKnown += $sections[$i];
                    }
                }
                if ($then > 0 && $thenKnown > 0) {
                    $factor = $sumKnown / $thenKnown;
                    $projection = [[$now, round($sum, 3)]];
                    for ($i = $last + 1; $i < count($times) - 1 && $i < count($before); $i++) {
                        $projection[] = [$times[$i] + $step, round($sum + ($before[$i] - $then) * $factor, 3)];
                    }
                    $projection[] = [$end, round($sum + ((float)end($before) - $then) * $factor, 3)];
                    $method = 'year_before';
                }
            }
            if ($projection === null) {
                $projection = [[$now, round($sum, 3)], [$end, round($sum / (($now - $from) / ($end - $from)), 3)]];
                $method = 'linear';
            }
        }
        // Bei Kosten die Vergütung der Einspeisung als zweite Reihe, aufaddiert, mit dem Saldo im Text (Burkhard 13.10.2026)
        $revenue = null;
        $earned = 0.0;
        foreach ($chart['series'] ?? [] as $s) {
            if ($unit === '€' && ($s['key'] ?? '') === 'export') {
                $revenue = [];
                foreach ($s['values'] as $i => $v) {
                    if ($running && $times[$i] > $now) {
                        $revenue[$i] = null;
                        continue;
                    }
                    $earned -= min(0.0, (float)$v);
                    $revenue[$i] = round($earned, 3);
                }
            }
        }
        $total = $projection === null ? 0.0 : (float)end($projection)[1];
        $lines = [match ($method) {
            'year_before' => sprintf($this->t('So far %s %s, projected %s %s for the whole period (after the course of the year before).'), $format($sum), $unit, $format($total), $unit),
            'linear'      => sprintf($this->t('So far %s %s, projected %s %s for the whole period.'), $format($sum), $unit, $format($total), $unit),
            default       => sprintf($this->t('In total %s %s.'), $format($sum), $unit),
        }];
        if ($revenue !== null) {
            $lines[] = sprintf($this->t('Feed-in %s €, net %s €.'), $format($earned), $format($sum - $earned));
        }
        $compare = null;
        if (isset($chart['compare']['values'])) {
            $raw = $chart['compare']['values'];
            while ($raw !== [] && end($raw) === null) {
                array_pop($raw);
            }
            $running2 = 0.0;
            $cumulated = array_map(static function (?float $v) use (&$running2): float {
                $running2 += $v ?? 0.0;
                return round($running2, 3);
            }, $raw);
            $compare = ['label' => $chart['compare']['label'] ?? '', 'range' => $chart['compare']['range'] ?? null, 'values' => $cumulated];
            // Zur selben Zeit: der laufende Abschnitt anteilig wie der Stand (Review 14.10.2026: vorher ganz)
            $then = null;
            if ($last >= 0 && $cumulated !== []) {
                $index = min($last, count($cumulated) - 1);
                $then = $cumulated[$index];
                if ($running && $index === $last) {
                    $next = $times[$last + 1] ?? $end;
                    $then = ($cumulated[$last - 1] ?? 0.0) + (float)($raw[$last] ?? 0.0) * min(1.0, max(0.0, ($now - $times[$last]) / max(1, $next - $times[$last])));
                }
            }
            if ($then !== null && $then > 0) {
                $change = (int)round(($sum - $then) / $then * 100);
                $lines[] = sprintf($this->t('Comparison by the same time %s %s (%s%d %%).'), $format($then), $unit, $change >= 0 ? '+' : '', $change);
            }
        }
        $text = preg_replace('/\n[^\n]*$/', '', $result['text']) . "\n" . implode("\n", $lines) . "\n" . $this->t('The chart is shown in the chat.');
        return ['text' => $text, 'isError' => false, 'structuredContent' => [
            'kind'       => 'cumulative', 'title' => $chart['title'] ?? '', 'per' => $chart['per'] ?? 'day', 'unit' => $unit,
            'range'      => [$from, $end], 'times' => $times, 'values' => $values, 'revenue' => $revenue, 'projection' => $projection, 'projectionMethod' => $method, 'compare' => $compare,
            'labels'     => ($chart['labels'] ?? []) + ['projection' => $this->t('projection'), 'compare' => $this->t('comparison'), 'total' => $this->t($unit === '€' ? 'grid import' : 'cumulative'), 'revenue' => $this->t('grid export')],
        ]];
    }

    /** Überschrift eines Teils der Bilanz in der Summenzeile (B74). */
    private const PART_TITLES = ['grid' => 'Grid import', 'feed_in' => 'Grid export', 'pv' => 'Solar production', 'battery_charge' => 'Battery charge', 'battery_discharge' => 'Battery discharge'];

    /**
     * Prüft part (B74): bekannter Teil, Bilanz mit diesem Zähler, nur fürs ganze Haus, keine Punktwolke. Ohne part: Trifft
     * ein Name kein Gerät, ist aber ein Teil der Bilanz, kommt der Hinweis auf part. null = in Ordnung.
     */
    private function partError(string $part, array $arguments): ?string
    {
        $name = trim((string)($arguments['name'] ?? ''));
        if ($part === '') {
            if ($name === '' || $this->balance === [] || $this->find('', '', $name) !== []) {
                return null;
            }
            foreach (self::PARTS as $candidate => $key) {
                if (!isset($this->balance[$key])) {
                    continue;
                }
                $words = [$candidate, $this->t(self::BALANCE_TERMS[$key][1]), $this->t(self::PART_TITLES[$candidate])];
                if (in_array(mb_strtolower($name), array_map('mb_strtolower', $words), true)) {
                    $this->problem = 'unknown_name';
                    return sprintf($this->t('"%s" is part of the energy balance, not a device: use part=%s without name.'), $name, $candidate);
                }
            }
            return null;
        }
        if (!isset(self::PARTS[$part])) {
            return sprintf($this->t('Unknown part "%s". Allowed: %s.'), $part, implode(', ', array_keys(self::PARTS)));
        }
        if (!isset($this->balance[self::PARTS[$part]])) {
            return $this->t('A part needs the energy balance with this meter.');
        }
        foreach (['room', 'floor', 'type', 'name'] as $field) {
            if (trim((string)($arguments[$field] ?? '')) !== '') {
                return $this->t('A part of the energy balance is only available for the whole house, without room, floor, type or name.');
            }
        }
        if (trim((string)($arguments['versus'] ?? '')) !== '') {
            return $this->t('A scatter chart takes no part of the energy balance.');
        }
        return null;
    }

    /** Diagramm ohne Summenkurve. */
    private function chartOf(array $arguments): array
    {
        // Teil der Bilanz (B74): nur fürs ganze Haus. Ein Name, der ein Teil der Bilanz ist, bekommt den Hinweis auf part;
        // die Claude-App holte sich nach name=Einspeisung ohne Treffer 22 Monate einzeln über get_state (13.10.2026).
        $part = trim((string)($arguments['part'] ?? ''));
        unset($arguments['part']);
        $partError = $this->partError($part, $arguments);
        if ($partError !== null) {
            return $this->fail($partError);
        }
        // Kosten in Euro (B72): nur fürs ganze Haus und nur mit Strompreis in der Energiebilanz
        $costs = ($arguments['costs'] ?? false) === true;
        unset($arguments['costs']);
        if ($costs && $part !== '' && !in_array($part, ['grid', 'feed_in'], true)) {
            return $this->fail($this->t('Costs are only available for part=grid and part=feed_in.'));
        }
        if ($costs) {
            if ($this->priceVariable === 0 || $this->balance === []) {
                return $this->fail($this->t('Costs need an electricity price in the energy balance.'));
            }
            foreach (['room', 'floor', 'type', 'name'] as $field) {
                if (trim((string)($arguments[$field] ?? '')) !== '') {
                    return $this->fail($this->t('Costs are only available for the whole house, without room, floor, type or name.'));
                }
            }
        }
        // Punktwolke (B70): versus nennt das Gerät der x-Achse; ohne Zeitraum der letzte Monat
        $versus = trim((string)($arguments['versus'] ?? ''));
        unset($arguments['versus']);
        if ($versus !== '') {
            if (trim((string)($arguments['period'] ?? '')) === '' && trim((string)($arguments['from'] ?? '')) === '') {
                $arguments['period'] = 'last_month';
            }
            return $this->scatter($arguments, $versus);
        }
        // Heatmap (B71): ein Wert je Stunde als Raster; ohne Zeitraum der letzte Monat
        $heatmap = ($arguments['heatmap'] ?? false) === true;
        unset($arguments['heatmap']);
        if ($heatmap) {
            if (trim((string)($arguments['period'] ?? '')) === '' && trim((string)($arguments['from'] ?? '')) === '') {
                $arguments['period'] = 'last_month';
            }
            return $this->heatmap($arguments, $costs, $part);
        }
        $period = trim((string)($arguments['period'] ?? ''));
        if ($period === self::NOW) {
            $filtered = array_filter(['room', 'floor', 'type', 'name'], static fn (string $field): bool => trim((string)($arguments[$field] ?? '')) !== '');
            if ($filtered === [] && $this->powerBalance !== []) {
                return $this->powerRings($arguments);
            }
            return $this->fail($this->t('A chart needs a period or from and to, now has no history.'));
        }
        if ($period === '' && trim((string)($arguments['from'] ?? '')) === '') {
            $arguments['period'] = 'today';
        }
        // Mehrere Räume mit Komma für einen Vergleich in einem Diagramm (Claude-App 12.10.2026 holte sich dafür zwei
        // Tabellen über get_state). Jeder Raum wird wie sonst gesucht; ein unbekannter steht im Text, die anderen werden
        // gezeichnet.
        $rooms = array_values(array_filter(array_map('trim', explode(',', (string)($arguments['room'] ?? ''))), static fn (string $room): bool => $room !== ''));
        // type=energy gibt es nur hier: Verbrauch je Gerät mit Zähler, gleich welchen Typs (Burkhard 12.10.2026)
        $byDevice = trim((string)($arguments['type'] ?? '')) === 'energy';
        if ($byDevice) {
            unset($arguments['type']);
        }
        if ($byDevice && count($rooms) > 1) {
            return $this->fail($this->t('A consumption chart takes one room at a time.'));
        }
        $compare = trim((string)($arguments['compare'] ?? ''));
        unset($arguments['compare']);
        if ($compare !== '' && !in_array($compare, self::COMPARES, true)) {
            return $this->fail(sprintf($this->t('Unknown compare "%s". Allowed: %s.'), $compare, implode(', ', self::COMPARES)));
        }
        if ($compare !== '' && count($rooms) > 1) {
            return $this->fail($this->t('A comparison takes one room at a time.'));
        }
        $this->chartByDevice = $byDevice;
        $this->chartCosts = $costs;
        $this->chartPart = $part;
        $this->chart = ['series' => []];
        $texts = [];
        $failures = [];
        try {
            foreach (count($rooms) > 1 ? $rooms : [null] as $room) {
                $state = $this->getState($room === null ? $arguments : ['room' => $room] + $arguments);
                if ($state['isError']) {
                    $failures[] = $state;
                    continue;
                }
                $texts[] = $state['text'];
            }
            $chart = $this->chart;
        } finally {
            $this->chart = null;
            $this->chartByDevice = false;
            $this->chartCosts = false;
            $this->chartPart = '';
        }
        if ($texts === []) {
            return $failures[0];
        }
        if (($chart['kind'] ?? '') === 'consumption' && count($rooms) > 1) {
            return $this->fail($this->t('A consumption chart takes one room at a time.'));
        }
        if (($chart['kind'] ?? '') === 'history' && count($chart['series'] ?? []) > self::HISTORY_MAX) {
            return $this->fail(sprintf($this->t('Too many measured values (%d) for a history (%s). Give a room or a name, at most %d at a time.'), count($chart['series']), 'room=' . implode(', ', $rooms), self::HISTORY_MAX));
        }
        if (count($chart['bands'] ?? []) > self::TIMELINE_CHART_MAX) {
            return $this->fail(sprintf($this->t('Too many devices (%d) for a timeline chart. Give a room or a name, at most %d at a time.'), count($chart['bands']), self::TIMELINE_CHART_MAX));
        }
        $result = ['text' => implode("\n", array_merge($texts, array_map(static fn (array $failure): string => (string)strtok($failure['text'], "\n"), $failures))), 'isError' => false];
        if (($chart['series'] ?? []) === [] && ($chart['bands'] ?? []) === []) {
            $this->problem = 'no_match';
            return $this->fail(sprintf($this->t('None of the matching devices has a history for a chart (%s).'), (string)($chart['filter'] ?? '')));
        }
        unset($chart['filter']);
        // Die Achse zeigt den ganzen Zeitraum, auch wenn er noch läuft; künftige Abschnitte bleiben leer (Layoutvergleich der
        // Diagramme 13.10.2026: „diese Woche“ am Dienstag ergab zwei sehr breite Balken).
        $span = $this->periodSpan($arguments);
        // Jede Art trägt ihren Zeitraum, die Seite schreibt ihn als Titelzeile darüber (Burkhard 13.10.2026 in der
        // Claude-App: „mir fehlt im diagramm das datum“)
        if ($span !== null && in_array($chart['kind'] ?? '', ['history', 'timeline', 'consumption'], true)) {
            $chart['range'] = $span;
        }
        if ($span !== null && ($chart['kind'] ?? '') === 'consumption') {
            self::padTimes($chart, $span[1]);
        }
        if ($part !== '') {
            // Mit mehreren Quellen eine Reihe je Quelle (B82 Stufe c), die Zeile nennt die Summe aller
            $sum = array_sum(array_map(static fn (array $series): float => array_sum(array_map('floatval', $series['values'])), $chart['series'] ?? []));
            $result['text'] .= "\n" . $this->t(self::PART_TITLES[$part]) . ' ' . (($chart['unit'] ?? '') === '€' ? number_format($sum, 2, '.', '') . ' €.' : self::formatNumber($sum) . ' kWh.');
        }
        if ($compare !== '') {
            $result['text'] .= "\n" . $this->compareChart($chart, $arguments, $compare, $byDevice, $costs, $part);
        }
        $result['text'] .= "\n" . $this->t('The chart is shown in the chat.');
        return $result + ['structuredContent' => $chart];
    }

    /**
     * Vergleich zweier Zeiträume (B69): holt dasselbe Diagramm für den Vergleichszeitraum und legt es auf den Zeitraum.
     * Beim Verlauf bekommt jede Reihe „compare“ (Punkte, um den Abstand der Zeiträume verschoben), beim Verbrauch das
     * Diagramm „compare.values“ (Summe je Balken, über die Zeit zugeordnet, null ohne Vergleich; reicht der Vergleich
     * weiter, kommen Balken ohne Werte dazu). Zeitleisten haben keinen Vergleich. Zurück kommt der Text fürs Modell: der Vergleichszeitraum wie in
     * get_state und, solange der Zeitraum noch läuft, der Verbrauch bis zur selben Zeit.
     *
     * @param array<string, mixed> $chart
     */
    private function compareChart(array &$chart, array $arguments, string $compare, bool $byDevice, bool $costs = false, string $part = ''): string
    {
        $kind = (string)($chart['kind'] ?? '');
        if ($kind !== 'history' && $kind !== 'consumption') {
            return $this->t('There is no comparison for timelines.');
        }
        $window = $this->compareWindow($arguments, $compare);
        if ($window === null) {
            return $this->t('A comparison needs a period or whole days in from and to.');
        }
        [$from, $to, $mainFrom, $running, $shift] = $window;
        $label = date('Y-m-d', $from) . (date('Y-m-d', $from) === date('Y-m-d', $to) ? '' : ' – ' . date('Y-m-d', $to));
        $this->chart = ['series' => []];
        $this->chartByDevice = $byDevice;
        $this->chartCosts = $costs;
        $this->chartPart = $part;
        $this->chartParts = null;
        try {
            $state = $this->getState(['from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to)] + array_diff_key($arguments, ['period' => 0, 'from' => 0, 'to' => 0]));
            $other = $this->chart;
            $otherParts = $this->chartParts;
        } finally {
            $this->chartParts = null;
            $this->chart = null;
            $this->chartByDevice = false;
            $this->chartCosts = false;
            $this->chartPart = '';
        }
        $head = sprintf($this->t('Compared with %s:'), $label);
        if ($state['isError'] || ($other['kind'] ?? '') !== $kind) {
            return $head . "\n" . (string)strtok($state['text'], "\n");
        }
        $offset = $mainFrom - $from;
        $chart['compare'] = ['label' => $label, 'range' => [$from, $to + 1]];
        $chart['labels']['compare'] = $this->t('comparison');
        $text = $head . "\n" . $state['text'];
        if ($kind === 'history') {
            foreach ($chart['series'] as &$series) {
                foreach ($other['series'] ?? [] as $old) {
                    if ($old['room'] === $series['room'] && $old['name'] === $series['name'] && $old['unit'] === $series['unit']) {
                        $series['compare'] = array_map(static fn (array $p): array => [$p[0] + $offset, $p[1], $p[2], $p[3]], $old['points']);
                    }
                }
            }
            unset($series);
            return $text;
        }
        // Verbrauch: Summe je Balken wie im Stapel (alle positiven Teile, die Einspeisung liegt darunter), zugeordnet über
        // die Zeit und nicht über die Position (Review 14.10.2026: Ein Archiv, das erst im Vergleichszeitraum beginnt,
        // schob jeden Wert nach vorn). Ein Abschnitt ohne Vergleich bleibt null.
        $byTime = [];
        foreach ($other['times'] ?? [] as $i => $time) {
            $at = $shift($time);
            $byTime[$at] = $byTime[$at] ?? 0.0;
            foreach ($other['series'] ?? [] as $series) {
                $byTime[$at] += max(0.0, (float)($series['values'][$i] ?? 0));
            }
        }
        // Reicht der Vergleich weiter (ein Monat mit mehr Tagen davor), kommen Balken ohne Werte dazu
        $times = $chart['times'] ?? [];
        $extra = array_diff(array_keys($byTime), $times);
        if ($extra !== []) {
            $all = array_merge($times, $extra);
            sort($all);
            $position = array_flip($times);
            foreach ($chart['series'] as &$series) {
                $series['values'] = array_map(static fn (int $t) => isset($position[$t]) ? ($series['values'][$position[$t]] ?? 0) : 0, $all);
            }
            unset($series);
            $chart['times'] = $times = $all;
        }
        $values = array_map(static fn (int $t): ?float => $byTime[$t] ?? null, $times);
        $chart['compare']['values'] = $values;
        // Anteil eines Abschnitts bis zur selben Zeit: der laufende anteilig, künftige nicht (Review 14.10.2026: vorher
        // ganz, am 12.10. der ganze Oktober des Vorjahres)
        $now = $this->backend->timestamp();
        $last = (int)($chart['range'][1] ?? 0);
        $position = array_flip($times);
        $weight = static function (int $time) use ($running, $now, $last, $times, $position): float {
            if (!$running) {
                return 1.0;
            }
            $next = $times[($position[$time] ?? PHP_INT_MAX) + 1] ?? max($last, $time + 1);
            return $time < $now ? min(1.0, ($now - $time) / max(1, $next - $time)) : 0.0;
        };
        if ($running) {
            $sum = 0.0;
            foreach ($times as $i => $time) {
                if ($values[$i] !== null) {
                    $sum += $values[$i] * $weight($time);
                }
            }
            $text .= "\n" . sprintf($this->t('By the same time: %s %s.'), ($chart['unit'] ?? 'kWh') === '€' ? number_format($sum, 2, '.', '') : self::formatNumber($sum), $chart['unit'] ?? 'kWh');
        }
        // Ringe: Autarkie und Eigenverbrauch des Vergleichszeitraums, bis zur selben Zeit (Hardcopy 15.10.2026: Titel
        // „2026 · Vergleich 2025“, die Ringe zeigten nur 2026)
        if (isset($chart['rings']) && is_array($otherParts)) {
            $totals = [];
            foreach ($otherParts as $time => $parts) {
                $share = $weight($shift($time));
                foreach ($parts as $key => $kwh) {
                    $totals[$key] = ($totals[$key] ?? 0.0) + $kwh * $share;
                }
            }
            $house = 0.0;
            foreach (self::BALANCE_TERMS as $key => [$sign]) {
                $house += $sign * ($totals[$key] ?? 0.0);
            }
            $shares = $this->shares($totals, max(0.0, $house));
            foreach ($chart['rings'] as &$ring) {
                $value = $shares[$ring['key'] === 'autarky' ? 'autarky' : 'solar'] ?? null;
                if ($value !== null) {
                    $ring['compare'] = ['share' => $value];
                }
            }
            unset($ring);
            $line = $this->selfSufficiency($totals, max(0.0, $house));
            if ($running && $line !== null) {
                $text .= "\n" . sprintf($this->t('By the same time: %s'), $line);
            }
        }
        return $text;
    }

    /**
     * Punktwolke (B70, Burkhard 13.10.2026): je Abschnitt ein Punkt aus dem Wert des Geräts „versus“ (x) und dem Wert des
     * Geräts der übrigen Filter (y), ohne Filter dem Hausverbrauch der Bilanz. Abschnitte bis zwei Tage je Stunde, bis 400
     * Tage je Tag, sonst je Monat; stündlich ist eine Wärmepumpe kaum vorherzusagen (am nuc erklärte kein Modell mehr als
     * 13 %), je Tag erklärte der Knick 84 %. Das Modell bekommt den Zusammenhang in Worten, die Seite Punkte, Klassenmittel
     * und die Modellkurve.
     */
    private function scatter(array $arguments, string $versus): array
    {
        if (trim((string)($arguments['compare'] ?? '')) !== '' || str_contains((string)($arguments['room'] ?? ''), ',')) {
            return $this->fail($this->t('A scatter chart takes one room and no comparison.'));
        }
        $period = trim((string)($arguments['period'] ?? ''));
        $span = $period === self::NOW ? null : $this->periodSpan($arguments);
        if ($span === null) {
            return $this->fail($this->t('A scatter chart needs a period or from and to.'));
        }
        [$from, $end] = $span;
        $to = $end > $this->backend->timestamp() ? 0 : $end - 1;
        $level = $end - $from <= 2 * 86400 ? 0 : ($end - $from <= 400 * 86400 ? 1 : 3);
        $room = trim((string)($arguments['room'] ?? ''));
        $floor = trim((string)($arguments['floor'] ?? ''));
        $type = trim((string)($arguments['type'] ?? ''));
        $name = trim((string)($arguments['name'] ?? ''));
        $fromText = trim((string)($arguments['from'] ?? ''));
        $toText = trim((string)($arguments['to'] ?? ''));
        $when = $period !== '' ? ', period=' . $period : ', from=' . $fromText . ($toText === '' ? '' : ', to=' . $toText);

        $xMatches = $this->find('', '', $versus, '');
        if ($xMatches === []) {
            $this->noMatch('', $versus);
            return $this->fail(sprintf($this->t('No released device matches %s.'), 'versus=' . $versus));
        }
        if (count($xMatches) > 1) {
            return $this->scatterAmbiguous('versus=' . $versus, $xMatches);
        }
        if ($room === '' && $floor === '' && $type === '' && $name === '') {
            if ($this->balance === []) {
                return $this->fail($this->t('A scatter chart needs room, type or name for the y axis, or an energy balance for the house.'));
            }
            $filter = 'versus=' . $versus;
            $y = ['label' => $this->t('house consumption'), 'unit' => 'kWh', 'values' => []];
            foreach ($this->balanceParts($this->balance, $level, $from, $to) ?? [] as $time => $parts) {
                $sum = 0.0;
                foreach (self::BALANCE_TERMS as $key => [$sign]) {
                    $sum += $sign * ($parts[$key] ?? 0.0);
                }
                $y['values'][$time] = max(0.0, $sum);
            }
        } else {
            $room = $this->effectiveRoom($room, $name, $floor);
            $filter = $this->describeFilter($room, $type, $name, $floor) . ', versus=' . $versus;
            $yMatches = $this->find($room, $type, $name, $floor);
            if ($yMatches === []) {
                $this->noMatch($room, $name);
                return $this->fail(sprintf($this->t('No released device matches %s.'), $this->describeFilter($room, $type, $name, $floor)));
            }
            if (count($yMatches) > 1) {
                return $this->scatterAmbiguous($this->describeFilter($room, $type, $name, $floor), $yMatches);
            }
            $y = $this->valueSeries($yMatches[0], $level, $from, $to);
            if ($y === null) {
                return $this->fail(sprintf($this->t('%s has no values for a scatter chart (only measured values, meters and power).'), $yMatches[0]['Room'] . ' · ' . $yMatches[0]['Name']));
            }
        }
        $x = $this->valueSeries($xMatches[0], $level, $from, $to);
        if ($x === null) {
            return $this->fail(sprintf($this->t('%s has no values for a scatter chart (only measured values, meters and power).'), $xMatches[0]['Room'] . ' · ' . $xMatches[0]['Name']));
        }

        $points = [];
        foreach ($y['values'] as $time => $value) {
            if (isset($x['values'][$time])) {
                $points[] = [round($x['values'][$time], 6), round($value, 6), $time];
            }
        }
        $per = self::HISTORY_LEVELS[$level][0];
        $head = sprintf($this->t('Scatter chart of %s (%s) against %s (%s) (%s), per %s: %d points.'), $y['label'], $y['unit'], $x['label'], $x['unit'], $filter . $when, $this->t($per), count($points));
        $model = self::scatterModel($points);
        $lines = [$head, $this->scatterSentence($model, $x, $y, count($points))];
        if ($points !== []) {
            $xs = array_column($points, 0);
            $ys = array_column($points, 1);
            $lines[] = sprintf($this->t('%s from %s to %s %s, %s from %s to %s %s.'), $x['label'], self::roughNumber(min($xs)), self::roughNumber(max($xs)), $x['unit'], $y['label'], self::roughNumber(min($ys)), self::roughNumber(max($ys)), $y['unit']);
        }
        $lines[] = $this->t('The chart is shown in the chat.');
        return ['text' => implode("\n", $lines), 'isError' => false, 'structuredContent' => [
            'kind'    => 'scatter', 'title' => $head, 'per' => $per, 'range' => [$from, $end],
            'x'       => ['label' => $x['label'], 'unit' => $x['unit']],
            'y'       => ['label' => $y['label'], 'unit' => $y['unit']],
            'points'  => $points,
            'classes' => self::scatterClasses($points),
            'model'   => $model,
            'labels'  => ['per' => $this->t($per), 'classes' => $this->t('class mean'), 'model' => $this->t('trend'), 'versus' => $this->t('against')],
        ]];
    }

    /**
     * Heatmap (B71, Burkhard 13.10.2026): ein Wert je Stunde als Raster, bis 62 Tage eine Zeile je Tag, darüber sieben Zeilen
     * Montag bis Sonntag mit dem Mittel je Wochentag und Stunde. Ein Gerät wie bei der Punktwolke, ohne Filter der
     * Hausverbrauch. Das Modell bekommt die stärkste und die schwächste Stunde im Mittel und Werktag gegen Wochenende (bei
     * Zählern je Tag summiert, sonst gemittelt), die Seite das Raster.
     */
    private function heatmap(array $arguments, bool $costs = false, string $part = ''): array
    {
        if (trim((string)($arguments['compare'] ?? '')) !== '' || str_contains((string)($arguments['room'] ?? ''), ',')) {
            return $this->fail($this->t('A heatmap takes one room and no comparison.'));
        }
        $period = trim((string)($arguments['period'] ?? ''));
        $span = $period === self::NOW ? null : $this->periodSpan($arguments);
        if ($span === null) {
            return $this->fail($this->t('A heatmap needs a period or from and to.'));
        }
        [$from, $end] = $span;
        $to = $end > $this->backend->timestamp() ? 0 : $end - 1;
        $room = trim((string)($arguments['room'] ?? ''));
        $floor = trim((string)($arguments['floor'] ?? ''));
        $type = trim((string)($arguments['type'] ?? ''));
        $name = trim((string)($arguments['name'] ?? ''));
        $fromText = trim((string)($arguments['from'] ?? ''));
        $toText = trim((string)($arguments['to'] ?? ''));
        $when = $period !== '' ? 'period=' . $period : 'from=' . $fromText . ($toText === '' ? '' : ', to=' . $toText);
        if ($room === '' && $floor === '' && $type === '' && $name === '') {
            if ($this->balance === []) {
                return $this->fail($this->t('A heatmap needs room, type or name, or an energy balance for the house.'));
            }
            $filter = $when;
            $series = ['label' => $this->t('house consumption'), 'unit' => 'kWh', 'values' => [], 'sum' => true];
            if ($costs) {
                $series = ['label' => $this->t('electricity costs'), 'unit' => '€', 'values' => array_map(static fn (array $row): float => $row['import'], $this->hourlyCosts($from, $to)[0] ?? []), 'sum' => true];
            }
            foreach ($costs ? [] : $this->balanceParts($this->balance, 0, $from, $to) ?? [] as $time => $parts) {
                $value = 0.0;
                foreach (self::BALANCE_TERMS as $key => [$sign]) {
                    $value += $sign * ($parts[$key] ?? 0.0);
                }
                $series['values'][$time] = $part === '' ? max(0.0, $value) : ($parts[self::PARTS[$part]] ?? 0.0);
            }
            if ($part !== '') {
                $series['label'] = $this->t(self::BALANCE_TERMS[self::PARTS[$part]][1]);
                if ($costs) {
                    $series['values'] = array_map(static fn (array $row): float => $part === 'grid' ? $row['import'] : $row['revenue'], $this->hourlyCosts($from, $to)[0] ?? []);
                }
            }
        } else {
            $room = $this->effectiveRoom($room, $name, $floor);
            $filter = $this->describeFilter($room, $type, $name, $floor) . ', ' . $when;
            $matches = $this->find($room, $type, $name, $floor);
            if ($matches === []) {
                $this->noMatch($room, $name);
                return $this->fail(sprintf($this->t('No released device matches %s.'), $this->describeFilter($room, $type, $name, $floor)));
            }
            if (count($matches) > 1) {
                $this->problem = 'ambiguous';
                return $this->fail(sprintf($this->t('A heatmap needs exactly one device; %s matches %d: %s.'), $this->describeFilter($room, $type, $name, $floor), count($matches),
                    implode(', ', array_map(static fn (array $e): string => $e['Room'] . ' · ' . $e['Name'], $matches))));
            }
            $series = $this->valueSeries($matches[0], 0, $from, $to);
            if ($series === null) {
                return $this->fail(sprintf($this->t('%s has no values for a heatmap (only measured values, meters and power).'), $matches[0]['Room'] . ' · ' . $matches[0]['Name']));
            }
        }

        $dayCount = (int)round(($end - $from) / 86400);
        $mode = $dayCount <= 62 ? 'days' : 'weekdays';
        $rows = [];
        if ($mode === 'days') {
            for ($day = $from; $day < $end; $day = (int)strtotime('+1 day', $day)) {
                $rows[] = $day;
            }
        } else {
            $rows = [1, 2, 3, 4, 5, 6, 7];
        }
        $rowOf = $mode === 'days' ? array_flip(array_map(static fn (int $day): string => date('Y-m-d', $day), $rows)) : null;
        // Je Kästchen Summe und Anzahl: ein Zähler summiert die doppelte Stunde der Zeitumstellung, sonst gilt das Mittel;
        // im Wochenprofil ist jedes Kästchen ein Mittel.
        $sums = $counts = [];
        $hours = array_fill(0, 24, []);
        $daily = [];
        foreach ($series['values'] as $time => $value) {
            $row = $mode === 'days' ? ($rowOf[date('Y-m-d', $time)] ?? null) : (int)date('N', $time) - 1;
            if ($row === null) {
                continue;
            }
            $hour = (int)date('G', $time);
            $sums[$row][$hour] = ($sums[$row][$hour] ?? 0.0) + $value;
            $counts[$row][$hour] = ($counts[$row][$hour] ?? 0) + 1;
            $hours[$hour][] = $value;
            $daily[date('Y-m-d', $time)][] = $value;
        }
        $cells = [];
        $all = [];
        foreach (array_keys($rows) as $row) {
            $line = [];
            for ($hour = 0; $hour < 24; $hour++) {
                if (!isset($sums[$row][$hour])) {
                    $line[] = null;
                    continue;
                }
                $value = $mode === 'days' && $series['sum'] ? $sums[$row][$hour] : $sums[$row][$hour] / $counts[$row][$hour];
                $line[] = round($value, 6);
                $all[] = $value;
            }
            $cells[] = $line;
        }

        $head = $mode === 'days'
            ? sprintf($this->t('Heatmap of %s (%s) per hour (%s): %d days.'), $series['label'], $series['unit'], $filter, count($rows))
            : sprintf($this->t('Heatmap of %s (%s) per weekday and hour (%s): average over %d days.'), $series['label'], $series['unit'], $filter, $dayCount);
        $lines = [$head];
        $profile = [];
        foreach ($hours as $hour => $values) {
            if ($values !== []) {
                $profile[$hour] = array_sum($values) / count($values);
            }
        }
        if ($profile !== []) {
            $high = array_keys($profile, max($profile))[0];
            $low = array_keys($profile, min($profile))[0];
            $hourText = static fn (int $h): string => sprintf('%02d:00–%02d:00', $h, ($h + 1) % 24);
            $lines[] = sprintf($this->t('On average highest at %s (%s %s), lowest at %s (%s %s).'), $hourText($high), self::roughNumber($profile[$high]), $series['unit'], $hourText($low), self::roughNumber($profile[$low]), $series['unit']);
            // Werktag gegen Wochenende: Zähler je Tag summiert, sonst das Mittel aller Werte
            $weekday = $weekend = [];
            foreach ($daily as $day => $values) {
                $part = $series['sum'] ? [array_sum($values)] : $values;
                if ((int)date('N', (int)strtotime($day)) >= 6) {
                    $weekend = array_merge($weekend, $part);
                } else {
                    $weekday = array_merge($weekday, $part);
                }
            }
            if ($weekday !== [] && $weekend !== []) {
                $lines[] = sprintf($this->t($series['sum'] ? 'Per day on weekdays %s %s, at weekends %s %s.' : 'On weekdays %s %s on average, at weekends %s %s.'),
                    self::roughNumber(array_sum($weekday) / count($weekday)), $series['unit'], self::roughNumber(array_sum($weekend) / count($weekend)), $series['unit']);
            }
        }
        $lines[] = $this->t('The chart is shown in the chat.');
        return ['text' => implode("\n", $lines), 'isError' => false, 'structuredContent' => [
            'kind'   => 'heatmap', 'title' => $head, 'mode' => $mode, 'range' => [$from, $end],
            'label'  => $series['label'], 'unit' => $series['unit'], 'rows' => $rows, 'cells' => $cells,
            'min'    => $all === [] ? null : min($all), 'max' => $all === [] ? null : max($all),
            'scale'  => $series['unit'] === '°C' ? 'diverging' : 'sequential',
            // Farbskala bis zum 95. Perzentil, sonst ließen einzelne Spitzen fast alle Kästchen weiß (Hausverbrauch am nuc)
            'cap'    => $all === [] ? null : self::percentile($all, 0.95),
        ]];
    }

    /** Wert beim Anteil $share der sortierten Werte (0,95 = 95. Perzentil), ohne Zwischenwerte. */
    private static function percentile(array $values, float $share): float
    {
        sort($values);
        return (float)$values[(int)floor($share * (count($values) - 1))];
    }

    /** Absage, wenn eine Achse mehr als ein Gerät trifft, mit den Kandidaten. */
    private function scatterAmbiguous(string $filter, array $matches): array
    {
        $this->problem = 'ambiguous';
        $names = array_map(static fn (array $e): string => $e['Room'] . ' · ' . $e['Name'], $matches);
        return $this->fail(sprintf($this->t('A scatter chart needs exactly one device per axis; %s matches %d: %s.'), $filter, count($matches), implode(', ', $names)));
    }

    /**
     * Wertereihe eines Geräts für die Punktwolke: Messwert (Mittel des Abschnitts), sonst Zähler (Verbrauch in kWh), sonst
     * Leistung (Mittel). Zustände wie an/aus haben keine. null = keine Werte.
     *
     * @return null|array{label: string, unit: string, values: array<int, float>}
     */
    private function valueSeries(array $entry, int $level, int $from, int $to): ?array
    {
        $roles = $entry['roles'];
        $label = $entry['Room'] . ' · ' . $entry['Name'];
        $average = function (int $variable) use ($label, $level, $from, $to): ?array {
            $history = $this->historyRows($variable, $level, $from, $to);
            if ($history === null) {
                return null;
            }
            $values = [];
            foreach ($history['rows'] as $row) {
                $values[$row['time']] = $row['avg'];
            }
            return ['label' => $label, 'unit' => trim($history['unit']), 'values' => $values, 'sum' => false];
        };
        if (self::isMeasured($entry) && !$this->isTimed($entry)) {
            return $average($roles['state']);
        }
        if (isset($roles['energy'])) {
            $series = $this->counterRows($roles['energy'], $level, $from, $to);
            if ($series === null || !self::isEnergyUnit($series['unit'])) {
                return null;
            }
            $values = [];
            foreach ($series['rows'] as $row) {
                $values[$row['time']] = (float)self::kilowattHours(['sum' => $row['sum'], 'unit' => $series['unit']]);
            }
            return ['label' => $label, 'unit' => 'kWh', 'values' => $values, 'sum' => true];
        }
        if (isset($roles['power'])) {
            return $average($roles['power']);
        }
        return null;
    }

    /**
     * Zusammenhang der Punkte: linear (y = a + b·x) und mit Knick (y = a + b·max(0, c − x) bzw. max(0, x − c)), c im Raster
     * eines Hundertstels der Spanne zwischen dem 10. und 90. Perzentil. Der Knick gilt, wenn er mindestens 5 Prozentpunkte
     * mehr erklärt. Unter 10 Punkten oder ohne Streuung in x gibt es kein Modell.
     *
     * @param list<array{0: float, 1: float, 2: int}> $points
     * @return array{type: string, a?: float, b?: float, c?: float, r2?: float}
     */
    private static function scatterModel(array $points): array
    {
        if (count($points) < 10) {
            return ['type' => 'none'];
        }
        $fit = static function (array $q): ?array {
            $n = count($q);
            $mx = array_sum(array_column($q, 0)) / $n;
            $my = array_sum(array_column($q, 1)) / $n;
            $sxy = $sxx = 0.0;
            foreach ($q as [$x, $y]) {
                $sxy += ($x - $mx) * ($y - $my);
                $sxx += ($x - $mx) ** 2;
            }
            return $sxx > 0 ? [$my - $sxy / $sxx * $mx, $sxy / $sxx] : null;
        };
        $explained = static function (callable $f) use ($points): float {
            $my = array_sum(array_column($points, 1)) / count($points);
            $sse = $sst = 0.0;
            foreach ($points as [$x, $y]) {
                $sse += ($y - $f($x)) ** 2;
                $sst += ($y - $my) ** 2;
            }
            return $sst > 0 ? 1 - $sse / $sst : 0.0;
        };
        $line = $fit($points);
        if ($line === null) {
            return ['type' => 'none'];
        }
        [$a, $b] = $line;
        $best = ['type' => 'linear', 'a' => $a, 'b' => $b, 'r2' => $explained(static fn (float $x): float => $a + $b * $x)];
        $xs = array_column($points, 0);
        sort($xs);
        $step = ($xs[count($xs) - 1] - $xs[0]) / 100;
        $knee = null;
        foreach (['knee_below' => -1, 'knee_above' => 1] as $type => $sign) {
            for ($c = $xs[(int)floor(count($xs) * 0.1)]; $c <= $xs[(int)floor(count($xs) * 0.9)] + 1e-9; $c += $step) {
                $part = $fit(array_map(static fn (array $p): array => [max(0.0, $sign * ($p[0] - $c)), $p[1]], $points));
                if ($part === null) {
                    continue;
                }
                [$ka, $kb] = $part;
                $r2 = $explained(static fn (float $x): float => $ka + $kb * max(0.0, $sign * ($x - $c)));
                if ($knee === null || $r2 > $knee['r2']) {
                    $knee = ['type' => $type, 'a' => $ka, 'b' => $kb, 'c' => $c, 'r2' => $r2];
                }
            }
        }
        return $knee !== null && $knee['r2'] >= $best['r2'] + 0.05 ? $knee : $best;
    }

    /** Der Zusammenhang in Worten fürs Modell. */
    private function scatterSentence(array $model, array $x, array $y, int $count): string
    {
        if ($model['type'] === 'none') {
            return sprintf($this->t('Too few points for a relation (%d).'), $count);
        }
        $share = (int)round($model['r2'] * 100);
        if ($model['r2'] < 0.1) {
            return sprintf($this->t('No clear relation between the two (explains %d %%).'), $share);
        }
        return match ($model['type']) {
            'knee_below' => sprintf($this->t('Below about %s %s, %s rises by about %s %s per %s lower; above it stays at about %s %s (explains %d %%).'),
                self::roughNumber($model['c']), $x['unit'], $y['label'], self::roughNumber($model['b']), $y['unit'], $x['unit'], self::roughNumber($model['a']), $y['unit'], $share),
            'knee_above' => sprintf($this->t('Above about %s %s, %s rises by about %s %s per %s higher; below it stays at about %s %s (explains %d %%).'),
                self::roughNumber($model['c']), $x['unit'], $y['label'], self::roughNumber($model['b']), $y['unit'], $x['unit'], self::roughNumber($model['a']), $y['unit'], $share),
            default => sprintf($this->t('Per %s more, %s changes by about %s %s (explains %d %%).'), $x['unit'], $y['label'], self::roughNumber($model['b']), $y['unit'], $share),
        };
    }

    /**
     * Klassenmittel: y gemittelt je Klasse von x, Breite die nächste runde Zahl (1, 2, 5 × 10ⁿ) über einem Zwölftel der
     * Spanne. Je Klasse [von, bis, Mittel, Anzahl].
     *
     * @return list<array{0: float, 1: float, 2: float, 3: int}>
     */
    private static function scatterClasses(array $points): array
    {
        if ($points === []) {
            return [];
        }
        $xs = array_column($points, 0);
        $raw = (max($xs) - min($xs)) / 12;
        $width = 1.0;
        if ($raw > 0) {
            $power = 10 ** floor(log10($raw));
            $width = (float)($raw <= $power ? $power : ($raw <= 2 * $power ? 2 * $power : ($raw <= 5 * $power ? 5 * $power : 10 * $power)));
        }
        $groups = [];
        foreach ($points as [$x, $y]) {
            $start = floor($x / $width) * $width;
            $groups[(string)$start][] = $y;
        }
        $classes = [];
        foreach ($groups as $start => $ys) {
            $classes[] = [(float)$start, (float)$start + $width, round(array_sum($ys) / count($ys), 6), count($ys)];
        }
        usort($classes, static fn (array $p, array $q): int => $p[0] <=> $q[0]);
        return $classes;
    }

    /** Zahl für Sätze: ab 1 eine Nachkommastelle, darunter zwei. */
    private static function roughNumber(float $value): string
    {
        return abs($value) >= 1 ? self::formatNumber($value) : (string)round($value, 2);
    }

    /**
     * Sensoren der Leistungsbilanz, deren Vorzeichen umgekehrt gilt (B77): 'import' (positiv = Einspeisung) und
     * 'discharge' (positiv = laden, so meldet der Marstek Venus E). Andere Schlüssel haben kein Vorzeichen.
     *
     * @param list<string> $keys
     */
    /**
     * Fehler einer Zeile der Liste „Quellen“ (B82): unbekannte Art, keine Variable, oder bei PV ein
     * zweiter Zähler bzw. eine zweite Leistung (eine PV-Anlage speist nur ein).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string> Zeile => unknown_type | empty | solar_out
     */
    public static function sourceProblems(array $rows): array
    {
        $problems = [];
        foreach ($rows as $index => $row) {
            $type = (string)($row['Type'] ?? '');
            $ids = array_map(static fn (string $field): int => (int)($row[$field] ?? 0), ['EnergyIn', 'EnergyOut', 'PowerIn', 'PowerOut']);
            if (!isset(self::SOURCE_KEYS[$type])) {
                $problems[$index] = 'unknown_type';
            } elseif (max($ids) <= 1) {
                $problems[$index] = 'empty';
            } elseif ($type === 'solar' && ($ids[1] > 1 || $ids[3] > 1)) {
                $problems[$index] = 'solar_out';
            }
        }
        return $problems;
    }

    /**
     * Quellen der Bilanz (B82, Wunsch Micha87 17.10.2026: drei PV-Anlagen): Netzanschlüsse, PV-Anlagen und Batterien wie
     * im Energie-Dashboard von Home Assistant, je Art zusammengezählt (das Modul übergibt seit build 192 nur noch die
     * Liste, die Felder des Konstruktors bleiben für die Tests der Bibliothek). Eine Quelle mit nur der ersten Leistung
     * (Netz, Batterie) ist ein Sensor mit Vorzeichen wie das einzelne Feld, „Invert“ dreht ihn um. Zeilen mit Fehlern (sourceProblems) übergeht die Bilanz; das Modul sperrt sie vorher ganz.
     *
     * @param list<array<string, mixed>> $sources Zeilen mit Type, Name, EnergyIn, EnergyOut, PowerIn, PowerOut, Invert
     */
    public function addSources(array $sources): void
    {
        $problems = self::sourceProblems($sources);
        foreach ($sources as $index => $source) {
            if (isset($problems[$index])) {
                continue;
            }
            [$in, $out] = self::SOURCE_KEYS[(string)$source['Type']];
            $name = trim((string)($source['Name'] ?? ''));
            foreach ([[$in, 'EnergyIn'], [$out, 'EnergyOut']] as [$key, $field]) {
                $id = (int)($source[$field] ?? 0);
                if ($key !== null && $id > 1) {
                    $this->balance[$key] ??= $id;
                    $this->balanceVars[$key][] = ['variable' => $id, 'name' => $name];
                }
            }
            $powerOut = (int)($source['PowerOut'] ?? 0);
            foreach ([[$in, 'PowerIn'], [$out, 'PowerOut']] as [$key, $field]) {
                $id = (int)($source[$field] ?? 0);
                if ($key !== null && $id > 1) {
                    $this->powerBalance[$key] ??= $id;
                    $this->powerVars[$key][] = ['variable' => $id, 'name' => $name,
                        'signed' => $field === 'PowerIn' && isset(self::SIGNED_POWER[$key]) && $powerOut <= 1, 'inverted' => (bool)($source['Invert'] ?? false)];
                }
            }
        }
    }

    /** Zählt ein Leistungssensor mit Vorzeichen, und ist er umgekehrt? Felder der Bilanz wie bisher (B77), die Liste je Zeile. */
    private function signedOf(string $key, array $entry): array
    {
        if ($entry['signed'] === null) {
            $signed = isset(self::SIGNED_POWER[$key]) && !isset($this->powerFields[self::SIGNED_POWER[$key]]);
            return [$signed, $signed && in_array($key, $this->invertedPower, true)];
        }
        return [$entry['signed'], $entry['signed'] && $entry['inverted']];
    }

    /**
     * Ein Teil der Bilanz in Worten, mit mehreren Quellen dahinter die benannten: „Netzbezug 0.2 (davon Tibber 0.1)“.
     *
     * @param array<string, float> $named Name => Wert
     */
    private function partText(string $word, float $value, array $named, int $count, callable $format): string
    {
        $text = $this->t($word) . ' ' . $format($value);
        if ($count < 2 || $named === []) {
            return $text;
        }
        $list = implode(', ', array_map(static fn (string $name, float $part): string => $name . ' ' . $format($part), array_keys($named), $named));
        return sprintf($this->t('%s (of this %s)'), $text, $list);
    }

    public function setInvertedPower(array $keys): void
    {
        $this->invertedPower = array_values(array_intersect(array_keys(self::SIGNED_POWER), $keys));
    }

    /**
     * Sensoren mit Vorzeichen der Leistungsbilanz (ohne eigenes Gegenfeld), für den Selbsttest: Rohwert in W und wie
     * er jetzt zählt.
     *
     * @return list<array{key: string, variable: int, raw: float, counted: string, watts: float, inverted: bool}>
     */
    public function signedPowers(): array
    {
        $result = [];
        foreach (self::SIGNED_POWER as $key => $opposite) {
            foreach ($this->powerVars[$key] ?? [] as $sensor) {
                [$signed, $inverted] = $this->signedOf($key, $sensor);
                $raw = $signed ? $this->wattsOf($sensor['variable']) : [];
                if (is_array($raw)) {
                    continue;
                }
                $value = $inverted ? -$raw : $raw;
                $result[] = ['key' => $key, 'variable' => $sensor['variable'], 'raw' => (float)$raw, 'source' => $sensor['name'],
                    'counted' => $value < 0 ? $opposite : $key, 'watts' => abs((float)$value), 'inverted' => $inverted];
            }
        }
        return $result;
    }

    /** Strompreis und Einspeisevergütung (ct/kWh) für die Kosten in Euro (B72); 0 = keine. */
    public function setTariff(int $priceVariable, float $feedInCent): void
    {
        $this->priceVariable = $priceVariable;
        $this->feedIn = max(0.0, $feedInCent);
    }

    /**
     * Mittlerer Strompreis je Stunde in €/kWh aus dem Archiv. Die Einheit kommt aus der Variable (ct oder Cent, € oder EUR),
     * ohne Angabe gelten Werte über 2 als Cent. Gerechnet wird mit Stundenmitteln, weil das Archiv nicht feiner aggregiert;
     * an der Tibber-Rechnung September 2026 lagen die Kosten so 2,6 % höher (1,4 % Menge vom SMA Home Manager, 1,2 % Preis).
     *
     * @return null|array<int, float>
     */
    private function hourlyPrices(int $from, int $to): ?array
    {
        $history = $this->priceVariable > 0 ? $this->historyRows($this->priceVariable, 0, $from, $to) : null;
        if ($history === null || $history['rows'] === []) {
            return null;
        }
        $unit = strtolower($history['unit']);
        $values = array_column($history['rows'], 'avg', 'time');
        $cent = str_contains($unit, 'ct') || str_contains($unit, 'cent') || (!str_contains($unit, '€') && !str_contains($unit, 'eur') && array_sum($values) / count($values) > 2);
        return array_map(static fn (float $v): float => $cent ? $v / 100 : $v, $values);
    }

    /**
     * Kosten je Stunde: Netzbezug × Preis, Vergütung der Einspeisung, Hausverbrauch × Preis (ohne PV und Batterie). Stunden
     * ohne Preis zählen als fehlend. null = kein Preis oder keine Bilanz.
     *
     * @return null|array{0: array<int, array{kwh: float, import: float, revenue: float, house: float, price: float}>, 1: int}
     */
    private function hourlyCosts(int $from, int $to): ?array
    {
        if ($this->priceVariable === 0 || $this->balance === []) {
            return null;
        }
        $parts = $this->balanceParts($this->balance, 0, $from, $to);
        $prices = $this->hourlyPrices($from, $to);
        if ($parts === null || $prices === null) {
            return null;
        }
        $rows = [];
        $missing = 0;
        foreach ($parts as $time => $part) {
            if (!isset($prices[$time])) {
                $missing++;
                continue;
            }
            $price = $prices[$time];
            $house = 0.0;
            foreach (self::BALANCE_TERMS as $key => [$sign]) {
                $house += $sign * ($part[$key] ?? 0.0);
            }
            $rows[$time] = ['kwh' => $part['import'] ?? 0.0, 'import' => ($part['import'] ?? 0.0) * $price, 'revenue' => ($part['export'] ?? 0.0) * $this->feedIn / 100,
                'house' => max(0.0, $house) * $price, 'price' => $price];
        }
        return [$rows, $missing];
    }

    /** Die Kostenzeile beim Hausverbrauch über einen Zeitraum; null ohne Strompreis. */
    private function costLine(int $from, int $to): ?string
    {
        $costs = $this->hourlyCosts($from, $to);
        if ($costs === null || $costs[0] === []) {
            return null;
        }
        [$rows, $missing] = $costs;
        $euro = static fn (float $v): string => number_format($v, 2, '.', '');
        $import = array_sum(array_column($rows, 'import'));
        $kwh = array_sum(array_column($rows, 'kwh'));
        $text = sprintf($this->t('Costs %s € for %s kWh grid import (paid on average %s ct/kWh, average price %s ct/kWh).'), $euro($import), self::formatNumber($kwh),
            self::formatNumber($kwh > 0 ? $import / $kwh * 100 : 0.0), self::formatNumber(array_sum(array_column($rows, 'price')) / count($rows) * 100));
        if ($this->feedIn > 0) {
            $text .= ' ' . sprintf($this->t('Feed-in %s €.'), $euro(array_sum(array_column($rows, 'revenue'))));
        }
        $text .= ' ' . sprintf($this->t('Without solar and battery it would have been %s €.'), $euro(array_sum(array_column($rows, 'house'))));
        if ($missing > 0) {
            $text .= ' ' . sprintf($this->t('No price for %d hour(s).'), $missing);
        }
        return $text;
    }

    /** Kosten als Stapel je Stunde, Tag oder Monat: Netzbezug in Euro, die Vergütung darunter (B72). */
    private function costChart(int $level, int $from, int $to, string $title, string $filter): void
    {
        $bucket = static fn (int $t): int => match ($level) {
            0       => $t,
            3       => (int)mktime(0, 0, 0, (int)date('n', $t), 1, (int)date('Y', $t)),
            default => (int)mktime(0, 0, 0, (int)date('n', $t), (int)date('j', $t), (int)date('Y', $t)),
        };
        $grid = $export = [];
        foreach ($this->hourlyCosts($from, $to)[0] ?? [] as $time => $row) {
            $b = $bucket($time);
            $grid[$b] = ($grid[$b] ?? 0.0) + $row['import'];
            $export[$b] = ($export[$b] ?? 0.0) - $row['revenue'];
        }
        ksort($grid);
        $times = array_keys($grid);
        $series = [['key' => 'grid', 'name' => $this->t('grid import'), 'values' => array_map(static fn (int $t): float => round($grid[$t], 3), $times)]];
        if ($this->feedIn > 0) {
            $series[] = ['key' => 'export', 'name' => $this->t('grid export'), 'values' => array_map(static fn (int $t): float => round($export[$t], 3), $times)];
        }
        $per = self::HISTORY_LEVELS[$level][0] ?? 'day';
        $labels = ['per' => $this->t($per)];
        // Teil (B74): Netzbezug oder die Vergütung allein, positiv
        if ($this->chartPart !== '') {
            $name = $this->t($this->chartPart === 'grid' ? 'grid import' : 'grid export');
            $series = [['key' => $this->chartPart, 'name' => $name, 'values' => array_map(fn (int $t): float => $this->chartPart === 'grid' ? round($grid[$t], 3) : round(-$export[$t], 3), $times)]];
            $labels['total'] = $name;
        }
        $this->chart = ['kind' => 'consumption', 'title' => $title, 'per' => $per, 'unit' => '€', 'filter' => $filter,
            'times' => $times, 'series' => $series, 'labels' => $labels];
    }

    /** Einheit eines Zeitraums aus period: Tag, Woche, Monat oder Jahr. */
    private static function periodUnit(string $period): string
    {
        return match ($period) {
            'today', 'yesterday'       => 'day',
            'this_week', 'last_week'   => 'week',
            'this_month', 'last_month' => 'month',
            default                    => 'year',
        };
    }

    /**
     * Ganzer Zeitraum eines Diagramms als [Beginn, Ende (ausschließlich)]: bei period bis zu seinem natürlichen Ende, bei
     * from/to ganze Tage bzw. bis zur Endstunde, ohne to bis jetzt. null = nicht zu bestimmen.
     *
     * @return null|array{0: int, 1: int}
     */
    private function periodSpan(array $arguments): ?array
    {
        $now = $this->backend->timestamp();
        $period = trim((string)($arguments['period'] ?? ''));
        if ($period !== '') {
            if (!in_array($period, self::PERIODS, true)) {
                return null;
            }
            [, $from] = self::periodBounds($period, $now);
            return [$from, (int)strtotime('+1 ' . self::periodUnit($period), $from)];
        }
        $first = self::parseMoment(trim((string)($arguments['from'] ?? '')), false);
        $toText = trim((string)($arguments['to'] ?? ''));
        $last = $toText === '' ? null : self::parseMoment($toText, true);
        if ($first === null || ($toText !== '' && $last === null)) {
            return null;
        }
        if (!$first['hourly'] && !($last['hourly'] ?? false)) {
            return [$first['time'], (int)strtotime('+1 day', ($last ?? $first)['time'])];
        }
        $end = $last === null ? $now : ($last['hourly'] ? $last['time'] : (int)strtotime('+1 day', $last['time']));
        return [$first['time'], $end];
    }

    /** Hängt an ein Stapeldiagramm leere Abschnitte bis zum Ende des Zeitraums an. */
    private static function padTimes(array &$chart, int $end): void
    {
        $times = $chart['times'] ?? [];
        if ($times === []) {
            return;
        }
        $step = match ($chart['per'] ?? '') {
            'hour'  => '+1 hour',
            'month' => '+1 month',
            default => '+1 day',
        };
        for ($next = (int)strtotime($step, (int)end($times)); $next < $end; $next = (int)strtotime($step, $next)) {
            $chart['times'][] = $next;
            foreach ($chart['series'] as &$series) {
                $series['values'][] = 0;
            }
            unset($series);
        }
    }

    /**
     * Zeitfenster des Vergleichs: [Beginn, Ende, Beginn des Zeitraums, läuft noch]. „previous“ ist der Zeitraum davor
     * (gestern, letzte Woche, Vormonat, Vorjahr; bei from/to gleich viele Tage davor), „year_before“ derselbe ein Jahr
     * früher. Ein laufender Zeitraum zählt bis zu seinem natürlichen Ende. null = from/to mit Uhrzeit.
     *
     * @return null|array{0: int, 1: int, 2: int, 3: bool}
     */
    private function compareWindow(array $arguments, string $compare): ?array
    {
        $now = $this->backend->timestamp();
        $period = trim((string)($arguments['period'] ?? ''));
        if ($period !== '') {
            [, $from, $to] = self::periodBounds($period, $now);
            $unit = self::periodUnit($period);
            $end = (int)strtotime('+1 ' . $unit, $from) - 1;
            $running = $to === 0;
        } else {
            $first = self::parseMoment(trim((string)($arguments['from'] ?? '')), false);
            $toText = trim((string)($arguments['to'] ?? ''));
            $last = $toText === '' ? null : self::parseMoment($toText, true);
            if ($first === null || $first['hourly'] || ($last['hourly'] ?? false) || ($toText !== '' && $last === null)) {
                return null;
            }
            $from = $first['time'];
            $end = (int)strtotime('+1 day', ($last ?? $first)['time']) - 1;
            $unit = (int)round(($end + 1 - $from) / 86400) . ' days';
            $running = $end >= $now;
        }
        // Fünftes Element: der Schritt vom Vergleichszeitraum zum Zeitraum nach dem Kalender (strtotime), nicht als feste
        // Sekundenzahl; sonst trifft ein Tag zwischen den Zeitumstellungen zweier Jahre den Balken um eine Stunde daneben.
        if ($compare === 'year_before') {
            // Beginnt der Zeitraum am 29.02., ist der Vergleich der 28.02. des Vorjahres und rückt auf den 29.02. (Review
            // 14.10.2026: strtotime machte daraus den 01.03., das Ende des Tages aber den 28.02., Beginn nach dem Ende).
            $leap = date('m-d', $from) === '02-29';
            $start = (int)strtotime('-1 year', $from);
            if ($leap) {
                $start = (int)strtotime('-1 day', $start);
            }
            $shift = static fn (int $time): int => (int)strtotime($leap && date('m-d', $time) === '02-28' ? '+1 year +1 day' : '+1 year', $time);
            return [$start, (int)strtotime('-1 year', $end + 1) - 1, $from, $running, $shift];
        }
        // Bei from/to ist $unit schon eine Anzahl („6 days“); „-1 6 days“ las strtotime als sechs Tage vorwärts (15.10.2026)
        $step = $period !== '' ? '+1 ' . $unit : '+' . $unit;
        $back = $period !== '' ? '-1 ' . $unit : '-' . $unit;
        return [(int)strtotime($back, $from), $from - 1, $from, $running, static fn (int $time): int => (int)strtotime($step, $time)];
    }

    /**
     * Werkzeug „show_devices“ (Gerätekarten, Burkhard 12.10.2026): dieselben Treffer und Zeilen wie get_state, dazu je
     * Gerät eine Karte in structuredContent mit den Bedienelementen, die das Gerät versteht. Ein Nur-Lese-Zugang bekommt
     * Karten ohne Bedienelemente. Zeiträume gibt es hier nicht.
     *
     * @return array{text: string, isError: bool, structuredContent?: array<string, mixed>}
     */
    public function cards(array $arguments, bool $readOnly): array
    {
        $arguments = array_diff_key($arguments, ['period' => 0, 'from' => 0, 'to' => 0]);
        $result = $this->getState($arguments);
        if ($result['isError']) {
            return $result;
        }
        $name = trim((string)($arguments['name'] ?? ''));
        $floor = trim((string)($arguments['floor'] ?? ''));
        $matches = $this->find($this->effectiveRoom(trim((string)($arguments['room'] ?? '')), $name, $floor), trim((string)($arguments['type'] ?? '')), $name, $floor);
        if ((bool)($arguments['active_only'] ?? false)) {
            $matches = array_values(array_filter($matches, fn (array $entry): bool => $this->stateOf($entry)['active'] === true));
        }
        $cards = array_map(fn (array $entry): array => $this->cardOf($entry, $readOnly), array_slice($matches, 0, self::CARDS_MAX));
        $result['text'] .= "\n" . $this->t('The devices are shown as cards in the chat.');
        return $result + ['structuredContent' => ['kind' => 'cards', 'readOnly' => $readOnly, 'cards' => $cards,
            'more' => max(0, count($matches) - self::CARDS_MAX), 'labels' => ['more' => $this->t('%d more device(s) not shown'),
                'current' => $this->t('current'), 'shut' => $this->t('shut'),
                'groups' => array_map(fn (string $group): array => [$group, $this->t(self::CARD_GROUP_LABELS[$group])], array_keys(self::CARD_GROUP_LABELS))]]];
    }

    /**
     * Werkzeug „control_device“, nur für die Seite der Karten: ein Gerät per Kartenschlüssel bedienen, über set_state
     * mit derselben Rückmeldung. Dazu die neue Karte, damit die Seite sie ersetzt.
     *
     * @return array{text: string, isError: bool, structuredContent?: array<string, mixed>}
     */
    public function controlDevice(array $arguments): array
    {
        $key = trim((string)($arguments['device'] ?? ''));
        $entry = null;
        foreach ($this->entries as $candidate) {
            if ($key !== '' && self::cardKey($candidate) === $key) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            $this->problem = 'unknown_name';
            return $this->fail($this->t('This device is not on the release list any more, show the devices again.'));
        }
        // Nur der Schlüssel: die Karte neu, ohne etwas zu schalten. Die Seite fragt so nach, solange ein Gerät fährt
        // oder noch nicht bestätigt ist (Burkhard 13.10.2026: die Karte blieb bei „fährt auf 90 %“ stehen).
        if (array_intersect_key($arguments, array_flip(self::CARD_FIELDS)) === []) {
            $card = $this->cardOf($entry, false);
            return ['text' => $entry['Room'] . ' | ' . $entry['Name'] . ' | ' . $card['text'], 'isError' => false,
                'structuredContent' => ['kind' => 'cards', 'problem' => null, 'cards' => [$card]]];
        }
        $this->onlyKey = $key;
        try {
            $result = $this->setState(['name' => $entry['Name']] + array_intersect_key($arguments, array_flip(self::CARD_FIELDS)));
        } finally {
            $this->onlyKey = null;
        }
        // „problem“ wie in „Was nicht geklappt hat“: Nur dann zeigt die Karte die Antwort, sonst reicht der neue Zustand
        // (Burkhard 12.10.2026: nach dem Schalten stand die ganze Antwort von set_state auf der Karte).
        return $result + ['structuredContent' => ['kind' => 'cards', 'problem' => $this->problem, 'cards' => [$this->cardOf($entry, false)]]];
    }

    /** Felder, die eine Karte an control_device schickt; das Schema in module.php nennt dieselben. */
    public const CARD_FIELDS = ['command', 'percent', 'temperature', 'humidity', 'volume', 'mode', 'fan_speed', 'color_temperature', 'color'];

    /** Schlüssel einer Karte aus allen Variablen des Geräts; ein Thermostat und sein Raumfühler teilen sich eine. */
    public static function cardKey(array $entry): string
    {
        return implode('.', array_values(is_array($entry['roles'] ?? null) ? $entry['roles'] : self::rolesOf($entry)));
    }

    /**
     * Karte eines Geräts: Zustandstext wie bei get_state und die Bedienelemente aus abilitiesOf().
     *
     * @return array<string, mixed>
     */
    private function cardOf(array $entry, bool $readOnly): array
    {
        $this->blindMoving = false;
        $state = $this->stateOf($entry);
        $moving = $this->blindMoving;
        $abilities = $readOnly ? [] : self::abilitiesOf($entry);
        $controls = [];
        if (in_array('on', $abilities, true)) {
            $controls['switch'] = true;
        }
        if (in_array('open', $abilities, true)) {
            $controls['cover'] = ['stop' => in_array('stop', $abilities, true)];
        }
        if (in_array('percent', $abilities, true)) {
            $controls['percent'] = $this->cardPercent($entry);
        }
        foreach (['temperature' => 'temperature', 'humidity' => 'humidity'] as $ability => $field) {
            if (in_array($ability, $abilities, true)) {
                $controls['setpoint'] = $this->cardSetpoint($entry, $field);
            }
        }
        if (in_array('play', $abilities, true)) {
            $controls['playback'] = true;
        }
        if (in_array('volume', $abilities, true)) {
            $controls['volume'] = $this->cardVolume($entry);
        }
        // Farbtemperatur und Farbe (Burkhard 16.10.2026); mit bekanntem Farbmodus ist nur der gültige Teil aktiv
        if (in_array('color_temperature', $abilities, true) || in_array('color', $abilities, true)) {
            $colorMode = $this->colorModeOf($entry);
            if (in_array('color_temperature', $abilities, true)) {
                $controls['colortemp'] = $this->cardKelvin($entry, $colorMode !== 'color');
            }
            if (in_array('color', $abilities, true)) {
                $rgb = $this->backend->read($entry['roles']['color'])['value'] ?? null;
                $controls['color'] = ['label' => $this->t('Colour'), 'active' => $colorMode !== 'temperature',
                    'value' => $colorMode !== 'temperature' && is_int($rgb) ? self::colorName($rgb) : null,
                    'entries' => array_map(fn (string $name, int $value): array => ['name' => $name, 'label' => $this->t($name), 'rgb' => sprintf('#%06x', $value)], array_keys(self::COLORS), self::COLORS)];
            }
        }
        foreach (['mode' => ['mode', 'Mode'], 'fan_speed' => ['fan', 'Fan speed']] as $field => [$role, $label]) {
            $choice = in_array($field, $abilities, true) ? $this->cardChoice($entry['roles'][$role] ?? 0, $field, $label) : null;
            if ($choice !== null) {
                $controls['options'][] = $choice;
            }
        }
        [$figure, $shown] = $this->cardFigure($entry, $state['active']);
        $status = self::cardStatus($state['text'], $shown);
        // Zeigt die Karte Sollwert oder Auswahl selbst, steht links nur der Modus bzw. beim Luftbe-/entfeuchter und
        // der Lüftung an/aus (Burkhard 13.10.2026: „Soll 24 °C, Aus, Lüfter Auto“ stand neben den Reglern).
        if (isset($controls['setpoint']) || isset($controls['options'])) {
            $mode = null;
            foreach ($controls['options'] ?? [] as $choice) {
                $mode = $choice['field'] === 'mode' ? $choice['value'] : $mode;
            }
            $status = match (true) {
                in_array($entry['Type'], ['humidifier', 'fan'], true) && $state['active'] !== null => $this->t($state['active'] ? 'on' : 'off'),
                $mode !== null                                                                     => $mode,
                default                                                                            => null,
            };
        }
        // Ein Klimagerät im Modus „off“ ist aus, sonst an; die Karte färbt danach (Layoutvergleich 13.10.2026: „AUS“ stand
        // blau da). Nur für die Karte, get_state zählt Thermostate weiter nicht bei active_only.
        $active = $state['active'];
        if ($entry['Type'] === 'thermostat' && isset($entry['roles']['mode'])) {
            $current = $this->backend->read($entry['roles']['mode']);
            if ($current !== null) {
                $active = self::normalize((string)$current['value']) !== 'off' && !in_array(self::normalize($current['formatted']), ['off', self::normalize($this->t('off'))], true);
            }
        }
        return ['key' => self::cardKey($entry), 'room' => $entry['Room'], 'name' => $entry['Name'], 'type' => $entry['Type'],
            'group' => self::CARD_GROUPS[$entry['Type']] ?? 'sensor', 'text' => $state['text'], 'active' => $active,
            'figure' => $figure, 'status' => $status, 'moving' => $moving, 'controls' => $controls];
    }

    /**
     * Hauptwert einer Karte, groß rechts: die Stufe eines eingeschalteten Lichts, der Öffnungsgrad, der Messwert, die
     * Ist-Temperatur bzw. Ist-Feuchte, sonst die Leistung. Dazu der Text, wie er im Zustandstext steht.
     *
     * @return array{0: ?array{value: string, unit: string}, 1: ?string}
     */
    private function cardFigure(array $entry, ?bool $active): array
    {
        $roles = $entry['roles'];
        $percent = match (true) {
            in_array($entry['Type'], ['blind', 'window'], true) || ($entry['Type'] === 'light' && $active === true) => $this->cardPercent($entry),
            $entry['Type'] === 'media'                                                                           => $this->cardVolume($entry),
            default                                                                                              => null,
        };
        if ($percent !== null) {
            return [['value' => (string)$percent, 'unit' => '%'], $percent . ' %'];
        }
        $variable = match (true) {
            in_array($entry['Type'], ['temperature', 'humidity', 'air_quality', 'measurement', 'thermostat'], true) => $roles['state'] ?? 0,
            $entry['Type'] === 'humidifier'                                                                     => $roles['humidity'] ?? 0,
            $entry['Type'] === 'meter'                                                                          => $roles['power'] ?? $roles['energy'] ?? 0,
            $entry['Type'] !== 'media'                                                                          => $roles['power'] ?? 0,
            default                                                                                             => 0,
        };
        $formatted = $variable === 0 ? '' : trim((string)($this->backend->read($variable)['formatted'] ?? ''));
        if (preg_match('/^(-?\d+(?:[.,]\d+)?)\s*(\S.*)$/u', $formatted, $m) !== 1) {
            return [null, null];
        }
        return [['value' => $m[1], 'unit' => $m[2]], $formatted];
    }

    /** Zustand ohne den Hauptwert: Teile in Klammern, die auf ihn enden, fallen weg; steht nur er da, bleibt nichts. */
    private static function cardStatus(string $text, ?string $shown): ?string
    {
        if ($shown === null) {
            return $text;
        }
        if ($text === $shown) {
            return null;
        }
        // „90 % offen“ beginnt mit dem Hauptwert (Rollladen zwischen offen und zu)
        if (str_starts_with($text, $shown . ' ')) {
            $text = substr($text, strlen($shown) + 1);
        }
        return (string)preg_replace_callback('/ \(([^()]*)\)/u', static function (array $m) use ($shown): string {
            // „Lautstärke 64 % = -17.5 dB“ nennt den Wert mitten im Teil
            $parts = array_filter(explode(', ', $m[1]), static fn (string $part): bool => $part !== $shown && !str_ends_with($part, ' ' . $shown) && !str_contains($part, ' ' . $shown . ' '));
            return $parts === [] ? '' : ' (' . implode(', ', $parts) . ')';
        }, $text);
    }

    /**
     * Auswahl einer Karte (Modus, Lüfterstufe): die Beschriftungen der Variable und der aktuelle Eintrag. Die Seite
     * schickt die Beschriftung als Name an control_device, runOption() findet den Wert dazu. Ohne Einträge keine Auswahl.
     *
     * @return array{field: string, label: string, value: ?string, entries: array<int, string>}|null
     */
    private function cardChoice(int $variable, string $field, string $label): ?array
    {
        $options = $variable > 0 ? $this->backend->options($variable) : null;
        if ($options === null || $options === []) {
            return null;
        }
        $current = $this->backend->read($variable)['value'] ?? null;
        $value = null;
        foreach ($options as [$optionValue, $caption]) {
            if ($current !== null && (string)$optionValue === (string)$current) {
                $value = (string)$caption;
            }
        }
        return ['field' => $field, 'label' => $this->t($label), 'value' => $value, 'entries' => array_map(static fn (array $o): string => (string)$o[1], $options)];
    }

    /**
     * Regler der Farbtemperatur in Kelvin: Bereich der Variable, gekappt auf CARD_KELVIN, und der Wert (null ohne Wert
     * oder bei 0, das HomeAssistant-Modul meldet ausgeschaltet 0 K). $active: Das Licht leuchtet gerade weiß oder der
     * Farbmodus ist unbekannt.
     *
     * @return array{label: string, value: ?int, min: int, max: int, active: bool}
     */
    private function cardKelvin(array $entry, bool $active): array
    {
        $variable = $entry['roles']['colortemp'];
        [$min, $max] = self::CARD_KELVIN;
        $range = $this->backend->setpointRange($variable);
        if ($range !== null && $range['max'] > $range['min'] && $range['min'] > 0) {
            $ends = [$this->kelvinOf($variable, $range['min']), $this->kelvinOf($variable, $range['max'])];
            $min = max($min, min($ends));
            $max = min($max, max($ends));
            if ($max <= $min) {
                [$min, $max] = self::CARD_KELVIN;
            }
        }
        $raw = $this->backend->read($variable)['value'] ?? null;
        $value = (is_int($raw) || is_float($raw)) && $raw > 0 ? $this->kelvinOf($variable, (float)$raw) : null;
        return ['label' => $this->t('Colour temperature'), 'value' => $value, 'min' => $min, 'max' => $max, 'active' => $active];
    }

    /** Lautstärke in ganzen Prozent des Bereichs wie im Zustandstext; null, wenn Wert oder Bereich unbekannt sind. */
    private function cardVolume(array $entry): ?int
    {
        $variable = $entry['roles']['volume'] ?? 0;
        $value = $variable === 0 ? null : ($this->backend->read($variable)['value'] ?? null);
        $range = $variable === 0 ? null : $this->backend->range($variable);
        if ((!is_int($value) && !is_float($value)) || $range === null || $range['max'] <= $range['min']) {
            return null;
        }
        return (int)round(max(0.0, min(100.0, ($value - $range['min']) / ($range['max'] - $range['min']) * 100.0)));
    }

    /** Stufe bzw. Öffnungsgrad in ganzen Prozent; null, wenn Wert oder Bereich unbekannt sind. */
    private function cardPercent(array $entry): ?int
    {
        $variable = $entry['roles']['level'] ?? 0;
        $value = $variable === 0 ? null : ($this->backend->read($variable)['value'] ?? null);
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        if ($entry['Type'] === 'blind' || $entry['Type'] === 'window') {
            $percent = $this->openPercent($entry, $value);
        } else {
            $range = $this->backend->range($variable);
            $percent = $range === null || $range['max'] <= $range['min'] ? null : ($value - $range['min']) / ($range['max'] - $range['min']) * 100.0;
        }
        return $percent === null ? null : (int)round(max(0.0, min(100.0, $percent)));
    }

    /** @return null|array{field: string, value: ?float, min: ?float, max: ?float, step: ?float, unit: string, label: string, lower: ?float, raise: ?float} */
    private function cardSetpoint(array $entry, string $field): ?array
    {
        $variable = $entry['roles']['setpoint'] ?? 0;
        $value = $this->backend->read($variable)['value'] ?? null;
        $value = is_int($value) || is_float($value) ? (float)$value : null;
        $range = $this->backend->setpointRange($variable);
        $step = $range === null || $range['step'] <= 0 ? null : $range['step'];
        // Ziele der Knöpfe „−“ und „+“: Ein Sonderwert außerhalb des Bereichs zählt vom nächsten Rand (Review 14.10.2026:
        // HM-CC-TC auf 100 = Ventil offen, die Seite rechnete von min aus und „+“ schickte 6.5).
        $base = $value ?? $range['min'] ?? null;
        if ($base !== null && $range !== null) {
            $base = max($range['min'], min($range['max'], $base));
        }
        $target = static function (int $direction) use ($base, $range, $step): ?float {
            if ($base === null) {
                return null;
            }
            $raster = $step ?? 0.5;
            $result = round(($base + $direction * $raster) / $raster) * $raster;
            if ($range !== null) {
                $result = max($range['min'], min($range['max'], $result));
            }
            return round($result, 2);
        };
        return ['field' => $field, 'value' => $value,
            'min' => $range['min'] ?? null, 'max' => $range['max'] ?? null, 'step' => $step,
            'unit' => $this->temperatureUnit($variable, (string)$entry['Type']),
            // Beschriftet wie Modus und Lüfterstufe (Layoutvergleich 13.10.2026: „24 °C“ unter „21,0 °C“ ohne Hinweis)
            'label' => $this->t($field === 'humidity' ? 'Target humidity' : 'Setpoint'),
            'lower' => $target(-1), 'raise' => $target(1)];
    }

    /** Kein Treffer: Liegt es am Raum, am Namen oder an der Kombination? */
    private function noMatch(string $room, string $name): void
    {
        $this->problem = match (true) {
            $room !== '' && !self::isAllRooms($room) && !$this->rooms->isKnown($room) && $this->find($room, '', '') === [] => 'unknown_room',
            $name !== '' && $this->find('', '', $name) === []                                                                => 'unknown_name',
            default                                                                                                          => 'no_match',
        };
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private function t(string $text): string
    {
        return $this->backend->translate($text);
    }
}
