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
Auftrag weiter unten ersetzen Sie dann die Zeile mit ZUGANGSDATEI und den Absatz „Zugriff“ durch einen Satz, der
diesen Weg nennt.

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
   dafür auf dem Rechner, auf dem der Assistent läuft (nicht auf der Anlage), eine Textdatei an, etwa `symcon.curl`
   in Ihrem Benutzerordner, nicht in einem Ordner, den Sie mit Git oder einer Cloud teilen. Der Name ist frei wählbar:

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

Dann geben Sie dem Assistenten diesen Auftrag. Liegt Ihre Datei mit den Zugangsdaten nicht als `symcon.curl` in
Ihrem Benutzerordner, tragen Sie in der zweiten Zeile ihren Pfad ein; sonst bleibt der Auftrag, wie er ist. Welche
Assistenten Sie verbinden wollen, fragt er Sie selbst.
```
Meine Angabe (vor dem Absenden prüfen):
ZUGANGSDATEI = die Datei symcon.curl in meinem Benutzerordner

Richte in Symcon das Modul „Home Agent“ ein. Es ist bereits aus dem Module Store installiert.

Zugriff: Du erreichst Symcon über seine JSON-RPC-Schnittstelle. Adresse und Anmeldung stehen in der
curl-Konfigurationsdatei ZUGANGSDATEI. Findest du sie nicht, frag mich, statt den Rechner zu durchsuchen.
Zeig ihren Inhalt nie an und schreib ihn in keine andere Datei. Jeder Aufruf ist ein POST mit dem Körper
  {"jsonrpc": "2.0", "method": "<Funktion>", "params": [<Parameter>], "id": 1}
am besten aus einer Datei:
  curl.exe -s -K ZUGANGSDATEI -H "Content-Type: application/json" -d @anfrage.json
(unter Linux und macOS curl statt curl.exe). Jede Symcon-Funktion geht so, auch die des Moduls (HAGT_…).
Namen und Parameter aller Symcon-Funktionen stehen im Index https://www.symcon.de/de/llms/function-index.md.
Hol ihn mit curl und such darin, statt Namen zu raten. Eine Antwort mit „error“ ist ein Fehler, auch wenn
curl nichts meldet.

Das Modul: Seine Instanzen liefert IPS_GetInstanceListByModuleID("{89E40AE0-BB2A-40B8-A16F-C9C5FFBFC09F}"),
eine neue legt IPS_CreateInstance mit derselben ID an. Das Formular einer Instanz liest du mit
IPS_GetConfigurationForm(<InstanzID>), ihre Konfiguration nur mit HAGT_ExportConfiguration(<InstanzID>);
jede andere Funktion gibt die Tokens der Zugänge im Klartext aus. Gespeichert wird mit
IPS_SetProperty(<InstanzID>, "<Property>", <Wert>), eine Liste als JSON-Text, und danach
IPS_ApplyChanges(<InstanzID>). Die Property Accesses schreibst du nie selbst, einen Zugang legt
HAGT_AddAccess(<InstanzID>, "<Name>", <ReadOnly>, <InPath>, "<Standardraum>") an.

So gehst du vor:
0. Sieh nach, ob es schon Instanzen des Moduls gibt, und nenne sie mir mit Name, Ort, Status und
   Anzahl der Geräte. Frag mich dann, ob du eine davon verwenden oder eine neue anlegen sollst. Bei
   einer neuen frag nach der Zielkategorie und dem Namen und, falls es schon eine Instanz gibt, ob du
   deren Räume, Geräte und Quellen übernehmen sollst; übernommene gelten danach als vorhanden. Frag
   im selben Zug, welche Assistenten ich mit Home Agent verbinden will (meist einer, etwa die
   Claude-App). Leg nichts an, bevor ich geantwortet habe.
1. Lies im Konfigurationsformular der Instanz den Text „Hinweis für Skripte und KI-Assistenten“. Dort
   stehen alle Properties, die Gerätetypen und die Felder je Typ. Halte dich daran und rate keine
   Feldnamen.
2. Räume: Schlag eine Raumliste vor, mit den Namen, die wir im Alltag sprechen (z. B. Küche, Bad oben,
   Wohnzimmer), dazu Etage und Aliase (z. B. „Kinderzimmer“ als Alias für „Leon“). Keine technischen
   Zusätze, jeder Raumname nur einmal. Neue Räume, die es auf mehreren Etagen gibt, bekommen je Etage
   einen eigenen Namen (z. B. „Küche oben“ und „Küche unten“) und beide den gemeinsamen als Alias
   („Küche“). Vorhandene Räume behalten ihren Namen, eine Umbenennung schlägst du nur vor. Was schon in
   der Instanz steht oder aus Schritt 0 übernommen ist, geht vor. Für alles andere nimm die Gerätenamen
   und Räume einer Alexa-Instanz als Vorlage (Modul-ID {CC759EB6-7821-4AA5-9267-EF08C6A6A5B3}), gibt es
   keine, die Kategorien im Objektbaum.
3. Geräte: Geh alle Geräteinstanzen durch, auch ModBus-, MQTT- und Cloud-Geräte, und schlag eine
   Freigabeliste vor, mit sprechbaren Namen, je Raum eindeutig.
   Aufnehmen:
   - Lichter, Steckdosen, Rollläden, Fenster und Türen, Thermostate, Mediaplayer, Heizung und Lüftung
     (Wärmepumpe, Warmwasser, Klimageräte, Lüftungsanlagen) und Messwerte wie Temperatur und
     Luftfeuchte. Für alles, was schalten soll, nur Variablen mit Aktion (HasAction(<VariablenID>)
     liefert true); Messwerte ohne Aktion sind in Ordnung.
   - Melder zum Abfragen: Wassermelder als water_leak, Rauchmelder als measurement.
   - Bei Heizkörperthermostaten auch die Ventilstellungen, mehrere Antriebe eines Raums am selben
     Thermostat (bis zu drei).
   - Leistung und Energiezähler, wo es sie gibt. Bei einer Wärmepumpe auch Wärmezähler oder
     Wärmeleistung; zwei Zeilen für Heizen und Warmwasser nur, wenn Wärme und Strom beide getrennt
     gemessen werden, sonst eine.
   - Zwischenzähler wie Unterverteilungen als Typ meter mit MeteredBy, einen Strompreis als
     PriceVariableID, die Energiebilanz als Liste Sources: je Netzanschluss, PV-Anlage und Batterie
     eine Zeile mit Zähler und Leistung.
   - Ist ein Gerät erkennbar mehrfach angebunden (etwa über Matter und eine Bridge, mit gleichem Namen
     und Raum), nimm die Anbindung, deren Variablen zuletzt aktualisiert wurden (VariableUpdated aus
     IPS_GetVariable, nicht VariableChanged), und nenne die andere.
   Nur abfragen (ReadOnly): Heizung und Lüftung, dazu Steckdosen, an denen Netzwerk, Server oder die
   Hausautomation selbst hängen.
   Nicht aufnehmen, auch nicht zum Abfragen: Schlösser, Alarmanlage, Türöffner, Garagentore und Tore.
   Relais, die eine smarte Lampe mit Strom versorgen, nicht als Licht; ihre Leistung darf die Lampe
   bekommen. Steht so etwas schon in der Instanz, schlag vor, es zu entfernen.
   Als offene Frage statt eines Eintrags: ein Gerät mit unklarem Raum (nicht mit leerem Raum
   eintragen), ein Zähler, bei dem unklar ist, welcher Zähler über ihm misst (MeteredBy), ein Relais, hinter dem
   vielleicht eine smarte Lampe hängt, zwei Anbindungen, die vielleicht dasselbe Gerät sind, ein
   Energiezähler, der nicht als Zähler archiviert ist, eine Leistung oder ein Zähler mit einer Einheit,
   die der Hinweistext nicht nennt oder die mehrere Kanäle misst, Zähler von Batterie und PV mit
   unklarer Richtung oder Abgrenzung, die getrennte Wärme einer Wärmepumpe mit nur einer Zeile, ein
   Stellantrieb, bei dem unklar ist, zu welchem Thermostat er gehört.
   Bei Geräten, die schon in der Instanz stehen, bleiben ReadOnly, Invert und die Namen, wie sie sind;
   Typ oder Variablen änderst du nur, wenn ich in der Tabelle zustimme.
4. Zeig mir alle Vorschläge als Tabellen: Räume (Name, Etage, Aliase), Geräte (Raum, Name, Typ,
   Variablen), Quellen (Art, Name, Zähler, Leistung) und den Strompreis. Kennzeichne, was schon in der
   Instanz steht und was neu ist oder sich ändert. Bei mehr als etwa 50 Geräten zeigst du im Chat nur
   Neues und Änderungen und schreibst die ganzen Tabellen in eine Datei im aktuellen Arbeitsordner.
   Dann wartest du auf mein OK. Es gilt für die Tabellen, die offenen Fragen bleiben draußen; was ich
   davon später beantworte, trägst du in einem zweiten Durchgang nach, wieder mit Sicherung.
5. Nach meinem OK: Schreib zuerst die Ausgabe von HAGT_ExportConfiguration(<InstanzID>) unverändert in
   eine Datei im aktuellen Arbeitsordner mit Datum und Uhrzeit im Namen. Trag dann Räume, Geräte,
   Quellen und Strompreis ein und übernimm. Leg je Assistent aus Schritt 0, der noch keinen Zugang hat,
   einen mit HAGT_AddAccess an; ob er InPath braucht, steht im Hinweistext. Vorhandene Räume, Geräte
   und Zugänge bleiben erhalten, außer ich habe einer Änderung in der Tabelle zugestimmt.
   Status und Token kommen einige Sekunden nach dem Übernehmen. Prüf dann mit
   HAGT_ExportConfiguration, dass kein Zugang ein leeres Token hat, und führ HAGT_RunSelfTest aus. Zeig
   mir die Fehler. Jeden Hinweis zu Energie- und Wärmezählern und zum Vorzeichen einer Leistung nennst
   du mir als offene Frage. Ob ein Rollladen, ein Fenster oder eine Tür richtig herum zählt, weiß nur
   ich: Zeig mir je Rollladen die Richtung aus dem Selbsttest und den Stand laut Symcon, je Fenster und
   Tür den Zustand laut Symcon, und frag mich, ob das stimmt. Invert setzt du nur dort, wo ich sage,
   dass es vertauscht ist.

Regeln:
- Schalte nichts und löse keine Aktion an Geräten aus. Nur lesen und die Instanz konfigurieren.
- Gib nie ein Token aus. Adressen nur über HAGT_GetAddresses, dort steht <token> als Platzhalter.
- Ändere keine anderen Instanzen.
- Am Ende eine kurze Zusammenfassung: Anzahl der Räume und Geräte, offene Fragen, Ergebnis des
  Selbsttests.
```

Der Assistent fragt zuerst, welche Instanz er nehmen und welche Assistenten er verbinden soll, und legt Ihnen
später die Tabelle vor. Gehen Sie sie in Ruhe durch: Sprechen Sie die Namen so aus, wie Sie sie im Alltag sagen
würden? Fehlt ein Gerät, oder ist eines dabei, das der Assistent nicht schalten soll? Erst Ihr OK lässt ihn
speichern. Seine offenen Fragen am Ende betreffen meist Zähler, die Symcon nicht als Zähler archiviert, oder das
Vorzeichen einer Batterieleistung. Die README erklärt beides unter [Die Freigabeliste](README.md#die-freigabeliste).

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
