<?php

declare(strict_types=1);

/**
 * Was die Freigabeliste von der Anlage braucht. Das Modul liefert es über Symcon-Funktionen
 * (SymconDeviceBackend), die Tests über echte Mitschnitte.
 */
interface DeviceBackend
{
    /** @return null|array{value: mixed, formatted: string} null = Variable fehlt */
    public function read(int $variableId): ?array;

    /** Hat die Variable eine Aktion, lässt sie sich also schalten? */
    public function hasAction(int $variableId): bool;

    /**
     * Einträge einer Auswahlvariable als [Wert, Beschriftung], aus der Darstellung (OPTIONS) oder
     * den Zuordnungen des Profils. null = Variable fehlt oder hat keine Einträge.
     *
     * @return null|list<array{0: mixed, 1: string}>
     */
    public function options(int $variableId): ?array;

    /**
     * Löst die Aktion der Variable aus, ohne auf ihr Ende zu warten. null = ausgelöst, sonst der
     * Fehlertext. Ob die Aktion selbst gescheitert ist, sagt danach failure().
     */
    public function request(int $variableId, mixed $value): ?string;

    /** Fehlertext der zuletzt ausgelösten Aktion dieser Variable, sobald sie gescheitert ist; sonst null. */
    public function failure(int $variableId): ?string;

    /**
     * Wertebereich der Variable und, für Rollläden, welcher Rohwert „offen“ und welcher
     * „geschlossen“ bedeutet. „source“ sagt, woher die Richtung stammt: „presentation“ (Darstellung
     * Rollladen), „rotation“ (dieselbe für Lamellen), „reversed“ (Profil mit „.Reversed“ im Namen,
     * dann steht der Name in „profile“), „module“ (das Modul des Geräts zählt den größten Wert als
     * offen, sein Name steht in „module“) oder „default“ (kleinster Wert offen). null = unbekannt.
     *
     * @return null|array{min: float, max: float, open: float, closed: float, source: string, profile: string, module?: string, step?: float}
     */
    public function range(int $variableId): ?array;

    /**
     * Bereich und Schrittweite eines Sollwerts. Sonderwerte am Rand, die im Profil einen Text statt
     * einer Zahl tragen („On“ bei 30,5 im Homematic-Profil), gehören nicht dazu. null = unbekannt.
     *
     * @return null|array{min: float, max: float, step: float}
     */
    public function setpointRange(int $variableId): ?array;

    /**
     * Farbmodus eines Lichts: Ident und Wert der Variable neben $variableId (Farbe oder Farbtemperatur), deren Ident
     * auf color_mode endet (Zigbee2MQTT color_mode, HomeAssistant-Modul light_color_mode, Matter ColorControl_ColorMode).
     * null = keine solche Variable. Die Bedeutung des Werts kennt DeviceIndex::colorModeKind().
     *
     * @return null|array{ident: string, value: mixed}
     */
    public function colorMode(int $variableId): ?array;

    /**
     * Wie sich die Fahrt eines Rollladens oder Fensters anhalten lässt: „variable“ (eigene
     * Stoppvariable mit Aktion, ein Wahrheitswert oder eine Zahl mit dem Eintrag „Stop“/„Stopp“),
     * der Name des Moduls, über dessen Funktion es geht („HomeMatic“, „KLF200“), oder '' (gar nicht).
     */
    public function stopMethod(int $levelVariable, int $stopVariable): string;

    /**
     * Hält die Fahrt an, ohne auf das Ende zu warten. null = ausgelöst, sonst der Fehlertext. Ob
     * der Stopp selbst gescheitert ist, sagt danach failure() der Positionsvariable.
     */
    public function stop(int $levelVariable, int $stopVariable): ?string;

    /** Gibt es das Skript einer Szene? */
    public function scriptExists(int $scriptId): bool;

    /** Startet ein Skript, ohne auf sein Ende zu warten. null = gestartet, sonst der Fehlertext. */
    public function runScript(int $scriptId): ?string;

