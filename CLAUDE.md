# HomeAgent

Ein MCP-Server als Symcon-Modul, über den KI-Assistenten das Haus abfragen und bedienen. Der Assistent
sieht nur die Geräte der Freigabeliste und wenige, einfache Werkzeuge, nicht die Verwaltung der Anlage.
Bis build 33 hieß das Modul „VoiceControl“ (Präfix `VCTL_`), seit
build 34 „Home Agent“ (Präfix `HAGT_`, Hook `/hook/homeagent/<InstanzID>`); die GUIDs sind gleich geblieben.

Diese Datei ist die öffentliche Entwicklerhilfe. Messungen, Instanz-IDs und Adressen der Anlagen des
Entwicklers stehen in einer `CLAUDE.local.md`, die Claude Code ebenfalls liest und die `.gitignore`
ausschließt. Neue Notizen gehören dorthin, wenn sie eine Anlage, eine Person oder ein Netz betreffen,
sonst hierher.

## Leitbild

Das Modul soll für den Anwender konsistenter, schneller, kompakter und günstiger sein als die
Sprachsteuerung von Home Assistant, ohne kleineren Umfang. Daraus folgen die Grundsätze:
- **Das Werkzeug zählt, nicht das Modell.** Die erste Zeile jeder Antwort nennt die Anzahl.
- **Gemeldet wird nur Belegtes.** Ein angenommener Befehl ist kein Beleg; erst die Variable bestätigt.
- **Vokabular statt Inventar.** Räume, Typen und Szenen stehen in Beschreibungen, nicht jedes Gerät.
- **Angeboten wird nur, was es gibt.** Das Schema von `set_state` nennt nur Befehle und Felder, die
  mindestens ein freigegebenes Gerät versteht.
- **Schnell vor günstig.** Weniger Runden im Sprachmodell gehen vor, danach so wenig Tokens wie möglich in
  Werkzeugliste und Antworten (Burkhard 12.10.2026).

## Aufbau

| Datei | Aufgabe |
|---|---|
| `libs/McpProtocol.php` | zustandsloser MCP-Server über Streamable HTTP (JSON-RPC 2.0), ohne Symcon-Abhängigkeit; Zugangswahl (`findAccess`), Token aus dem Pfad |
| `libs/OAuthServer.php` | OAuth ohne Symcon-Abhängigkeit: reservierte Unterpfade, Anmeldeaufforderung im 401, Metadaten |
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
  ein Fehler der Zeile. Nach 20 abgewiesenen Anmeldungen in einer Minute ist der Hook für diesen Absender 60 s zu, nach 100 von allen
  zusammen für alle (`SignInGuard`, je Absender seit build 99, Backlog B60). Über Connect kommt alles von 127.0.0.1;
  `SignInGuard::sender()` nimmt dann den letzten Eintrag aus `X-Forwarded-For`, nur bei 127.0.0.1 oder ::1.
- **Ein fehlerhafter Eintrag sperrt nur sich selbst**: Zugang (`usableAccesses()`), Gerät
  (`usableDevices()`), Szene (`usableScenes()`), Energiebilanz als Ganzes (`usableBalance()`). Geprüft wird
  bei jedem Aufruf, eine gelöschte Variable sperrt ihr Gerät also sofort, ohne Übernehmen. Eine Anfrage
  prüft nur einmal (`checkedOnce` in Hook, Selbsttest und Formular; im Formular erst seit build 175, vorher prüfte die Spalte „Werkzeugliste“ je Zugang erneut, am nuc 523 bis 542 ms je Öffnen, danach 172 bis 260 ms), und Beschriftungen entstehen nur für fehlerhafte
  Zeilen: vorher übersetzte jede der rund zehn Prüfungen je Anfrage über 20 Texte je Gerät, `get_state`
  brauchte am nuc (152 Geräte) 4,5 bis 6,2 s, danach rund 45 ms (build 72). Warnung im Log,
  Überschrift im Formular „Fehlerhafte Einträge sind gesperrt“. Status 104 ohne Zugang mit Token, 201 bei
  Fehlern in der Raumliste oder ohne fehlerfreien Zugang (`endpointClosed()`); beides antwortet mit 503,
  nicht 401 (ein Client wie HA beginnt bei 401 die OAuth-Suche). Anlass: Im Blindtest vom 11.10.2026 zeigte
  das Formular Fehler gelöschter Variablen, der Status blieb 102.
- **Referenzen** wie BlindControl: `registerReferences()` in `ApplyChanges` (erst bei `KR_READY`, sonst über
  `MessageSink`) meldet jede Variable der Geräte und der Bilanz und jedes Szenenskript an, vorher fallen die
  alten weg. Dann nennt Symcon die Instanz beim Löschen einer Variable.
- Eingaben sind begrenzt: Körper erst nach der Anmeldung lesen, Stapel höchstens 50 Nachrichten und ein
  `tools/call`, Argumente gegen das `inputSchema` prüfen.
- **OAuth (angeboten, sobald Connect aktiv ist; den Schalter `OAuthEnabled` gibt es seit build 80 nicht mehr):** Autorisierungsserver und
  Ressource sind der Endpunkt selbst, der Issuer liegt unter dem Hook. Die Wurzel des Webservers
  (`/.well-known/…`) erreicht ein Modul nicht; Claude findet die Metadaten über
  `<Endpunkt>/.well-known/openid-configuration` (Mitschnitt 11.10.2026, `tests/fixtures/oauth_claude_2026-10-11.json`).
  Basis ist die Connect-Adresse (`CC_GetConnectURL`), nicht der Host der Anfrage; ohne aktives
  Connect gibt es die reservierten Pfade nicht (404). Sie gelten nie als Token in der
  Adresse und zählen nie als Fehlversuch. Über Connect kommt jede Anfrage von 127.0.0.1, der Absender
  steht nur in `X-Forwarded-For`. Die Anmeldeseite (`oauth/authorize`) kann zweimal kommen, der Service
  Worker der Kachel-Visu auf demselben Host ruft sie nach; ein GET darf nichts verbrauchen.
  Clients melden sich über ihr Client ID Metadata Document (CIMD, `client_id` ist eine HTTPS-Adresse):
  Das Modul holt es selbst (`fetchClientDocument`, nur HTTPS auf Port 443, 5 KB, 5 s, keine Weiterleitung;
  curl löst den Namen selbst auf, damit gilt das Zeitlimit auch für DNS, und `CURLOPT_PREREQFUNCTION` bricht vor der
  Anfrage ab, wenn die Adresse nicht öffentlich ist; ein nicht lesbares Dokument wird 60 s gemerkt, Code-Review 12.10.2026) und hält es eine Stunde im Buffer `OAuthClients`.
  Gekoppelt wird mit einem Kopplungscode je Zugang (`HAGT_CreatePairingCode`, 6 Ziffern, 10 min,
  einmal, nach 5 Fehleingaben verfallen, jede zählt auch im `SignInGuard`); der Client erbt den Zugang,
  gemerkt als SHA-256 seines Tokens. Wer das Token eines Zugangs ändert, trennt damit dessen
  OAuth-Clients. Kopplungs- und Autorisierungscodes liegen nur als Hash in den Buffern
  `OAuthPairings` und `OAuthCodes`. Fehler vor geprüfter Rücksprungadresse ergeben eine Fehlerseite
  (keine offene Weiterleitung), danach eine Weiterleitung mit `error`, `state` und `iss`.
  Der Token-Endpunkt (`oauth/token`, nur POST, Formular) löst den Code einmal ein (danach verbraucht,
  auch bei falschem `code_verifier`, sonst ließe sich der Prüfwert raten) und gibt ein Zugriffstoken für
  1 h aus, mit `offline_access` auch ein Erneuerungstoken. Erneuerungstokens rotieren und gelten 90 Tage
  ab der letzten Erneuerung; ein schon verbrauchtes widerruft die ganze Familie (Kopplung eines Clients
  mit einem Zugang, Attribut `OAuthGrants`, überlebt einen Neustart). Ausnahme ist das unmittelbar vorige
  Erneuerungstoken (`previous`), solange die letzte Erneuerung unbenutzt ist (`pending`, `OAuthServer::confirm` in
  `oauthAccess` löscht beides beim ersten Zugriffstoken ab dieser Erneuerung), höchstens `PREVIOUS_SECONDS` (7 Tage)
  ab der ersten unbestätigten Erneuerung (`since`; vorher ab der letzten, alle sechs Tage eingelöst galt es beliebig lange,
  Review 18.10.2026, N1):
  Es bekommt ein neues Paar, das nie zugestellte Token gilt als verbraucht und widerruft, falls es doch noch kommt.
  Anlass (betreute Anlage 16.10.2026, Fixture `oauth_betreut_2026-10-16_antwort_verloren.json`): Die Antwort auf eine
  Erneuerung kam wegen des Connect-Tageslimits nie an, claude.ai erneuerte 3 h 38 min später mit dem alten Token, und die
  Kopplung fiel. Eine Frist von Sekunden hätte nicht gereicht, der Client erneuert erst, wenn er den Endpunkt wieder
  braucht; ein gestohlenes voriges Token nützt nur bis zur ersten Benutzung der Erneuerung. Test `check-oauth-verloren.php`. Zugriffstokens liegen nur im
  Buffer `OAuthTokens`; nach einem Neustart erneuert der Client. Abgelaufene bleiben dort `EXPIRED_KEEP_SECONDS`
  (1 Tag), damit ein Client mit altem Token als „abgelaufen“ gilt und nicht als Fehlversuch (am 16.10.2026 zählten
  acht solche Anfragen im `SignInGuard`). Ein ungültiger Code oder ein ungültiges
  Erneuerungstoken zählt im `SignInGuard`. Am Hook gilt ein Bearer-Token, das kein festes Token ist, als
  OAuth-Zugriffstoken (`oauthAccess`, nur im Header): nicht abgelaufen, Familie vorhanden, der Zugang
  benutzbar und mit demselben Token. Der Client bekommt Werkzeuge, Rechte und Standardraum dieses
  Zugangs. Jede Kopplung hat eine Familie, auch ohne `offline_access`, damit sie sich widerrufen lässt.
  Kam ein Token mit und gilt nicht, sagt der 401 zusätzlich `error="invalid_token"` (RFC 6750).
  Aus dem Code-Review vom 11.10.2026 (build 78): Als Fehlversuch zählt nur ein Token, das nicht gilt
  (auch in der Adresse), ein falscher Kopplungscode und ein ungültiger Code bzw. ein ungültiges
  Erneuerungstoken; eine Anfrage ohne Anmeldung und ein bekanntes, abgelaufenes Zugriffstoken gehören zum
  normalen Ablauf. Erneuert wird nur für einen benutzbaren Zugang, sonst bleibt die Familie unverändert.
  Speicher ändert nur `changeStored` unter einer Sperre; ohne Sperre gibt es 503 statt Tokens, die nie
  gespeichert wurden. Körper an `oauth/authorize` und `oauth/token` höchstens 8 KB (413). Das CIMD wird
  nur von global erreichbaren Adressen geholt (`FILTER_FLAG_GLOBAL_RANGE`, also nicht aus 100.64.0.0/10).
  Die Einwilligungsseite nennt in der Überschrift den Host der `client_id`, den Namen wählt der Client
  selbst. Bei mehr als 50 Kopplungen fallen die am längsten nicht erneuerten heraus. **Zuletzt benutzt** (`lastUse` je
  Familie, `OAuthServer::markUsed` in `oauthAccess`, höchstens alle `USE_STEP` = 600 s ins Attribut, sonst schriebe jede
  Anfrage; ohne gemerkten Wert gilt Kopplung bzw. letzte Erneuerung): claude.ai legt bei jedem neuen Hinzufügen des
  Connectors eine neue Kopplung an und lässt die alte stehen (nuc 12.10.2026: drei Zeilen „Claude-App“). Die Liste
  steht nach letzter Nutzung, neueste zuerst. Ein automatisches Ersetzen gleicher Clients am selben Zugang (Idee a)
  ist zurückgestellt: zwei Claude-Konten an einem Zugang würden sich gegenseitig abmelden.
  Ende zu Ende mit der Claude-App erprobt am 11.10.2026 (Connector mit „Jetzt anmelden“ und CIMD,
  Kopplungscode, danach `get_state`); die Token-Anfrage steht als Mitschnitt in der Fixture.

