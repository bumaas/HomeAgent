# HomeAgent

Ein MCP-Server als Symcon-Modul, über den KI-Assistenten das Haus abfragen und bedienen. Der Assistent
sieht nur die Geräte der Freigabeliste und wenige, einfache Werkzeuge; die Verwaltung der Anlage bleibt
beim eingebauten MCP-Server von Symcon. Bis build 33 hieß das Modul „VoiceControl“ (Präfix `VCTL_`), seit
build 34 „Home Agent“ (Präfix `HAGT_`, Hook `/hook/homeagent/<InstanzID>`); die GUIDs sind gleich geblieben.

Diese Datei ist die öffentliche Entwicklerhilfe. Messungen, Instanz-IDs und Adressen der Anlagen des
Entwicklers stehen in einer `CLAUDE.local.md`, die Claude Code ebenfalls liest und die `.gitignore`
ausschließt. Neue Notizen gehören dorthin, wenn sie eine Anlage, eine Person oder ein Netz betreffen,
sonst hierher.

## Leitbild

Das Modul soll für den Anwender konsistenter, schneller und kompakter sein als die Sprachsteuerung von
Home Assistant, ohne kleineren Umfang. Daraus folgen die Grundsätze:
- **Das Werkzeug zählt, nicht das Modell.** Die erste Zeile jeder Antwort nennt die Anzahl.
- **Gemeldet wird nur Belegtes.** Ein angenommener Befehl ist kein Beleg; erst die Variable bestätigt.
- **Vokabular statt Inventar.** Räume, Typen und Szenen stehen in Beschreibungen, nicht jedes Gerät.
- **Angeboten wird nur, was es gibt.** Das Schema von `set_state` nennt nur Befehle und Felder, die
  mindestens ein freigegebenes Gerät versteht.

## Aufbau

| Datei | Aufgabe |
|---|---|
| `libs/McpProtocol.php` | zustandsloser MCP-Server über Streamable HTTP (JSON-RPC 2.0), ohne Symcon-Abhängigkeit; Zugangswahl (`findAccess`), Token aus dem Pfad |
| `libs/SignInGuard.php` | Bremse gegen Durchprobieren von Tokens |
| `libs/RoomIndex.php` | Raumliste mit Aliasen und Etage; Raumerkennung für den Alexa-Vorschlag (`bestMatch`, `nameOf`) |
| `libs/DeviceIndex.php` | Freigabeliste: Geräte mit Fähigkeiten (`ROLES`, `TYPE_ROLES`), Suche nach Raum, Etage, Typ und Name, Zustandstexte, Schalten, Stellen, Sollwert, Verbrauch und Leistung |
| `libs/SceneIndex.php` | Szenenliste: Suche und Start |
| `libs/AlexaImport.php` | Vorschlag der Freigabeliste aus der Alexa-Instanz von Symcon |
| `libs/DeviceBackend.php` | Schnittstelle zur Anlage; `SymconDeviceBackend.php` über Symcon-Funktionen, `tests/FakeBackend.php` spielt Mitschnitte ab |
| `HomeAgent/module.php` | Hook per `RegisterHook`, Status, Werkzeugliste, Formular |

Alle Bibliotheken unter `libs/` kommen ohne Symcon aus; Werte, Aktionen und Übersetzungen liefert das
Backend. So laufen die Tests mit echten Mitschnitten.

## Transport und Anmeldung

- Nur POST, Antwort `application/json`, GET 405, kein SSE, keine Sitzung, Benachrichtigungen 202.
- `Authorization: Bearer <Token>`, je Assistent ein Zugang (`Accesses`: Name, Token, Standardraum,
  „Nur abfragen“, „Token auch in der Adresse“). Mit `InPath` auch `/hook/homeagent/<InstanzID>/<Token>`
  für Clients ohne Anmeldung (Home Assistant, Claude-App mit „No sign-in“). Ein Header entscheidet allein.
- **Die Adresse geht nie ins Debug oder Log**, sie kann das Token tragen. Tokens unter 24 Zeichen sind
  ein Fehler der Zeile. Nach 20 abgewiesenen Anmeldungen in einer Minute ist der Hook 60 s zu.
- **Ein fehlerhafter Zugang sperrt nur sich selbst** (`usableAccesses()`), Warnung im Log, Überschrift
  im Formular „Fehlerhafte Zugänge sind gesperrt“. Status 104 ohne Zugang mit Token, 201 bei Fehlern in
  Räumen, Geräten oder Szenen oder ohne fehlerfreien Zugang; beides antwortet mit 503, nicht 401
  (ein Client wie HA beginnt bei 401 die OAuth-Suche).
- Eingaben sind begrenzt: Körper erst nach der Anmeldung lesen, Stapel höchstens 50 Nachrichten und ein
  `tools/call`, Argumente gegen das `inputSchema` prüfen.

