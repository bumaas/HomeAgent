# HomeAgent

Ein MCP-Server als Symcon-Modul, über den KI-Assistenten das Haus abfragen und bedienen. Der Assistent
sieht nur die Geräte der Freigabeliste und wenige, einfache Werkzeuge, nicht die Verwaltung der Anlage.
Bis build 33 hieß das Modul „VoiceControl“ (Präfix `VCTL_`), seit build 34 „Home Agent“ (Präfix `HAGT_`, Hook
`/hook/homeagent/<InstanzID>`); die GUIDs sind gleich geblieben.

Diese Datei ist das Regelwerk für die Arbeit am Modul. **Anlass, Messungen, verworfene Wege und Review-Funde zu jeder
Regel stehen in `ENTWICKLUNG.md`**, gegliedert wie hier; dort nachlesen, bevor eine Regel geändert oder eine verworfene
Idee neu aufgegriffen wird. Gestaltung der MCP Apps: `DESIGN.md`. Messungen, Instanz-IDs und Adressen der Anlagen des
Entwicklers stehen in einer `CLAUDE.local.md`, die die `.gitignore` ausschließt.

**Schreibregel:** Ein neuer Eintrag hier hat höchstens drei Zeilen (Regel, Grund in einem Halbsatz, Test). Anlass,
Zahlen und Zitate gehören in die `ENTWICKLUNG.md`, Anlagen, Personen und Netze in die `CLAUDE.local.md`. Keine Ortsnamen
betreuter Anlagen im Repo.

## Leitbild

Das Modul soll für den Anwender konsistenter, schneller, kompakter und günstiger sein als die
Sprachsteuerung von Home Assistant, ohne kleineren Umfang. Daraus folgen die Grundsätze:
- **Das Werkzeug zählt, nicht das Modell.** Die erste Zeile jeder Antwort nennt die Anzahl.
- **Gemeldet wird nur Belegtes.** Ein angenommener Befehl ist kein Beleg; erst die Variable bestätigt.
- **Vokabular statt Inventar.** Räume, Typen und Szenen stehen in Beschreibungen, nicht jedes Gerät.
- **Angeboten wird nur, was es gibt.** Das Schema von `set_state` nennt nur Befehle und Felder, die
  mindestens ein freigegebenes Gerät versteht.
- **Schnell vor günstig.** Weniger Runden im Sprachmodell gehen vor, danach so wenig Tokens wie möglich in
  Werkzeugliste und Antworten.

## Aufbau

| Datei | Aufgabe |
|---|---|
| `libs/McpProtocol.php` | zustandsloser MCP-Server über Streamable HTTP (JSON-RPC 2.0), beide Protokollgenerationen; Zugangswahl (`findAccess`), Token aus dem Pfad, `clientLabel`, `toolsFingerprint` |
| `libs/OAuthServer.php` | OAuth: reservierte Unterpfade, Anmeldeaufforderung im 401, Metadaten, Kopplung, Codes, Tokens, CIMD |
| `libs/SignInGuard.php` | Bremse gegen Durchprobieren von Tokens, je Absender und gesamt |
| `libs/RoomIndex.php` | Raumliste mit Aliasen und Etage; Raumerkennung für den Alexa-Vorschlag |
| `libs/DeviceIndex.php` | Freigabeliste: Fähigkeiten (`ROLES`, `TYPE_ROLES`), Suche, Zustandstexte, Schalten und Stellen, Verbrauch, Leistung, Bilanz und Quellen, Zähler, Verlauf, Zeitleiste, alle Diagramme, Karten, Selbsttest-Prüfungen |
| `libs/SceneIndex.php` | Szenenliste: Suche und Start |
| `libs/PromptCatalog.php` | Vorlagen („Haus verlassen“, „Gute Nacht“, „Energiebericht“) |
| `libs/AlexaImport.php` | Vorschlag der Freigabeliste und der Räume aus der Alexa-Instanz von Symcon |
| `libs/DeviceBackend.php` | Schnittstelle zur Anlage; `SymconDeviceBackend.php` über Symcon-Funktionen, `tests/FakeBackend.php` spielt Mitschnitte ab |
| `HomeAgent/module.php` | Hook per `RegisterHook`, Status, Werkzeugliste, Formular, Protokoll, OAuth-Speicher |
| `HomeAgent/chart.html`, `devices.html` | MCP-App-Seiten für Diagramme und Gerätekarten, ohne Bibliotheken |
| `tests/check-*.php`, `tests/fixtures/` | Tests je Thema mit Mitschnitten echter Anlagen; `tests/stubs` ist der Kernel-Stub (Submodul) |