- **Protokoll 2026-07-28 neben den älteren** („dual-era“, Backlog B62): Eine Anfrage mit
  `params._meta['io.modelcontextprotocol/protocolVersion']` geht nach 2026-07-28 (`McpProtocol::handleModern`):
  kein `initialize`, Kopfzeilen `MCP-Protocol-Version`, `Mcp-Method` und bei `tools/call` `Mcp-Name` müssen zum
  Körper passen (400, -32020), unbekannte Version 400 mit -32022 und der Liste, unbekannte Methode 404 (auch `ping`
  und `initialize`), kein Stapel. `server/discover` (auch ohne `_meta`), `resultType: complete` und Serverinfo in
  `_meta` jedes Ergebnisses, `tools/list` und `server/discover` mit `ttlMs` 300000 und `cacheScope: private`.
  Ohne `_meta` bleibt alles wie in 2025-11-25. Das Modul reicht die Kopfzeilen aus `$_SERVER['HTTP_MCP_*']` durch
  (`mcpHeaders`). Claude Code 2.1.296 verbindet so mit 2 statt 5 Anfragen (Mitschnitt
  `claude_code_2026-10-12_mcp_2026-07-28.json`, am nuc belegt 12.10.2026); die Claude-App über claude.ai nutzt weiter
  `initialize`.

- **Diagramm als MCP App** (`show_chart`, Backlog B63): nur für Clients, die `io.modelcontextprotocol/ui` mit
  `text/html;profile=mcp-app` anmelden (nach 2026-07-28 in jeder Anfrage, `McpProtocol::showsApps`; gemessen: Claude-App ja,
  Claude Code und HA nein) und nur mit einem Gerät mit Verlauf. Clients von Anthropic (`clientInfo.name` „Anthropic/…“)
  bekommen es immer: claude.ai holt die Liste für den Chat als „Anthropic/Toolbox“ ohne Apps-Fähigkeit (nuc, 12.10.2026,
  danach stand in claude.ai nur „Get state“). claude.ai beachtet `ttlMs` nicht: Am 13.10.2026 holte es die
  Werkzeugliste nach rund 75 min wieder (10:28, dann 11:45 beim Öffnen eines neuen Chats), sofort nur nach neuem
  Hinzufügen des Connectors. Das Werkzeug trägt `_meta.ui.resourceUri`
  `ui://homeagent/chart/<8 Zeichen SHA-256 der Seite>` (`resourceUri`: claude.ai nahm am 13.10.2026 eine über eine halbe Stunde alte Seite aus dem Zwischenspeicher, ohne `resources/read`, trotz `ttlMs`; eine geänderte Seite bekommt so eine neue Adresse, sobald der Client die Werkzeugliste neu holt; `readResource` liefert auch zu alten Prüfsummen und ohne Prüfsumme die heutige Seite, bei den Karten ebenso), die Seite liefert `resources/read` aus `HomeAgent/chart.html` (ohne Bibliotheken, die CSP der
  Apps erlaubt nichts Fremdes; Handshake `ui/initialize`, Werte über `ui/notifications/tool-result`). `DeviceIndex::chart()`
  gibt dem Modell nur Kopfzeile und Minimum/Maximum, die Werte stehen in `structuredContent`. `room` darf mehrere Räume mit Komma
  enthalten (Vergleich in einem Diagramm, höchstens `HISTORY_MAX` Reihen insgesamt; ein unbekannter Raum steht im Text, die
  anderen werden gezeichnet). Das Band von Minimum bis Maximum zeigt die Seite nur, wenn jedes Diagramm eine Reihe hat; ein
  Haken darunter schaltet es um, ein Feld im Werkzeug gibt es dafür nicht (Tokens; Burkhard 12.10.2026: „das band ist nicht
  immer gewollt“). Mit Energiebilanz gibt es `show_chart` auch ohne Messwert: ohne Raum, Etage, Typ und Name kommt der
  Hausverbrauch als Stapel (`kind: consumption`, `houseChart`, Werte aus `DeviceBackend::counterSeries`): Netz, PV direkt
  = PV − Einspeisung − Batterie geladen (gekappt auf 0 und den Hausverbrauch), Batterie entladen, der Rest Netz, die
  Einspeisung negativ. Dazu die Ringe Autarkie und Eigenverbrauch (`rings`, `shareRings`, Anregung pitti im Forum,
  13.10.2026): Autarkie teilt den Hausverbrauch über den ganzen Zeitraum in PV direkt, Batterie und Netz, Eigenverbrauch
  die PV in direkt, geladen und eingespeist; die Zahl in der Mitte rechnet `shares()` wie die Textzeile des Modells.
  Ohne Batterie in der Bilanz (`hasBattery`) fehlt ihre Zeile in allen Ringen, wie im Stapel (Neustadt 15.10.2026:
  „Batterie 0 kWh“, eingetragen war nur ein Leistungssensor). Mit `compare` trägt jeder Ring `compare.share`, gerechnet
  in `compareChart` aus den Teilen des Vergleichs (`chartParts`), solange der Zeitraum läuft bis zur selben Zeit wie
  „By the same time“; das Modell bekommt dieselben Anteile als Zeile. Die Seite zeigt sie rechts in der Kopfzeile der
  Ringkarte (Hardcopy 15.10.2026: Titel „2026 · Vergleich 2025“, die Ringe zeigten nur 2026).
  `show_chart` mit `period=now` ohne Filter gibt es nur mit Leistungsbilanz (`powerRings`, `kind: rings`): Hausleistung
  auf einer Skala in ganzen kW, dann dieselben Ringe aus den Leistungen. Wie Ringe und Stapel gezeichnet werden, steht in `DESIGN.md`. Jeder Balken ist der Hausverbrauch der Bilanz; am nuc lud die Batterie am 11.10.2026 um 14 Uhr
  aus dem Netz (Fixture `nuc_2026-10-12_verbrauch.json`, Burkhard 12.10.2026: „verbrauchsdiagramme, also
  stapeldiagramme“). Stufe wie beim Verlauf (`chartLevel`). Verbrauch je Gerät (`deviceChart`): `type=energy` gibt es nur
  bei `show_chart` (kein Gerätetyp, `chart()` nimmt es heraus und setzt `chartByDevice`), oder automatisch, wenn die Treffer
  Zähler und keinen Messwert haben. `CHART_DEVICES` (5) größte Geräte, der Rest „other“, mit Bilanz fürs ganze Haus
  „unmetered“ (Haus minus Geräte je Zeitraum, nicht unter 0). Nur ein Raum je Verbrauchsdiagramm, `HISTORY_MAX` gilt nur
  für den Verlauf. Ein Zähler, der nur einmal am Tag weiterzählt (gemessen an einem eBUS-Zähler einer Wärmepumpe), steht
  im Stundendiagramm mit dem ganzen Tag in einer Stunde; ausgeglichen wird das nicht. Anlass: Die Claude-App holte sich für einen Vergleich zweier Räume zwei Tabellen über `get_state`. Resources gibt es nur, wenn
  der Aufrufer `$readResource` übergibt; `resources/read` und `resources/list` tragen wie `tools/list` `ttlMs` und
  `cacheScope` (Pflicht in 2026-07-28, ohne meldete claude.ai „kann nicht erreicht werden“).
