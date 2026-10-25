# Home Agent

[English version](README.en.md)

Dieses Modul gibt dem Haus einen Agenten: Ein KI-Assistent, der das Model Context Protocol (MCP)
spricht, bedient damit Symcon, ob gesprochen, getippt oder aus einem eigenen Ablauf. Er kann fragen
„Ist noch irgendwo ein Fenster offen?“, „Mach das Licht im Wohnzimmer aus“ sagen oder einen
Rollladen halb schließen und bekommt eine kurze, eindeutige Antwort. Er sieht und schaltet dabei nur
die Geräte, die Sie freigeben.

> **Stand:** Beta, erprobt auf drei Anlagen und bei den ersten Testern. Der Assistent fragt ab und schaltet. Er stellt
> Helligkeit, Farbe, Farbtemperatur, Position, Sollwerte, Betriebsart und Lüfterstufe, hält Rollläden an, startet
> Szenen und bedient Mediaplayer. Verbrauch und Leistung kennt er auch hinter Zählern und Unterzählern, fürs Haus
> dazu die Kosten, bei Wärmepumpen Wärme und Arbeitszahl. Verläufe von Messwerten und Leistungen und Zeitleisten von Zuständen gibt
> es als Text und in der Claude-App als Diagramm. Die Geräteliste lässt sich aus einer Alexa-Instanz vorschlagen.

## Wann brauche ich das?

- **Sie möchten Ihr Haus per Sprache fragen, ohne Alexa oder Google.** Das Modul liefert einem
  KI-Assistenten genau die Geräte und Zustände, die er für Alltagsfragen braucht.
- **Ein Assistent soll nicht die ganze Anlage sehen.** Hier sieht er nur Ihre Freigabeliste und wenige,
  einfache Werkzeuge, nicht die Verwaltung der Anlage.
- **Ein Assistent verzählt sich oder verwechselt Geräte.** Das Modul zählt selbst und kennt Räume,
  Gerätetypen und Ihre eigenen Bezeichnungen.

## Was Sie dafür brauchen