Alle Bibliotheken unter `libs/` kommen ohne Symcon aus; Werte, Aktionen und Übersetzungen liefert das
Backend. So laufen die Tests mit echten Mitschnitten.

## Protokoll

- Nur POST, Antwort `application/json`, GET 405, kein SSE, keine Sitzung, Benachrichtigungen 202.
- Eingaben begrenzt: Körper erst nach der Anmeldung lesen, Stapel höchstens 50 Nachrichten und ein `tools/call`,
  Argumente gegen das `inputSchema` prüfen.
- **2026-07-28 neben 2025-11-25** („dual-era“): Eine Anfrage mit `params._meta['io.modelcontextprotocol/protocolVersion']`
  geht nach `McpProtocol::handleModern` (kein `initialize`, Kopfzeilen `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`
  müssen zum Körper passen: 400/-32020; unbekannte Version 400/-32022; unbekannte Methode 404; kein Stapel;
  `server/discover`, `resultType: complete`, Serverinfo in `_meta`). `tools/list`, `server/discover`, `resources/*`
  tragen `ttlMs` 300000 und `cacheScope: private` (Pflicht, sonst meldet claude.ai „nicht erreichbar“). Das Modul reicht
  `$_SERVER['HTTP_MCP_*']` durch (`mcpHeaders`). Test `check-protokoll-2026.php`.
- **Origin-Prüfung** (DNS-Rebinding): Eine `Origin` muss ohne Port auf einen eigenen Namen zeigen (`originAllowed`:
  IPs aus `Sys_GetNetworkInfo`, localhost, Rechnername auch mit `LOCAL_SUFFIXES`, Connect-Adresse; nie der Host der
  Anfrage), sonst 403 vor der Anmeldung, kein Fehlversuch; OAuth-Pfade ausgenommen.
- Resources und Prompts gibt es nur, wenn der Aufrufer `$readResource` bzw. `$prompts` übergibt, sonst -32601.

## Anmeldung

- `Authorization: Bearer <Token>`, je Assistent ein Zugang (`Accesses`: Name, Token, Standardraum, „Nur abfragen“,
  „Token auch in der Adresse“). Mit `InPath` auch `/hook/homeagent/<InstanzID>/<Token>`. Ein Header entscheidet allein.
- **Die Adresse geht nie ins Debug oder Log**, sie kann das Token tragen. Tokens unter 24 Zeichen sind ein Fehler der
  Zeile.
- **`SignInGuard`:** nach 20 abgewiesenen Anmeldungen in einer Minute 60 s zu für diesen Absender, nach 100 von allen
  zusammen für alle. Über Connect zählt der letzte Eintrag aus `X-Forwarded-For`, nur bei 127.0.0.1 oder ::1.
  Als Fehlversuch zählen nur ein ungültiges Token (auch in der Adresse), ein falscher Kopplungscode, ein ungültiger Code
  und ein ungültiges Erneuerungstoken, eine nicht lesbare `client_id`; keine Anmeldung oder ein bekanntes, abgelaufenes Token zählen nicht.
- **Ein fehlerhafter Eintrag sperrt nur sich selbst**: Zugang (`usableAccesses()`), Gerät (`usableDevices()`), Szene
  (`usableScenes()`), Bilanz als Ganzes (`usableBalance()`). Ein unbekannter Standardraum sperrt nichts, er wirkt nur nicht
  (`defaultRoomOf`, `unknownDefaultRooms`, Hinweis in Formular und Selbsttest). Geprüft bei jedem Aufruf, aber nur einmal je Anfrage
  (`checkedOnce` in Hook, Selbsttest und Formular), Beschriftungen nur für fehlerhafte Zeilen (Laufzeit). Status 104
  ohne Zugang mit Token, 201 bei Fehlern in der Raumliste oder ohne fehlerfreien Zugang (`endpointClosed()`); beides
  antwortet 503, nicht 401 (HA beginnt bei 401 die OAuth-Suche).
- **Referenzen:** `registerReferences()` in `ApplyChanges` (erst bei `KR_READY`, sonst über `MessageSink`) meldet jede
  Variable der Geräte und der Bilanz und jedes Szenenskript an.