- **Gerätekarten als MCP App** (B63, Burkhard 12.10.2026): `show_devices` (für das Modell, Filter wie `get_state` ohne
  Zeitraum, `DeviceIndex::cards`) liefert die Zeilen von `get_state` und je Gerät eine Karte (`cardOf`: Zustandstext,
  `controls` aus `abilitiesOf`: `switch`, `percent`, `cover` mit `stop`, `setpoint` mit Bereich, `playback`), höchstens
  `CARDS_MAX`. Die Ziele der Knöpfe „−“ und „+“ rechnet das Modul (`setpoint.lower`/`raise`, `cardSetpoint`), ein
  Sonderwert außerhalb des Bereichs zählt vom nächsten Rand: Das HM-CC-TC auf 100 („Ventil offen“, Bereich 6 bis 30) fiel
  vorher bei „+“ auf 6,5 (Review 14.10.2026). Das Schema von `control_device` nennt jedes Feld, das die Karte schickt
  (`volume` fehlte bei `additionalProperties: false`; das Modul selbst prüft keine Zusatzfelder). Seite `ui://homeagent/devices` (`HomeAgent/devices.html`). Bedient wird über `control_device`
  (`_meta.ui.visibility: ["app"]`, der Host zeigt es dem Modell nicht; nur ohne „Nur abfragen“; aufrufen lassen sich die App-Werkzeuge auch ohne angemeldete Apps, denn claude.ai schickte den Aufruf von der Karte am 13.10.2026 ohne Client und Fähigkeiten, Ergebnis „Tool call failed“): Kartenschlüssel
  (`cardKey`, alle Variablen des Geräts, weil Thermostat und Raumfühler eine teilen) und Befehl, intern `setState` mit
  `onlyKey` in `find()`, also dieselbe Rückmeldung; zurück kommt die neue Karte und `problem` wie in „Was nicht geklappt hat“; die Karte zeigt die Antwort nur bei einem Problem, sonst den neuen Zustand (Burkhard 12.10.2026). Die Seite meldet die letzten fünf
  Bedienungen über `ui/update-model-context` (jede Meldung ersetzt die vorige). Am nuc 12.10.2026: Karte in 69 ms,
  `control_device` 68 ms. Modus und Lüfterstufe stehen als Auswahl auf der Karte (`controls.options`, `cardChoice`: Beschriftungen aus
  `DeviceBackend::options()` und der aktuelle Eintrag); die Seite schickt die Beschriftung als `mode` bzw. `fan_speed`, `runOption`
  sucht den Wert dazu (13.10.2026).
  **Farbe auf der Karte** (Burkhard 16.10.2026, Hardcopy aus der Claude-App: „Gibt es noch keine Möglichkeit, Farbe
  einzustellen?“): `controls.colortemp` (`cardKelvin`, Bereich der Variable gekappt auf `CARD_KELVIN` 2000 bis 6500 K,
  `~TWColor` reicht von 1000 bis 12000) und `controls.color` (Tupfer aus `COLORS` mit Name, Beschriftung und `rgb`),
  geschickt als `color_temperature` bzw. `color` (`CARD_FIELDS`, dieselben im Schema von `control_device`). Der
  **Farbmodus** kommt aus einer Variable neben Farbe oder Farbtemperatur, deren Ident auf `color_mode` endet
  (`DeviceBackend::colorMode`, Bedeutung in `colorModeKind`): Zigbee2MQTT `color_mode` COLOR_TEMP/XY, HomeAssistant-Modul
  `light_color_mode` (auch `light_<entität>_color_mode`) color_temp/xy, Matter `ColorControl_ColorMode` 0/1 Farbe, 2
  Farbtemperatur (Werte nach der Matter-Spezifikation, die Variable hat keine Einträge). Alle drei zeigen den Modus als
  Wertanzeige ohne eigene Darstellung, deshalb der Ident (geprüft an drei Anlagen, 17.10.2026). Mit bekanntem Modus nennen Zustand (`stateOf`, also auch get_state) und Karte nur den gültigen Wert,
  der andere Teil der Bedienung ist blass (`active`). Anlass an einer betreuten Anlage: Ein weißes Licht hieß „2732 K,
  gelb“ (Zigbee2MQTT rechnet die Farbtemperatur in RGB um), ein rotes „4739 K, rot“. Bewusst keine Rolle „Farbmodus“
  im Dialog, solange alle bekannten Module die Variable so nennen. Test `check-farbmodus.php`.
  **Daten der Karten** (Gestaltung nach pitti, die Regeln stehen in `DESIGN.md`): Jede Karte trägt `group` (`CARD_GROUPS`,
  Überschriften in fester Reihenfolge aus `labels.groups`; `measurement` bildet die Gruppe `measure`), `figure` (Hauptwert
  als Zahl und Einheit, `cardFigure`: Stufe eines eingeschalteten Lichts, Öffnungsgrad, Messwert, Ist-Temperatur bzw.
  Ist-Feuchte, sonst Leistung; beim Mediaplayer die Lautstärke, `cardVolume` und `controls.volume`), `status` (`cardStatus`:
  der Zustandstext ohne den Hauptwert, null bei reinen Messwerten) und `active` (ein Klimagerät im Modus „off“ ist nur auf
  der Karte nicht aktiv, `cardOf`; `get_state` zählt Thermostate bei `active_only` weiter nicht). Beschriftungen kommen aus
  `labels` (`current` bei Messwerten, `shut` für das Schild am geschlossenen Fenster) und `controls.setpoint.label`.
  Zeigt die Karte Sollwert oder Auswahl selbst, steht links nur der Modus, beim Luftbe-/entfeuchter und der Lüftung an/aus,
  sonst „aktuell“ (Nur-Lese-Zugang behält den ganzen Text); ein Zustand, der mit dem Hauptwert beginnt („90 % offen“), verliert ihn.
  **Nachführen:** `moving` (aus `blindText`) sagt, dass eine Fahrt läuft; `control_device` nur mit dem Schlüssel liefert die
  Karte neu, ohne zu schalten. Die Seite fragt so alle 3 s nach, solange `moving` gilt oder ein Befehl `not_confirmed` war
  (ohne Fahrt zweimal, höchstens 90 s). Anlass Burkhard 13.10.2026: die Karte blieb bei „fährt auf 90 % offen“ stehen.
  Eine Antwort zeichnet nur ihre eigene Karte neu (`redraw`, `data-key`), die Sperre hängt am Schlüssel (`busy`), und eine
  Karte, die gerade bedient wird (Zeiger gedrückt, Auswahl im Fokus, `inUse`), wartet bis zum Loslassen. Vorher baute jede
  Nachfrage alle Karten neu: Ein gezogener Regler schickte den Zwischenwert (61 statt 94 %), eine offene Auswahl klappte
  zu, ein zweiter Klick während der Sperre ging durch (Review 14.10.2026). Geprüft mit einem Chrome-Prüfstand über CDP mit
  echter Maus (nicht im Repo, die CI führt kein JavaScript aus): vorher 4 von 6 Prüfungen rot, danach 6 grün.
  Mit Karten verweist die Beschreibung von `get_state` fürs Zeigen („show me …“) auf `show_devices` (`$hasCards`): Die
  Claude-App beantwortete „Zeig mir die Lichter im Wohnzimmer“ sonst mit einer Tabelle über `get_state` (12.10.2026).
  Ebenso verweist sie für Diagramme auf `show_chart` (`$hasChart`): „Hausverbrauch von gestern als Diagramm“ ergab
  `get_state` und eine SVG-Datei, die das Modell selbst zeichnete. Der Verweis nennt Heatmap, Summenkurve und Punktwolke beim
  Namen: „Hausverbrauch vom letzten Monat als Heatmap“ ergab am 14.10.2026 ohne „Nutze Home Agent“ 32 Aufrufe von
  `get_state`, je Tag einer, und eine selbst gezeichnete Heatmap aus Tageswerten.
- **Vorlagen (Prompts, B63):** `libs/PromptCatalog.php` baut „Haus verlassen“, „Gute Nacht“ (Fenster, Türen, Licht,
  Mediaplayer, je nach Freigabeliste, `active_only=true`) und „Energiebericht“ (nur mit Bilanz oder Zähler); ein
  Nur-Lese-Zugang bekommt keine Frage nach dem Schalten. `prompts/list` und `prompts/get` gibt es nur, wenn der
  Aufrufer `$prompts` übergibt (`promptsFor`), sonst -32601.

## Werkzeuge

Drei Werkzeuge: `get_state`, `set_state`, `run_scene`. Alle tragen `openWorldHint: false`.

- **`get_state`** filtert nach Raum, Etage, Typ und Name, `active_only` zählt nur Geräte mit an/aus und
  nennt die übrigen in einer Schlusszeile. `period` bzw. `from`/`to` liefern den Verbrauch aus den
  Archiv-Aggregaten, `period=now` die Leistung; fürs ganze Haus aus der Energiebilanz (Bezug + PV +
  Batterie entladen − Einspeisung − Batterie geladen), mit Autarkie und den fünf größten Geräten.
  Ein einzelner Netz- bzw. Batteriesensor mit Vorzeichen zählt wie bei Home Assistant (negativ = Einspeisung bzw.
  laden); der Haken „Vorzeichen umkehren“ der Zeile dreht das um (B77, `Invert` in `Sources`, nur ohne eigenes Gegenfeld;
  bis build 191 `InvertGridPower`/`InvertBatteryPower`, in der Bibliothek weiter `DeviceIndex::setInvertedPower`). Anlass: Der Marstek Venus E meldet positiv = laden; die Hausleistung lag damit um das
  Doppelte der Ladeleistung zu hoch. Der Selbsttest nennt je Sensor mit Vorzeichen Rohwert und Zählweise
  (`signedPowers`). Test `check-vorzeichen.php`, Orakel ist der Verbrauch aus einem Skript des Anwenders. Seit B77
  gibt es die Hausleistung jetzt auch ohne freigegebene Geräte (`houseOnly` mit `period=now`).
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
- **Melder mit einer Zahl** (Fenster, Tür, Präsenz, Wasser): aktiv über 0, mit dem Haken „Umgekehrt“ nur bei 0
  (`activeValue`, auch in der Zeitleiste; an schaltbaren Geräten dreht der Haken keine Zahl, ein Dimmer auf 0 bleibt aus).
  Hat die Variable Einträge, steht der Eintrag in Klammern, außer er ist nur ein anderes Wort für den gezählten Zustand
  (`PLAIN_CAPTIONS`); ohne Haken bleibt so „offen (Geschlossen)“ sichtbar. Anlass Forum 14.10.2026: Fenstergriffe über
  Matter melden den Türstatus (0 Geöffnet, 1 Geschlossen, 2 Blockiert, 3 Gewaltsam geöffnet, 4 Unbekannter Fehler,
  5 Angelehnt), die Claude-App sagte „offen (Geschlossen)“, und der Haken wirkte nur auf Wahrheitswerte. Home Assistant
  übersetzt das Attribut in der Matter-Integration fest (0, 2, 3 offen, 1 zu, 4 und 5 unbekannt); das Modul sieht nur
  die Variable. Test `check-ganzzahl-melder.php`.
- **Richtung eines Rollladens** aus der Darstellung „Rollladen“, sonst aus einem Profil mit `.Reversed`,
  sonst aus dem Modul des Geräts (`SymconDeviceBackend::OPEN_AT_MAX_MODULES`: HomeAssistant-Modul, Ident
  `cover_…`, 100 offen), sonst ist der kleinste Wert offen; der Haken „Richtung umkehren“ dreht es um.
  Anlass: Im Blindtest vom 11.10.2026 fuhren HA-Rollläden (Schieberegler 0 bis 100 %) falsch herum.
- **Hinweistext für Skripte und KI-Assistenten** (Label im Formular): Ein Agent hat damit allein, ohne weitere
  Doku, eine Instanz eingerichtet (Blindtest 11.10.2026). Was er dort nicht fand (Clients
  mit Token in der Adresse, Connect-Adresse, Token erzeugen, Raumnamen, Felder je Typ, Richtung prüfen),
  steht seitdem drin; neue Felder und Typen gehören auch dorthin. Nach dem Prompttest vom 13.10.2026 nennt er die
  Claude-App mit OAuth und Kopplungscode statt mit dem Token in der Adresse, `show_chart` und `show_devices`, die weiteren
  Felder von `set_state` und die Properties der Energiebilanz.
- **Prozent beim Licht rechnet das Modul selbst**; der Anzeigetext von Symcon schneidet ab.
- **Sollwert:** Bereich und Schrittweite aus Darstellung oder Profil, Sonderwerte mit Text am Rand
  („On“; nicht ein Eintrag ohne Text oder mit Formatnamen wie „%d“, Review 18.10.2026, N7) zählen nicht dazu, bei einem Profil ohne Schrittweite um 0,5 (das Raster der Karte; Homematic-IP-Profil
  einer betreuten Anlage mit Einträgen 15 bis 24 und „On“ bei 30,5, vorher bis 30,5, 16.10.2026). Batteriegeräte melden den neuen Sollwert oft schon über die Zentrale,
  bevor das Gerät ihn übernimmt. Hat das Profil keine Schrittweite, gilt der kleinste Abstand seiner Einträge mit Zahlen
  (B81, 18.10.2026: dasselbe Homematic-IP-Profil hat Einträge im Abstand von 0,5; der Selbsttest meldete „in Schritten
  von 0“, `set_state` rundete nicht). Das gilt nur für dichte Einträge (`associationStep`: mindestens drei Abstände, jeder
  ein Vielfaches des kleinsten, höchstens 1; Review 18.10.2026, M2: zwei Zahlen an den Rändern ergaben Schrittweite 24, und
  21 °C wurde 30 °C). Sonst bleibt sie 0, der Selbsttest sagt „ohne feste Schrittweite“.