    /** Wartet beim Abfragen der Rückmeldung. */
    public function sleep(int $milliseconds): void;

    /**
     * Zeitpunkt der letzten Aktualisierung der Variable in Millisekunden, auch wenn sich ihr Wert
     * nicht geändert hat; 0 = unbekannt. Symcon kennt ihn nur auf die Sekunde genau.
     */
    public function updated(int $variableId): int;

    /** Uhr in Millisekunden, in derselben Zählung wie updated(). */
    public function now(): int;

    /**
     * Gemerkte Fahrt eines Rollladens, damit eine spätere Abfrage das Ziel kennt.
     *
     * @return null|array{target: int|float, since: int} null = keine
     */
    public function rememberedTravel(int $levelVariable): ?array;

    /** @param null|array{target: int|float, since: int} $travel null = Merker löschen */
    public function rememberTravel(int $levelVariable, ?array $travel): void;

    /**
     * Merkt die Wiedergabevariablen der Mediaplayer, die gerade per Werkzeug pausiert wurden, mit
     * der Uhrzeit von now(). „play“ ohne Raum setzt sie später fort.
     *
     * @param list<int> $playbackVariables
     */
    public function rememberPaused(array $playbackVariables): void;

    /** @return array<int, int> gemerkte Wiedergabevariable => Zeitpunkt in ms (Uhr von now()) */
    public function rememberedPaused(): array;

    /**
     * Summe eines als Zähler archivierten Werts über die Aggregate einer Stufe (1 Tag, 2 Woche,
     * 3 Monat, 4 Jahr), deren Beginn zwischen $from und $to liegt; $to = 0 heißt bis jetzt, der
     * laufende Zeitraum zählt dann mit. „unit“ ist die Einheit der Variable (etwa Wh oder kWh).
     * null = nicht archiviert oder nicht als Zähler aggregiert.
     *
     * @return null|array{sum: float, unit: string}
     */
    public function counterSum(int $variableId, int $level, int $from, int $to): ?array;

    /**
     * Wie counterSum, aber je Zeitraum der Stufe (0 Stunde, 1 Tag, 3 Monat) einzeln und aufsteigend, für das
     * Stapeldiagramm des Verbrauchs. null = nicht archiviert oder nicht als Zähler aggregiert.
     *
     * @return null|array{rows: list<array{time: int, sum: float}>, unit: string}
     */
    public function counterSeries(int $variableId, int $level, int $from, int $to): ?array;

    /**
     * Verlauf eines Messwerts aus den Aggregaten des Archivs, aufsteigend: je Zeitraum der Stufe
     * (0 Stunde, 1 Tag, 3 Monat) Beginn, Minimum, Mittel und Maximum. $to = 0 heißt bis jetzt.
     * null = nicht archiviert oder als Zähler aggregiert.
     *
     * @return null|array{rows: list<array{time: int, min: float, avg: float, max: float}>, unit: string}
     */
    public function history(int $variableId, int $level, int $from, int $to): ?array;

    /**
     * Zustandswechsel aus den Rohwerten des Archivs (Zeitleiste, B69): der letzte Wert vor $from (null = keiner) und
     * die Änderungen von $from bis $to, aufsteigend. $to = 0 heißt bis jetzt. Das Archiv speichert nur Änderungen.
     * null = nicht archiviert oder als Zähler aggregiert.
     *
     * @return null|array{start: mixed, changes: list<array{0: int, 1: mixed}>}
     */
    public function stateSeries(int $variableId, int $from, int $to): ?array;

    /** Einheit der Variable aus Profil-Suffix oder Darstellung (etwa W, kW); '' = keine. */
    public function unit(int $variableId): string;

    /** Uhrzeit der Anlage als Unix-Zeitstempel, für Zeiträume wie „letzten Monat“. */
    public function timestamp(): int;

    /** Übersetzt einen englischen Text (Schlüssel aus locale.json). */
    public function translate(string $text): string;
}