- **OAuth** (angeboten, sobald Connect aktiv ist): Autorisierungsserver und Ressource sind der Endpunkt selbst, Issuer
  unter dem Hook, Metadaten unter `<Endpunkt>/.well-known/openid-configuration`, Basis `CC_GetConnectURL`. Ohne aktives
  Connect gibt es die reservierten Pfade nicht (404); sie gelten nie als Token in der Adresse. Ein GET auf
  `oauth/authorize` verbraucht nichts (kann zweimal kommen).
  - Clients melden sich per CIMD; `fetchClientDocument` nur HTTPS auf 443, 5 KB, 5 s, keine Weiterleitung, nur global
    erreichbare Adressen (`CURLOPT_PREREQFUNCTION`, NAT64 ausgeschlossen), Fehlschlag 60 s gemerkt, Dokument 1 h im
    Buffer `OAuthClients`. Höchstens `CLIENT_DOCUMENT_FETCHES` Abrufe je Minute; eine angegebene, nicht lesbare
    `client_id` ist ein Fehlversuch (Abruf ohne Anmeldung belegt einen Thread). Tests `check-module.php`, `check-oauth.php`.
  - Kopplung mit Kopplungscode je Zugang (`HAGT_CreatePairingCode`, 6 Ziffern, 10 min, einmal, nach 5 Fehleingaben
    verfallen); der Client erbt den Zugang, gemerkt als SHA-256 seines Tokens. Neues Token trennt die Kopplungen.
  - Codes und Tokens nur als Hash in Buffern (`OAuthPairings`, `OAuthCodes`, `OAuthTokens`), Kopplungen im Attribut
    `OAuthGrants`. Speicher ändert nur `changeStored` unter einer Sperre, ohne Sperre 503.
  - Code einmal einlösbar (verbraucht auch bei falschem `code_verifier`). Zugriffstoken 1 h, mit `offline_access`
    Erneuerungstoken: rotieren, 90 Tage ab letzter Erneuerung, ein verbrauchtes widerruft die Familie. Ausnahme ist das
    unmittelbar vorige (`previous`/`pending`/`since`, höchstens `PREVIOUS_SECONDS` = 7 Tage ab der ersten unbestätigten
    Erneuerung, `confirm` beim ersten Zugriff). Test `check-oauth-verloren.php`. Abgelaufene Zugriffstokens bleiben
    `EXPIRED_KEEP_SECONDS` (1 Tag) bekannt.
  - Am Hook: ein Bearer-Token, das kein festes ist, ist ein OAuth-Zugriffstoken (`oauthAccess`, nur im Header).
    Ungültig mit Token → 401 mit `error="invalid_token"`. Erneuert wird nur für einen benutzbaren Zugang.
  - Fehler vor geprüfter Rücksprungadresse ergeben eine Fehlerseite, danach eine Weiterleitung mit `error`, `state`,
    `iss`. Körper an `oauth/*` höchstens 8 KB (413). Höchstens 50 Kopplungen. `lastUse` je Familie (`markUsed`,
    höchstens alle `USE_STEP` = 600 s geschrieben), Liste nach letzter Nutzung.
  - Kein automatisches Ersetzen gleicher Clients am selben Zugang (zwei Konten an einem Zugang würden sich abmelden).

## Werkzeuge

| Werkzeug | Zweck | Sichtbar |
|---|---|---|
| `get_state` | Zustand, Verbrauch, Leistung, Verlauf, Zeitleiste; Filter `room`, `floor`, `type`, `name`, `active_only`, `period`/`from`/`to` | immer |
| `set_state` | schalten und stellen (`command`, `percent`, `temperature`, `humidity`, `mode`, `fan_speed`, `color_temperature`, `color`, `volume`, `volume_change`, `mute`) | ohne „Nur abfragen“ |
| `run_scene` | freigegebenes Skript oder Ablaufplan starten (`IPS_RunScript`, Ablaufplan braucht eine Aktion); Szenennamen in der Beschreibung | mit Szenen |
| `show_chart` | Diagramm als MCP App | Clients mit Apps, Clients von Anthropic immer |
| `show_devices` | Gerätekarten als MCP App | ebenso |
| `control_device` | Bedienung von der Karte (`_meta.ui.visibility: ["app"]`) | ohne „Nur abfragen“, aufrufbar auch ohne Apps |

Alle tragen `openWorldHint: false`. Regeln, die nicht offensichtlich sind:
- **Aktionen laufen in einem eigenen Skript** (`IPS_RunScriptTextEx`), nie im Thread der Anfrage (manche Aktoren
  blockieren `RequestAction` bis zum Ziel). Fehlschläge meldet das Skript per `IPS_RequestAction(<Instanz>, 'ActionFailed', …)`.
- **Rückmeldung:** erst alle Aktionen auslösen, dann gemeinsam bis 2 s auf die Variablen warten. Rollläden gelten als
  „fährt“, sobald Position, Fahrstatus oder ein Lebenszeichen es zeigen; das Ziel merkt sich der Buffer `Travels`.