- **Stopp:** über eine Stoppvariable (Wahrheitswert oder Eintrag „Stop“), sonst über das Modul des
  Geräts (`SymconDeviceBackend::STOP_MODULES`, Homematic und Velux KLF200).
- **Zeitleiste für Zustände** (B69, Burkhard 13.10.2026: „was hat es mit der Zeitleiste auf sich?“): Zustände (`hasTimeline`:
  Typ mit Wörtern in `TYPES` und Rolle `state`; dazu `measurement` mit Einträgen, `isTimed`, etwa ein Batteriemodus) bekommen
  mit Zeitraum Abschnitte aus den Rohwerten (`DeviceBackend::stateSeries`: letzter Wert vor dem Zeitraum, dann die Änderungen;
  laut Doku `AC_GetLoggedValues` neueste zuerst, höchstens 10000 je Abfrage, nur Änderungen; `SymconDeviceBackend::allRows` holt in Scheiben bis vor den ältesten erhaltenen Datensatz, höchstens zehn, ebenso alle Aggregate: vorher rechneten Kosten über from=2025-01-01 nur mit den neuesten 10000 Stunden, Review 14.10.2026). Gleiche Zustände werden zusammengefasst
  (der Bewegungsmelder schreibt aus und an in derselben Sekunde). Summen je Zustand, bei Wörtern der aktive zuerst, sonst
  der längste; einzeln bei Wörtern nur die aktiven Abschnitte, bei einer Auswahl alle, bis `TIMELINE_SECTIONS` (12), bis
  zwei Tage und bis `TIMELINE_DETAIL` (5) Geräte, sonst die Anzahl je Zustand. Ein Gerät mit Zähler bekommt Verbrauch
  und Zeiten in einer Zeile (Burkhard: Verbrauch auf „wann war das Licht an?“ wäre falsch), nicht fürs ganze Haus. Ein
  Zustand ohne Archiv wird ausgelassen und gezählt; bleibt nichts, sagt die Absage „misst keine Energie oder hat keinen
  archivierten Zustand“. Am nuc waren am 13.10.2026 rund 40 von 120 Zuständen archiviert (Türen, Schalter, Präsenz, wenige
  Lichter). Test `check-zeitleiste.php`. In `show_chart` werden Zustände Bänder
  (`chartBands`, `structuredContent.bands`: Raum, Name, `from`, `to`, `states` mit `label` und `active`, `sections` als
  `[Zustand, Beginn, Ende]`, alle Abschnitte, auch wenn der Text nur Summen nennt); nur Zustände ergeben `kind: timeline`,
  mit Messwerten bleibt es `history` mit Bändern darunter. `states` kommen in fester Reihenfolge (bei Wörtern der aktive
  zuerst, bei einer Auswahl die der Einträge), damit die Seite jeden Tag dieselben Farben vergibt. Höchstens
  `TIMELINE_CHART_MAX` (10) Bänder. Die Achse zeigt immer den ganzen Zeitraum (`periodSpan`; Verlauf und Zeitleiste über
  `range`, Stapel mit leeren Abschnitten bis zum Ende, `padTimes`). Jede Art liefert ihren Zeitraum (`range`, beim Vergleich
  `compare.range`, bei „Leistung jetzt“ `at`) für die Titelzeile der Seite (Burkhard 13.10.2026 in der Claude-App: „mir fehlt
  im diagramm das datum“; der Assistent bot an, das Diagramm selbst zu zeichnen). Dazu nennt jedes Diagramm die Zeitzone
  der Anlage (`timeZone`, `DeviceIndex::chart`), und `chart.html` beschriftet Titel, Achsen und Heatmap-Zeilen darin
  (`wall`/`unwall`, nur UTC-Methoden auf diesen Daten); vorher in der Zone des Browsers, unterwegs stand eine Heatmap-Zeile
  auf dem Vortag (Review 14.10.2026). Geprüft mit einem Chrome-Prüfstand, der per CDP die Zone auf America/New_York stellt
  (nicht im Repo): vorher 4 von 6 Prüfungen rot, danach 6 grün.
- **Vergleich zweier Zeiträume** (B69, `show_chart` mit `compare`: `previous` oder `year_before`, `DeviceIndex::COMPARES`):
  `compareWindow` bestimmt den Vergleichszeitraum (der davor in der Einheit des Zeitraums, bei from/to gleich viele Tage
  davor; nur ganze Tage), `compareChart` holt dasselbe Diagramm über from/to und legt es um den Abstand verschoben
  darüber: Verlauf je Reihe `compare`, Verbrauch `compare.values` als Summe der positiven Teile je Balken
  über die Zeit: Jeder Abschnitt des Vergleichs geht um einen Kalenderschritt (`strtotime`, Funktion im fünften Element
  von `compareWindow`; beginnt der Zeitraum am 29.02., ist der Vergleich der 28.02. des Vorjahres und rückt auf den 29.02.) weiter, ein Balken ohne Vergleich bekommt null; reicht der Vergleich weiter, kommen Balken ohne Werte
  dazu. Bis build 167 nach Position: Ein Archiv, das erst im Vergleichszeitraum beginnt, schob jeden Wert nach vorn, und
  eine feste Sekundenzahl träfe Tage zwischen den Zeitumstellungen zweier Jahre um eine Stunde daneben (Review 14.10.2026).
  Das Modell bekommt den Text des Vergleichszeitraums wie aus get_state und, solange der Zeitraum läuft, den Verbrauch bis
  zur selben Zeit, der laufende Abschnitt anteilig (vorher ganz: am 12.10. der ganze Oktober des Vorjahres, 6 % zu viel).
  Die Summenkurve trägt die Summe über Lücken weiter und rechnet die Hochrechnung nur aus Abschnitten, die beide Zeiträume
  haben. Nur ein Raum, keine Zeitleiste. Test `check-vergleich.php` (Orakel: das Diagramm des Vergleichszeitraums selbst).
- **Punktwolke** (B70, Burkhard 13.10.2026, `show_chart` mit `versus`, `DeviceIndex::scatter`): `versus` ist das Gerät der
  x-Achse, die übrigen Filter das der y-Achse, ohne Filter der Hausverbrauch der Bilanz; jede Achse genau ein Gerät, sonst
  Absage mit Kandidaten. Werte je Abschnitt aus `valueSeries`: Messwert (Mittel), sonst Zähler (kWh), sonst Leistung
  (Mittel); Zustände haben keine. Raster bis zwei Tage je Stunde, bis 400 Tage je Tag, sonst Monat, ohne Zeitraum der
  letzte Monat: am nuc erklärte stündlich kein Modell mehr als 13 % (die Wärmepumpe taktet), je Tag der Knick 84 %, die
  Gerade 63 %. `scatterModel` rechnet linear und mit Knick (`knee_below`/`knee_above`, c im Raster eines Hundertstels der
  Spanne zwischen 10. und 90. Perzentil, rund 100 Grenzen × Punkte, wenige Millisekunden) und nimmt den Knick bei
  mindestens 5 Prozentpunkten mehr; quadratisch bewusst nicht (die Parabel stieg über 20 °C wieder an). Unter 10
  Punkten kein Modell, unter 10 % „kein klarer Zusammenhang“. Klassenmittel je runder Breite über einem Zwölftel der
  Spanne (`scatterClasses`). Test `check-punktwolke.php` rechnet die Kennzahlen selbst aus der Fixture nach.
- **Heatmap** (B71, Burkhard 13.10.2026, `show_chart` mit `heatmap=true`, `DeviceIndex::heatmap`): ein Gerät wie bei der
  Punktwolke (`valueSeries`, je Stunde), ohne Filter der Hausverbrauch; bis 62 Tage eine Zeile je Tag (`rows` mit dem
  Tagesbeginn), darüber `mode: weekdays` mit sieben Zeilen und dem Mittel je Wochentag und Stunde. Zähler werden in einem
  Kästchen summiert (doppelte Stunde der Zeitumstellung), sonst gemittelt. Text: stärkste und schwächste Stunde im Mittel,
  Werktag gegen Wochenende (Zähler je Tag summiert). `cap` ist das 95. Perzentil für die Farbskala: im September am nuc
  reichte der Hausverbrauch bis 9,5 kWh je Stunde, fast alle Kästchen waren sonst blass. Test `check-heatmap.php`
  (Fixture kompakt, Zähler im September, Temperatur ab August).
- **Kosten in Euro** (B72, Burkhard 13.10.2026): Properties `PriceVariableID` (archivierte Variable in ct/kWh oder €/kWh,
  Einheit aus dem Suffix, ohne Suffix gelten Werte über 2 als Cent) und `FeedInTariff` (ct/kWh), an den Index über
  `setTariff`; nur mit Bilanz. `hourlyCosts` rechnet je Stunde Netzbezug × Stundenmittel, Einspeisung × Vergütung und
  Hausverbrauch × Preis (ohne PV und Batterie), `costLine` hängt die Zeile an den Hausverbrauch von get_state, show_chart
  mit `costs=true` zeichnet den Stapel in Euro (`costChart`, auch mit Vergleich) bzw. die Heatmap der Kosten; nur fürs
  ganze Haus, ohne Grundgebühr. Am Tibber-Preis (`act_price` = API `total`, brutto) gegen die Rechnung September 2026
  geprüft: Archiv 38,21 € gegen Rechnung 37,23 € (1,4 % Menge vom eigenen Zähler, 1,2 % Preis aus Stundenmitteln).
  Test `check-kosten.php`.
- **Summenkurve** (B73, Burkhard 13.10.2026, `show_chart` mit `cumulative=true`): `chart()` setzt `forceLevel` (bis zwei Tage
  Stunde, sonst Tag; ohne das gäbe ein Jahr nur zwölf Stufen), holt über `chartOf` das Verbrauchsdiagramm (Haus, Gerät mit
  Zähler oder Kosten) samt Vergleich und macht in `cumulativeOf` daraus die Summenkurve: Summe der positiven Teile je
  Abschnitt, aufaddiert, künftige Abschnitte `null`, der Vergleich aufaddiert. Ohne Zeitraum das laufende Jahr. Hochrechnung
  (`projection`, Punkte von jetzt bis zum Ende, `projectionMethod`): mit dem Verlauf desselben Zeitraums ein Jahr früher
  (eigener `chartOf`-Aufruf mit `compare=year_before`, außer er steht schon im Diagramm): Stand × Vorjahr gesamt ÷ Vorjahr bis
  zur selben Zeit, der laufende Abschnitt anteilig, die Kurve folgt dem Vorjahr; nur wenn das Vorjahr für mindestens 80 % der
  Abschnitte bis heute Werte hat, sonst linear. Anlass Burkhard 13.10.2026: linear ergab am nuc für 2026 1632 € Kosten,
  nach dem Vorjahr 2120 € (Ende Dezember 2025 bis 129 kWh am Tag). Ein Jahr mit Kosten braucht am nuc rund 1 s.
  Test `check-summenkurve.php`. Der Hausverbrauch über einen Zeitraum geht seitdem auch ohne freigegebene Geräte (`houseOnly`).