## Werkzeuge

Drei Werkzeuge: `get_state`, `set_state`, `run_scene`. Alle tragen `openWorldHint: false`.

- **`get_state`** filtert nach Raum, Etage, Typ und Name, `active_only` zählt nur Geräte mit an/aus und
  nennt die übrigen in einer Schlusszeile. `period` bzw. `from`/`to` liefern den Verbrauch aus den
  Archiv-Aggregaten, `period=now` die Leistung; fürs ganze Haus aus der Energiebilanz (Bezug + PV +
  Batterie entladen − Einspeisung − Batterie geladen), mit Autarkie und den fünf größten Geräten.
  Für Messwerte (Temperatur, Feuchte, Luftqualität, Messwert, Ist-Temperatur des Thermostats) liefern
  dieselben Zeiträume den Verlauf (Minimum / Mittel / Maximum je Stunde, Tag oder Monat).
- **`set_state`** mit `command` (`on`, `off`, `open`, `close`, `stop`), `percent` oder `temperature`;
  Widersprüche werden abgewiesen. `percent` ist bei Rollläden und Fenstern der Öffnungsgrad (0 zu,
  100 offen). Ohne Raum, Etage, Typ oder Name passiert nichts; ein Name muss genau ein Gerät treffen,
  sonst kommt die Kandidatenliste mit Zustand. Geräte einer Gruppe, die den Befehl nicht verstehen,
  werden ausgelassen und gezählt.
- **`run_scene`** startet ein freigegebenes Skript; die Szenennamen stehen in der Beschreibung.

Weitere Regeln, die nicht offensichtlich sind:
- **Aktionen laufen in einem eigenen Skript** (`IPS_RunScriptTextEx`), nie im Thread der Anfrage;
  manche Aktoren blockieren `RequestAction` bis zum Ziel. Fehlschläge meldet das Skript per
  `IPS_RequestAction(<Instanz>, 'ActionFailed', …)` zurück.
- **Rückmeldung:** erst alle Aktionen auslösen, dann gemeinsam bis 2 s auf die Variablen warten.
  Rollläden gelten als „fährt“, sobald Position, Fahrstatus oder ein Lebenszeichen (Aktualisierung nach
  dem Befehl) es zeigen; das Ziel einer Fahrt merkt sich der Buffer `Travels`.
- **Richtung eines Rollladens** aus der Darstellung „Rollladen“, sonst aus einem Profil mit `.Reversed`,
  sonst ist der kleinste Wert offen; der Haken „Richtung umkehren“ dreht es um.
- **Prozent beim Licht rechnet das Modul selbst**; der Anzeigetext von Symcon schneidet ab.
- **Sollwert:** Bereich und Schrittweite aus Darstellung oder Profil, Sonderwerte mit Text am Rand
  („On“) zählen nicht dazu. Batteriegeräte melden den neuen Sollwert oft schon über die Zentrale,
  bevor das Gerät ihn übernimmt.
- **Stopp:** über eine Stoppvariable (Wahrheitswert oder Eintrag „Stop“), sonst über das Modul des
  Geräts (`SymconDeviceBackend::STOP_MODULES`, Homematic und Velux KLF200).
- **Verlauf statt Verbrauch** (`DeviceIndex::history`), wenn nach einem Typ aus `HISTORY_TYPES` gefragt
  wird oder kein Treffer einen Zähler hat, nie fürs ganze Haus. Stufe aus dem Zeitraum (heute und
  gestern stündlich, Wochen und Monate täglich, Jahre monatlich; `from`/`to` bis 2 Tage stündlich, bis
  100 Tage täglich), höchstens `HISTORY_MAX` Messwerte, jede Variable nur einmal (ein Thermostat kann
  den Raumfühler als Ist-Temperatur lesen). Als Zähler aggregierte Variablen haben keinen Verlauf.
- **Namenssuche** in Stufen: genauer Name oder Alias, dann die Anfrage als Teil des Namens, dann der
  Name in der Anfrage, aber nur an einem Wortanfang („Esstischlampe“ trifft „Esstisch“,
  „Außentemperatur“ trifft „Temperatur“ nicht). Nur die beste Stufe zählt.
- **Raumvorschlag beim Alexa-Import** (`AlexaImport::proposeRooms`), nur wenn die Raumliste nichts
  weiß: erste Kategorie im eigenen Pfad (ohne Instanz und Variable), die ein Wort mit dem Alexa-Namen
  teilt, sonst ein Wort des Namens, das bei einem anderen Vorschlag Kategorie ist. Kategorien aus
  höchstens zwei Buchstaben sind die Etage; verschiedene Etagen in einem Raum ergeben je Etage einen
  Raum mit dem gemeinsamen Namen als Alias. Die neuen Räume liefert `propose()` in `rooms`, das Modul
  hält sie im Buffer `AlexaRooms` und legt sie beim Freigeben mit Etage und Alias an. Test `check-raumvorschlag.php` mit den Mitschnitten aus
  einer betreuten Anlage (anonymisiert) und vom nuc ohne Raumliste.