- **`set_state`** ohne Raum, Etage, Typ oder Name tut nichts; ein Name muss genau ein Gerät treffen, sonst
  Kandidatenliste. Widersprüche werden abgewiesen, Geräte, die den Befehl nicht verstehen, ausgelassen und gezählt.
- **Namenssuche** in Stufen, nur die beste zählt: genauer Name oder Alias, Anfrage als Teil des Namens, Name in der
  Anfrage nur an einem Wortanfang. Genannte Geräte gehören in `name`, nicht in `type` (steht in der Beschreibung).
- **Vokabular:** Die Beschreibung von `room` nennt die bekannten Räume, die von `mode`/`fan_speed` die Einträge der
  freigegebenen Geräte (kein `enum`). Mit Apps verweist `get_state` fürs Zeigen auf `show_devices` und für Diagramme
  (auch Heatmap, Summenkurve, Punktwolke beim Namen) auf `show_chart`; für Leistungsverläufe nennt es `type=power`.
- **Nur-Lese-Zugang:** `instructions` und die Beschreibung von `get_state` sagen es, damit der Assistent eine Absage
  richtig begründet.
- **Datum und Uhrzeit** hängt `withNow()` in `module.php` an jede Antwort von `get_state`, nicht an Anleitung oder
  Werkzeugliste (Clients speichern beides lange).
- **Hinweistext für Skripte und KI-Assistenten** (Label im Formular): genügt allein zum Einrichten; neue Felder und
  Typen gehören auch dorthin, Funktionen immer mit voller Signatur (Symcon verlangt jeden Parameter). Der Auftrag in
  `EINRICHTUNG.md`/`.en.md` baut darauf auf; was der Assistent suchen oder auslassen soll, gehört in beide Fassungen.
  Was der Anwender vor dem Absenden ändert, steht als Zeile ganz oben (`ZUGANGSDATEI`), alles andere erfragt der
  Agent; keine Platzhalter in Klammern für den Anwender (schwer zu finden). Test `check-module.php`.
- **Ein neues Feld** von `set_state` kostet eine Fähigkeit in `ROLES`/`TYPE_ROLES`, einen Eintrag in `abilitiesOf()`,
  einen Zweig in `setState()`, eine Eigenschaft im Schema, ein Dialogfeld und, falls auf der Karte, `CARD_FIELDS`.

## Geräte und Typen

- **Melder mit einer Zahl** (Fenster, Tür, Präsenz, Wasser): aktiv über 0, mit „Umgekehrt“ nur bei 0 (`activeValue`,
  auch in der Zeitleiste). Einträge der Variable in Klammern, außer sie sind nur ein anderes Wort (`PLAIN_CAPTIONS`).
  Test `check-ganzzahl-melder.php`.
- **Richtung eines Rollladens:** Darstellung „Rollladen“, sonst Profil mit `.Reversed`, sonst Modul des Geräts
  (`OPEN_AT_MAX_MODULES`), sonst kleinster Wert offen; „Richtung umkehren“ dreht es. `percent` ist der Öffnungsgrad
  (0 zu, 100 offen). **Stopp** über Stoppvariable (Wahrheitswert oder Eintrag „Stop“), sonst `STOP_MODULES`.
- **Fenster mit Antrieb** tragen in `get_state` „with drive“ (nicht bei „Nur abfragen“), bei gemischten Treffern zählt
  eine Schlusszeile sie.
- **Licht:** Prozent rechnet das Modul selbst. Farbtemperatur in Kelvin, ein Bereich bis 1000 ist Mired; Schrittweite
  der Darstellung zählt nicht, außerhalb aufs nächste Ende, ein ausgeschaltetes Licht wird eingeschaltet. Farbe nur als
  Ganzzahl aus `COLORS` (Farbe als Text ist nicht dokumentiert). Ein Licht ohne Schaltzustand ist an, solange die Farbe
  nicht schwarz ist; `off` setzt sie erst nach bestätigter Stufe auf 0. **Farbmodus** aus einer Variable mit Ident auf
  `color_mode` (`colorModeKind`: Zigbee2MQTT, HomeAssistant-Modul, Matter 0/1 Farbe, 2 Farbtemperatur); dann nennen
  Zustand und Karte nur den gültigen Wert. Test `check-farbmodus.php`.
- **Sollwert:** Bereich und Schrittweite aus Darstellung oder Profil. Sonderwerte mit Text am Rand zählen nicht dazu
  (nicht ein Eintrag ohne Text oder mit Formatnamen wie „%d“). Ohne Schrittweite gilt der kleinste Abstand dichter
  Einträge (`associationStep`: mindestens drei Abstände, alle Vielfache des kleinsten, höchstens 1), sonst 0 („ohne feste
  Schrittweite“). Batteriegeräte melden den Sollwert oft vor der Übernahme. Ein Sonderwert nennt den Text seines Eintrags
  (`specialCaption`), eine Bedeutung erfindet das Modul nicht. Test `check-sollwert.php`.