- **Teil der Bilanz** (B74, Burkhard 13.10.2026, `show_chart` mit `part`, `DeviceIndex::PARTS`): `grid`, `feed_in`, `pv`,
  `battery_charge`, `battery_discharge`, im Schema nur die eingetragenen Zähler. Nur fürs ganze Haus, nicht mit `versus`;
  `chartPart` lässt `houseChart` bzw. `costChart` eine Reihe mit dem Schlüssel des Teils zeichnen (Farbe wie im Stapel,
  `STACK_COLORS` in `chart.html`), `labels.total` benennt die Summenkurve. `costs` nur bei `grid` (Netzbezug × Preis) und
  `feed_in` (Vergütung, positiv, ohne Saldo-Linie). Die Heatmap nimmt den Zähler je Stunde. Die Summe steht als eigene
  Zeile unter dem Text des Hausverbrauchs. Trifft `name` kein Gerät, ist aber ein Teil der Bilanz („Einspeisung“,
  „Netzbezug“, „PV-Ertrag“ …), sagt die Absage `part=…` (`partError`). Anlass: Die Claude-App fragte nach der
  Einspeisevergütung als Summenkurve gegen das Vorjahr, versuchte `name=Einspeisung` und holte sich danach 22 Monate
  einzeln über `get_state`, ohne Diagramm. Verworfen: die Zähler der Bilanz als Geräte (Typ `meter`): Ausnahmen in allen
  Gerätesummen, Unterzähler doppelt, Zähler ohne Freigabe sichtbar (Burkhard: „riskant“). Test `check-teil.php`.
  Bei Kosten liefert `cumulativeOf` die aufaddierte Vergütung als `revenue` und den Saldo im Text (Burkhard 13.10.2026).
- **Verlauf statt Verbrauch** (`DeviceIndex::history`), wenn nach einem Typ aus `HISTORY_TYPES` gefragt
  wird oder kein Treffer einen Zähler hat, nie fürs ganze Haus. Stufe aus dem Zeitraum (heute und
  gestern stündlich, Wochen und Monate täglich, Jahre monatlich; `from`/`to` bis 2 Tage stündlich, bis
  100 Tage täglich; mit Uhrzeit immer stündlich), höchstens `HISTORY_MAX` Messwerte, jede Variable nur einmal (ein Thermostat kann
  den Raumfühler als Ist-Temperatur lesen). Als Zähler aggregierte Variablen haben keinen Verlauf.
  Die Gerätezeile nennt Minimum und Maximum über den ganzen Zeitraum mit Zeitpunkt (bei Gleichstand der erste), damit
  das Modell nicht selbst in der Tabelle sucht (Bedienungs-Blindtest 12.10.2026); das Mittel bewusst nicht (Tokens,
  über ungleich lange Abschnitte nur ungefähr).
- **Verlauf der Leistung** (B84, Micha87 im Forum 17.10.2026: „min und max Werte der Leistung“ für Oktober, ChatGPT bekam
  nur kWh): `type=power` (`DeviceIndex::POWER_TYPE`) ist kein Gerätetyp, wie `type=energy` beim Diagramm. `powerState`
  nimmt die Treffer mit Rolle `power` (auch Zähler); ohne Zeitraum oder mit `period=now` die Leistung jetzt (`power()`),
  sonst `powerHistory`: je Leistung einmal (wie `firstPerVariable`) dieselben Zeilen wie beim Messwert (`historyLines`),
  die Tabelle nur bei einer Reihe, bis `POWER_HISTORY_MAX` (20) Geräte. Fürs ganze Haus mit Bilanz die Hausleistung im
  Stundenmittel (`housePowerRows`: je Stunde der Hausverbrauch aus `balanceParts`, die laufende Stunde fehlt, ihr Mittel
  wäre ein falsches Minimum; ebenso Stunden mit negativem Hausverbrauch, gezählt in `inconsistent` und in der Antwort
  genannt: in der betreuten Anlage zählte der Ladezähler der Batterie an 15 Vormittagen im Oktober mehr, als PV und Netz
  lieferten, gekappt stand dort „Minimum 0 kW“, Fixture `betreut_2026-10-18_hausleistung.json`) und je Netzleistung eine Reihe in kW (`kilowattHistory`, umgekehrte Sensoren gedreht).
  Gleichzeitige Spitzen mehrerer Sensoren gibt kein Aggregat her; 15-Minuten-Aggregate (Stufe 8, laut Doku aus Rohdaten)
  scheiterten am nuc schon über 17 Tage an „Zu viele Werte (>150000)“: Rohwerte liest das Archiv dafür höchstens bis zum
  Spezialschalter `ArchiveRecordLimit` (Standard 50000, am nuc 150000, in der betreuten Anlage 50000). Minimum und
  Maximum tragen seitdem den Zeitpunkt aus `MinTime`/`MaxTime` der Aggregate (Doku AC_GetAggregatedValues), auch bei
  Messwerten; Backends ohne diese Felder behalten den Beginn des Abschnitts. Am nuc gemessen: das Monatsaggregat der
  Netzleistung in 1 ms, sein Maximum gleich dem der Rohwerte (Test `check-leistungsverlauf.php`, Fixture
  `nuc_2026-10-18_leistung.json`). Die Beschreibung von `get_state` nennt dafür ein Beispiel fürs Haus („Minimum and maximum
  power in October?“) und die von `period` den Hinweis auf `type=power`: Die Claude-App holte am 18.10.2026 die neue
  Werkzeugliste, beantwortete Micha87s Satz aber mit 17 Abfragen je Tag und nannte Tagesverbräuche in kWh als Minimum und
  Maximum. Minimum und Maximum von Geräten und Netzleistung heißen in der Antwort Momentanwerte (Kopfzeile bzw.
  „(instantaneous)“ an der Zeile), die Hausleistung Stundenmittel; der Hinweis auf unstimmige Stunden nennt bis zu
  `INCONSISTENT_DAYS` (5) Tage mit den meisten (`inconsistentLine`). Anlass Micha87 #47 (18.10.2026): ChatGPT antwortete
  auf „höchster 15-Minuten-Wert“ nur „kann ich nicht“, obwohl kein 15-Minuten-Mittel über der Momentanspitze liegt, und
  „42 Stunden ausgelassen“ sagte nicht, wo. 15-Minuten-Mittel selbst (Stufe 8, aus Rohdaten) sind zurückgestellt: am nuc
  0,14 bis 0,55 s, in der betreuten Anlage 2,5 bis 5,8 s je Sensor und Tag. Die Hausleistung umfasst höchstens
  `HOUSE_POWER_DAYS` (400) Tage, geprüft vor den Archivabfragen: `housePowerRows` hält jede Stunde aller Zähler der
  Bilanz, sechs Jahre mit fünf Zählern brauchten 86 MB (Review 18.10.2026, H1). Ebenso zählt die Gerätegrenze
  `POWER_HISTORY_MAX` die Leistungen vor den Abfragen (M6).
- **Uhrzeit in `from`/`to`** (`parseMoment`, Burkhard 12.10.2026: „nicht stundengenau“): JJJJ-MM-TT HH:MM, auch mit T
  oder Sekunden. Ganze Stunden aus den Stundenaggregaten (Stufe 0 auch für Zähler): `from` abgerundet, `to` aufgerundet
  und das Ende (13:00 bis 15:00 sind zwei Stunden), ein `to` ohne Uhrzeit zählt den ganzen Tag, ohne `to` bis jetzt.
  Höchstens 48 Stunden, sonst würde die Tabelle des Verlaufs zu lang. Die Kopfzeile nennt die gerechneten Stunden.
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
  Bei gemischten Treffern zählt eine Schlusszeile die Fenster mit Antrieb (build 68): die Claude-App verzählte
  sich zweimal („fünf“, dann sechs aufgezählt).
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
  und zwar erst nach der bestätigten Stufe. Als „aus“ gemeldet wird sie erst, wenn auch die Farbe schwarz ist (`status` je
  Gerät aus `runLevel`), ein nicht ausgelöster Farbbefehl ist ein Fehlschlag (Code-Review 12.10.2026). (IPS-868 schreibt bei jedem Weißbefehl den alten Farbwert mit;
  `FakeBackend` bildet das über `schreibtAltwert` nach).
- **Luftbe-/entfeuchter** (Typ `humidifier`): `state` (Ein/Aus), `humidity` (Ist-Feuchte), `setpoint`
  (Zielfeuchte, Feld `humidity` von `set_state` über `runSetpoint`, Einheit ohne Angabe %). Gemessen am
  Luftentfeuchter über das HomeAssistant-Modul: Die Darstellung nennt 35 bis 85 in Einerschritten, das Gerät
  nimmt aber nur Fünferschritte; 56 kam nie an, die Antwort sagt dann „noch nicht bestätigt“. 60 brauchte
  1,7 bis 2,3 s. Modus (`mode`) und Lüfterstufe (`fan`) sind **Auswahl per Name** (`runOption`, Felder `mode`
  und `fan_speed`): Treffer auf Beschriftung oder Wert aus `DeviceBackend::options()` (Darstellung `OPTIONS`,
  sonst Profilzuordnungen), Name in der anderen Auswahl ergibt einen Hinweis, die Einträge der freigegebenen
  Geräte stehen als Vokabular in der Beschreibung (kein `enum`; aus `$row['roles']` der aufbereiteten Einträge, bis build 99 blieb
  die Liste leer, gefunden im Bedienungs-Blindtest 12.10.2026). Die Rollen sind nicht an den Typ gebunden;
  Lüftung, Thermostat-Betriebsart und Radiosender sollen denselben Weg nehmen. Befehle kurz hintereinander
  brauchten am Entfeuchter über 2 s (gemessen 11.10.2026), die Antwort sagt dann „noch nicht bestätigt“.
- **Klimagerät** (B19, 13.10.2026): Typ `thermostat` mit den wahlweisen Rollen `mode` und `fan`, Felder `mode` und
  `fan_speed` wie beim Luftentfeuchter (`runOption`), Zeile „Soll 24 °C (derzeit 20.0 °C), Aus, Lüfter Auto“. Ein/Aus geht über
  den Modus, kein eigener Schaltzustand (die Rolle `state` ist beim Thermostat die Ist-Temperatur). Leistung und Zähler
  wahlweise wie beim AV-Receiver (Typ `media`, B85, 18.10.2026); die Karte behält die Ist-Temperatur bzw. die Lautstärke als
  Hauptwert, und die Leistung allein ist nur dann der Zustand, wenn ein Gerät sonst nichts hat (`stateOf`; vorher verdrängte
  sie den Sollwert eines Klimageräts ohne Ist-Temperatur). Test `check-leistung-typen.php`. `command=off` setzt
  den Eintrag `off` (Wert oder Beschriftung) über `runOption`, ohne diesen Eintrag nennt die Zeile die Einträge; `on` gibt es
  nicht, Heizen, Kühlen und Auto wären alle möglich (Burkhard 13.10.2026). Die Karte hat deshalb keinen Schalter. Gemessen an einem
  Panasonic-Klimagerät über das HomeAssistant-Modul: Modus nach 1,2 bis 1,5 s bestätigt, Lüfterstufe erst nach 2,7 bis 3,2 s
  (nach der Wartezeit, also „noch nicht bestätigt“); Test `check-klima.php`. Homematic-Wandthermostate haben an
  `CONTROL_MODE` keine Aktion (Modus über `AUTO_MODE`, `MANU_MODE` …), das geht so nicht.
