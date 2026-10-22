# Home Agent einrichten und aktualisieren

[English version](EINRICHTUNG.en.md)

Diese Anleitung führt durch die erste Einrichtung und durch jedes spätere Update. Was die einzelnen Felder
bedeuten, steht in der [README](README.md).

## Installieren

Sie brauchen Symcon ab Version 9.0. Das Modul installieren Sie in der Verwaltungskonsole aus dem Module Store
und legen danach eine Instanz **Home Agent** an. Sie zeigt zunächst den Status 104, weil es noch keinen Zugang
gibt.

## Einrichten

Für das Einrichten gibt es zwei Wege. Beide ergeben dieselbe Instanz, und Sie können sie mischen.

### Im Formular

Räume, Geräte und Zugänge tragen Sie selbst ein, mit einer Alexa-Instanz geht der größte Teil per Vorschlag. Die
Schritte stehen in der README unter [Erste Schritte](README.md#erste-schritte).

### Mit einem KI-Assistenten

Ein KI-Assistent auf Ihrem Rechner kann die Arbeit übernehmen. Er liest im Formular den Hinweistext für
Skripte und KI-Assistenten, geht alle Instanzen und Variablen Ihrer Anlage durch und schlägt Räume, Geräte und
die Energiebilanz als Tabelle vor. Gespeichert wird erst, wenn Sie zugestimmt haben. Davor sichert er die
bisherige Konfiguration, danach führt er den Selbsttest aus. Sie prüfen nur noch die Tabelle.

Hat Ihr Assistent schon auf anderem Weg Zugriff auf die Anlage, brauchen Sie die folgenden vier Punkte nicht. Im
Auftrag weiter unten ersetzen Sie dann nur den Absatz „Zugriff“ durch einen Satz, der diesen Weg nennt.

Sonst spricht der Assistent mit der JSON-RPC-Schnittstelle von Symcon. Das braucht vier Dinge:

1. **Ein Fernzugriff-Kennwort.** Ohne Kennwort nimmt die Schnittstelle im lokalen Netz Aufrufe von jedem an,
   der die Adresse kennt. Wie Sie es setzen, steht in der Symcon-Dokumentation unter
   [Fernzugriff](https://www.symcon.de/de/service/dokumentation/komponenten/fernzugriff/), auf der SymBox etwa
   in der Weboberfläche unter Einstellungen → Fernzugriff. Der Benutzername ist immer die E-Mail-Adresse Ihrer
   Lizenz.
2. **Einen Assistenten, der Befehle ausführen kann**, etwa Claude Code, Codex oder Gemini CLI, auf einem Rechner
   im selben Netz wie die Anlage. Ein Chat in einer App oder im Browser reicht nicht, er kann keine Befehle
   ausführen.
3. **curl.** Windows 10 und 11, macOS und Linux bringen es mit. In der Windows PowerShell heißt es `curl.exe`,
   denn `curl` allein ist dort ein anderer Befehl.
4. **Eine Datei mit den Zugangsdaten.** curl kann seine Angaben aus einer Datei lesen (Schalter `-K`). Legen Sie
   dafür eine Textdatei an, etwa `symcon.curl` in Ihrem Benutzerordner, nicht in einem Ordner, den Sie mit Git
   oder einer Cloud teilen. Der Name ist frei wählbar:

   ```
   url = "http://192.168.1.10:3777/api/"
   user = "lizenz@example.com:IhrFernzugriffKennwort"
   ```

   Weil curl Adresse und Anmeldung von dort liest, steht das Kennwort nie in einem Befehl. Der Assistent zeigt
   seine Befehle im Chat an, und manche Clients speichern sie im Verlauf. Zur Probe legen Sie daneben eine Datei
   `anfrage.json` an:

   ```json
   {"jsonrpc": "2.0", "method": "IPS_GetKernelVersion", "params": [], "id": 1}
   ```

   und rufen im selben Ordner auf:

   ```
   curl.exe -s -K symcon.curl -H "Content-Type: application/json" -d @anfrage.json
   ```

   | Teil | Bedeutung |
   |---|---|
   | `curl.exe` | das Programm, das die Anfrage schickt |
   | `-s` | ohne Fortschrittsanzeige |
   | `-K symcon.curl` | liest Adresse (`url`) und Anmeldung (`user`) aus der Datei mit den Zugangsdaten |
   | `-H "Content-Type: application/json"` | sagt Symcon, dass JSON kommt |
   | `-d @anfrage.json` | schickt den Inhalt von `anfrage.json`, das `@` heißt „aus Datei“ |

   Zurück kommt die Version Ihrer Anlage, etwa `{"id":1,"jsonrpc":"2.0","result":"9.1"}`. Steht dort
   „Invalid Username/Password for remote access!“, stimmen Benutzer oder Kennwort nicht. Nach zu vielen
   Fehlversuchen prüft Symcon Kennwörter erst langsamer und dann gar nicht mehr.

**Bevor Sie anfangen:** Mit diesen Zugangsdaten darf der Assistent alles, was Sie in der Verwaltungskonsole
dürfen. Der Auftrag unten verbietet ihm, Geräte zu schalten oder andere Instanzen zu ändern, aber er bleibt ein
Sprachmodell. Bestätigen Sie seine Befehle deshalb einzeln, statt sie pauschal freizugeben, und legen Sie vorher
eine Sicherung der Anlage an.

Dann geben Sie dem Assistenten diesen Auftrag. Die Angaben in spitzen Klammern ersetzen Sie vorher: den Pfad zur
Datei mit den Zugangsdaten und in Schritt 5 die Assistenten, die Sie mit Home Agent verbinden wollen.
```
Richte in Symcon das Modul „Home Agent“ ein. Es ist bereits aus dem Module Store installiert.

Zugriff: Du erreichst Symcon über seine JSON-RPC-Schnittstelle. Adresse und Anmeldung stehen in der
curl-Konfigurationsdatei <Pfad zur Datei mit den Zugangsdaten>. Zeig ihren Inhalt nie an und schreib ihn in
keine andere Datei. Jeder Aufruf ist ein POST mit dem Körper
  {"jsonrpc": "2.0", "method": "<Funktion>", "params": [<Parameter>], "id": 1}
am besten aus einer Datei:
  curl.exe -s -K <Pfad zur Datei mit den Zugangsdaten> -H "Content-Type: application/json" -d @anfrage.json
(unter Linux und macOS curl statt curl.exe). Jede Symcon-Funktion geht so, auch die des Moduls (HAGT_…).
Das Formular einer Instanz liest du mit IPS_GetConfigurationForm(<InstanzID>).

So gehst du vor:
0. Sieh nach, ob es schon Instanzen des Moduls gibt, und nenne sie mir mit Name, Ort, Status und
   Anzahl der Geräte. Frag mich dann, ob du eine davon verwenden oder eine neue anlegen sollst. Bei
   einer neuen frag nach der Zielkategorie und dem Namen. Leg nichts an, bevor ich geantwortet habe.
1. Lies zuerst im Konfigurationsformular der Instanz den Text „Hinweis für Skripte und
   KI-Assistenten“. Dort stehen die Properties Accesses, Rooms und Devices, die Gerätetypen und die
   Felder je Typ. Halte dich daran und rate keine Feldnamen.
2. Räume: Schlag eine Raumliste vor, mit den Namen, die wir im Alltag sprechen (z. B. Küche, Bad oben,
   Wohnzimmer), dazu Etage und Aliase (z. B. „Kinderzimmer“ als Alias für „Leon“). Keine technischen
   Zusätze. Jeder Raumname darf nur einmal vorkommen. Gibt es einen Raum auf mehreren Etagen, bekommt
   jeder einen eigenen Namen (z. B. „Küche EG“ und „Küche DG“) und beide den gemeinsamen Namen als
   Alias („Küche“). Das gilt für neue Räume; vorhandene behalten ihren Namen, eine Umbenennung schlägst
   du nur in der Tabelle vor. Gibt es eine Alexa-Instanz, nimm deren Gerätenamen und Räume als Vorlage,
   sonst die Kategorien im Objektbaum.
3. Geräte: Schlag eine Freigabeliste vor mit Lichtern, Steckdosen, Rollläden, Fenstern und Türen,
   Thermostaten, Mediaplayern und Messwerten wie Temperatur und Luftfeuchte. Für alles, was schalten
   soll, nur Variablen mit Aktion; Messwerte ohne Aktion sind in Ordnung. Sprechbare Namen, je Raum
   eindeutig. Leistung und Energiezähler mit eintragen, wo es sie gibt.
   Geh dafür alle Geräteinstanzen durch, auch ModBus-, MQTT- und Cloud-Geräte. Zu Heizung und Lüftung
   gehören Wärmepumpe, Warmwasser, Klimageräte und Lüftungsanlagen. Bei einer Wärmepumpe such neben
   Leistung und Stromzähler auch einen Wärmezähler oder eine Wärmeleistung und trag sie ein. Zwei Zeilen
   für Heizen und Warmwasser schlägst du nur vor, wenn Wärme und Strom beide getrennt gemessen werden;
   sonst eine Zeile, und die getrennte Wärme nennst du als offene Frage. Zwischenzähler wie
   Unterverteilungen schlägst du als Typ „meter“ mit „gemessen von“ vor, einen Strompreis als
   PriceVariableID.
   Nicht freigeben: Schlösser, Alarmanlage und alles Sicherheitsrelevante, dazu zählen auch Türöffner,
   Garagentore und Tore. Relais, die eine smarte Lampe mit Strom versorgen, nicht als Licht freigeben;
   ihre Leistung darf die Lampe bekommen. Steht so etwas schon in der Instanz, schlag vor, es zu
   entfernen. Melder wie Wasser- oder Rauchmelder dürfen zum Abfragen hinein. Steckdosen, an denen
   Netzwerk, Server oder die Hausautomation selbst hängen, nur zum Abfragen (ReadOnly), ebenso Heizung
   und Lüftung zunächst.
   Bei Geräten, die schon in der Instanz stehen, bleiben ReadOnly, Invert und die Namen, wie sie sind;
   Typ oder Variablen eines solchen Geräts änderst du nur, wenn ich in der Tabelle zustimme. Ist ein Gerät
   mehrfach angebunden (etwa über Matter und eine Bridge), nimm die Anbindung mit den jüngsten Werten und
   nenne die andere. Einen Energiezähler, der nicht als Zähler archiviert ist, und eine Leistung oder
   einen Zähler mit einer Einheit, die der Hinweistext nicht nennt, lässt du weg und nennst sie als
   offene Frage.
   Schlag auch die Energiebilanz als Liste „Quellen“ vor: je Netzanschluss, PV-Anlage und Batterie eine
   Zeile mit Zähler und Leistung.
4. Zeig mir beide Listen als Tabelle (Raum, Name, Typ, Variablen) und warte auf mein OK, bevor du
   etwas speicherst. Kennzeichne, was schon in der Instanz steht und was neu ist oder sich ändert. Bei
   mehr als etwa 50 Geräten zeigst du im Chat nur Neues und Änderungen und schreibst die ganze Tabelle in
   eine Datei im aktuellen Arbeitsordner.
5. Nach meinem OK: Sichere zuerst die Properties Rooms, Devices, Sources und Accesses, so wie sie
   jetzt sind, mit Tokens als <token>, in eine Datei im aktuellen Arbeitsordner mit Datum und Uhrzeit im
   Namen, damit keine frühere Sicherung überschrieben wird. Trag dann Räume und Geräte ein und leg je
   Assistent, den ich nutze, einen Zugang an: <z. B. „Home Assistant“ mit Token in der Adresse
   (InPath), „Claude-App“ ohne Token in der Adresse>. Steht dort noch dieses Beispiel, frag mich, welche
   Assistenten ich nutze. Zugänge legst du nur für Assistenten an, die noch keinen haben. Vorhandene
   Räume, Geräte und Zugänge bleiben erhalten, außer ich habe einer Änderung in der Tabelle zugestimmt.
   Das Token lässt du leer, das Modul erzeugt es beim Übernehmen selbst. Dann übernehmen; Status und
   Token kommen erst einige Sekunden danach. Lies dann beides erneut und prüf, dass jeder Zugang ein
   Token hat, ohne es auszugeben. HAGT_RunSelfTest ausführen und mir Fehler und die erkannte Richtung
   jedes Rollladens zeigen. Jeden Hinweis zu Energie- und Wärmezählern und zum Vorzeichen einer
   Leistung nennst du mir als offene Frage. Wo offen und geschlossen vertauscht sind, Invert setzen.
   Prüf bei Fenstern und Türen, ob „geschlossen“ zum aktuellen Zustand passt; bei einer Abweichung
   änderst du nichts, sondern fragst mich.

Regeln:
- Schalte nichts und löse keine Aktion an Geräten aus. Nur lesen und die Instanz konfigurieren.
- Gib nie ein Token aus. Adressen nur über HAGT_GetAddresses, dort steht <token> als Platzhalter.
- Ändere keine anderen Instanzen.
- Am Ende eine kurze Zusammenfassung: Anzahl der Räume und Geräte, offene Fragen, Ergebnis des
  Selbsttests.
```

Der Assistent fragt zuerst, welche Instanz er nehmen soll, und legt Ihnen später die Tabelle vor. Gehen Sie sie in
Ruhe durch: Sprechen Sie die Namen so aus, wie Sie sie im Alltag sagen würden? Fehlt ein Gerät, oder ist eines
dabei, das der Assistent nicht schalten soll? Erst Ihr OK lässt ihn speichern. Seine offenen Fragen am Ende
betreffen meist Zähler, die Symcon nicht als Zähler archiviert, oder das Vorzeichen einer Batterieleistung. Die
README erklärt beides unter [Die Freigabeliste](README.md#die-freigabeliste).

### Danach

Verbinden Sie Ihre Assistenten so, wie es in der README unter [Erste Schritte](README.md#erste-schritte) in
Schritt 6 steht, und probieren Sie die Fragen aus Schritt 7.

## Aktualisieren

1. **Update einspielen.** In der Verwaltungskonsole unter Module Store das Update von Home Agent einspielen. Die
   Instanz behält ihre Konfiguration.
2. **Releaseinfo lesen.** Sie nennt neue Gerätetypen und Felder und alles, was sich im Verhalten ändert.
3. **Instanz prüfen.** Das Formular öffnen: Der Status sollte aktiv sein, oben stehen keine Meldungen. Dann
   **Selbsttest ausführen**. Felder, die ein Update ersetzt, übernimmt das Modul selbst.
4. **Neues nutzen.** Neue Felder tragen Sie im Formular nach. Bei vielen Geräten lassen Sie den Auftrag von oben
   noch einmal laufen: Er behält, was schon eingetragen ist, und schlägt nur Neues und Änderungen vor.
5. **Werkzeugliste auffrischen.** Die Spalte **Werkzeugliste** in der Liste der Zugänge zeigt je Assistent, ob er
   die neue Liste schon kennt. In der Claude-App öffnen Sie dafür einen neuen Chat; hilft das nicht, trennen Sie
   den Connector und verbinden ihn neu. Home Assistant holt die Liste von sich aus nur alle 30 Minuten, schneller
   geht es, wenn Sie die Integration Model Context Protocol neu laden.

## Wenn etwas nicht geht

| Was Sie sehen | Ursache | Was Sie tun |
|---|---|---|
| Status 104, Assistenten bekommen HTTP 503 | Es gibt keinen Zugang mit Token. | einen Zugang anlegen und übernehmen |
| Status 201, Assistenten bekommen HTTP 503 | Die Raumliste hat einen Fehler, oder kein Zugang ist fehlerfrei. | die rot markierten Zeilen und die Meldungen oben im Formular prüfen |
| Assistent bekommt HTTP 401 | Token, Adresse oder Kopplung stimmen nicht mehr, etwa nach **Neues Token**. | im Client Token oder Adresse erneuern, die Claude-App neu koppeln |
| ein Gerät fehlt in den Antworten | Seine Zeile ist fehlerhaft und sperrt nur sich selbst. | die rote Zeile in der Freigabeliste prüfen |
| ein Befehl kommt nicht an | Das zeigt der Bereich **Was nicht geklappt hat**. | dem Hinweis in der Zeile folgen |

Für eine genauere Suche schalten Sie im selben Bereich das **Protokoll** ein. Es hält jede Anfrage mit Antwort
fest, Tokens stehen nie darin.