- **Luftbe-/entfeuchter** (`humidifier`): `state`, `humidity`, `setpoint` (Feld `humidity`, `runSetpoint`), `mode` und
  `fan` als **Auswahl per Name** (`runOption` über `DeviceBackend::options()`). **Lüftung** (`fan`) ebenso, Pflicht
  Ein/Aus oder Lüfterstufe. Tests `check-entfeuchter.php`, `check-lueftung.php`.
- **Klimagerät** (`thermostat` mit `mode`/`fan`): Ein/Aus nur über den Modus, `off` setzt den Eintrag `off`, `on` gibt es
  nicht (Heizen, Kühlen, Auto wären möglich), deshalb kein Schalter auf der Karte. Homematic-Wandthermostate gehen so
  nicht (keine Aktion an `CONTROL_MODE`). Test `check-klima.php`.
- **Leistung und Zähler** wahlweise an Thermostat und Mediaplayer; die Karte behält ihren Hauptwert, die Leistung ist
  nur Zustand, wenn das Gerät sonst nichts hat. Test `check-leistung-typen.php`.
- **Mediaplayer** (`media`): `state`, `playback`, `volume`, `mute`, `title`, `artist` wie die Darstellung „Media
  Player“. Wiedergabewerte je Profil (`PLAYBACK_CODES`), Statustext des HomeAssistant-Moduls über `MEDIA_STATES` und
  `playbackPhase`. `play`, `pause`, `stop`, `next`, `previous` (`runPlayback`): fremdes Profil abgewiesen, Player in der
  Zielphase bekommt nichts; Weiter/Zurück bestätigt durch Änderung von Wert, Titel oder Interpret. Lautstärke in Prozent
  des Bereichs, gesendet im Raster der Variable (`range()['step']`), andere Einheit in der Abfrage dabei. `volume` oder
  `volume_change` dürfen mit `play`/`pause`/`stop` kommen (`combineResults`). `play` ohne Raum setzt fort, was in der
  letzten Stunde per Werkzeug pausiert wurde (Buffer `Paused`). Ein/Aus über einen schaltbaren Wahrheitswert; ohne ihn
  pausiert `off` und `on` mit `type=media` setzt fort (`pausesOnOff`), ohne `type=media` lässt `on` solche Player aus.
  Tests `check-medien.php`, `check-wiedergabe.php`, `check-lautstaerke*.php`, `check-musik-aus.php`.

## Energie

- **Jede Variable zählt einmal:** dieselbe Leistung oder derselbe Zähler an zwei Geräten zählt beim ersten
  (`firstPerVariable`); der Selbsttest nennt solche Variablen.
- **Bilanz** (Bezug + PV + Batterie entladen − Einspeisung − Batterie geladen) aus der Liste **`Sources`** (Type
  grid/solar/battery, Name, EnergyIn, EnergyOut, PowerIn, PowerOut, Invert; `addSources`, `SOURCE_KEYS`). Die alten
  Properties (`BALANCE`, `BALANCE_POWER`, `InvertGridPower`, `InvertBatteryPower`) bleiben registriert und werden in
  `ApplyChanges` per `legacySources()` in die Liste übernommen. PowerIn ohne PowerOut ist ein Sensor mit Vorzeichen wie
  bei HA (negativ = Einspeisung bzw. laden), `Invert` dreht es (`signedOf`). Fehler (`sourceProblems`: unknown_type,
  empty, solar_out, duplicate, fehlende Variable) sperren die ganze Bilanz. Mehrere Variablen je Teil: `partText` nennt
  sie. Tests `check-quellen.php`, `check-vorzeichen.php`, `check-module.php`.
- **Zähler mit „gemessen von“** (`meter`, Rollen `energy` und `power`): zählt nie in einer Gerätesumme, eigene Zeile
  (`meterLine`). `MeteredBy` verweist auf den Zähler darüber; `meterTree` baut den Baum, Rest „nicht erfasst“ nur, wenn
  positiv; fürs Haus zählt die oberste Ebene (`topLevelLine`). Verweis ins Leere und Kreis sperren die Zeile
  (`meterProblems`), der Selbsttest meldet über 105 % (`meterOverruns`). Nur Typen mit Energie oder Leistung haben das
  Feld. Erzeugung gehört in die Bilanz, ein `meter` misst nur Verbrauch. Test `check-zaehler.php`.