- **Wärmepumpe** (B83, Nachfrage aus einer betreuten Anlage 21.10.2026: „mein Claude hat keinen Zugriff auf den
  Wärmemengenzähler“): Rollen `heat` (Wärmezähler, als Zähler archiviert) und `heatpower` (Wärmeleistung), nur beim Typ
  `appliance`. Beide zählen nie in einer Stromsumme. `consumption()` hängt an die Zeile „heat … kWh, performance factor …“
  (Wärme ÷ Strom des Zeitraums, `heatText`; ohne Strom keine Arbeitszahl, nie als Mittel der Leistungszahl), bei mehr als
  einem Wärmezähler steht die Arbeitszahl aller unter der Kopfzeile; ein Wärmezähler ohne Stromzähler hat nur seine Wärme.
  `power()` hängt „heat … W, COP …“ an (`heatPowerText`). Die Beschreibung von `get_state` nennt ein Beispiel nur, wenn ein
  Gerät einen Wärmezähler hat; der Selbsttest meldet einen, der nicht als Zähler archiviert ist. Noch nicht: Diagramme,
  `type=power`, die Zählerprüfung aus B78. Gemessen an einer Stiebel Eltron über ModBus (Fixture
  `betreut_2026-10-21_waermepumpe.json`): Wärme- und Stromzähler des Reglers springen nur um 0 Uhr und zählen dann den
  Vortag, der Strom ist der des Verdichters, und seit Juni 2026 liegt der Wärmezähler beim 2- bis 2,4-Fachen der aus dem
  Volumenstrom gerechneten Wärmeleistung (im Winter 1 bis 4 %). Im Oktober ergaben die Zähler eine Arbeitszahl von 14,8,
  am 21.10. bis 19 Uhr der Regler 25,7 (über der Carnot-Grenze von rund 10 bis 12), Wärmeleistung und Inverter 7,3. Der
  „Wärmezähler“ dort ist eine virtuelle Variable des ModBus-Geräts aus den Registern des Reglers, also eine Schätzung.
  Deshalb **ohne Wärmezähler die Wärme aus der Wärmeleistung** (`heatOf`, `powerKilowattHours`): Stundenmittel × Dauer, die
  laufende Stunde bis jetzt. Das Archiv mittelt zeitgewichtet und schreibt jede Stunde, auch ohne neuen Satz (21.10.2026 an
  drei Stunden gegen die Rohwerte nachgerechnet, auf vier Stellen gleich; drei Tage im Juni: 72 Stundenaggregate ohne einen
  Rohsatz). Mit Leistung am Gerät kommt dann auch der Strom der Arbeitszahl aus der Leistung („from heat output and power“):
  Gegen den Stromzähler des Reglers, der erst um Mitternacht für den Vortag bucht, ergab „gestern“ 15,6 statt 6,9. Ein
  Wärmezähler geht vor; der Selbsttest meldet eine nicht archivierte Wärmeleistung nur ohne Wärmezähler.
  Test `check-waermepumpe.php`.
- **Lüftung** (Typ `fan`, build 90): `state`, `mode`, `fan`, wahlweise `power`/`energy`; Pflicht Ein/Aus oder
  Lüfterstufe. Zeile und Befehle wie beim Luftbe-/entfeuchter (`humidifierText`, `runOption`), hier mit Ganzzahlwerten aus
  den Profilzuordnungen. Gemessen an einer Ambientika (12.10.2026): `RequestAction` blockiert 2,2 bis 2,4 s, das Modul
  setzt den Wert vorher selbst; Test `check-lueftung.php`.
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
  (`runVolume`, `runMute`, gemeinsames Warten in `confirmJobs`). `volume` oder `volume_change` dürfen mit `play`,
  `pause` oder `stop` kommen (erst Lautstärke, dann Befehl, `combineResults` über den Status je Gerät aus
  `confirmJobs`); das spart eine Runde (Bedienungs-Blindtest 12.10.2026, Leitbild „schnell vor günstig“). Die Lautstärke zählt in Prozent des
  Bereichs: Sonos-Modul `~Volume` 0 bis 100 (Ganzzahl), HomeAssistant-Modul Kommazahl 0 bis 1 aus der
  Darstellung (ein Befehl 25 wird dort 1 = 100 %, gemessen). Gesendet wird auf ganze Prozent gerundet.
  Gesendet wird im Raster der Variable (`range()['step']`, Darstellung `STEP_SIZE` oder Profil `StepSize`): Das
  Denon-Modul (DenonAVRTelnet, -80 bis 18 dB, Schritt 0.5) lehnt -50.6 ab und nimmt -50.5 an, gemessen 12.10.2026
  (`check-lautstaerke-db.php`). Ein Bereich in anderer Einheit steht in der Abfrage dabei („64 % = -17.5 dB“).
  Mit `volume_change` ist `set_state` nicht mehr idempotent (`idempotentHint`). `play` ohne Raum,
  Etage und Name (Typ höchstens `media`) setzt fort, was in der letzten Stunde per Werkzeug pausiert
  wurde (`recentlyPaused`, Merker über `DeviceBackend::rememberPaused`, im Modul Buffer `Paused`, unter einer Sperre wie alle Buffer, die gleichzeitige Anfragen ändern).
  Noch offen: Ein/Aus; das Sonos-Modul hat an `Volume` teils keine Aktion (dann „nicht stellbar“).
- **Dieselbe Leistung oder derselbe Zähler an zwei Geräten** (Messsteckdose und Gerät dahinter) zählt in
  `consumption()` und `power()` nur einmal, unter dem ersten Gerät der Liste (`firstPerVariable`, wie der Verlauf);
  der Selbsttest nennt solche Variablen. Anlass: Luftentfeuchter im Keller und Steckdose „Entfeuchter“ (build 69).
- **Zähler mit „gemessen von“** (Typ `meter`, B82, Wunsch Micha87 im Forum 16./17.10.2026: Unterverteilungen, Kinderzimmer
  mit Terrarium dahinter): Rollen `energy` (Pflicht) und `power`, Raum wahlweise. Ein Zähler zählt nie in einer
  Gerätesumme; `consumption()` und `power()` nehmen ihn aus den Treffern und geben ihm eine eigene Zeile (`meterLine`).
  Jede Zeile kann über `MeteredBy` (Energievariable des Zählers darüber, 0 = keiner) an einem Zähler hängen, beliebig tief.
  `meterTree` baut je Rolle den Baum über alle Einträge (jede Variable einmal wie `firstPerVariable`; die Variable eines Zählers zählt bei ihm, nie zusätzlich an einem Gerät, in jeder Reihenfolge, Review 18.10.2026, M3): je Knoten eigener
  Wert, Summe der direkten Kinder und der Wert, mit dem er oben zählt (ohne eigenen Wert die Summe darunter, etwa ein
  Haushaltszähler ohne Leistung bei `period=now`). Die Zeile nennt den Rest „nicht erfasst“ nur, wenn er positiv ist.
  Fürs Haus zählt mit Zählern die oberste Ebene (`topLevelLine`), ohne Zähler bleibt der alte Satz mit der Gerätesumme.
  Nur Zähler unter den Treffern: keine Summe, einer kann den anderen enthalten. Vorbild ist `included_in_stat` von Home
  Assistant (core `energy/data.py`, frontend `common/sankey.ts`, 17.10.2026); anders als dort sperren ein Verweis ins Leere
  und ein Kreis die Zeile (`DeviceIndex::meterProblems`, geprüft in `findProblems`), und der Selbsttest meldet Zähler, unter
  denen in den letzten 30 Tagen mehr als 105 % gemessen ist (`meterOverruns`). Der Dialog hat die Auswahl `MeteredBy` mit
  „-“ und den Zählern der gespeicherten Liste (wie der Raum: ein unbekannter Wert bekommt einen eigenen Eintrag). Nur Typen mit Energie oder Leistung haben
  „gemessen von“ (`meteredByOf`); `UIDeviceChanged` leert das Feld beim Ausblenden (Review 18.10.2026, M5: ein alter Wert
  aus der Zeit als Steckdose sperrte eine Tür, das Feld war unsichtbar). Diagramme
  (Stufe c): Ein einzelner Zähler unter den Treffern zeigt in `deviceChart` seine direkten Kinder (`meterTree` liefert
  `children`) und als `unmetered` sich selbst minus die Kinder (Parameter `$total`), das Haus je Gerät mit Zählern die
  oberste Ebene gegen die Bilanz; vorher stand der Rest gegen alle Geräte, Geräte unter einem Zähler zählten doppelt. Das Verworfene unter „Teil der Bilanz“ (Zähler der
  Bilanz als Geräte) bleibt verworfen: Erzeugung gehört in die Bilanz, ein `meter` misst nur Verbrauch. Test
  `check-zaehler.php` mit `nuc_2026-10-17_zaehler.json` (Werte echt, Verschachtelung angenommen, rechnerisch belegt).
