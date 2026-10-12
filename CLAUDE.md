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
  ein Fehler der Zeile. Nach 20 abgewiesenen Anmeldungen in einer Minute ist der Hook 60 s zu.
- **Ein fehlerhafter Eintrag sperrt nur sich selbst**: Zugang (`usableAccesses()`), Gerät
  (`usableDevices()`), Szene (`usableScenes()`), Energiebilanz als Ganzes (`usableBalance()`). Geprüft wird
  bei jedem Aufruf, eine gelöschte Variable sperrt ihr Gerät also sofort, ohne Übernehmen. Eine Anfrage
  prüft nur einmal (`checkedOnce` in Hook und Selbsttest), und Beschriftungen entstehen nur für fehlerhafte
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
  mit einem Zugang, Attribut `OAuthGrants`, überlebt einen Neustart). Zugriffstokens liegen nur im
  Buffer `OAuthTokens`; nach einem Neustart erneuert der Client. Ein ungültiger Code oder ein ungültiges
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
  selbst. Bei mehr als 50 Kopplungen fallen die am längsten nicht erneuerten heraus.
  Ende zu Ende mit der Claude-App erprobt am 11.10.2026 (Connector mit „Jetzt anmelden“ und CIMD,
  Kopplungscode, danach `get_state`); die Token-Anfrage steht als Mitschnitt in der Fixture.

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
  sonst aus dem Modul des Geräts (`SymconDeviceBackend::OPEN_AT_MAX_MODULES`: HomeAssistant-Modul, Ident
  `cover_…`, 100 offen), sonst ist der kleinste Wert offen; der Haken „Richtung umkehren“ dreht es um.
  Anlass: Im Blindtest vom 11.10.2026 fuhren HA-Rollläden (Schieberegler 0 bis 100 %) falsch herum.
- **Hinweistext für Skripte und KI-Assistenten** (Label im Formular): Ein Agent hat damit allein über den
  MCP-Server von Symcon eine Instanz eingerichtet (Blindtest 11.10.2026). Was er dort nicht fand (Clients
  mit Token in der Adresse, Connect-Adresse, Token erzeugen, Raumnamen, Felder je Typ, Richtung prüfen),
  steht seitdem drin; neue Felder und Typen gehören auch dorthin.
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
  Geräte stehen als Vokabular in der Beschreibung (kein `enum`). Die Rollen sind nicht an den Typ gebunden;
  Lüftung, Thermostat-Betriebsart und Radiosender sollen denselben Weg nehmen. Befehle kurz hintereinander
  brauchten am Entfeuchter über 2 s (gemessen 11.10.2026), die Antwort sagt dann „noch nicht bestätigt“.
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
  (`runVolume`, `runMute`, gemeinsames Warten in `confirmJobs`). Die Lautstärke zählt in Prozent des
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
- **Datum und Uhrzeit** hängt das Modul an jede Antwort von `get_state` (`withNow()` in `module.php`, nicht in
  `DeviceIndex`, damit Skriptfunktionen und Bibliothekstests ohne sie bleiben). In Anleitung oder Werkzeugliste
  veraltete sie, weil Claude-App und HA beides lange zwischenspeichern.
- **Mediaplayer ein/aus** über den Schaltzustand, wenn er ein Wahrheitswert mit Aktion ist (Denon-Receiver am nuc:
  an nach 0,5 s, aus nach 0,1 s); der Statustext des HomeAssistant-Moduls wird beim Schalten abgewiesen.
- **Ein neues Feld** von `set_state` kostet: eine Fähigkeit in `ROLES`/`TYPE_ROLES`, einen Eintrag in
  `abilitiesOf()`, einen Zweig in `setState()`, eine Eigenschaft im Schema und ein Dialogfeld.

## Formular

- Bereiche „Endpunkt und Zugänge“, „Räume“, „Geräte“, „Szenen“ und „Energiebilanz“ als
  `ExpansionPanel`, in „Endpunkt und Zugänge“ dazu drei Unterbereiche (`PanelClaude`, `PanelHA`, `PanelOther`);
  der Zustand aller steht im Attribut `ExpandedPanels` (`UITogglePanel`, Namen in `PANELS`).
- Der Bearbeitungsdialog einer Geräte-Zeile entsteht je Typ über `UIDeviceForm`; sichtbare Spalten sind
  Textspalten mit dem Namen der Variable, die IDs stehen in unsichtbaren Spalten.
- „Geräte aus Alexa vorschlagen“ öffnet ein `PopupAlert` mit Haken und bearbeitbarem Raum je Vorschlag;
  „Ausgewählte freigeben“ speichert direkt. Ein Knopf in `popup.buttons` zeigt jede Ausgabe rot im
  Popup, außer sie beginnt mit `MESSAGE:`.
- Fehlerhafte Zeilen sind rot (`rowColor`), die Meldungen stehen oben im Formular.
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

- **Was nicht geklappt hat und Protokoll** (Burkhard 12.10.2026): `DeviceIndex::problem()` und
  `SceneIndex::problem()` nennen die Art (`unknown_room`, `unknown_name`, `ambiguous`, `not_understood`, `left_out`,
  `not_confirmed`, `failed` …; ein fahrender Rollladen zählt nicht, `underway` in `runLevel`). `logged()` merkt
  Aufrufe eines Zugangs mit Problem im Attribut `Problems` (50, neueste zuerst angezeigt, Hinweis je Art aus
  `PROBLEM_HINTS`), abgewiesene Anmeldungen je Absender zehn Minuten zusammengefasst; Skriptfunktionen nicht.
  Kein Knopf „Als Alias übernehmen“: dann bräuchten alle Arten etwas Ähnliches (Burkhard). Das Protokoll
  (`LogEnabled`, standardmäßig aus, `LogLines` 5000) schreibt `writeLog()` unter einer Sperre nach
  `IPS_GetLogDir()/homeagent_<ID>.log`, gewechselt nach 24 h oder `LogLines` zur `.old.log`; Zustand im Buffer
  `LogState`, nach einem Neustart aus der Datei. Leere Felder fallen in der Zeile weg (`initialize` ohne Argumente). Herunterladen über den Button-Parameter `download` (Data-URL, beide
  Dateien, die alte zuerst). Tokens, Adressen und Kopplungscodes übergibt kein Aufrufer.

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