- **Zeitpunkte der Aggregate** setzt `sectionStart` auf den Beginn des Abschnitts (das erste Aggregat trägt den ersten
  Satz). Reihen deshalb nur über `counterRows` und `historyRows` lesen, nie direkt über das Backend. Die Stunde mit
  `date('Z')`, nicht `mktime()` (doppelte 02:00). Test `check-zeitumstellung.php`.
- **Wärmepumpe** (`appliance` mit `heat`/`heatpower`): nie in einer Stromsumme. Arbeitszahl = Wärme ÷ Strom des Zeitraums
  (`heatText`), nie als Mittel der Leistungszahl. Ohne Wärmezähler Wärme aus der Wärmeleistung (`heatOf`,
  `powerKilowattHours`), mit Leistung am Gerät auch der Strom daraus, als eigener Block in der Zeile; der Verbrauch bleibt
  der Zähler. Noch nicht: Diagramme, `type=power`. Test `check-waermepumpe.php`.
- **Kosten** (`PriceVariableID` in ct/kWh oder €/kWh, Werte ohne Suffix über 2 sind Cent; `FeedInTariff`, `setTariff`;
  nur mit Bilanz, nur fürs Haus, ohne Grundgebühr): `hourlyCosts` je Stunde, `costLine` in `get_state`, `costs=true` in
  `show_chart`. Test `check-kosten.php`.
- **Plausibilität der Zähler im Selbsttest** (`counterJumps`): erst die Gesamtprobe, dann je Monat Summe gegen Zuwachs des
  Zählerstands (`counterReadings`); die Schwellen (2 kWh, ein Fünftel des Medians, drei Viertel, zwei Mediane,
  `JUMP_KWH`, `JUMP_ABOVE`) sind an echten Fällen gemessen. Stand nach jetzt = Wert der Variable, wenn im letzten Tag
  aktualisiert (`DeviceBackend::LATEST`, `FRESH_SECONDS`). Test `check-zaehlerstand.php`.

## Verlauf und Diagramme

- **Verbrauch oder Verlauf:** `period`/`from`/`to` liefern Verbrauch aus Aggregaten, `period=now` die Leistung, fürs
  Haus aus der Bilanz (auch ohne Geräte, `houseOnly`). Verlauf (`history`) bei Typen aus `HISTORY_TYPES` oder ohne
  Zähler, nie fürs Haus; Stufe aus dem Zeitraum, höchstens `HISTORY_MAX`, jede Variable einmal. Min/Max mit Zeitpunkt
  aus `MinTime`/`MaxTime`, kein Mittel.
- **Uhrzeit in `from`/`to`** (`parseMoment`): ganze Stunden, `from` ab-, `to` aufgerundet, ohne `to` bis jetzt, höchstens
  48 Stunden. Test `check-stunden.php`.
- **`type=power`** (`POWER_TYPE`, kein Gerätetyp): Leistung jetzt oder Verlauf der Leistung (`powerHistory`, bis
  `POWER_HISTORY_MAX`, Zähler vor den Abfragen). Fürs Haus Stundenmittel (`housePowerRows`, laufende Stunde und Stunden
  mit negativem Hausverbrauch fehlen und werden genannt, `inconsistentLine`, `INCONSISTENT_DAYS`), höchstens
  `HOUSE_POWER_DAYS` (400). Momentanwerte als solche gekennzeichnet. 15-Minuten-Mittel zurückgestellt (zu langsam).
  Test `check-leistungsverlauf.php`.
- **Zeitleiste** für Zustände (`hasTimeline`, `stateSeries`, `allRows` holt in Scheiben): gleiche Zustände zusammengefasst,
  Summen je Zustand, Einzelabschnitte bis `TIMELINE_SECTIONS`/`TIMELINE_DETAIL`. Gerät mit Zähler: Verbrauch und Zeiten in
  einer Zeile. In `show_chart` als Bänder (`chartBands`, höchstens `TIMELINE_CHART_MAX`), feste Reihenfolge der Zustände.
  Test `check-zeitleiste.php`.