- **Fenster mit Antrieb** tragen in `get_state` den Zusatz „with drive“, sofern `set_state` sie fahren kann
  (nicht bei „nur abfragen“); die Beschreibung von `get_state` erklärt ihn. Anlass: Die Claude-App konnte
  „Welche Fenster kannst du öffnen?“ nicht beantworten. Antworten von `set_state` bleiben ohne Zusatz.
- **Räume als Vokabular:** Die Beschreibung von `room` nennt die Räume der Geräte und der Raumliste
  („Known rooms: …“), sonst kürzt ein Modell „Bad oben“ zu `room=Bad`.
- **Nur-Lese-Zugang:** `instructions` und die Beschreibung von `get_state` sagen dem Assistenten, dass
  der Zugang nicht schalten kann, damit er eine Absage richtig begründet.
- **Farbtemperatur** (`colortemp`, Feld `color_temperature` in Kelvin): Ein Bereich bis 1000 heißt Mired,
  umgerechnet wird im Modul. Die Schrittweite der Darstellung zählt nicht (nur Raster des Reglers), ein
  Wert außerhalb geht aufs nächste Ende, ein ausgeschaltetes Licht wird eingeschaltet. Farbe geht
  als Ganzzahl (`color`, Feld `color` mit fester Liste `DeviceIndex::COLORS`), Farbe als Text noch nicht:
  die Doku beschreibt das Textformat nicht (Doku-Feedback 10.10.2026), und das HomeAssistant-Modul
  schreibt RGB als `{"r":…}`, xy und HS aber als Liste. Die Abfrage nennt die nächstliegende Farbe.
  Ein Licht ohne Schaltzustand gilt als an, solange die Farbe nicht schwarz ist; `off` setzt sie auf 0,
  und zwar erst nach der bestätigten Stufe (IPS-868 schreibt bei jedem Weißbefehl den alten Farbwert mit;
  `FakeBackend` bildet das über `schreibtAltwert` nach).
- **Luftbe-/entfeuchter** (Typ `humidifier`): `state` (Ein/Aus), `humidity` (Ist-Feuchte), `setpoint`
  (Zielfeuchte, Feld `humidity` von `set_state` über `runSetpoint`, Einheit ohne Angabe %). Gemessen am
  Luftentfeuchter über das HomeAssistant-Modul: Die Darstellung nennt 35 bis 85 in Einerschritten, das Gerät
  nimmt aber nur Fünferschritte; 56 kam nie an, die Antwort sagt dann „noch nicht bestätigt“. 60 brauchte
  1,7 bis 2,3 s. Modus und Lüfterstufe folgen als Auswahl per Name.
- **Mediaplayer** (Typ `media`): Fähigkeiten `state` (Ein/Aus), `playback`, `volume`,
  `mute`, `title`, `artist`, wie sie Symcon in der Darstellung „Media Player“ zusammenfasst. Die Werte der
  Wiedergabe hängen am Profil (`DeviceIndex::PLAYBACK_CODES`, an der Anlage gelesen): `~Playback…` zählt
  0 Zurück, 1 Stop, 2 Play, 3 Pause, 4 Weiter, der eingebaute Media Player (`~PlayerStatus`) 1 Play, 2 Pause,
  3 Stop. Das HomeAssistant-Modul hat nur eine Befehlsvariable (Wert -1); als `state` darf dort sein
  Statustext stehen (`MEDIA_STATES`: playing, paused, idle, buffering, standby, on, off), die Phase kommt
  dann daraus (`playbackPhase`). Aktiv ist, was spielt oder an ist ohne Wiedergabezustand.
  `play`, `pause`, `stop`, `next`, `previous` (`runPlayback`): gesendet wird der Wert aus dem Profil, ein
  fremdes Profil wird abgewiesen, ein Player in der Zielphase bekommt nichts. Play/Pause/Stop sind bestätigt,
  wenn die Phase stimmt, Weiter/Zurück, wenn sich Wiedergabewert, Titel oder Interpret ändern. Das
  Sonos-Modul meldet „Weiter“ teils nach 0,2 s (Status -1), teils erst nach 4,5 s mit dem neuen Titel;
  über das HomeAssistant-Modul kam „paused“ in 3 s nie. Dann bleibt es bei „noch nicht bestätigt“, die
  Wartezeit bleibt 2 s. `stop` teilt sich in Rollläden (`runStop`) und Mediaplayer.
  `volume` (Prozent), `volume_change` (Punkte, gekappt bei 0 und 100) und `mute` stehen je allein
  (`runVolume`, `runMute`, gemeinsames Warten in `confirmJobs`). Die Lautstärke zählt in Prozent des
  Bereichs: Sonos-Modul `~Volume` 0 bis 100 (Ganzzahl), HomeAssistant-Modul Kommazahl 0 bis 1 aus der
  Darstellung (ein Befehl 25 wird dort 1 = 100 %, gemessen). Gesendet wird auf ganze Prozent gerundet.
  Mit `volume_change` ist `set_state` nicht mehr idempotent (`idempotentHint`). `play` ohne Raum,
  Etage und Name (Typ höchstens `media`) setzt fort, was in der letzten Stunde per Werkzeug pausiert
  wurde (`recentlyPaused`, Merker über `DeviceBackend::rememberPaused`, im Modul Buffer `Paused`).
  Noch offen: Ein/Aus; das Sonos-Modul hat an `Volume` teils keine Aktion (dann „nicht stellbar“).