- **Quellen der Bilanz** (B82 Stufe b, Micha87 17.10.2026: drei PV-Anlagen mit je einem Zähler): Property
  `Sources` (Liste mit Type grid/solar/battery, Name, EnergyIn, EnergyOut, PowerIn, PowerOut, Invert), an den Index über
  `DeviceIndex::addSources`, Zuordnung zu den Schlüsseln in `SOURCE_KEYS`. Seit build 192 ist sie der einzige Ort der
  Bilanz (Burkhard 17.10.2026 zu build 191 mit Liste neben den Feldern: „Ist das nicht verwirrend“). Die alten Properties
  (`DeviceIndex::BALANCE`, `BALANCE_POWER`, `InvertGridPower`, `InvertBatteryPower`) bleiben registriert:
  `legacySources()` macht daraus je Art eine Zeile mit dem Namen der Art (ein einzelnes Leistungsfeld ohne Gegenfeld
  ist wie eine Zeile mit PowerIn ohne PowerOut ein Sensor mit Vorzeichen, also dieselbe Rechnung), `sources()` rechnet
  sie mit, und `ApplyChanges` schreibt sie vor die Liste, leert die Felder und übernimmt über den einmaligen Timer
  erneut (auch ein Skript, das noch die alten Properties setzt, landet so in der Liste). Test in `check-module.php`:
  Orakel ist die Rechnung der Bibliothek mit den Feldern im Konstruktor. Der Dialog entsteht je Art über
  `UISourceForm`/`UISourceChanged` wie der Gerätedialog (bei PV werden zweiter Zähler, zweite Leistung und Umkehr
  geleert, mit zweiter Leistung auch die Umkehr, die dann nichts dreht; ein unbekannter Typ bekommt in der Auswahl einen
  eigenen Eintrag, sonst verweigert die Konsole das Übernehmen; Review 18.10.2026, N9 und N10), sichtbar sind die Textspalten `EnergyLabel` und `PowerLabel` (`sourceLabels`; eine Leistung allein im ersten Feld steht dort als „… (mit Vorzeichen)“
  statt „… / -“, das nach einem fehlenden Feld aussah, Burkhard 17.10.2026). Das Modul übergibt dem
  Index keine Felder mehr. Intern zählen `balanceVars`/`powerVars` je Schlüssel alle Variablen,
  `$this->balance` bleibt die Anwesenheit (das Feld oder die erste Zeile), damit Diagramme, Kosten, Teile und Ringe
  unverändert über `balanceParts`/`houseConsumption`/`housePower` laufen. `partText` nennt bei mehreren Variablen die
  benannten („Netzbezug 0.2 (davon Tibber 0.1)“). Vorzeichen: Eine Zeile mit PowerIn ohne PowerOut ist ein Sensor mit
  Vorzeichen, Invert je Zeile (`signedOf`; Felder im Konstruktor der Bibliothek wie bis build 191 über `powerFields`);
  `signedPowers` nennt `source`. Fehler (`sourceProblems`: unknown_type, empty, solar_out, duplicate für eine Variable, die schon in einer früheren Zeile oder einem anderen Feld steht (Review 18.10.2026, M4), dazu fehlende Variablen mit
  dem Teil der Bilanz) sperren die ganze Bilanz (`sourcesUsable`), Anwesenheit im Modul über `balanceKeys`. Test
  `check-quellen.php`: Orakel „ein Eintrag in der Liste statt im Feld ändert nichts“ (nuc, betreute Anlage), zwei
  Netzanschlüsse mit dem Tibber Pulse (Werte echt, Anordnung angenommen), dazu `betreut_2026-10-17_quellen.json` mit
  echter Anordnung: zwei Wechselrichter, ein ausgebauter und ein neuer Netzzähler nacheinander, eine Batterie (die
  Archive waren vorher repariert, siehe `_quelle`). **Diagramme (Stufe c):** `sourceSeries` liefert je Quelle eines
  Teils die Abschnitte. Im Stapel des Hauses teilt `houseChart` PV direkt auf die Anlagen im Verhältnis ihres Ertrags je
  Abschnitt (wer einspeist oder lädt, misst niemand), mit `part` bekommt jede Quelle eine Reihe; Reihen tragen `source`
  und `shade`, die Seite färbt nach `SOURCE_SHADES` (`DESIGN.md`). Die Ringe bleiben je Art.
  **Das erste Aggregat eines Zählers trägt den Zeitpunkt seines ersten Satzes** (`AC_GetAggregatedValues`, auf jeder
  Stufe: Hoymiles Monat ab 14.09.2025 11:00, Batterie Tag ab 20.05.2026 15:45). Mehrere Zähler übereinander bekamen
  dadurch eine eigene Säule (Hardcopy Burkhard 17.10.2026: 13 Monate, „Warum fehlt hier der Septemberwert für
  Huawei?“). Deshalb liest `DeviceIndex` Reihen nur über `counterRows` und `historyRows`, die jeden Zeitpunkt auf den
  Beginn seines Abschnitts setzen (`sectionStart`), nie direkt über `counterSeries`/`history` des Backends. Die Stunde
  rechnet `sectionStart` mit dem Versatz zu UTC (`date('Z')`), nicht mit `mktime()`: Am Tag der Umstellung auf Winterzeit
  liefert das Archiv 02:00 zweimal, und `mktime()` legte die zweite auf die erste (Review 18.10.2026, Mitschnitt vom
  26.10.2025, Test `check-zeitumstellung.php`).
- **Plausibilität der Energiezähler im Selbsttest** (B78, Burkhard 14.10.2026; Anlass ein ModBus-Fehlwert in einer betreuten
  Anlage, der in der Punktwolke 32,8 Mio. kWh ergab): `DeviceIndex::counterJumps` prüft jeden Zähler der Bilanz und der Geräte
  einmal (die der Bilanz zuerst). Das Archiv summiert nur positive Sprünge, ein Fehlwert nach oben und zurück zählt also als
  Verbrauch. Erst die Probe übers ganze Archiv (Summe der Jahre gegen den Zuwachs seit dem ersten Satz), nur bei mehr als
  `JUMP_KWH` Abweichung je Monat Summe gegen Zuwachs des Zählerstands (`DeviceBackend::counterReadings`: letzter Rohwert über
  0 vor jeder Grenze; das erste Aggregat trägt den ersten Satz, deshalb hier die Reihe des Backends ohne `counterRows`).
  Ein Monat fällt auf, wenn die Abweichung über 2 kWh und über einem Fünftel des Medians aller Monate liegt, die Summe des
  Monats um mindestens drei Viertel der Abweichung über dem Median liegt (bei einer Rücksetzung oder einem Zählertausch
  bleibt die Summe normal, das Archiv zählt richtig) und der Zählerstand an beiden Grenzen über zwei Medianen liegt (sonst
  hat er kurz vorher neu angefangen). Die Schwellen sind an echten Fällen gemessen (Test `check-zaehlerstand.php`, Fixture
  `mix_2026-10-17_zaehlerstand.json`): Homematic-`ENERGY_COUNTER` setzen nach Stromausfällen auf 0 zurück (am nuc eine
  Kühltruhe in 34 Monaten, eine Fernsehsteckdose im Sommer 2023 mit doppeltem Verbrauch), die Wallbox sprang an einem Tag
  41-mal hin und her, die Wärmepumpe lieferte Milliarden kWh. Nicht erkannt wird ein dauerhafter Sprung nach oben (der Zähler
  geht dann auf). Laufzeit (betreute Anlage, 18.10.2026: Selbsttest 8,2 s, davon 5,5 s in `counterReadings`): Eine Ablesung
  kurz vor jetzt kostete bei Netzzählern, die alle paar Sekunden schreiben, 0,9 bis 1,4 s, eine an einer alten Monatsgrenze
  2 bis 100 ms; die Startzeit der Abfrage ändert daran nichts. Deshalb ist der Stand nach jetzt der Wert der Variable, wenn
  sie im letzten Tag aktualisiert wurde (`DeviceBackend::LATEST` als Zeitpunkt, nicht die Uhrzeit + 1, sonst fiel eine Sekunde später
  der Satz wieder teuer aus dem Archiv, Review 18.10.2026, N3; `FRESH_SECONDS`; ein stillgelegter Zähler kann nach einer Reparatur neuere Sätze im
  Archiv haben, so der alte Netzzähler dort nach dem Auffüllen vom 12. bis 14.05.), die Monatsprüfung liest nur die Grenzen
  der Monate, die überhaupt auffallen können (Summe um mehr als `JUMP_ABOVE` mal die Schwelle über dem Median, folgt aus den
  Bedingungen), und fehlt mit „Nullen ignorieren“ der Stand vor dem ersten Satz, beginnt die Gesamtprobe mit dem zweiten
  Monat. Die Zählerprüfung dort danach 1,3 bis 1,6 s statt 5,1 bis 7,2 s, gleicher Befund.
- **Datum und Uhrzeit** hängt das Modul an jede Antwort von `get_state` (`withNow()` in `module.php`, nicht in
  `DeviceIndex`, damit Skriptfunktionen und Bibliothekstests ohne sie bleiben). In Anleitung oder Werkzeugliste
  veraltete sie, weil Claude-App und HA beides lange zwischenspeichern.
- **Mediaplayer ein/aus** über den Schaltzustand, wenn er ein Wahrheitswert mit Aktion ist (Denon-Receiver am nuc:
  an nach 0,5 s, aus nach 0,1 s); der Statustext des HomeAssistant-Moduls wird nie geschaltet. Ohne solchen Schaltzustand,
  aber mit Wiedergabe pausiert `off` und `on` mit `type=media` setzt fort (`pausesOnOff`, `runPlayback`; B86): Im Vergleichslauf gegen HA
  (18.10.2026) wies `set_state` „Musik im Wintergarten aus“ am Sonos ab, das Modell brauchte dreimal von drei eine zweite
  Runde mit `stop`. Pause statt Stopp, damit `play` ohne Raum ihn wiederfindet (Burkhard). Die Beschreibung von `command`
  nennt das nur, wenn es so einen Player gibt (dieselbe Prüfung `pausesOnOff`, auch ein Statustext ohne Aktion). Ohne
  `type=media` lässt `on` solche Player aus und zählt sie, „Wintergarten an“ startet keine Musik (Burkhard 18.10.2026 nach
  dem Review); trifft `on` nur solche Player, nennt die Absage `type=media`. Test `check-musik-aus.php`.
- **Genannte Geräte gehören in `name`** (B87): Die Beschreibung von `type` in `get_state` und `set_state` sagt es. „Ist die
  Fußbodenheizung an?“ ergab vorher in 2 von 13 Läufen `type=thermostat` und „keine Fußbodenheizung“ (die Geräte sind Schalter).
- **Ein neues Feld** von `set_state` kostet: eine Fähigkeit in `ROLES`/`TYPE_ROLES`, einen Eintrag in
  `abilitiesOf()`, einen Zweig in `setState()`, eine Eigenschaft im Schema und ein Dialogfeld.

## Formular

- Bereiche „Endpunkt und Zugänge“, „Räume“, „Geräte“, „Szenen“ und „Energiebilanz“ als
  `ExpansionPanel`, in „Endpunkt und Zugänge“ dazu drei Unterbereiche (`PanelClaude`, `PanelHA`, `PanelOther`);
  der Zustand aller steht im Attribut `ExpandedPanels` (`UITogglePanel`, Namen in `PANELS`).
- **Raum im Gerätedialog als Auswahl** (B75, Wunsch doctee 13.10.2026; Burkhard: „nur einen angelegten oder keinen“): Das
  Dialogfeld `Room` ist ein Select mit „-“ (Wert "") und den Räumen der Raumliste, sortiert. Die Konsole füllt die Felder
  des Dialogs mit den Werten der Zeile, nicht mit `value` aus dem Formular (Testbox 13.10.2026). Ein Wert, der nicht
  wörtlich in der Raumliste steht, bekommt deshalb einen eigenen Eintrag, ein Alias als „Bad_DG (Bad oben)“, ein fehlender
  Raum als „… (nicht in der Raumliste)“; ein Wert ohne Eintrag sperrt das Übernehmen. Fehlende Räume (auch als Alias nicht
  bekannt) nennt über der Liste das Label `RoomsMissing` (`missingRooms()`), gesperrt wird nichts. Ein neuer Raum braucht
  erst die Raumliste und Übernehmen. Verworfen: Textfeld plus Hilfsauswahl der benutzten Räume (zwei Felder, unsichtbare
  Spalte mit `"save": false`). Ladezeit des Dialogs am nuc 0,44 bis 0,52 s, gleich bei 1 und 6 sichtbaren Variablenauswahlen.