- **Symcon ab Version 9.0.** Das Modul ist selbst ein MCP-Server und braucht sonst nichts.
- **Einen Assistenten, der MCP spricht.** Mikrofon, Spracherkennung und Sprachmodell bringt der
  Assistent mit. Erprobt sind Claude Code, Home Assistant und die Claude-App, Claude Desktop (über `mcp-remote`) und
  Open WebUI bieten MCP nach ihrer Dokumentation ebenfalls. Wie sie sich verbinden, steht in Schritt 7 der
  [ersten Schritte](#erste-schritte), welche Clients nicht gehen, unter [Grenzen](#grenzen).

## Erste Schritte

Die Einrichtung kann auch ein KI-Assistent übernehmen, der Ihnen Räume und Geräte zur Freigabe vorschlägt. Wie das
geht und was bei einem Update zu tun ist, steht in [Einrichten und aktualisieren](EINRICHTUNG.md).

1. **Instanz anlegen.** Modul installieren und eine Instanz **Home Agent** anlegen.
2. **Zugang anlegen.** Im Bereich **Endpunkt und Zugänge** in der Liste **Zugänge** einen Zugang mit
   Namen anlegen. Das Token erzeugt das Modul beim Übernehmen selbst. Für den Anfang empfiehlt sich der
   Haken **Nur abfragen**: Der Assistent kann dann fragen, aber nichts schalten.
3. **Räume anlegen (wahlweise).** Im Bereich **Räume** die Räume mit weiteren Bezeichnungen und Etage
   eintragen, etwa „Gast“ mit dem Alias „Gästezimmer“. Das lohnt sich vor dem nächsten Schritt, denn der
   Vorschlag aus Alexa erkennt diese Räume auch an anderen Namen und kennt danach ihre Etage.
4. **Geräte freigeben.** Mit einer Alexa-Instanz geht es am schnellsten: unten im Formular **Geräte aus
   Alexa vorschlagen**, im Fenster die gewünschten Geräte anhaken und **Ausgewählte freigeben** (siehe
   [Geräte aus Alexa übernehmen](#geräte-aus-alexa-übernehmen)). Ohne Alexa oder für weitere Geräte den
   Bereich **Geräte** aufklappen und in der Liste je Gerät Raum, Name und Typ eintragen; der Dialog fragt
   danach die passenden Variablen ab (siehe [Die Freigabeliste](#die-freigabeliste)).
5. **Energiebilanz und Szenen (wahlweise).** Mit Zählern für Netz, PV oder Batterie tragen Sie im Bereich
   **Energiebilanz** die Quellen ein, dann kennt der Assistent den Verbrauch des ganzen Hauses (siehe
   [Energiebilanz und Zähler](#energiebilanz-und-zähler)). Skripte und Ablaufpläne, die er starten darf, kommen in
   den Bereich **Szenen** (siehe [Szenen](#szenen)).
6. **Prüfen.** **Selbsttest ausführen** meldet fehlende Aktionen, die erkannte Richtung jedes Rollladens
   und den Bereich jedes Thermostats und sagt, ob eine Energiebilanz eingetragen ist. **Zustand aller
   freigegebenen Geräte anzeigen** zeigt, was auch der Assistent sieht. Beides erscheint in einem eigenen Fenster.
7. **Assistenten verbinden.** Unter der Zugangsliste steht im Bereich **Endpunkt und Zugänge** für jede Art von
   Assistent, was zu tun ist, mit den Adressen Ihrer Anlage.

   | Client | Einrichtung |
   |---|---|
   | Claude Code | `claude mcp add --transport http symcon-homeagent http://<Adresse>:3777/hook/homeagent/<InstanzID> --header "Authorization: Bearer <Token>"` |
   | Claude-App, auch auf dem Handy | Mit aktivem Symcon Connect den Connector so anlegen, wie es unter [Anmeldung per OAuth](#anmeldung-per-oauth) steht. Ohne Anmeldung geht es auch mit dem Haken **Token auch in der Adresse**: dann als Adresse `https://<Connect-Adresse>/hook/homeagent/<InstanzID>/<Token>` und als Anmeldung „Keine Anmeldung“. Die App erreicht die Anlage aus dem Internet, die Connect-Adresse steht in der Instanz Connect Control. |
   | Home Assistant | Beim Zugang den Haken **Token auch in der Adresse** setzen. Die Integration **Model Context Protocol** mit der Adresse hinzufügen, die **Adresse anzeigen** für diesen Zugang liefert (`http://<Adresse>:3777/hook/homeagent/<InstanzID>/<Token>`), und beim Gesprächsagenten unter „Home Assistant steuern“ auswählen. In der Sprach-Pipeline „Befehle lokal bevorzugen“ ausschalten, sonst schaltet Home Assistant Sätze, die es selbst versteht, über seine eigenen Geräte. |
   | andere Clients | MCP über einfache HTTP-Anfragen mit `Authorization: Bearer <Token>`; kann der Client keinen Header schicken, das Token in der Adresse. |

8. **Erste Fragen.** Diese Sätze zeigen schnell, ob alles stimmt:

   | Frage | Was zurückkommen sollte |
   |---|---|
   | „Ist irgendwo im Haus ein Fenster offen?“ | die Zahl der offenen Fenster mit Raum |
   | „Brennt noch irgendwo Licht?“ | die eingeschalteten Lichter mit Raum und Helligkeit |
   | „Wie warm ist es im Wohnzimmer?“ | der Messwert, bei einem Thermostat auch der Sollwert |
   | „Wie viel Strom ziehen wir gerade?“ | mit Energiebilanz die Hausleistung, sonst die Geräte mit Leistungsmessung |
   | „Mach das Licht in der Küche aus.“ | mit „Nur abfragen“ eine Absage, sonst die Rückmeldung je Licht |

   Die Zahlen nennt das Modul selbst in der ersten Zeile jeder Antwort; der Assistent muss nicht zählen.

## Geräte aus Alexa übernehmen

Sind Ihre Geräte schon in der Alexa-Instanz von Symcon eingerichtet, müssen Sie sie nicht noch einmal
eintragen. Wählen Sie unten im Formular die Alexa-Instanz und drücken Sie **Geräte aus Alexa
vorschlagen**. Ein Fenster zeigt die Vorschläge mit Raum, Name, Typ und Alias, jeder mit einem Haken.
Nehmen Sie den Haken bei allem weg, was der Assistent nicht sehen soll, und drücken Sie **Ausgewählte
freigeben**. **Alle anhaken** und **Keine anhaken** über der Liste setzen die Haken auf einmal. Die
angehakten Geräte kommen in die Liste und sind sofort gespeichert; ungespeicherte Änderungen im
Formular gehen dabei verloren.

Übernommen werden Lichter, Schalter, Rollläden, Thermostate und Temperatursensoren. Ein Rollladen,
dessen Name mit „Fenster“ beginnt, wird ein Fenster mit Antrieb. Den Raum erkennt das Modul am Namen
und am Pfad der Variable, zuerst über die Raumliste, sonst über eine Kategorie des Objektbaums; Kategorien wie „EG“
oder „OG“ werden dabei die Etage. Stimmt ein Raum nicht, ändern Sie ihn im Fenster in der Spalte „Raum“. Ein neuer
Raum kommt beim Freigeben in die Raumliste.
Aus „Deckenlicht Bad“ wird so „Deckenlicht“ im Raum Bad, und der Alexa-Name bleibt als Alias.
Ein Temperatursensor wird die Ist-Temperatur des Thermostats im selben Raum. Was schon freigegeben ist,
kommt nicht doppelt. Schlösser, Fernseher, Farblichter, Szenen und Rollläden, die nur Öffnen, Stop
und Schließen kennen (Profil `~ShutterMoveStop` oder `~ShutterMoveStep`), übernimmt das Modul nicht,
die Meldung nach dem Klick zählt sie.

## Zugänge

Jeder Assistent bekommt seinen eigenen Zugang. Die Geräte pflegen Sie trotzdem nur einmal, alle
Zugänge einer Instanz sehen dieselbe Freigabeliste. Die Liste hat diese Spalten:

| Spalte | Bedeutung |
|---|---|
| Name | Frei wählbar, am besten nach dem, der über den Zugang kommt (siehe unten). Er erscheint im Protokoll, unter „Was nicht geklappt hat“ und im Debug der Instanz. |
| Nur abfragen | Dieser Zugang darf fragen, aber nichts schalten. Er sieht nur das Werkzeug zum Abfragen, und der Assistent erfährt, dass der Zugang nur lesen darf. |
| Standardraum | Der Raum, in dem der Assistent steht. „Mach das Licht an“ ohne Raum meint dann diesen Raum, und bei gleichen Gerätenamen gewinnt das Gerät dort. Für das ganze Haus sagt der Assistent `room=all`. Steht der Raum weder in der Raumliste noch bei einem Gerät, wirkt er nicht; das Formular nennt ihn unter den Zugängen. |
| Verbindung | Wie sich der Assistent anmeldet: „Token im Header“, „Adresse mit Token“ oder bei der Anmeldung per OAuth „angemeldet:“ mit den gekoppelten Assistenten. |
| Werkzeugliste | Ob der Assistent die aktuelle Werkzeugliste kennt: **aktuell**, **veraltet** oder **noch nicht geholt**, mit der Uhrzeit des letzten Abholens. Die Liste ändert sich mit einem Update des Moduls und mit Ihren Einstellungen, etwa einem Gerät mit neuem Typ, einem neuen Raum oder einer neuen Szene. Steht dort **veraltet**, öffnen Sie in der Claude-App einen neuen Chat; hilft das nicht, trennen Sie den Connector und verbinden ihn neu. |

Das Token ist das Kennwort des Zugangs. Das Modul erzeugt es beim Übernehmen (64 Zeichen), ein per Skript gesetztes
braucht mindestens 24. Sichtbar wird es nur auf Knopfdruck: **Token anzeigen** für Claude Code und ähnliche Clients,
**Adresse anzeigen** für Home Assistant. Im Dialog einer Zeile stehen dazu der Haken **Token auch in der Adresse** für
Clients, die keine Anmeldung im Header mitschicken können, und **Neues Token**. Das ersetzt das Token beim
Übernehmen, alle Assistenten dieses Zugangs brauchen dann das neue.

Ohne Zugang zeigt die Instanz den Status 104 und weist alle Anfragen ab.

### Welcher Zugang für wen

Benennen Sie einen Zugang nach dem, der über ihn kommt: „Claude-App“, „Home Assistant“, „Claude Code“. Die Rechte
gehören nicht in den Namen, die zeigt die Spalte **Nur abfragen**.

Nutzen mehrere Personen die Claude-App, bekommt jede einen eigenen Zugang mit ihrem Namen, etwa „Claude-App Anna“.
Dann hat jede ihren eigenen Standardraum und ihre eigenen Rechte, und Protokoll und Liste der Kopplungen zeigen, wer
gefragt hat. Koppeln sich zwei Konten an denselben Zugang, stehen in der Liste zwei gleiche Zeilen, und man sieht nicht
mehr, welche wem gehört.

Für Home Assistant reicht ein Zugang für das ganze Haus. Alle sprechen mit Home Assistant, und Home Assistant fragt mit
seinem einen Zugang bei Home Agent nach; wer gesprochen hat, kommt hier nicht an. Einen zweiten Zugang braucht es nur,
wenn Sie in Home Assistant einen eigenen Sprachassistenten mit anderen Rechten einrichten, etwa „Home Assistant
Kinderzimmer“ mit **Nur abfragen**. Home Agent bekommt in Home Assistant dafür einen zweiten Eintrag.

Wer per Skript oder mit einem KI-Assistenten einrichtet, bekommt die Adressen eines Zugangs mit
`HAGT_GetAddresses($InstanzID, 'Claude-App')` (leerer Name für alle Zugänge): im Heimnetz, über Symcon
Connect und ob die Anmeldung per OAuth geht. Das Token steht dort nur als `<token>`, es zeigt allein das
Formular. Ebenso liefert `HAGT_ExportConfiguration($InstanzID)` die ganze Konfiguration der Instanz zum Sichern,
und `HAGT_AddAccess($InstanzID, 'Claude-App', false, false, '')` legt einen Zugang an, ohne dass das Skript die
Tokens der übrigen kennen muss.

## Anmeldung per OAuth

Die Claude-App kann sich anmelden, statt das Token in der Adresse zu tragen. Symcon Connect muss dafür
aktiv sein, denn die App erreicht die Anlage aus dem Internet.

1. Im Bereich **Endpunkt und Zugänge** steht unter **Claude-App verbinden** die Adresse für den Connector. Ohne
   aktives Connect steht dort stattdessen ein Hinweis.
2. In der Claude-App unter „Konnektoren“ → „Connector hinzufügen“ einen benutzerdefinierten Connector
   mit dieser Adresse anlegen. Die Vorauswahl „Jetzt anmelden“ und „Claudes veröffentlichte Identität verwenden“
   bleibt stehen.
3. Den Zugang wählen, den der Assistent nutzen soll, und auf **Kopplungscode erzeugen** klicken. Der
   Code hat sechs Ziffern und gilt zehn Minuten.
4. In der App auf „Verbinden“ klicken. Es öffnet sich eine Seite der Anlage. Dort den Code eingeben
   und auf **Erlauben** klicken.

Der Assistent bekommt die Rechte und den Standardraum dieses Zugangs. Die Seite nennt ihn mit der
Adresse, von der er stammt. Steht dort etwas anderes als claude.ai, klicken Sie auf **Ablehnen**.

Die gekoppelten Assistenten stehen in der Liste darunter. **widerrufen** in seiner Zeile trennt
einen sofort. Bekommt ein Zugang ein neues Token, sind alle Assistenten getrennt, die über ihn gekoppelt
sind. Eine Kopplung, die 90 Tage lang nicht benutzt wurde, verfällt. Geht eine Antwort an den Assistenten
verloren, etwa weil Connect gerade nicht durchkommt, bleibt die Kopplung bestehen. Die Claude-App legt bei jedem neuen
Hinzufügen des Connectors eine neue Kopplung an und lässt die alte stehen; die Spalte **Zuletzt benutzt** zeigt,
welche noch in Gebrauch ist, die zuletzt benutzte steht oben. Der Selbsttest nennt die Zahl der
gekoppelten Assistenten, ohne Connect sagt er, dass die Anmeldung nicht möglich ist.

Diagramme zeichnet das Werkzeug `show_chart`. Es erscheint in den Einstellungen des Connectors unter „Interaktive
Tools“, dort lässt es sich auf „Immer erlauben“ stellen. Ob die App nach einem Update des Moduls die neuen Werkzeuge
kennt, zeigt die Spalte **Werkzeugliste** der [Zugänge](#zugänge).

## Räume und Etagen

Räume legen Sie in der Liste **Räume** an, wahlweise mit weiteren Bezeichnungen und einer Etage. Danach
findet „Stube“ das Wohnzimmer, und „alle Lichter im Obergeschoss“ trifft jeden Raum dieser Etage.

Im Dialog eines Geräts wählen Sie den Raum aus dieser Liste, bei einem Gerät ohne Raum „-“. Ein neuer
Raum kommt zuerst in die Raumliste; nach dem Übernehmen steht er in der Auswahl. Räume, die in der
Raumliste fehlen (etwa per Skript eingetragen), werden weiter über ihren Namen gefunden. Das Formular
nennt sie über der Geräteliste, der Selbsttest ebenso.

## Die Freigabeliste

Jede Zeile ist ein Gerät mit seinen Fähigkeiten. Im Dialog zum Bearbeiten wählen Sie den Typ, danach
zeigt der Dialog nur die Variablen, die zu diesem Typ gehören. Das Suchfeld über der Liste filtert nach Raum,
Name, Alias, Typ und den Namen der Variablen.

| Spalte | Bedeutung |
|---|---|
| Raum | Ein Raum aus der Raumliste oder „-“. In Anfragen sind Groß- und Kleinschreibung und Umlaute egal („Küche“ = „kueche“). |
| Name, Aliase | Der Name und weitere Bezeichnungen, durch Komma getrennt. Ein Teilwort genügt beim Suchen. Wird ein Gerät über einen Alias gefunden, steht er in der Antwort in Klammern hinter dem Namen. |
| Typ | Licht, Schalter, Steckdose, Gerät, Rollladen, Thermostat, Mediaplayer, Luftbe-/entfeuchter, Lüftung/Ventilator, Fenster, Tür, Anwesenheit, Wasserleck, Temperatur, Luftfeuchte, Luftqualität, sonstiger Messwert oder Zähler. |
| Zustand oder Messwert | Die Variable für an/aus, offen/zu oder erkannt, bei Messwerten der Wert selbst. Beim Thermostat die Ist-Temperatur. |
| Sollwert | Die Variable mit der Solltemperatur. Sie braucht eine Aktion. Bereich und Schrittweite liest das Modul aus der Darstellung oder dem Profil, ohne Schrittweite gilt der Abstand der Einträge (etwa 15,0, 15,5, 16,0). Ein Wert am Rand mit eigenem Text zählt nicht zum Bereich, ob der Text aus einem Eintrag des Profils oder aus einem Intervall der Darstellung mit festem Wert kommt, etwa „On“ bei 30,5. Der Selbsttest zeigt den Bereich je Thermostat. |
| Ventilstellung, 2, 3 | Wahlweise bis zu drei Ventile der Heizkörper, im Dialog nebeneinander. Gezählt wird in Prozent des Bereichs der Variable. Bei Homematic IP steht die Stellung in `LEVEL`, nicht in `VALVE_STATE` (das ist der Status der Ventiladaption); ein Stellantrieb HM-CC-VD ist ein eigenes Gerät neben dem HM-CC-TC. |
| Farbtemperatur | Wahlweise: die Variable mit der Farbtemperatur in Kelvin oder Mired. Sie braucht eine Aktion. |
| Farbe | Wahlweise: die Variable mit der Farbe als Ganzzahl (RGB, etwa Profil `~HexColor`). Sie braucht eine Aktion. |
| Ist-Feuchte, Zielfeuchte, Modus, Lüfterstufe | Beim Luftbe-/entfeuchter: die gemessene Luftfeuchte, die Zielfeuchte, die der Assistent stellen darf (Bereich und Schrittweite aus Darstellung oder Profil), und zwei Auswahlvariablen, deren Einträge er per Name setzt. Bei der Lüftung nur Modus und Lüfterstufe. Der Zustand heißt dort „Ein/Aus“. |
| Wiedergabe, Lautstärke, Stumm, Titel, Interpret | Die Variablen, die Symcon in der Darstellung „Media Player“ zusammenfasst: die Wiedergabe mit einem der Profile `~Playback`, `~PlaybackNoStop`, `~PlaybackPreviousNext`, `~PlaybackPreviousNextNoStop` oder `~PlayerStatus`, die Lautstärke (in Prozent ihres Bereichs angesagt), Stumm als Wahrheitswert, Titel und Interpret als Text. Beim Mediaplayer heißt der Zustand „Ein/Aus“. |
| Helligkeit/Position | Beim Licht die Helligkeit. Sie wird bei eingeschaltetem Licht mit angesagt und lässt sich in Prozent stellen. Beim Rollladen die Position, beim Fenster der Antrieb. |
| Fahrstatus | Wahlweise eine Variable, die meldet, ob der Rollladen gerade fährt, etwa `WORKING` bei Homematic oder der Laufstatus beim Velux KLF200. Dann antwortet das Modul sofort mit „fährt“, auch wenn die Position erst am Ziel kommt. |
| Stopp | Wahlweise: eine Variable, die die Fahrt anhält, also ein Wahrheitswert mit Aktion oder eine Auswahl mit dem Eintrag „Stop“ (etwa „Aktion“ beim HomeAssistant-Modul). Homematic- und Velux-KLF200-Geräte hält das Modul auch ohne sie an. |
| Leistung | Wahlweise: die aktuelle Leistung, etwa `POWER` eines Homematic-Messkanals. Sie steht in jeder Antwort hinter dem Zustand, zum Beispiel „an (26.1 W)“. |
| Zähler | Ebenso wahlweise: der Energiezähler, etwa `ENERGY_COUNTER`. Für den Verbrauch über Zeiträume muss er im Archiv mit der Aggregation „Zähler“ geloggt sein (siehe [Was archiviert sein muss](#was-archiviert-sein-muss)). Hängt ein Gerät an einer Messsteckdose, dürfen beide Zeilen dieselbe Leistung und denselben Zähler tragen; Verbrauch und Leistung eines Raums oder des Hauses zählen jede Variable nur einmal, und der Selbsttest weist darauf hin. |
| Wärmezähler | Wahlweise: der Wärmemengenzähler einer Wärmepumpe, im Archiv mit der Aggregation „Zähler“. Er zählt nie im Stromverbrauch. |
| Wärmeleistung | Wahlweise: die Wärmeleistung jetzt, in W oder kW. Mit der Leistung des Geräts ergibt sie die Leistungszahl, ohne Wärmezähler auch die Wärme über Zeiträume; dann muss sie archiviert sein. |
| Gemessen von | Bei allem mit Leistung oder Zähler, wahlweise: der Zähler, hinter dem das Gerät oder der Zähler hängt, etwa die Unterverteilung OG. Zur Auswahl stehen die Zähler der gespeicherten Liste, ein neuer also erst nach dem Übernehmen. |
| Umgekehrt | Haken setzen, wenn `true` „zu“ oder „aus“ bedeutet. Viele Fensterkontakte melden `true` für geschlossen. Bei Fenster, Tür und Meldern mit einer Zahl gilt sonst alles über 0 als offen bzw. erkannt, mit dem Haken nur die 0; so passt etwa der Türstatus von Matter (0 Geöffnet, 1 Geschlossen). Weitere Einträge wie „Angelehnt“ nennt die Antwort in Klammern. Bei Rollläden erkennt das Modul selbst, welcher Wert „offen“ bedeutet: aus der Darstellung „Rollladen“, aus dem Profil (ein Profilname mit „.Reversed“ zählt umgekehrt), beim HomeAssistant-Modul wie in Home Assistant 100 als offen. Der Selbsttest zeigt es je Rollladen, etwa „Rollladen Küche Rollladen: offen bei 1, geschlossen bei 0“. Stimmt das nicht, dreht der Haken, hier „Richtung umkehren“, es um. |
| Kippfenster | Nur beim Fenster mit Antrieb: Haken setzen, wenn der Antrieb das Fenster nur kippt. Die Antwort sagt dann „gekippt“ und „50 % gekippt“ statt „offen“ und „50 % offen“. |
| Nur abfragen | Der Assistent darf den Zustand ansagen, das Gerät aber nicht schalten oder stellen. Gedacht für Geräte wie das Garagentor oder die Heizung im Kinderzimmer. |

Welche Variablen ein Gerät braucht, hängt vom Typ ab:

| Typ | Variablen |
|---|---|
| Licht | Schaltzustand, Helligkeit oder beides. Ein Dimmer ohne eigenen Schalter hat nur die Helligkeit. Wahlweise Leistung, Zähler, Farbtemperatur und Farbe. |
| Schalter, Steckdose | Schaltzustand, wahlweise Leistung und Zähler |
| Gerät | Schaltzustand oder Leistung, wahlweise Zähler, Wärmezähler und Wärmeleistung. Ein Gerät nur mit Leistung, etwa eine Wärmepumpe, lässt sich abfragen, aber nicht schalten. |
| Rollladen | Position, dazu wahlweise Fahrstatus und Stopp |
| Thermostat | Sollwert, wahlweise die Ist-Temperatur, bis zu drei Ventile, Modus und Lüfterstufe (Klimagerät), Leistung und Zähler |
| Mediaplayer | Ein/Aus, Wiedergabe oder Lautstärke, wahlweise Stumm, Titel, Interpret, Leistung und Zähler |
| Luftbe-/entfeuchter | Ein/Aus oder Zielfeuchte, wahlweise Ist-Feuchte, Modus und Lüfterstufe |
| Lüftung/Ventilator | Ein/Aus oder Lüfterstufe, wahlweise Modus |
| Fenster | Kontakt, Antrieb oder beides, zum Antrieb wahlweise Fahrstatus und Stopp |
| Tür | Kontakt |
| Anwesenheit, Wasserleck | Melder |
| Temperatur, Luftfeuchte, Luftqualität, sonstiger Messwert | Messwert |
| Zähler | Zähler, wahlweise Leistung |

Einen eigenen Typ für Rauchmelder gibt es nicht. Tragen Sie sie als sonstigen Messwert ein, schalten kann der
Assistent einen Messwert ohnehin nicht.

Bei einigen Geräten braucht es mehr als die Variable des Typs:

- **Licht nur mit Helligkeit:** Liegt die kleinste Stufe über 0 (bei Matter etwa 1 bis 254), lässt sich das Licht
  über die Helligkeit nicht ausschalten. Tragen Sie dann auch den Schaltzustand ein.
- **Klimagerät:** Tragen Sie es als Thermostat ein, dazu Modus und Lüfterstufe. Beide Variablen brauchen eine Aktion
  und eine Auswahl in der Darstellung oder im Profil.
- **Mediaplayer des HomeAssistant-Moduls:** Dort ist die Wiedergabe nur eine Befehlsvariable. Tragen Sie als Ein/Aus
  den Statustext ein (`playing`, `paused`, `idle`, `off` …), dann kennt das Modul den Zustand trotzdem. Ohne beides
  steht dort nur der Titel, und der Player zählt bei den aktiven Geräten nicht mit.

Fehlt einer Zeile die Variable ihres Typs, oder wird eine eingetragene Variable gelöscht, ist nur dieses
Gerät gesperrt. Die Zeile wird rot, Formular und Log nennen sie, alle anderen Geräte bleiben erreichbar.
Symcon nennt die Instanz außerdem beim Löschen einer eingetragenen Variable als Referenz.

Geben Sie nur frei, was Sie per Sprache wirklich brauchen, je Raum etwa einen Temperatur- und einen
Feuchtesensor. Jedes Gerät mehr macht die Antworten des Assistenten langsamer und unsicherer.

## Energiebilanz und Zähler

Mit PV oder Batterie misst kein einzelner Zähler, was das Haus verbraucht. Das Modul rechnet es deshalb aus der
**Energiebilanz**:

> Hausverbrauch = Netzbezug + PV-Ertrag + Batterie entladen − Einspeisung − Batterie geladen

Die Werte dafür kommen aus den **Quellen**. Eine Quelle ist alles, worüber Strom ins Haus kommt oder es verlässt: ein
Netzanschluss, eine PV-Anlage oder eine Batterie. Jede Quelle ist eine Zeile der Liste **Quellen** im Bereich
„Energiebilanz“ des Formulars. Der Dialog einer Zeile zeigt je Art die passenden Felder:

| Art | Zähler | Leistung (wahlweise) |
|---|---|---|
| Netz | Bezug und Einspeisung | Bezug und Einspeisung oder ein Sensor mit Vorzeichen |
| PV-Anlage | Ertrag | Ertrag |
| Batterie | entladen und geladen | entladen und geladen oder ein Sensor mit Vorzeichen |

Ohne PV und Batterie genügt eine Zeile Netz mit dem Bezug. Mehrere PV-Anlagen, Batterien oder Netzanschlüsse bekommen
je eine Zeile. Die Bilanz zählt die Zeilen einer Art zusammen, auch rückwirkend aus dem Archiv, und die Antworten
nennen sie mit Namen. Jede Variable darf nur einmal in der Liste stehen. Hat eine Zeile einen Fehler, etwa eine
fehlende Variable, ist die ganze Bilanz gesperrt, sonst wäre der Hausverbrauch falsch. Stand die Bilanz vor einem
Update in einzelnen Feldern, finden Sie sie als Zeilen „Netz“, „PV“ und „Batterie“ in der Liste; die Namen dürfen Sie
ändern.

Der Zähler am Netzanschluss steht nur hier, nicht zusätzlich als Gerät vom Typ Zähler. Er misst den Netzbezug, und
mit PV oder Batterie ist das weniger, als das Haus verbraucht.

Eine Instanz hat genau eine Energiebilanz und einen Strompreis. Für einen zweiten Standort mit eigener Bilanz oder
eigenem Tarif legen Sie eine zweite Instanz an.

### Vorzeichen der Leistung

Haben Sie für das Netz nur einen Sensor mit Vorzeichen, gehört er ins Feld „Netzbezug Leistung“, negativ heißt dann
Einspeisung. Ein Batteriesensor gehört ebenso ins Feld „Batterie entladen Leistung“, negativ heißt laden. Zählt ein
Sensor andersherum, etwa eine Batterie, die beim Laden positiv meldet, setzen Sie in der Zeile den Haken „Vorzeichen
umkehren“. Der Selbsttest nennt je Quelle den aktuellen Wert und wie er zählt, etwa „Energiebilanz: Batterie
entladen Leistung "Marstek" Battery Power meldet jetzt 1112 W und zählt als Batterie geladen 1112 W, durch den Haken
umgekehrt“.

### Zähler und „Gemessen von“

Eine Unterverteilung, eine Wallbox oder einen Wohnungszähler tragen Sie in der Freigabeliste als Gerät vom Typ
**Zähler** ein, mit dem Energiezähler und wahlweise der Leistung. Ein Zähler zählt nie in einer Gerätesumme, er hat
seine eigene Zeile. Die Erzeugung einer PV-Anlage gehört in die Energiebilanz, nicht in einen Zähler.

Im Feld **Gemessen von** wählen Sie bei Geräten und Zählern den Zähler darüber, beliebig tief, etwa das Terrarium am
Kinderzimmer und das Kinderzimmer an der Unterverteilung OG. Die Antworten nennen dann je Zähler, was darunter gemessen
ist und was nicht erfasst ist. Fürs ganze Haus zählt die oberste Ebene, also alles ohne „Gemessen von“. Ein Verweis
auf etwas, das kein Zähler der Liste ist, und Zähler, die sich im Kreis messen, sperren die Zeile. Liegt unter einem
Zähler in den letzten 30 Tagen mehr als 105 % seines eigenen Werts, weist der Selbsttest darauf hin.

### Strompreis

Tragen Sie in der Energiebilanz den **Strompreis** ein, dann nennen die Antworten zum Hausverbrauch auch die Kosten.
Der Strompreis ist eine archivierte Variable in ct/kWh oder €/kWh, als Suffix der Darstellung oder des Profils genügt
„ct“. Bei einem dynamischen Tarif ist das etwa der aktuelle Preis aus dem Tibber-Modul. Daneben tragen Sie die
Einspeisevergütung in ct/kWh ein. Gerechnet wird je
Stunde Netzbezug mal Stundenmittel des Preises. Das sind reine Arbeitskosten ohne Grundgebühr. Gegen die Abrechnung
des Versorgers weicht das um wenige Prozent ab, weil der eigene Zähler etwas anders misst und das Archiv nur
Stundenmittel kennt.

### Wärmepumpe

Eine Wärmepumpe tragen Sie als Typ Gerät ein, mit ihrer Leistung oder ihrem Stromzähler und dazu wahlweise dem
Wärmezähler und der Wärmeleistung. Der Wärmezähler zählt nie im Stromverbrauch. Ohne Wärmezähler rechnet das Modul die Wärme aus den
Stundenmitteln der archivierten Wärmeleistung.

Die Arbeitszahl ist so gut wie die Zähler. Zählt der Stromzähler nur den Verdichter, wie die Verbrauchswerte im Regler
mancher Wärmepumpen, fehlen Umwälzpumpen und Heizstab, und sie fällt zu gut aus. Manche Regler schätzen die Wärme nur
und buchen ihre Zähler erst um Mitternacht. Lassen Sie den Wärmezähler dann leer, das Modul rechnet die Wärme aus der
Wärmeleistung.

Gibt es Wärmeleistung und Leistung nur einmal für Heizen und Warmwasser, haben Sie zwei Möglichkeiten. Eine Zeile für
beides nennt die Arbeitszahl über alles, also die Jahresarbeitszahl. Für getrennte Zeilen teilt ein kleines Skript auf
Ihrer Anlage beide Werte nach der Betriebsart auf, etwa nach einer Variable „Warmwasserbetrieb“ des Reglers: im
Warmwasserbetrieb in „Wärmeleistung Warmwasser“ und „Leistung Warmwasser“, sonst in die beiden Variablen fürs Heizen,
die jeweils anderen auf 0. Alle vier müssen archiviert sein.

### Was archiviert sein muss

Antworten über Zeiträume kommen aus dem Archiv von Symcon. Fehlt dort etwas, sagt die Zeile „kein Verlauf archiviert“,
und der Selbsttest nennt die Variable mit ihrer ID.

| Damit der Assistent … | muss im Archiv … |
|---|---|
| Verbrauch nennt, je Gerät oder fürs Haus | der Energiezähler mit der Aggregation „Zähler“ geloggt sein, ebenso jeder Zähler der Quellen und ein Wärmezähler |
| Kosten nennt | dazu der Strompreis geloggt sein |
| die Wärme ohne Wärmezähler nennt | die Wärmeleistung geloggt sein |
| die Leistung über einen Zeitraum nennt | die Leistung geloggt sein |
| einen Verlauf von Messwerten zeigt | der Messwert geloggt sein |
| eine Zeitleiste zeigt | der Zustand geloggt sein |

Ein Energiezähler zählt in mWh, Wh, kWh oder MWh, ohne Einheit gilt kWh. Ein Zähler in einer anderen Einheit, etwa ein
Wasser- oder Gaszähler, gilt nicht als Energiezähler: Seine Zeile sagt „kein Energiezähler (Einheit m³)“, und er zählt
nicht mit. Leistungen in mW, W, kW und MW rechnet das Modul um (Matter meldet mW), ohne Einheit gilt W.

### Zählerstände im Selbsttest

Der Selbsttest prüft auch, ob das Archiv zum Zählerstand passt. Ein einzelner Fehlwert nach oben und zurück zählt im
Archiv als Verbrauch. Dann nennt der Selbsttest den Zähler mit seiner ID und die Monate, etwa „Das Archiv zählt mehr,
als der Zählerstand gestiegen ist (01/2024 +659.8 kWh)“. Den Fehlwert löschen Sie im Archiv und aggregieren die
Variable neu. Setzt ein Zähler zurück, etwa nach einem Stromausfall, zählt das Archiv richtig weiter, das ist kein
Befund.

## Szenen

Eine Szene ist ein Skript oder Ablaufplan Ihrer Anlage, den der Assistent auf Zuruf starten darf, zum Beispiel
„Filmabend“ oder „Gute Nacht“. Tragen Sie in der Liste **Freigegebene Szenen** je Szene einen Namen und das Skript bzw.
den Ablaufplan ein, dazu nach Wunsch Aliase und einen Raum. Den Raum braucht es nur, wenn zwei Szenen gleich heißen.
Ein Ablaufplan ohne Aktion lässt sich nicht starten.

Die Namen stehen in der Beschreibung des Werkzeugs **run_scene**, der Assistent kennt sie also von Anfang an. Der Name
muss genau eine Szene treffen, sonst startet nichts, und die Antwort nennt die Szenen. Die Antwort bestätigt den
Start; was die Szene bewirkt hat, zeigt eine Abfrage danach. Ohne eingetragene Szene gibt es das Werkzeug nicht. Für
Skripte: `HAGT_RunScene($InstanzID, '', 'Filmabend')`.

## Was der Assistent kann

Der Assistent bekommt drei Werkzeuge: eines zum Fragen (**get_state**), eines zum Schalten und Stellen
(**set_state**) und eines für Szenen (**run_scene**). Die folgenden Abschnitte gehen von Fragen aus, wie man sie im
Alltag stellt. Welche Angaben der Assistent dafür an die Werkzeuge schickt, steht im
[Anhang Technik](#angaben-der-werkzeuge).

### „Ist noch irgendwo ein Fenster offen?“

Nach Zuständen fragt der Assistent je Raum, Etage, Typ oder Name, auf Wunsch nur nach dem, was aktiv ist (an, offen,
Anwesenheit erkannt). Die erste Zeile der Antwort nennt immer die Anzahl, etwa „1 von 4 geprüften Geräten aktiv“; der
Assistent muss nicht selbst zählen. Geräte ohne an und aus, etwa Rollläden und Messwerte, zählen dabei nicht mit,
eine Schlusszeile nennt ihre Zahl und ihre Typen. Findet das Modul nichts, nennt es die bekannten Räume und Typen,
und der Assistent kann neu fragen. Die letzte Zeile nennt Datum, Uhrzeit und Wochentag, etwa „Jetzt: 2026-10-11
12:19 (Sonntag)“. So beantwortet der Assistent auch „Wie spät ist es?“ und rechnet „gestern“ richtig.

Je Gerät steht eine Zeile mit seinem Zustand, zum Beispiel:

| Typ | Zustand in der Antwort |
|---|---|
| Licht | „an (100 %, 2700 K)“ |
| Gerät mit Leistung | „an (26.1 W)“ |
| Fenster mit Antrieb | „gekippt (mit Antrieb)“ |
| Thermostat | „Soll 21.5 °C (derzeit 20.4 °C, Ventil 97 %)“ |
| Klimagerät | „Soll 24 °C (derzeit 20.0 °C), Aus, Lüfter Auto“ |
| Luftentfeuchter | „an (51 %, Soll 55 %, Smart, Lüfter Niedrig)“ |
| Lüftung | „an (Nacht, Lüfter niedrig)“ |
| Mediaplayer | „spielt „Can't Get Enough“ von Bad Company (Lautstärke 20 %)“, „pausiert (Lautstärke 37 %, stumm)“ |

Zu einigen Typen gibt es mehr zu sagen:

- **Thermostat:** Fragt der Assistent nach Temperaturen, findet er auch die Thermostate mit Ist-Temperatur; eine
  eigene Temperaturzeile auf derselben Variable brauchen Sie dafür nicht. Ein Sollwert außerhalb des Bereichs oder am
  Rand mit eigenem Text heißt Sonder-Sollwert; manche Homematic-Thermostate nutzen 100 für „Ventil ganz offen“. Ein
  Text steht dann mit in der Zeile, etwa „Sonder-Sollwert „On“ (30.5 °C, außerhalb von 6 bis 30 °C)“.
- **Ventile:** Mit Ventilstellung nennt die Zeile das offenste Ventil, bei mehreren „Ventile bis 98 %“. Bei „Welche
  Heizkörper heizen?“ zählt ein Thermostat als aktiv, solange ein Ventil über 0 steht. Auf einem Sonder-Sollwert
  zählt es nie, denn „Ventil ganz offen“ hält das Ventil fest auf, ob der Raum Wärme braucht oder nicht. Die
  Gerätekarte zeigt die Stellung neben dem Sollwert.
- **Klimagerät:** Modus und Lüfterstufe stellt der Assistent per Name wie beim Luftentfeuchter. „Schalte das
  Klimagerät aus“ stellt den Modus auf Aus, eingeschaltet wird über einen Modus wie Kühlen oder Heizen.
- **Luftentfeuchter, Lüftung, Mediaplayer:** Sie zählen als aktiv, solange sie laufen bzw. spielen.

### „Wie viel Strom haben wir letzten Monat verbraucht?“

Den Verbrauch gibt es je Gerät, Raum oder Typ und fürs ganze Haus. Gefragt werden kann nach heute, gestern, dieser und
letzter Woche, diesem und letztem Monat, diesem und letztem Jahr oder nach einem freien Zeitraum, auf Wunsch
stundengenau („heute zwischen 13 und 15 Uhr“). Laufende Zeiträume zählen bis jetzt. Ein Gerät braucht dafür einen
Energiezähler, das Haus die [Energiebilanz](#energiebilanz-und-zähler).

Nach einem Raum oder Typ gefragt, nennt die erste Zeile die Summe der Geräte, etwa „88.5 kWh verbraucht durch 3 von 3
Gerät(en) mit Zähler“, darunter steht jedes Gerät in der Reihenfolge der Liste. Geräte ohne Energiezähler bleiben
außen vor.

Fürs ganze Haus kommt der Verbrauch aus der Energiebilanz, mit ihren Teilen:

> Hausverbrauch 774.8 kWh (alle Geräte, period=last_month): Netzbezug 122.7, PV-Ertrag 1356.8, Batterie entladen 285,
> Einspeisung 658.7, Batterie geladen 330.9 kWh.
>
> Davon 166.2 kWh durch 9 von 10 Gerät(en) mit eigenem Zähler, 608.6 kWh nicht erfasst.
>
> Autarkie 84 %, Eigenverbrauch der PV 51 %.

Die Geräte mit eigenem Zähler sind ein Teil des Hausverbrauchs und werden nicht dazugezählt. Fürs Haus nennt die
Antwort nur die fünf größten Verbraucher und fasst den Rest zusammen („5 weitere Gerät(e) zusammen 25.6 kWh.“).
Mehrere Anlagen einer Art stehen mit Namen da, etwa „PV-Ertrag … (davon Dach …, Garage …)“. Autarkie und
Eigenverbrauch gibt es nur mit PV oder Batterie. Fehlt einem Zähler der Bilanz der Verlauf, sagt die Antwort
„Hausverbrauch unbekannt“ und nennt den Zähler.

Mit Geräten vom Typ Zähler nennt jede Zählerzeile, was darunter gemessen ist, und den Rest: „Haushalt 13.4 kWh, davon
2.1 kWh von 9 Zähler(n) oder Gerät(en) darunter gemessen, 11.3 kWh nicht erfasst“. Fürs Haus zählt dann die oberste
Ebene: „Davon 13.4 kWh durch 2 Zähler und 0 Gerät(e) auf oberster Ebene, 0 kWh nicht erfasst.“

Bei einer Wärmepumpe nennt die Zeile auch die Wärme und die Arbeitszahl, also Wärme geteilt durch Strom, etwa
„Heizungsraum | Wärmepumpe | appliance | 1397 kWh, Wärme 5161 kWh, Arbeitszahl 3.7“. Haben mehrere Zeilen eine Wärme, etwa Heizen
und Warmwasser, steht die Arbeitszahl aller zusammen darüber. Kommt die Wärme aus der Wärmeleistung und hat das Gerät
eine Leistung, rechnet das Modul auch den Strom der Arbeitszahl aus ihr, für dieselben Stunden, und nennt beides als
eigenen Block hinter dem Zähler: „6 kWh (Zähler); aus den Leistungen: Wärme 93.7 kWh aus 13.6 kWh Strom, Arbeitszahl
6.9“. Der Verbrauch vorn und in den Summen bleibt der Wert des Zählers.

### „Was hat uns der Strom gekostet?“

Ist der [Strompreis](#strompreis) eingetragen, nennt der Hausverbrauch auch die Kosten:

> Kosten 38.21 € für 122.7 kWh Netzbezug (im Mittel 31.1 ct/kWh bezahlt, Durchschnittspreis 35.4 ct/kWh).
> Einspeisung 60.47 €. Ohne PV und Batterie wären es 259.20 € gewesen.

Kosten gibt es nur fürs ganze Haus. In der Claude-App gehen sie auch als Diagramm, etwa als Summenkurve mit
Hochrechnung bis zum Jahresende (siehe [Diagramme](#diagramme-gerätekarten-und-vorlagen-in-der-claude-app)).

### „Was zieht gerade Strom?“

Nach der Leistung jetzt nennt jedes Gerät mit Leistungsmessung seinen Wert, dazu kommt die Summe. Fürs ganze Haus
rechnet das Modul die Hausleistung aus den Leistungen der Quellen:

> Hausleistung 1548 W (alle Geräte, period=now): Netzbezug 27, PV-Ertrag 0, Batterie entladen 1521, Einspeisung 0,
> Batterie geladen 0 W.
>
> Davon 750 W durch 11 von 11 Gerät(en) mit Leistungsmessung, 798 W nicht erfasst.

Ein Zähler ohne eigene Leistung zählt dabei mit dem, was darunter gemessen ist. Bei einer Wärmepumpe mit
Wärmeleistung kommt die Leistungszahl von jetzt dazu („500 W, Wärme 4188 W, Leistungszahl 8.4“).

Auch nach der Leistung über einen Zeitraum kann man fragen, etwa „Wann hat der Trockner letzten Monat am meisten
gezogen?“. Gemeint sind alle Treffer mit Leistung, auch Zähler. Je Gerät nennt eine Zeile die kleinste und die größte
Leistung mit dem Zeitpunkt aus den Rohwerten des Archivs („Minimum 20.8 W (2026-10-08 22:40), Maximum 336.9 W
(2026-10-01 20:46)“), bei nur einem Gerät folgt eine Tabelle je Stunde, Tag oder Monat. Diese Werte sind
Momentanwerte und als solche gekennzeichnet; kein Mittel über 15 Minuten kann darüber liegen.

Fürs ganze Haus nennt die Antwort die Hausleistung aus den Zählern der Bilanz mit dem niedrigsten und höchsten
Stundenmittel, dazu Netzbezug und Einspeisung mit ihren Spitzen in kW. Die Spitzen mehrerer Sensoren lassen sich aus
dem Archiv nicht sekundengenau zusammenrechnen, deshalb gilt fürs Haus das Stundenmittel. Ergibt die Bilanz in einer
Stunde einen Hausverbrauch unter 0, passen die Zähler nicht zusammen. Solche Stunden zählen nicht mit, und die Antwort
nennt ihre Anzahl und die Tage mit den meisten davon.

### „Wie warm war es letzte Woche im Gästezimmer?“

Für Messwerte (Temperatur, Feuchte, Luftqualität, sonstige Messwerte und die Ist-Temperatur eines Thermostats) liefern
dieselben Zeiträume den **Verlauf**: je Stunde, Tag oder Monat Minimum, Mittel und Maximum. Die erste Zeile lautet etwa
„Verlauf von 1 Messwert(en) (room=Gast, type=temperature, from=2026-09-10, to=2026-10-09), je Tag: Minimum / Mittel /
Maximum.“ Darunter steht je Messwert der tiefste und der höchste Wert des ganzen Zeitraums mit Zeitpunkt („Minimum
20.6 °C (2026-09-30 05:40), Maximum 23.4 °C (2026-10-02 16:15)“) und je Abschnitt eine Zeile wie „2026-09-28 22.2 /
22.6 / 23.1“.

Den Verlauf gibt es, wenn nach einem Messwert-Typ gefragt wird oder unter den Treffern kein Gerät mit Zähler ist;
fürs ganze Haus bleibt es beim Verbrauch. Liest ein Thermostat denselben Fühler wie ein Temperaturgerät, kommt der
Verlauf nur einmal.

### „Wann war die Haustür heute offen?“

Für Geräte mit einem Zustand (an/aus, offen/zu, Bewegung, eine Auswahl wie die Betriebsart einer Batterie) liefern
dieselben Zeiträume eine **Zeitleiste**: wie lange jeder Zustand galt und, bei wenigen Abschnitten, wann. Die Antwort
lautet dann etwa „Flur | Haustür | door | offen 12 min, geschlossen 15 h 36 min“ und darunter „offen 08:32,
09:17-09:18, …“. Hat ein Gerät auch einen Zähler, stehen Verbrauch und Zeiten in einer Zeile („0.2 kWh, an 3 h 31 min,
aus 12 h 18 min“). Ein Zustand ohne Archiv wird ausgelassen und gezählt. Fürs ganze Haus gibt es keine Zeitleiste.

### Für Skripte

Die Zustandsabfrage gibt es auch für Skripte:

```php
echo HAGT_QueryState($InstanzID, 'Küche', 'window', '', true);
```

### Schalten und Stellen

**set_state** erledigt alles, was etwas verändert. Der Assistent wählt die Geräte wie beim Abfragen
aus und gibt dazu einen Befehl oder einen Prozentwert an:

| Angabe | Wirkung |
|---|---|
| `on`, `off` | schaltet Lichter, Schalter, Steckdosen, Geräte, Mediaplayer, Luftentfeuchter und Lüftungen ein oder aus; ein Mediaplayer ohne Ein/Aus (etwa über das Sonos-Modul) pausiert bei `off` („Musik aus“) und setzt bei `on` nur mit `type=media` fort, damit „Wintergarten an“ keine Musik startet |
| `open`, `close` | fährt Rollläden und Fenster mit Antrieb ganz auf oder ganz zu |
| `stop` | hält Rollläden und Fenster an, die gerade fahren, und beendet die Wiedergabe eines Mediaplayers |
| `play`, `pause` | setzt die Wiedergabe eines Mediaplayers fort oder hält sie an |
| `next`, `previous` | springt beim Mediaplayer zum nächsten oder vorigen Titel |
| `volume` | stellt die Lautstärke eines Mediaplayers in Prozent, auch zusammen mit `play`, `pause` oder `stop` („Musik leise an“) |
| `volume_change` | macht einen Mediaplayer um so viele Prozentpunkte lauter oder leiser, ohne Zahl um 10 |
| `mute` | schaltet einen Mediaplayer stumm (`true`) oder wieder laut (`false`) |
| `percent` | stellt die Helligkeit eines Lichts oder die Öffnung eines Rollladens oder Fensters |
| `temperature` | stellt den Sollwert eines Thermostats in °C |
| `humidity` | stellt die Zielfeuchte eines Luftbe- oder -entfeuchters in Prozent |
| `mode`, `fan_speed` | setzt Modus oder Lüfterstufe per Name, etwa „Kontinuierlich“ oder „Hoch“ |
| `color_temperature` | stellt die Farbtemperatur eines Lichts in Kelvin, etwa 2700 für warmweiß |
| `color` | stellt die Farbe eines Lichts: rot, orange, gelb, grün, türkis, blau, lila, pink oder weiß |

Bei Lichtern schaltet 0 Prozent aus, jeder andere Wert schaltet ein. Ein Licht, für das nur eine
Helligkeit eingetragen ist und dessen kleinste Stufe über 0 liegt (bei Matter etwa 1 bis 254), lässt
sich so nicht ausschalten; die Antwort sagt das dann, statt die kleinste Stufe zu senden (siehe
[Die Freigabeliste](#die-freigabeliste)). Bei Rollläden und Fenstern sagt die Zahl, wie weit sie
offen sind: 0 ist ganz geschlossen, 100 ganz offen. Die Antwort nennt
dieselbe Zahl: „offen“, „geschlossen“ oder „30 % offen“, bei einem Kippfenster „gekippt“ und „30 %
gekippt“.

Das Werkzeug bietet nur an, was Ihre freigegebenen Geräte können. Ohne Rollladen und ohne Fenster mit
Antrieb gibt es `open` und `close` nicht, ohne dimmbares Licht und ohne beides keinen Prozentwert. Ein
Fenster nur mit Kontakt lässt sich nur abfragen; bei „alle Fenster zu“ nennt die Antwort, wie viele
solcher Fenster ausgelassen wurden. In der Abfrage tragen Fenster, die das Werkzeug fahren kann, den
Zusatz „mit Antrieb“, etwa „gekippt (mit Antrieb)“. Sind Fenster mit und ohne Antrieb dabei, nennt eine Schlusszeile
ihre Zahl, etwa „6 von 21 Fenstern haben einen Antrieb“.

Beim Mediaplayer gilt Play, Pause oder Stop als erledigt, wenn der Wiedergabezustand passt, Weiter und
Zurück, sobald sich Titel oder Wiedergabezustand ändern. Ein Player, der schon pausiert ist, bekommt
keinen Befehl, die Zeile sagt „war schon pausiert“. Manche Player melden den neuen Titel erst nach
einigen Sekunden; dann sagt die Antwort „gesendet, aber das Gerät hat noch nicht bestätigt“. Welche
Werte die Wiedergabevariable versteht, liest das Modul aus ihrem Profil; bei einem fremden Profil
lässt es die Finger davon und sagt das.

Modus und Lüfterstufe nimmt das Modul per Name an. Es vergleicht mit der Beschriftung und dem Wert jedes
Eintrags der Variable, Groß- und Kleinschreibung zählen nicht; „Hoch“ und „high“ treffen also beide.
Unbekannte Namen gehen nicht hinaus, die Antwort nennt dann die Einträge des Geräts. Welche Einträge es gibt,
steht in der Beschreibung des Werkzeugs.

`play` ganz ohne Raum, Etage und Name setzt die Mediaplayer fort, die in der letzten Stunde über das
Werkzeug pausiert wurden. So klappt „Musik aus“ und später „weiter“, ohne den Raum zu wiederholen. Gibt
es keinen solchen Player, verlangt das Werkzeug wie sonst einen Raum.

Die Lautstärke rechnet das Modul in Prozent des Bereichs der Variable und sendet in ihrer Schrittweite. Zählt sie in
einer anderen Einheit, nennt die Abfrage beides, etwa „Lautstärke 64 % = -17.5 dB“.

Eine Temperatur prüft das Modul gegen den [Bereich des Sollwerts](#die-freigabeliste). Liegt sie außerhalb, geht
nichts hinaus, und die Antwort nennt den Bereich. Krumme Werte gehen auf die Schrittweite, aus 21,3 wird bei
0,5er-Schritten 21,5, und die Antwort nennt den gesendeten Wert.

Die Farbtemperatur nimmt der Assistent in Kelvin an. Zählt die Variable in Mired (Bereich bis 1000, so
etwa Hue über Matter), rechnet das Modul um. Ein Wert außerhalb des Bereichs der Lampe geht auf das
nächste Ende, und die Antwort nennt den gesendeten Wert. Ein ausgeschaltetes Licht wird dabei
eingeschaltet. Bei eingeschaltetem Licht nennt die Abfrage die Farbtemperatur, etwa „an (100 %, 2700 K)“.

Liegt neben der Farbe oder der Farbtemperatur eine Variable mit dem Farbmodus (so bei Zigbee2MQTT, beim
HomeAssistant-Modul und bei Matter), nennen Abfrage und Karte nur, was gerade gilt: bei weißem Licht die
Farbtemperatur, bei farbigem die Farbe. Ohne diese Variable stehen beide da.

Eine Farbe wählt der Assistent aus einer festen Liste von neun Namen. Das Modul schreibt sie als RGB in
die Variable und schaltet ein ausgeschaltetes Licht dabei ein. Die Abfrage nennt bei eingeschaltetem
Licht die nächstliegende Farbe der Liste. Ein Licht ohne eigenen Schalter, etwa eine RGBW-Leiste, gilt als an,
solange seine Farbe nicht schwarz ist, und „aus“ setzt die Farbe mit auf Schwarz. Eine Farbvariable mit Text (Darstellung Farbe mit Kodierung,
etwa beim HomeAssistant-Modul) stellt das Modul noch nicht, die Antwort sagt das.

Ein Befehl für eine Gruppe erreicht nur die Geräte, die ihn verstehen. „Wohnzimmer aus“ schaltet
Lichter und Steckdosen und lässt die Rollläden in Ruhe; die Antwort nennt am Ende, wie viele Geräte
ausgelassen wurden. Nennt der Assistent ein einzelnes Gerät mit einem Befehl, den es nicht versteht,
ändert sich nichts, und die Antwort sagt, was das Gerät kann.

Damit nichts Falsches geschaltet wird, gelten vier Regeln:

- Ohne Raum, Etage, Typ oder Name passiert nichts. „Alles aus“ ohne weitere Angabe gibt es nicht,
  auch nicht bei einem Zugang mit Standardraum.
- Ein Name muss genau ein Gerät treffen. Passen mehrere, schaltet das Modul nichts und nennt die
  Kandidaten samt Zustand, damit der Assistent gezielt nachfragt. Gruppen laufen über Raum und Typ („alle Lichter im
  Wohnzimmer“).
- Fenster ohne Antrieb, Türen, Melder und Messwerte lassen sich nur abfragen, auch wenn ihre Variable
  eine Aktion hat. Dasselbe gilt für jedes Gerät mit dem Haken „Nur abfragen“.
- Ein Prozentwert für einen ganzen Raum braucht den Typ, sobald dort Lichter und dazu Rollläden oder
  Fenster mit Antrieb stehen.
  „Wohnzimmer auf 50 %“ würde sonst beides zugleich stellen.

Nach dem Schalten wartet das Modul bis zu zwei Sekunden auf die Rückmeldung der Geräte. Die Antwort
sagt je Gerät, was daraus geworden ist: „an“, „war schon an“, „gesendet, aber das Gerät hat noch
nicht bestätigt“ oder „fehlgeschlagen“ mit dem Grund. Ein Gerät, das seinen Zustand nie zurückmeldet
(etwa weil es stromlos ist), erscheint also als nicht bestätigt, und die erste Zeile zählt es eigens.
Scheitert ein Befehl erst nach der Antwort, steht der Grund im Meldungslog der Instanz.

Rollläden brauchen für ihre Fahrt länger. Das Modul antwortet mit „fährt“, sobald sich zeigt, dass der Rollladen
losgefahren ist: an seiner Position, an seinem Fahrstatus oder daran, dass er sich nach dem Befehl gemeldet hat. Meldet
er sich gar nicht, lautet die Antwort „Fahrbefehl gesendet, noch keine Positionsmeldung“. Fragt der Assistent während
der Fahrt nach, nennt die Antwort das Ziel: „fährt auf geschlossen (derzeit offen)“.

Mit `stop` hält das Modul einen fahrenden Rollladen an und meldet, wo er steht: „angehalten (68 %
offen)“. Angehalten wird über die eingetragene Stoppvariable, sonst über das Modul des Geräts
(Homematic und Velux KLF200); der Selbsttest nennt den Weg je Rollladen. `stop` gibt es nur, wenn
mindestens ein Rollladen einen solchen Weg hat. Meldet der Fahrstatus, dass er schon steht, geht kein Befehl hinaus, die Antwort sagt dann
„stand schon“. Meldet der Rollladen seine Position erst später, lautet sie „Stopp gesendet, noch nicht bestätigt“.

Für Skripte gibt es das Schalten und das Stellen als zwei Funktionen:

```php
echo HAGT_SwitchDevices($InstanzID, 'Wohnzimmer', 'light', '', false);
echo HAGT_SetLevel($InstanzID, '', '', 'Deckenpanel', 30);
```

### Diagramme, Gerätekarten und Vorlagen in der Claude-App

In der Claude-App zeichnet das Modul auf Wunsch Diagramme direkt in den Chat. Die Werte gehen nur an das Diagramm, das
Sprachmodell bekommt Kennzahlen wie Minimum und Maximum. Über jedem Diagramm steht der Zeitraum, etwa „Montag,
12. Oktober 2026“ oder „September 2026“. Andere Assistenten sehen das Werkzeug dafür nicht.

| Diagramm | Beispiel | Was man sieht |
|---|---|---|
| Verlauf | „Zeig mir die Temperatur im Gästezimmer der letzten 30 Tage als Diagramm“ | je Einheit ein Diagramm mit einem Band von Minimum bis Maximum und einer Linie für das Mittel, bei mehreren Linien blendet ein Haken das Band ein. Mit `type=power` ebenso die Leistung, mit mehreren Räumen alle in einem Diagramm („Vergleiche die Temperatur im Gästezimmer und draußen“). |
| Zustände | „Zeig mir, wann die Haustür heute offen war“ | Bänder über der Zeitachse, gefärbt nach Zustand |
| Vergleich | „Hausverbrauch heute im Vergleich zu gestern“, „Temperatur im Gästezimmer diesen Monat gegen denselben Monat im Vorjahr“ | beim Verlauf eine gestrichelte Linie, beim Verbrauch eine Marke je Balken; in einem laufenden Zeitraum nennt die Antwort den Vergleich bis zur selben Zeit. Nicht für Zustände. |
| Punktwolke | „Leistung der Wärmepumpe gegen die Außentemperatur im September“ | ein Punkt je Tag (bis zwei Tage je Stunde), das Mittel je Temperaturklasse und eine Trendlinie. Eine Heizgrenze findet das Modul selbst („unter etwa 16 °C je Grad kälter rund 0,06 kW mehr, erklärt 84 %“). Auf der y-Achse ein Messwert, ein Zähler oder eine Leistung, ohne Gerät der Hausverbrauch. |
| Heatmap | „Hausverbrauch im September als Heatmap“ | ein Kästchen je Stunde, eine Zeile je Tag, über 62 Tage eine Zeile je Wochentag mit dem Mittel. Der Assistent bekommt die stärkste und die schwächste Stunde und den Unterschied zwischen Werktag und Wochenende. |
| Hausverbrauch | „Zeig mir unseren Stromverbrauch in diesem Monat als Diagramm“ | je Abschnitt ein Balken aus Netz, PV direkt und Batterie, die Einspeisung darunter (lädt die Batterie aus dem Netz, zählt das als Netz). Darüber die Ringe Autarkie und Eigenverbrauch, mit Vergleich oben rechts der Anteil des Vergleichszeitraums. Mehrere PV-Anlagen stehen in Abstufungen der PV-Farbe. |
| Leistung jetzt | „Zeig mir die Leistung jetzt als Diagramm“ | dieselben Ringe aus den Leistungen der Bilanz, dazu ein Ring mit der Hausleistung aus PV, Batterie und Netz |
| Verbrauch je Gerät | „Welche Geräte haben im September am meisten verbraucht? Als Diagramm“ | die fünf größten Geräte mit Zähler übereinander, der Rest als „übrige Geräte“, fürs Haus dazu „nicht erfasst“. Das Diagramm eines Zählers zeigt, was unter ihm hängt. |
| Summenkurve | „Stromverbrauch dieses Jahr als Summenkurve“ | Verbrauch oder Kosten Tag für Tag aufaddiert, ein laufender Zeitraum hochgerechnet nach dem Verlauf im Vorjahr, ohne Vorjahr linear: „Bisher 10239.5 kWh, hochgerechnet 13079.9 kWh für den ganzen Zeitraum.“ |
| Teil der Bilanz | „Was habe ich dieses Jahr an Einspeisevergütung verdient, und wo lande ich am Jahresende?“ | nur Netzbezug, Einspeisung, PV-Ertrag oder Batterie, je Quelle eine Reihe, als Balken, Summenkurve, Heatmap und mit Vergleich |

Mit eingetragenem Strompreis gehen Hausverbrauch, Netzbezug und Einspeisung auch in Euro. Die Summenkurve zeigt dann
die Einspeisevergütung als grüne Linie und nennt den Saldo. Hat ein Raum neben Zählern auch Messwerte, zeigt das
Diagramm zunächst die Messwerte; nach dem Verbrauch muss man dann ausdrücklich fragen. Ein Zähler, der nur einmal am
Tag weiterzählt, steht im Stundendiagramm mit dem ganzen Tag in einer Stunde.

Mit „Zeig mir die Lichter im Wohnzimmer“ zeigt die Claude-App die Geräte als Karten, nach Art gruppiert. Jede Karte
nennt den Zustand und groß den wichtigsten Wert, was an ist, ist farbig. Je nach Gerät gibt es einen Schalter, einen
Balken für Helligkeit oder Öffnung, Knöpfe für Auf, Stopp und Zu, den Sollwert mit Plus und Minus, Wiedergabe und
Lautstärke, Betriebsart und Lüfterstufe, beim Licht Farbtemperatur und Farbtupfer. Ein Tippen schaltet sofort, ohne
Umweg über das Sprachmodell. Die Karte zeigt danach, was das Gerät zurückgemeldet hat, und folgt einem fahrenden
Rollladen, bis er steht. Der Assistent erfährt im weiteren Gespräch, was bedient wurde. Bei einem Zugang mit **Nur
abfragen** zeigen die Karten nur den Zustand.

Über „+“ → „Konnektoren“ → „Von … hinzufügen“ bietet die Claude-App fertige Vorlagen an: **Haus verlassen** und
**Gute Nacht** prüfen, was noch offen oder an ist, **Energiebericht** nennt Verbrauch, Autarkie und die größten
Verbraucher von gestern. Welche es gibt, richtet sich nach der Freigabeliste.

## Was nicht geklappt hat

Der Bereich **Was nicht geklappt hat** zeigt die Anfragen der Assistenten, bei denen nicht alles erreicht
wurde, die neuesten zuerst. Die Überschrift zählt die Einträge der letzten 24 Stunden. Jede Zeile nennt Zeit,
Zugang, die Anfrage, die erste Zeile der Antwort und einen Hinweis, was Sie tun können:

| Fall | Beispiel | Hinweis |
|---|---|---|
| Raum unbekannt | „Licht im Badezimmer an“, der Raum heißt „Bad“ | das Wort als Alias des Raums eintragen |
| Name unbekannt | „Stehlampe an“, das Gerät heißt „Leselampe“ | das Wort als Alias des Geräts eintragen |
| Mehrere Geräte passen | „Deckenlicht an“ ohne Raum trifft drei Deckenlichter | eindeutige Namen vergeben |
| Gerät kann das nicht | „Licht öffnen“ | nichts zu tun |
| Gerät bestätigt nicht | ein stromloser LED-Streifen | prüfen, ob das Gerät erreichbar ist |
| Aktion fehlgeschlagen | die Variable hat keine Aktion | Gerät und Aktion prüfen |
| Anmeldung abgewiesen | ein Client mit altem Token | Token oder Adresse im Client prüfen |

Ein Rollladen, der noch fährt, steht nicht darin. Die letzten 50 Einträge bleiben, auch über einen Neustart;
**Liste leeren** räumt sie ab. Abgewiesene Anmeldungen desselben Absenders innerhalb von zehn Minuten sind ein
Eintrag mit Anzahl.

Für die Fehlersuche gibt es darunter ein **Protokoll**, anfangs ausgeschaltet. Mit **Protokoll führen**
schreibt das Modul jede Anfrage als Zeile in eine Datei im Log-Verzeichnis von Symcon
(`homeagent_<InstanzID>.log`): Zeit, Zugang, Werkzeug, Argumente, Dauer und Antwort. Nach 24 Stunden oder
nach **Zeilen je Datei** (anfangs 5000) beginnt eine neue Datei, die vorige bleibt als
`homeagent_<InstanzID>.old.log`. So liegen ein bis zwei Tage vor. **Protokoll anzeigen** zeigt die letzten
200 Zeilen, **Protokoll herunterladen** lädt beide Dateien als eine herunter (von jeder höchstens die letzten 3 MB), **Protokoll leeren** löscht
sie. Tokens und Kopplungscodes stehen nie darin. Abgewiesene Anfragen ohne Anmeldung stehen je Absender höchstens zehnmal in zehn Minuten darin, danach ein Hinweis.

## Grenzen

- Farbe als Text geht noch nicht. Die Betriebsart eines Thermostats geht nur über eine Variable mit Aktion;
  bei Homematic-Wandthermostaten hat `CONTROL_MODE` keine.
- Den Verlauf einer Ventilstellung zeigt das Modul noch nicht, nur die Stellung von jetzt.
- Ein batteriebetriebenes Thermostat übernimmt einen neuen Sollwert oft erst beim nächsten Funkkontakt.
  Die Zentrale meldet ihn trotzdem sofort zurück, „gestellt“ heißt dann „an die Zentrale übergeben“.
- Bei einem Rollladen, der sich erst am Ziel meldet und keinen Fahrstatus hat, weiß das Modul nach
  dem Fahrbefehl nicht, ob er fährt.
- Fehler innerhalb eines Szenen-Skripts oder Ablaufplans sieht der Assistent nicht, er erfährt nur vom Start.
- Die Prüfung der Zähler im Selbsttest findet nur Fehlwerte, die wieder verschwinden. Springt ein Zähler dauerhaft nach oben, etwa beim Tausch gegen einen Zähler mit höherem Stand, zählt das Archiv den Sprung als Verbrauch, ohne dass der Selbsttest es merkt. Kleine Fehlwerte unter einem Fünftel eines üblichen Monats und Monate kurz nach einer Rücksetzung prüft er nicht.
- Wärme, Arbeitszahl und Leistungszahl gibt es nur als Text, nicht im Diagramm und nicht mit `type=power`. Zählt der
  Regler einer Wärmepumpe nur einmal am Tag weiter, steht der ganze Vortag kurz nach Mitternacht im Archiv; „gestern“
  meint dann den Tag davor.
- Die Spitze der Hausleistung ist ein Stundenmittel aus den Zählern der Bilanz. Kurze Spitzen, etwa beim Anlaufen eines Geräts, zeigt nur die Netzleistung oder das einzelne Gerät.
- Lamellen, deren Variable die Darstellung „Rollladen“ mit Rotation trägt, bekommen nur eine
  Prozentzahl ohne „offen“ oder „geschlossen“.
- Der Endpunkt spricht MCP über einfache HTTP-Anfragen und bietet keinen Datenstrom an. Clients, die
  zwingend einen Datenstrom (SSE) verlangen, können sich nicht direkt verbinden. Per OAuth melden sich
  nur Clients mit einem Client ID Metadata Document an, erprobt ist die Claude-App. ChatGPT verbindet
  sich mit dem Token, mit OAuth ist es nicht erprobt.
- Anfragen aus einem Browser mit fremder Herkunft (`Origin`) weist der Endpunkt ab, auch mit richtigem Token. Als eigen gelten die IP-Adressen der Anlage, `localhost`, ihr Rechnername (auch mit `.local`, `.fritz.box`, `.lan`, `.home.arpa`, `.home` oder `.internal`) und die Connect-Adresse.
- Tokens und Kopplungscodes sind das einzige Schloss, und über Symcon Connect ist der Endpunkt aus dem Internet
  erreichbar; verwenden Sie deshalb erzeugte Tokens. Abgewiesene Anmeldungen und falsche Kopplungscodes stehen im
  Meldungsprotokoll. Nach 20 davon in einer Minute ist der Endpunkt für diesen Absender 60 Sekunden gesperrt (über
  Connect zählt der Absender im Internet, Ihre Assistenten kommen weiter durch), nach 100 von allen zusammen für alle.
- Steht das Token in der Adresse, kann es in Logs, Verläufen und Einstellungsdateien des Clients
  landen. Setzen Sie den Haken nur bei Zugängen, deren Client die Anmeldung nicht im Header
  mitschicken kann.
- Die Tokens stehen in der Konfiguration der Instanz. Wer die Konfiguration der Anlage lesen darf, etwa ein
  Skript oder ein anderes Modul, liest sie mit.
- Das Protokoll zur Fehlersuche enthält Raum- und Gerätenamen und die Antworten an die Assistenten. Wer das
  Log-Verzeichnis von Symcon lesen kann, liest es mit. Tokens und Kopplungscodes stehen nicht darin.
- Die Skriptfunktionen kennen weder Standardraum noch Etage. Es gibt sie für Zustand, Schalten,
  Stellen und Szenen, für Stopp, Sollwert, Farbtemperatur, Farbe, Verbrauch, Leistung und Verlauf nicht.

## Begriffe

- **MCP:** Model Context Protocol, die Schnittstelle, über die KI-Assistenten Werkzeuge nutzen.
- **Token:** das Kennwort, mit dem sich der Assistent anmeldet.
- **Zugang:** ein Token samt seinen Einstellungen (Standardraum, nur abfragen).
- **Energiebilanz:** die Rechnung, aus der das Modul den Verbrauch des ganzen Hauses ermittelt, aus Netzbezug,
  PV-Ertrag, Batterie entladen, Einspeisung und Batterie geladen.
- **Quelle:** eine Zeile der Energiebilanz, also ein Netzanschluss, eine PV-Anlage oder eine Batterie.
- **Energiezähler:** die Variable, die die Energie eines Geräts aufsummiert, etwa `ENERGY_COUNTER`. Im Archiv braucht sie
  die Aggregation „Zähler“.
- **Zähler (Typ):** ein Gerät der Freigabeliste, das nur misst, etwa eine Unterverteilung, eine Wallbox oder ein
  Wohnungszähler. Es zählt nie in einer Gerätesumme.
- **Gemessen von:** der Zähler, hinter dem ein Gerät oder ein anderer Zähler hängt.
- **Nicht erfasst:** der Teil eines Verbrauchs oder einer Leistung, den kein Gerät und kein Zähler darunter misst.
- **Sonder-Sollwert:** ein Sollwert außerhalb des Bereichs eines Thermostats oder an seinem Rand mit eigenem Text, etwa
  100 für „Ventil ganz offen“ oder 30,5 für „On“.

## Anhang Technik

- Endpunkt `/hook/homeagent/<InstanzID>`, nur POST mit JSON-RPC 2.0, Antwort `application/json`.
  GET wird mit 405 abgewiesen, Benachrichtigungen mit 202 quittiert.
- Anmeldung mit `Authorization: Bearer <Token>`, bei Zugängen mit gesetztem Haken auch über das Token
  als letzten Teil des Pfads. Schickt ein Client beides, zählt der Header.
- Unterstützte Protokollversionen: 2026-07-28 (ohne `initialize`, mit `server/discover`; Clients dürfen die
  Werkzeugliste 5 Minuten behalten), dazu 2025-11-25, 2025-06-18 und 2025-03-26.
- Status 104: noch kein Zugang, der Endpunkt weist alle Anfragen ab. Status 201: Die Raumliste ist
  fehlerhaft, oder es gibt keinen fehlerfreien Zugang mehr; die roten Zeilen im Formular nennen den Fehler, und
  der Endpunkt bleibt bis zur Korrektur geschlossen. In beiden Fällen antwortet er mit HTTP 503
  („nicht bereit“), nicht mit einem Anmeldefehler. Ein fehlerhafter Zugang (Name fehlt, Token zu kurz oder
  doppelt), ein Gerät oder eine Szene mit fehlender Variable bzw. fehlendem Skript
  sperrt nur sich selbst; der Rest arbeitet weiter, Formular und Log nennen den Eintrag. Fehlt eine
  Variable der Energiebilanz, ist die ganze Bilanz gesperrt, sonst wäre der Hausverbrauch falsch.

### Angaben der Werkzeuge

Diese Angaben schickt der Assistent an **get_state**; die Werte stehen in der Beschreibung des Werkzeugs.

| Angabe | Bedeutung |
|---|---|
| `room`, `floor`, `type`, `name` | Auswahl der Geräte. Ohne Raum gilt bei einem Zugang mit Standardraum dieser, `room=all` meint das ganze Haus. |
| `active_only` | nur, was an, offen oder erkannt ist |
| `period` | `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_year`, `last_year` für Verbrauch, Verlauf und Zeitleiste, `now` für die Leistung jetzt. Den Parameter bekommt der Assistent nur, wenn mindestens ein freigegebenes Gerät einen Zähler hat oder die Energiebilanz eingetragen ist. |
| `from`, `to` | freier Zeitraum statt `period`: erster und letzter Tag als JJJJ-MM-TT, beide eingeschlossen, ohne `to` nur der eine Tag, etwa „im Januar“ mit `from=2026-01-01` und `to=2026-01-31`. Mit Uhrzeit (JJJJ-MM-TT HH:MM) stundengenau, etwa `from=2026-10-12 13:00` und `to=2026-10-12 15:00`; dann ist `to` das Ende, ohne `to` gilt bis jetzt. Gerechnet wird mit ganzen Stunden, höchstens 48 am Stück. |
| `type=power` | kein Gerätetyp: mit Zeitraum die Leistung über den Zeitraum, ohne Zeitraum die Leistung jetzt |

Woher die Werte kommen und wo die Grenzen liegen:

- Verbrauch: ganze Jahre und Monate aus den Jahres- und Monatswerten des Archivs, Stunden aus den Stundenwerten, alles
  andere aus den Tageswerten.
- Verlauf: heute und gestern stündlich, Wochen und Monate täglich, Jahre monatlich; bei `from` und `to` bis zu zwei
  Tage stündlich, bis zu 100 Tage täglich, darüber monatlich. Höchstens fünf Messwerte auf einmal, sonst bittet die
  Antwort um einen Raum oder Namen.
- Leistung über einen Zeitraum: bis zu 20 Geräte auf einmal, fürs Haus höchstens 400 Tage.
- Zeitleiste: die Zeitpunkte jedes Abschnitts bei bis zu zwölf Abschnitten. Bei mehr als fünf Geräten, mehr als zwölf
  Abschnitten oder mehr als zwei Tagen stehen nur die Summen mit der Anzahl da.

Für Diagramme (**show_chart**) kommen dazu: `compare` (`previous` oder `year_before`), `versus` (zweites Gerät für die
Punktwolke), `heatmap`, `cumulative` (Summenkurve), `costs` (in Euro) und `part` (`grid`, `feed_in`, `pv`,
`battery_charge` oder `battery_discharge`).