- **Ein neues Feld** von `set_state` kostet: eine Fähigkeit in `ROLES`/`TYPE_ROLES`, einen Eintrag in
  `abilitiesOf()`, einen Zweig in `setState()`, eine Eigenschaft im Schema und ein Dialogfeld.

## Formular

- Bereiche „Endpunkt und Zugänge“, „Räume“, „Geräte“, „Szenen“ und „Energiebilanz“ als
  `ExpansionPanel`; der Zustand wird im Attribut `ExpandedPanels` gemerkt (`UITogglePanel`).
- Der Bearbeitungsdialog einer Geräte-Zeile entsteht je Typ über `UIDeviceForm`; sichtbare Spalten sind
  Textspalten mit dem Namen der Variable, die IDs stehen in unsichtbaren Spalten.
- „Geräte aus Alexa vorschlagen“ öffnet ein `PopupAlert` mit Haken und bearbeitbarem Raum je Vorschlag;
  „Ausgewählte freigeben“ speichert direkt. Ein Knopf in `popup.buttons` zeigt jede Ausgabe rot im
  Popup, außer sie beginnt mit `MESSAGE:`.
- Fehlerhafte Zeilen sind rot (`rowColor`), die Meldungen stehen oben im Formular.

## Entwickeln und Testen

- **Tests zuerst, mit Rot/Grün-Nachweis.** Jeder Fix und jede Funktion bekommt Prüfungen, die vor dem
  Code scheitern und danach bestehen; beide Zahlen gehören in die Commit-Message. Alte Erwartungen, die
  sich ändern, schon im roten Lauf anpassen.
- **Fixtures aus echten Mitschnitten**, nie nachgebaut (`tests/fixtures/`). Ist ein Fall an keiner Anlage
  messbar, steht im Test, was echt und was angenommen ist.
- Tests: `php tests/check-*.php` (Protokoll, Zustand, Schalten, Szenen, Sollwert, Verbrauch, Leistung,
  Bilanz, Zeiträume, Alexa-Import, Modul am Kernel-Stub im Submodul `tests/stubs`) und
  `tests/check_locale.php`. Die CI (`.github/workflows/check.yml`) führt alle aus, dazu Stil und die
  MCP-Regeln (`bumaas/symcon-mcp-check`).
- Debug der Instanz: `Request`, `Tool` (Aufruf, Argumente, Antwort), `Hook` (abgewiesene Anfragen).
- Neue Attribute, Properties und Timer gibt es erst nach einem Neuladen der Bibliothek; der Code muss
  bis dahin ohne sie auskommen.

## Benennung

- PHP-Bezeichner englisch: öffentliche Modulfunktionen PascalCase, private Methoden camelCase.
  Kommentare und Testmeldungen deutsch.
- MCP-Schnittstelle englisch (`get_state`, `room`, `type`, Typwerte `light`, `window` …). Die Typwerte
  stehen auch in der Property `Devices`; wer sie umbenennt, bricht bestehende Instanzen.
- Antworttexte über `Translate()` mit englischen Schlüsseln in `locale.json`, auch die Texte der
  Bibliotheken. Raum- und Gerätenamen bleiben, wie der Anwender sie einträgt. Die Filterangabe in der
  Kopfzeile (`room=Küche`) bleibt unübersetzt.
- In der Anwenderdoku Home Assistant nur dort nennen, wo der Leser handeln muss (als Client), keine
  Vergleiche.

## Versionen

Version, Build und Datum stehen in der `library.json`. Jede inhaltliche Änderung zählt den Build hoch,
der Betreff lautet `<version> build <NN>: <kurze Beschreibung>`. `compatibility.version` ist 9.0 (seit build 53; erprobt unter 9.0 C++ und 9.1). Symcon 9.0 bringt PHP 8.5 mit, die CI prüft mit 8.5.