- **`show_chart`** (`DeviceIndex::chart`): nur mit Gerät mit Verlauf oder mit Bilanz. Werte in `structuredContent`, das
  Modell bekommt nur Kopfzeile und Kennzahlen. `resourceUri` `ui://homeagent/chart/<Prüfsumme der Seite>` (claude.ai
  speichert Seiten lange), `readResource` liefert auch zu alten Prüfsummen die heutige Seite. Jede Art liefert `range`
  und `timeZone`; `chart.html` rechnet nur in UTC-Methoden auf Wandzeit (`wall`/`unwall`). Arten:
  - Verlauf, mehrere Räume mit Komma (höchstens `HISTORY_MAX` Reihen); Band nur, wenn jedes Diagramm eine Reihe hat.
  - Hausverbrauch als Stapel (`houseChart`, PV direkt aufgeteilt nach Anlagen, `SOURCE_SHADES`), Ringe Autarkie und
    Eigenverbrauch (`shareRings`, ohne Batterie ohne ihre Zeile), `period=now` als Leistungsringe (`powerRings`).
  - Verbrauch je Gerät (`deviceChart`, `type=energy`, `CHART_DEVICES` größte, Rest und „unmetered“; ein Zähler zeigt
    seine Kinder).
  - Vergleich (`compare`: `previous`, `year_before`; `compareWindow`, `compareChart` verschiebt je Kalenderschritt, nicht
    nach Position oder Sekunden; bis zur selben Zeit anteilig). Test `check-vergleich.php`.
  - Punktwolke (`versus`, `scatter`, `scatterModel` linear oder mit Knick, kein quadratisches Modell). Test
    `check-punktwolke.php`.
  - Heatmap (`heatmap=true`, bis 62 Tage je Tag, sonst Wochentage, Farbskala bis zum 95. Perzentil). Test
    `check-heatmap.php`.
  - Summenkurve (`cumulative=true`, `cumulativeOf`, Hochrechnung nach dem Vorjahr bei 80 % Abdeckung, sonst linear).
    Test `check-summenkurve.php`.
  - Teil der Bilanz (`part`, `PARTS`, eine Reihe je Quelle; `partError`, wenn `name` einen Teil meint). Test
    `check-teil.php`.
- **`show_devices`** (`DeviceIndex::cards`, höchstens `CARDS_MAX`): Zeilen von `get_state` und je Gerät eine Karte
  (`cardOf`: `group`, `figure`, `status`, `active`, `controls`). Ziele von „−“/„+“ rechnet das Modul (`cardSetpoint`,
  Sonderwerte zählen vom nächsten Rand). `control_device` mit `cardKey` geht über `setState` mit `onlyKey`; zurück kommt
  die neue Karte und `problem`. Nur der Schlüssel liefert die Karte neu (Nachführen bei `moving` oder `not_confirmed`,
  alle 3 s, höchstens 90 s). Eine Antwort zeichnet nur ihre Karte neu, eine bediente Karte wartet. Das Schema von
  `control_device` nennt jedes Feld der Karte. Test `check-karten.php`.
- **Vorlagen** (`PromptCatalog`): „Haus verlassen“, „Gute Nacht“, „Energiebericht“ je nach Freigabeliste; Nur-Lese ohne
  Frage nach dem Schalten. Test `check-prompts.php`.
- **Gestaltung:** `DESIGN.md` ist das Regelwerk für Karten und Diagramme. Jede Änderung an `devices.html` oder
  `chart.html` hält sich daran oder ändert zuerst das Regelwerk. Vor dem Commit hell und dunkel mit echten Daten rendern
  (`dev/` ist nicht im Repo) und Abweichungen unter „Bekannte Abweichungen“ eintragen; Tooltips über
  `--headless=new --dump-dom`. `check-module.php` verbietet jedes Nachladen; die Größengrenze 100 KB bremst nur
  eingebettete Bibliotheken.

## Formular und Diagnose

- Bereiche als `ExpansionPanel` (Zustand im Attribut `ExpandedPanels`, `UITogglePanel`, Namen in `PANELS`), in
  „Endpunkt und Zugänge“ die Unterbereiche `PanelClaude`, `PanelHA`, `PanelOther`.
- **Zugänge für Einsteiger:** sichtbar Name, Nur abfragen, Standardraum und `Connection` (`connectionOf`), Token und
  `InPath` unsichtbar. Ein Zugang ohne Token bekommt in `ApplyChanges` eines (64 Hex), danach Übernehmen über einen
  einmaligen Timer (Selbstaufruf lehnt Rust ab). **Neues Token** leert nur. Sichtbar wird ein Token nur auf Knopfdruck
  (`UIShowToken`, `UIShowAddress`, Fenster `SecretPopup`), bei OAuth nie. Claude-App: Kopplungscode mit `PairingAccess`,
  Liste `OAuthClients` mit „✖ widerrufen“, `ClaudeNoConnect`/`ClaudeConnectDown`. Andere Assistenten: `localEndpoint`
  (erste private IPv4, Port 3777), `HAGT_GetAddresses` mit `<token>`.
- **Skripte lesen die Konfiguration nur über `HAGT_ExportConfiguration`** (Tokens als `<token>`, leeres bleibt leer) und
  legen Zugänge mit `HAGT_AddAccess` an; `IPS_GetProperty` gäbe Tokens aus, ein zurückgeschriebenes `<token>` ersetzte
  sie. Test `check-module.php`.