- Der Bearbeitungsdialog einer Geräte-Zeile entsteht je Typ über `UIDeviceForm`; sichtbare Spalten sind
  Textspalten mit dem Namen der Variable, die IDs stehen in unsichtbaren Spalten.
- „Geräte aus Alexa vorschlagen“ öffnet ein `PopupAlert` mit Haken und bearbeitbarem Raum je Vorschlag;
  „Ausgewählte freigeben“ speichert direkt. Ein Knopf in `popup.buttons` zeigt jede Ausgabe rot im
  Popup, außer sie beginnt mit `MESSAGE:`.
- Fehlerhafte Zeilen sind rot (`rowColor`), die Meldungen stehen oben im Formular.
- **Jede Auswahl trägt einen Wert aus ihren Einträgen.** Sonst lehnt die Konsole das Übernehmen ab („Aktueller Wert ""
  ist nicht verfügbar“). Die Auswahlen der Zugänge (`PairingAccess`, `AddressAccess`, `TokenAccess`) bekommen ohne
  benutzbaren Zugang den Eintrag „-“. Anlass loerdy im Forum (13.10.2026): Ein neuer Zugang hat bis zum Übernehmen kein
  Token, das Übernehmen scheiterte, und das Token entstand nie.
- **Bereich „Endpunkt und Zugänge“ für Einsteiger** (build 80, Burkhard 12.10.2026: „für einsteiger wirkt es
  kompliziert“): Die Zugangsliste (3 bis 5 Zeilen hoch, darüber mit Scrollbalken) zeigt Name, Nur abfragen, Standardraum und die
  Textspalte `Connection`
  (`connectionOf`: per OAuth angemeldet mit dem Namen aus `clientLabel`, Claude-App und Claude Code wie die Unterbereiche, sonst Name und Host, Adresse mit Token oder Token im Header; nur diese Spalte kommt in
  `values`, die Tokens nicht). Token und `InPath` sind unsichtbare Spalten mit `save: true`, bearbeitet im eigenen
  `form` der Liste (Doku List, Beispiel 1). Das Token steht dort nur als unsichtbares Feld: Ein Zugang ohne Token
  bekommt in `ApplyChanges` eines (64 Hex-Zeichen, danach Property setzen und über einen einmaligen Timer erneut übernehmen: ein `IPS_ApplyChanges` auf sich selbst lehnt die Rust-Edition als re-entranten Aufruf ab, die Instanz blieb auf 104, Blindtest 12.10.2026). **Neues Token**
  (`UIGenerateToken`, mit Rückfrage) leert das Feld nur. Sichtbar wird ein Token erst auf Knopfdruck, und nur wo es
  gebraucht wird: **Token anzeigen** (`UIShowToken`, Auswahl `TokenAccess`) für Clients mit Header, **Adresse
  anzeigen** für Home Assistant; bei OAuth nie. Anlass (Burkhard 12.10.2026, build 81 bis 83): Im Kennwortfeld sah
  man das erzeugte Token nicht, als Textfeld stand es offen da, und gebraucht wird es fast nie.
  Darunter je Assistent ein aufklappbarer Unterbereich (Burkhard 12.10.2026: „das funktioniert“, verschachtelte
  ExpansionPanels): Claude-App mit Connect-Adresse, Auswahl `PairingAccess` direkt am Knopf Kopplungscode
  (vorausgewählt ein Zugang, an dem schon ein Claude-Connector hängt), Liste `OAuthClients` nur mit Einträgen und
  „✖ widerrufen“ als Spalte „Aktion“ (sonst als Aktion nicht zu erkennen, Spalten-`onClick` wählt laut Doku die Zeile aus, mit `confirm`), ohne Connect-Instanz nur `ClaudeNoConnect`, mit eingerichtetem, aber getrenntem Connect `ClaudeConnectDown` (`connectConfigured`; Anlass ein Verbindungsabbruch am nuc 12.10.2026); Home Assistant mit Auswahl `AddressAccess` (nur Zugänge mit
  `InPath`) und **Adresse anzeigen** (`UIShowAddress`, Token erst auf Knopfdruck; Adresse und Token stehen im Fenster
  `SecretPopup` in einem breiten Textfeld, `showSecret`: als Ausgabe eines Knopfs öffnete die Konsole eine Adresse im
  Browser, und ein Token passte nicht ins Meldungsfenster); andere Assistenten mit dem
  Endpunkt im Heimnetz (`HAGT_GetAddresses` nennt dieselben Adressen für Skripte und KI-Assistenten, das Token
  nur als `<token>`, Blindtest 12.10.2026; `localEndpoint`: erste private IPv4 aus `Sys_GetNetworkInfo`, Port 3777: laut Doku
  der feste Grundport; weitere Ports kommen nur über eigene WebServer-Instanzen, die bewusst nicht angezeigt werden,
  Burkhard 12.10.2026).

- **Spalte „Werkzeugliste“** (Burkhard 12.10.2026: „Kann man dem Anwender signalisieren, ob sich die Werkzeugliste beim
  Speichern verändert hat?“): Holt ein Assistent `tools/list`, merkt sich das Modul im Attribut `ToolLists` je Zugang
  (SHA-256 des Tokens) den Fingerabdruck (`McpProtocol::toolsFingerprint`, ohne Datumsangaben, die Beschreibungen nennen das
  heutige Datum), ob mit Apps, und die Zeit; geschrieben nur bei Änderung oder nach 10 min. `toolListStatus` vergleicht
  beim Öffnen des Formulars mit der heutigen Liste. So sieht man auch, ob claude.ai die Liste nach `ttlMs` (5 min) selbst
  nachholt.
- **Was nicht geklappt hat und Protokoll** (Burkhard 12.10.2026): `DeviceIndex::problem()` und
  `SceneIndex::problem()` nennen die Art (`unknown_room`, `unknown_name`, `ambiguous`, `not_understood`, `left_out`,
  `not_confirmed`, `failed` …; ein fahrender Rollladen zählt nicht, `underway` in `runLevel`). `logged()` merkt
  Aufrufe eines Zugangs mit Problem im Attribut `Problems` (50, neueste zuerst angezeigt, Hinweis je Art aus
  `PROBLEM_HINTS`), abgewiesene Anmeldungen je Absender zehn Minuten zusammengefasst; Skriptfunktionen nicht.
  Kein Knopf „Als Alias übernehmen“: dann bräuchten alle Arten etwas Ähnliches (Burkhard). Das Protokoll
  (`LogEnabled`, standardmäßig aus, `LogLines` 5000) schreibt `writeLog()` unter einer Sperre nach
  `IPS_GetLogDir()/homeagent_<ID>.log`, gewechselt nach 24 h oder `LogLines` zur `.old.log`; Zustand im Buffer
  `LogState`, nach einem Neustart aus der Datei (gezählt, nicht mit `file()` eingelesen; Anzeige und Download nehmen je Datei höchstens `LOG_TAIL_BYTES` = 3 MB vom Ende, `logText`: vorher sprengte der Download mit 5000 vollen Zeilen die 64 MB der SymBox, Review 14.10.2026). Leere Felder fallen in der Zeile weg (`initialize` ohne Argumente). Bei `initialize` und `server/discover` steht der Client mit Name, Version und Fähigkeiten in der Zeile (`McpProtocol::clientLabel`, B63). Ein Werkzeugaufruf nach 2026-07-28 nennt Name und Version des Clients (`McpProtocol::callerOf`, drittes Argument von `$callTool`): Ein Chat in der Claude-App lief am 12.10.2026 über „claude-code“ ohne Apps, zu sehen war das vorher nur am `server/discover` Sekunden davor. Weist schon das Protokoll einen Werkzeugaufruf ab (unbekanntes Werkzeug, Kopfzeilen passen nicht), steht er mit Client, Status und Fehler als `tools/call` im Protokoll (13.10.2026: Die Karten meldeten „Tool call failed“, im Protokoll war nichts zu sehen). Zeilen ohne Anmeldung (abgewiesene Origin, Anmeldung, `oauth/authorize` und `oauth/token`) schreibt `writeAnonymousLog`: je Absender höchstens 10 in 10 Minuten, alle zusammen 100, beim Erreichen einmal ein Hinweis (Buffer `LogQuiet`); Abrufe der OAuth-Metadaten und erlaubte Origins kommen nicht hinein. Vorher ließen sich mit solchen Zeilen die echten aus beiden Dateien verdrängen (Review 14.10.2026). **Origin-Prüfung** (MCP, DNS-Rebinding, build 107): Eine `Origin` muss ohne Port auf einen eigenen Namen zeigen (`originAllowed`: IP-Adressen aus `Sys_GetNetworkInfo`, localhost, Rechnername auch mit `LOCAL_SUFFIXES`, Connect-Adresse; bis build 171 der Host der Anfrage, der bei DNS-Rebinding aber denselben fremden Namen trägt, Review 14.10.2026), sonst 403 vor der Anmeldung, kein Fehlversuch; die OAuth-Pfade sind ausgenommen. Gemessen am nuc 12.10.2026: Claude Code, Home Assistant und die Claude-App über claude.ai schicken keine `Origin`. Die Claude-App spricht seitdem 2026-07-28 (`server/discover`). Herunterladen über den Button-Parameter `download` (Data-URL, beide
  Dateien, die alte zuerst). Tokens, Adressen und Kopplungscodes übergibt kein Aufrufer.

## Gestaltung der MCP Apps

**`DESIGN.md` ist das Regelwerk für Gerätekarten und Diagramme** (Farben je Art und Zustand, Tokens für hell und dunkel,
Aufbau der Karten, Bedienelemente, Achsen, Legenden, Ringe; Linien und Band des Verlaufs als monotone Kurve
`smoothPath`, die nie über einen Messwert hinausschießt; die Schrift der Zeichnungen wächst auf schmalen Bildschirmen
mit, `axisScale`; Ersatzwerte für Text und Hintergrund aus den Themes der Kachel-Visu, Hinweis pitti 14.10.2026). Jede Änderung an `devices.html` oder `chart.html` hält sich
daran oder ändert zuerst das Regelwerk (Burkhard 13.10.2026). Vor dem Commit hell und dunkel mit echten Daten rendern
(`dev/showcase.php`, `dev/devices_render.php`, `dev/chart_render2.php`; `dev/` ist nicht im Repo) und neue Abweichungen
unter „Bekannte Abweichungen“ eintragen. Tooltips (SVG-`title`) zeigt kein Screenshot; ihre Texte liest man aus der
Ausgabe von Chrome mit `--headless=new --dump-dom` auf dieselbe gerenderte Seite. Die Prüfung der Seiten in `check-module.php` verbietet jedes Nachladen (Skript,
Stylesheet, `@import`, Bilder über http, `fetch`); die Größe ist nur eine Bremse bei 100 KB gegen eine eingebettete Bibliothek.
Vorher wuchs die Grenze mit jeder Diagrammart mit (20 bis 50 KB) und prüfte nichts mehr (Burkhard 13.10.2026).

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