- **Jede Auswahl trägt einen Wert aus ihren Einträgen**, sonst lehnt die Konsole das Übernehmen ab; ohne Zugang „-“.
- **Gerätedialog** je Typ über `UIDeviceForm`; sichtbare Spalten sind Textspalten mit dem Variablennamen, IDs unsichtbar.
  Raum als Auswahl mit „-“ und der Raumliste; ein Wert, der nicht wörtlich darin steht, bekommt einen eigenen Eintrag
  (Alias bzw. „nicht in der Raumliste“). `missingRooms()` nennt fehlende Räume, gesperrt wird nichts. `MeteredBy` als
  Auswahl wie der Raum, `UIDeviceChanged` leert es beim Ausblenden. Dialog „Quellen“ über `UISourceForm`/
  `UISourceChanged`, Textspalten `EnergyLabel`/`PowerLabel` (`sourceLabels`).
- **Alexa-Vorschlag** im `PopupAlert` mit Haken und Raum je Vorschlag; Räume nur, wenn die Raumliste nichts weiß
  (`proposeRooms`, Buffer `AlexaRooms`). Ein Knopf in `popup.buttons` zeigt Ausgaben rot, außer mit `MESSAGE:`. Test
  `check-raumvorschlag.php`.
- Fehlerhafte Zeilen rot (`rowColor`), Meldungen oben im Formular. Spalte **„Werkzeugliste“** je Zugang
  (`ToolLists`, `toolListStatus`: aktuell, veraltet, noch nicht geholt).
- **Was nicht geklappt hat:** `problem()` in `DeviceIndex`/`SceneIndex` nennt die Art, `logged()` merkt Aufrufe mit
  Problem im Attribut `Problems` (50), Hinweise aus `PROBLEM_HINTS`. Kein Knopf „Als Alias übernehmen“.
- **Protokoll** (`LogEnabled`, aus; `LogLines` 5000): `writeLog()` unter Sperre nach `IPS_GetLogDir()/homeagent_<ID>.log`,
  Wechsel nach 24 h oder `LogLines` zur `.old.log`, Zustand im Buffer `LogState`; Anzeige und Download höchstens
  `LOG_TAIL_BYTES` (3 MB) je Datei. Client je Werkzeugaufruf (`callerOf`), auch vom Protokoll abgewiesene Aufrufe.
  Zeilen ohne Anmeldung über `writeAnonymousLog` gedrosselt (Buffer `LogQuiet`). Tokens, Adressen und Kopplungscodes
  stehen nie darin.
- **Selbsttest und Zustand** im Fenster `ResultPopup` (`UIShowSelfTest`, `UIShowState`), Geräte als „Name (Raum)“
  (`entryLabel`), Zahlen mit `localNumber` (Dezimalzeichen aus dem Schlüssel „.“), Anzahlen mit `countText`, Schlusszeile
  zur Bilanz (`balanceLine`). Test `check-module.php`.
- Debug der Instanz: `Request`, `Tool` (Aufruf, Argumente, Antwort), `Hook` (abgewiesene Anfragen).

## Entwickeln und Testen

- **Tests zuerst, mit Rot/Grün-Nachweis.** Jeder Fix und jede Funktion bekommt Prüfungen, die vor dem
  Code scheitern und danach bestehen; beide Zahlen gehören in die Commit-Message. Alte Erwartungen, die
  sich ändern, schon im roten Lauf anpassen.
- **Fixtures aus echten Mitschnitten**, nie nachgebaut (`tests/fixtures/`). Ist ein Fall an keiner Anlage
  messbar, steht im Test, was echt und was angenommen ist.
- Tests: `php tests/check-*.php` (ein Test je Thema, `check-module.php` prüft das Modul am Kernel-Stub im Submodul
  `tests/stubs`) und `tests/check_locale.php`. Die CI (`.github/workflows/check.yml`) führt alle aus, dazu Stil und die
  MCP-Regeln (`bumaas/symcon-mcp-check`). Platzhalter-IDs in Modultests ab 69990 (der Stub vergibt bis 59999).
- Neue Attribute, Properties und Timer gibt es erst nach einem Neuladen der Bibliothek; der Code muss
  bis dahin ohne sie auskommen.
- Buffer, die gleichzeitige Anfragen ändern, nur unter einer Sperre schreiben.

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
der Betreff lautet `<version> build <NN>: <kurze Beschreibung>`. `compatibility.version` ist 9.0 (erprobt unter 9.0
C++ und 9.1). Symcon 9.0 bringt PHP 8.5 mit, die CI prüft mit 8.5.
