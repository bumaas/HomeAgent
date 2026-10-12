# Home Agent

[English version](README.en.md)

Dieses Modul gibt dem Haus einen Agenten: Ein KI-Assistent, der das Model Context Protocol (MCP)
spricht, bedient damit Symcon, ob gesprochen, getippt oder aus einem eigenen Ablauf. Er kann fragen
„Ist noch irgendwo ein Fenster offen?“, „Mach das Licht im Wohnzimmer aus“ sagen oder einen
Rollladen halb schließen und bekommt eine kurze, eindeutige Antwort. Er sieht und schaltet dabei nur
die Geräte, die Sie freigeben.

> **Stand:** frühe Vorschau. Abfragen, Schalten, das Stellen von Helligkeit, Farbtemperatur, Farbe, Position und
> Heizungs-Sollwert, das Anhalten von Rollläden, Verbrauch und Leistung, der Verlauf von Messwerten
> das Starten von Szenen sowie Wiedergabe und Lautstärke von Mediaplayern gehen. Die Geräteliste lässt sich aus einer Alexa-Instanz vorschlagen. Die Claude-App meldet sich auf Wunsch per OAuth an. Erprobt ist das Modul bisher
> auf drei Anlagen.

## Wann brauche ich das?

- **Sie möchten Ihr Haus per Sprache fragen, ohne Alexa oder Google.** Das Modul liefert einem
  KI-Assistenten genau die Geräte und Zustände, die er für Alltagsfragen braucht.
- **Der eingebaute MCP-Server von Symcon ist Ihnen für diesen Zweck zu mächtig.** Er ist für die
  Verwaltung einer Anlage gebaut und gibt einem Assistenten Zugriff auf alles. Hier sieht der
  Assistent nur Ihre Freigabeliste.
- **Ein Assistent verzählt sich oder verwechselt Geräte.** Das Modul zählt selbst und kennt Räume,
  Gerätetypen und Ihre eigenen Bezeichnungen.

## Was Sie dafür brauchen

- **Symcon ab Version 9.0.** Den eingebauten MCP-Server von Symcon brauchen Sie nicht. Das Modul ist
  selbst ein MCP-Server und läuft unabhängig davon.
- **Einen Assistenten, der MCP spricht.** Mikrofon, Spracherkennung und Sprachmodell bringt der
  Assistent mit. Das Modul liefert ihm die Geräte und die Werkzeuge, um sie abzufragen und zu schalten.
- **Einen passenden Client.** Verbinden kann sich jeder Client, der MCP über einfache HTTP-Anfragen
  spricht und ein festes Token im Header `Authorization` mitschickt. Erprobt sind Claude Code, die
  MCP-Integration von Home Assistant und die Claude-App. Home Assistant bekommt das Token in der
  Adresse, die Claude-App meldet sich per [OAuth](#anmeldung-per-oauth) an. Claude Desktop (über
  `mcp-remote`) und Open WebUI bieten das nach ihrer Dokumentation ebenfalls. Welche
  Clients nicht gehen, steht unter [Grenzen](#grenzen).

## Erste Schritte

1. **Instanz anlegen.** Modul installieren und eine Instanz **Home Agent** anlegen. Das Formular ist in
   aufklappbare Bereiche gegliedert, die sich merken, ob sie offen oder zu sind.
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
5. **Prüfen.** **Selbsttest ausführen** meldet fehlende Aktionen, die erkannte Richtung jedes Rollladens
   und den Bereich jedes Thermostats. **Zustand aller freigegebenen Geräte anzeigen** zeigt, was auch der
   Assistent sieht.
6. **Assistenten verbinden.** Unter der Zugangsliste steht im Bereich **Endpunkt und Zugänge** für jede Art von
   Assistent, was zu tun ist, mit den Adressen Ihrer Anlage.

   | Client | Einrichtung |
   |---|---|
   | Claude Code | `claude mcp add --transport http symcon-homeagent http://<Adresse>:3777/hook/homeagent/<InstanzID> --header "Authorization: Bearer <Token>"` |
   | Claude-App, auch auf dem Handy | Mit aktivem Symcon Connect den Connector so anlegen, wie es unter [Anmeldung per OAuth](#anmeldung-per-oauth) steht. Ohne Anmeldung geht es auch mit dem Haken **Token auch in der Adresse**: dann als Adresse `https://<Connect-Adresse>/hook/homeagent/<InstanzID>/<Token>` und als Anmeldung „Keine Anmeldung“. Die App erreicht die Anlage aus dem Internet, die Connect-Adresse steht in der Instanz Connect Control. |
   | Home Assistant | Beim Zugang den Haken **Token auch in der Adresse** setzen. Die Integration **Model Context Protocol** mit der Adresse hinzufügen, die **Adresse anzeigen** für diesen Zugang liefert (`http://<Adresse>:3777/hook/homeagent/<InstanzID>/<Token>`), und beim Gesprächsagenten unter „Home Assistant steuern“ auswählen. In der Sprach-Pipeline „Befehle lokal bevorzugen“ ausschalten, sonst schaltet Home Assistant Sätze, die es selbst versteht, über seine eigenen Geräte. |
   | andere Clients | MCP über einfache HTTP-Anfragen mit `Authorization: Bearer <Token>`; kann der Client keinen Header schicken, das Token in der Adresse. |

7. **Erste Fragen.** Diese Sätze zeigen schnell, ob alles stimmt:

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
und am Pfad der Variable, zuerst über die Raumliste. Kennt die ihn nicht, nimmt das Modul eine Kategorie
aus dem Objektbaum, deren Name auch im Alexa-Namen steht („Küche links“ unter `…\EG\Küche\…` kommt in den
Raum Küche). Kategorien wie „EG“ oder „OG“ zählen dabei nicht als Raum, sondern werden die Etage des
neuen Raums. Hängen die Geräte eines Raums unter verschiedenen Etagen, entsteht je Etage ein Raum
(„Flur EG“, „Flur OG“), und alle bekommen den Alias „Flur“. Stimmt ein Raum nicht
oder fehlt er, ändern Sie ihn im Fenster in der Spalte „Raum“. Ein Raum, den die Raumliste noch nicht
kennt, wird dort beim Freigeben angelegt.
Aus „Deckenlicht Bad“ wird so „Deckenlicht“ im Raum Bad, und der Alexa-Name bleibt als Alias.
Ein Temperatursensor wird die Ist-Temperatur des Thermostats im selben Raum. Was schon freigegeben ist,
kommt nicht doppelt. Schlösser, Fernseher, Farblichter, Szenen und Rollläden, die nur Öffnen, Stop
und Schließen kennen (Profil `~ShutterMoveStop` oder `~ShutterMoveStep`), übernimmt das Modul nicht,
die Meldung nach dem Klick zählt sie.

## Zugänge

Jeder Assistent bekommt seinen eigenen Zugang. Die Geräte pflegen Sie trotzdem nur einmal, alle
Zugänge einer Instanz sehen dieselbe Freigabeliste. Die Liste zeigt Name, Nur abfragen, Standardraum und in
der Spalte **Verbindung**, wie der Assistent verbunden ist. „Token auch in der Adresse“ steht im
Bearbeiten-Dialog der Zeile. Das Token selbst sehen Sie nur, wenn Sie es brauchen: **Token anzeigen** für
Claude Code und ähnliche Clients, **Adresse anzeigen** für Home Assistant. **Neues Token** im Dialog ersetzt
es beim Übernehmen; alle Assistenten dieses Zugangs brauchen dann das neue.

| Spalte | Bedeutung |
|---|---|
| Name | Frei wählbar, zum Beispiel „Küche“ oder „Gäste“. Er erscheint im Debug der Instanz. |
| Token | Das Kennwort dieses Zugangs. Ein Zugang ohne Token bekommt beim Übernehmen eines aus 64 Zeichen; ein per Skript gesetztes braucht mindestens 24. |
| Standardraum | Der Raum, in dem der Assistent steht. „Mach das Licht an“ ohne Raum meint dann diesen Raum, und bei gleichen Gerätenamen gewinnt das Gerät dort. Für das ganze Haus sagt der Assistent `room=all`. |
| Nur abfragen | Dieser Zugang darf fragen, aber nichts schalten. Er sieht nur das Werkzeug zum Abfragen, und der Assistent erfährt, dass der Zugang nur lesen darf. |
| Token auch in der Adresse | Für Clients, die keine Anmeldung mitschicken können. Das Token hängt dann an der Adresse. |

Ohne Zugang mit Token zeigt die Instanz den Status 104 und weist alle Anfragen ab.

## Anmeldung per OAuth

Die Claude-App kann sich anmelden, statt das Token in der Adresse zu tragen. Symcon Connect muss dafür
aktiv sein, denn die App erreicht die Anlage aus dem Internet.

1. Im Bereich **Endpunkt und Zugänge** steht unter **Claude-App** die Adresse für den Connector. Ohne
   aktives Connect steht dort stattdessen ein Hinweis.
2. In der Claude-App unter „Anpassen“ → „Connectoren“ einen benutzerdefinierten Connector mit dieser
   Adresse anlegen. Die Vorauswahl „Jetzt anmelden“ und „Claudes veröffentlichte Identität verwenden“
   bleibt stehen.
3. Den Zugang wählen, den der Assistent nutzen soll, und auf **Kopplungscode erzeugen** klicken. Der
   Code hat sechs Ziffern und gilt zehn Minuten.
4. In der App auf „Verbinden“ klicken. Es öffnet sich eine Seite der Anlage. Dort den Code eingeben
   und auf **Erlauben** klicken.

Der Assistent bekommt die Rechte und den Standardraum dieses Zugangs. Die Seite nennt ihn mit der
Adresse, von der er stammt. Steht dort etwas anderes als claude.ai, klicken Sie auf **Ablehnen**.

Die gekoppelten Assistenten stehen in der Liste darunter. **Ausgewählten Assistenten widerrufen** trennt
einen sofort. Bekommt ein Zugang ein neues Token, sind alle Assistenten getrennt, die über ihn gekoppelt
sind. Eine Kopplung, die 90 Tage lang nicht benutzt wurde, verfällt. Der Selbsttest nennt die Zahl der
gekoppelten Assistenten, ohne Connect sagt er, dass die Anmeldung nicht möglich ist.

## Räume und Etagen

Der Raum eines Geräts ist freier Text. In der Liste **Räume** können Sie einem Raum weitere
Bezeichnungen und eine Etage geben. Danach findet „Stube“ das Wohnzimmer, und „alle Lichter im
Obergeschoss“ trifft jeden Raum dieser Etage. Die Liste ist freiwillig. Ein Raum, der dort fehlt,
wird weiter über seinen Namen gefunden.

Schreiben Sie den Raum beim Gerät so wie in der Raumliste. Der Selbsttest der Instanz nennt Räume,
die bei einem Gerät stehen, aber nicht in der Raumliste.

## Die Freigabeliste

Jede Zeile ist ein Gerät mit seinen Fähigkeiten. Im Dialog zum Bearbeiten wählen Sie den Typ, danach
zeigt der Dialog nur die Variablen, die zu diesem Typ gehören.

| Spalte | Bedeutung |
|---|---|
| Raum | So, wie Sie den Raum nennen. Groß- und Kleinschreibung und Umlaute sind egal („Küche“ = „kueche“). |
| Name, Aliase | Der Name und weitere Bezeichnungen, durch Komma getrennt. Ein Teilwort genügt beim Suchen. Wird ein Gerät über einen Alias gefunden, steht er in der Antwort in Klammern hinter dem Namen. |
| Typ | Licht, Schalter, Steckdose, Gerät, Rollladen, Thermostat, Mediaplayer, Luftbe-/entfeuchter, Fenster, Tür, Anwesenheit, Wasserleck, Temperatur, Luftfeuchte, Luftqualität oder sonstiger Messwert. |
| Zustand oder Messwert | Die Variable für an/aus, offen/zu oder erkannt, bei Messwerten der Wert selbst. Beim Thermostat die Ist-Temperatur. |
| Sollwert | Nur beim Thermostat: die Variable mit der Solltemperatur. Sie braucht eine Aktion. |
| Farbtemperatur | Nur beim Licht, wahlweise: die Variable mit der Farbtemperatur in Kelvin oder Mired. Sie braucht eine Aktion. |
| Farbe | Nur beim Licht, wahlweise: die Variable mit der Farbe als Ganzzahl (RGB, etwa Profil `~HexColor`). Sie braucht eine Aktion. |
| Ist-Feuchte, Zielfeuchte, Modus, Lüfterstufe | Nur beim Luftbe-/entfeuchter: die gemessene Luftfeuchte, die Zielfeuchte, die der Assistent stellen darf (Bereich und Schrittweite aus Darstellung oder Profil), und zwei Auswahlvariablen, deren Einträge er per Name setzt. Der Zustand heißt dort „Ein/Aus“. |
| Wiedergabe, Lautstärke, Stumm, Titel, Interpret | Nur beim Mediaplayer. Es sind die Variablen, die Symcon in der Darstellung „Media Player“ zusammenfasst: die Wiedergabe mit einem der Profile `~Playback`, `~PlaybackNoStop`, `~PlaybackPreviousNext`, `~PlaybackPreviousNextNoStop` oder `~PlayerStatus`, die Lautstärke (in Prozent ihres Bereichs angesagt), Stumm als Wahrheitswert, Titel und Interpret als Text. Beim Mediaplayer heißt der Zustand „Ein/Aus“. |
| Helligkeit/Position | Beim Licht die Helligkeit. Sie wird bei eingeschaltetem Licht mit angesagt und lässt sich in Prozent stellen. Beim Rollladen die Position, beim Fenster der Antrieb. |
| Fahrstatus | Nur bei Rollladen und Fenster mit Antrieb und nur, wenn es so eine Variable gibt: sie meldet, ob er gerade fährt, etwa `WORKING` bei Homematic oder der Laufstatus beim Velux KLF200. Dann antwortet das Modul sofort mit „fährt“, auch wenn die Position erst am Ziel kommt. |
| Stopp | Nur bei Rollladen und Fenster mit Antrieb, wahlweise: eine Variable, die die Fahrt anhält, also ein Wahrheitswert mit Aktion oder eine Auswahl mit dem Eintrag „Stop“ (etwa „Aktion“ beim HomeAssistant-Modul). Homematic- und Velux-KLF200-Geräte hält das Modul auch ohne sie an. |
| Leistung | Nur bei Licht, Schalter, Steckdose, Gerät und Luftbe-/entfeuchter, wahlweise: die aktuelle Leistung, etwa `POWER` eines Homematic-Messkanals. Sie steht in jeder Antwort hinter dem Zustand, zum Beispiel „an (26.1 W)“. |
| Zähler | Ebenso wahlweise: der Energiezähler, etwa `ENERGY_COUNTER`. Für den Verbrauch über Zeiträume muss er im Archiv als Zähler aggregiert werden. Hängt ein Gerät an einer Messsteckdose, dürfen beide Zeilen dieselbe Leistung und denselben Zähler tragen; Verbrauch und Leistung eines Raums oder des Hauses zählen jede Variable nur einmal, und der Selbsttest weist darauf hin. |
| Umgekehrt | Haken setzen, wenn `true` „zu“ oder „aus“ bedeutet. Viele Fensterkontakte melden `true` für geschlossen. Bei Rollläden erkennt das Modul selbst, welcher Wert „offen“ bedeutet; der Haken dreht das um, falls auf und zu vertauscht sind. |
| Kippfenster | Nur beim Fenster mit Antrieb: Haken setzen, wenn der Antrieb das Fenster nur kippt. Die Antwort sagt dann „gekippt“ und „50 % gekippt“ statt „offen“ und „50 % offen“. |
| Nur abfragen | Der Assistent darf den Zustand ansagen, das Gerät aber nicht schalten oder stellen. Gedacht für Geräte wie das Garagentor oder die Heizung im Kinderzimmer. |

Welche Variablen ein Gerät braucht, hängt vom Typ ab:

| Typ | Variablen |
|---|---|
| Licht | Schaltzustand, Helligkeit oder beides. Ein Dimmer ohne eigenen Schalter hat nur die Helligkeit. Wahlweise Leistung, Zähler, Farbtemperatur und Farbe. |
| Schalter, Steckdose | Schaltzustand, wahlweise Leistung und Zähler |
| Gerät | Schaltzustand oder Leistung, wahlweise der Zähler. Ein Gerät nur mit Leistung, etwa eine Wärmepumpe, lässt sich abfragen, aber nicht schalten. |
| Rollladen | Position, dazu wahlweise Fahrstatus und Stopp |
| Thermostat | Sollwert, wahlweise die Ist-Temperatur |
| Mediaplayer | Ein/Aus, Wiedergabe oder Lautstärke, wahlweise Stumm, Titel und Interpret |
| Luftbe-/entfeuchter | Ein/Aus oder Zielfeuchte, wahlweise Ist-Feuchte, Modus und Lüfterstufe |
| Fenster | Kontakt, Antrieb oder beides, zum Antrieb wahlweise Fahrstatus und Stopp |
| Tür | Kontakt |
| Anwesenheit, Wasserleck | Melder |
| Temperatur, Luftfeuchte, Luftqualität, sonstiger Messwert | Messwert |

Fehlt einer Zeile die Variable ihres Typs, oder wird eine eingetragene Variable gelöscht, ist nur dieses
Gerät gesperrt. Die Zeile wird rot, Formular und Log nennen sie, alle anderen Geräte bleiben erreichbar.
Symcon nennt die Instanz außerdem beim Löschen einer eingetragenen Variable als Referenz.

Geben Sie nur frei, was Sie per Sprache wirklich brauchen, je Raum etwa einen Temperatur- und einen
Feuchtesensor. Jedes Gerät mehr macht die Antworten des Assistenten langsamer und unsicherer.

## Szenen

Eine Szene ist ein Skript Ihrer Anlage, das der Assistent auf Zuruf starten darf, zum Beispiel
„Filmabend“ oder „Gute Nacht“. Tragen Sie in der Liste **Freigegebene Szenen** je Szene einen Namen
und das Skript ein, dazu nach Wunsch Aliase und einen Raum. Den Raum braucht es nur, wenn zwei Szenen
gleich heißen.

## Was der Assistent kann

Der Assistent bekommt drei Werkzeuge: eines zum Fragen, eines zum Schalten und Stellen und eines
für Szenen.

### Abfragen

Das Werkzeug **get_state** filtert nach Raum, Etage, Typ und Name und kann auf aktive Geräte beschränken
(an, offen, Anwesenheit erkannt). Die erste Zeile der Antwort nennt immer die Anzahl, zum Beispiel
„1 von 4 geprüften Geräten aktiv“. Geräte ohne an und aus, etwa Rollläden und Messwerte, zählen dabei
nicht mit; eine Schlusszeile nennt ihre Zahl und ihre Typen. Findet es nichts, nennt es die bekannten Räume und Typen, damit
der Assistent neu fragen kann. Die letzte Zeile nennt Datum, Uhrzeit und Wochentag, etwa „Jetzt: 2026-10-11 12:19
(Sonntag)“; so beantwortet der Assistent auch „Wie spät ist es?“ und rechnet „gestern“ richtig.

Ein Thermostat nennt Soll- und Ist-Temperatur, etwa „Soll 21 °C (derzeit 20.6 °C)“. Fragt der
Assistent nach `type=temperature`, findet er auch die Thermostate mit Ist-Temperatur; eine eigene
Temperaturzeile auf derselben Variable brauchen Sie dafür nicht. Steht der Sollwert außerhalb seines
Bereichs, heißt er Sonder-Sollwert: Manche Homematic-Thermostate nutzen 100 für „Ventil ganz offen“.

Ein Luftentfeuchter meldet sich mit „an (51 %, Soll 55 %, Smart, Lüfter Niedrig)“ oder „aus“ und zählt als
aktiv, solange er läuft.

Ein Mediaplayer meldet sich etwa mit „spielt „Can't Get Enough“ von Bad Company (Lautstärke 20 %)“,
„pausiert (Lautstärke 37 %, stumm)“ oder „aus“. Als aktiv zählt er, wenn er spielt. Beim
HomeAssistant-Modul ist die Wiedergabe nur eine Befehlsvariable; tragen Sie dort als Ein/Aus den
Statustext ein (`playing`, `paused`, `idle`, `off` …), dann kennt das Modul den Zustand trotzdem. Ohne
beides steht dort nur der Titel, und der Player zählt bei den aktiven Geräten nicht mit.

Mit `period` fragt **get_state** nach dem Verbrauch statt nach dem Zustand: `today`, `yesterday`,
`this_week`, `last_week`, `this_month`, `last_month`, `this_year` oder `last_year`. Statt `period` geht
auch ein freier Zeitraum mit `from` und `to` (erster und letzter Tag als JJJJ-MM-TT, beide
eingeschlossen, ohne `to` nur der eine Tag), etwa „im Januar“ mit `from=2026-01-01` und `to=2026-01-31`.
Ganze Jahre und ganze Monate kommen aus den Jahres- und Monatswerten des Archivs, alles andere aus den
Tageswerten. Die erste Zeile
nennt die Summe, etwa „88.5 kWh verbraucht durch 3 von 3 Gerät(en) mit Zähler“, darunter steht der
Verbrauch je Gerät. Die Werte kommen aus den Aggregaten des Archivs, der laufende Tag, die laufende
Woche usw. zählen bis jetzt. Ist ein Zähler nicht als Zähler archiviert, sagt die Zeile „kein Verlauf
archiviert“, und der Selbsttest weist darauf hin. Ein Zähler in einer anderen Einheit als Wh, kWh oder MWh, etwa ein Wasser- oder Gaszähler, gilt nicht als Energiezähler: Seine Zeile sagt „kein Energiezähler (Einheit m³)“, und er zählt nicht mit. Ohne Einheit gilt kWh. Geräte ohne Zähler bleiben außen vor. Den Parameter
bekommt der Assistent nur, wenn mindestens ein freigegebenes Gerät einen Zähler hat oder die
Energiebilanz eingetragen ist.

Den Verbrauch des ganzen Hauses rechnet das Modul aus der **Energiebilanz**:
Netzbezug + PV-Ertrag + Batterie entladen − Einspeisung − Batterie geladen. Die fünf Zähler tragen
Sie im Abschnitt „Energiebilanz“ des Formulars ein, jeder muss als Zähler archiviert sein. Ohne PV und
Batterie genügt der Netzbezug. Fragt der Assistent ohne Raum, Etage, Typ und Name (bei einem Zugang
mit Standardraum mit `room=all`), lautet die erste Zeile
etwa „Hausverbrauch 774.8 kWh (alle Geräte, period=last_month): Netzbezug 122.7, PV-Ertrag 1356.8,
Batterie entladen 285, Einspeisung 658.7, Batterie geladen 330.9 kWh.“ Die Geräte mit eigenem Zähler
sind ein Teil davon und werden nicht dazugezählt: „Davon 166.2 kWh durch 9 von 10 Gerät(en) mit
eigenem Zähler, 608.6 kWh nicht erfasst.“ Fehlt einem der Zähler der Verlauf, sagt die Antwort
„Hausverbrauch unbekannt“ und nennt den Zähler. Nach einem Raum oder Typ gefragt, bleibt es bei der
Summe der Geräte. Mit PV oder Batterie folgt eine Zeile mit Autarkie und Eigenverbrauch:
„Autarkie 84 %, Eigenverbrauch der PV 51 %.“ Fürs ganze Haus nennt die Antwort nur die fünf größten
Verbraucher und fasst den Rest zusammen („5 weitere Gerät(e) zusammen 25.6 kWh.“); nach einem Raum
gefragt, kommen alle Geräte in der Reihenfolge der Liste.

Mit `period=now` nennt **get_state** die Leistung jetzt in W, aufgebaut wie der Verbrauch: je Gerät mit
Leistung der aktuelle Wert, dazu die Summe. Fürs ganze Haus rechnet das Modul die Hausleistung aus den
fünf Leistungsfeldern der Energiebilanz, etwa „Hausleistung 1548 W (alle Geräte, period=now):
Netzbezug 27, PV-Ertrag 0, Batterie entladen 1521, Einspeisung 0, Batterie geladen 0 W.“ und „Davon
750 W durch 11 von 11 Gerät(en) mit Leistungsmessung, 798 W nicht erfasst.“ W, kW und MW werden
umgerechnet, ohne Einheit gilt W. Haben Sie für das Netz nur einen Sensor mit Vorzeichen, gehört er
zum Netzbezug (negativ heißt Einspeisung); ebenso ein Batteriesensor zu „Batterie entladen“ (negativ
heißt laden).

Für Messwerte (Temperatur, Feuchte, Luftqualität, sonstige Messwerte und die Ist-Temperatur eines
Thermostats) liefern dieselben Zeiträume den **Verlauf**: je Stunde, Tag oder Monat Minimum, Mittel und
Maximum, etwa „Wie warm war es die letzten 30 Tage im Gästezimmer?“. Die erste Zeile lautet dann
„Verlauf von 1 Messwert(en) (room=Gast, type=temperature, from=2026-09-10, to=2026-10-09), je Tag:
Minimum / Mittel / Maximum.“, darunter je Messwert eine Zeile mit der Einheit und je Zeitraum eine Zeile
wie „2026-09-28 22.2 / 22.6 / 23.1“. Heute und gestern kommen stündlich, Wochen und Monate täglich,
Jahre monatlich; bei `from` und `to` bis zu zwei Tage stündlich, bis zu 100 Tage täglich, darüber
monatlich. Der Verlauf gilt, wenn nach einem Messwert-Typ gefragt wird oder unter den Treffern kein
Gerät mit Zähler ist; fürs ganze Haus bleibt es beim Verbrauch. Höchstens fünf Messwerte gehen auf
einmal, sonst bittet die Antwort um einen Raum oder Namen. Liest ein Thermostat denselben Fühler wie
ein Temperaturgerät, kommt der Verlauf nur einmal. Der Messwert muss im Archiv geloggt sein, sonst sagt
die Zeile „kein Verlauf archiviert“.

Die Zustandsabfrage gibt es auch für Skripte:

```php
echo HAGT_QueryState($InstanzID, 'Küche', 'window', '', true);
```

### Schalten und Stellen

**set_state** erledigt alles, was etwas verändert. Der Assistent wählt die Geräte wie beim Abfragen
aus und gibt dazu einen Befehl oder einen Prozentwert an:

| Angabe | Wirkung |
|---|---|
| `on`, `off` | schaltet Lichter, Schalter, Steckdosen, Geräte, Mediaplayer und Luftentfeuchter ein oder aus |
| `open`, `close` | fährt Rollläden und Fenster mit Antrieb ganz auf oder ganz zu |
| `stop` | hält Rollläden und Fenster an, die gerade fahren, und beendet die Wiedergabe eines Mediaplayers |
| `play`, `pause` | setzt die Wiedergabe eines Mediaplayers fort oder hält sie an |
| `next`, `previous` | springt beim Mediaplayer zum nächsten oder vorigen Titel |
| `volume` | stellt die Lautstärke eines Mediaplayers in Prozent |
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
sich so nicht ausschalten; die Antwort sagt das dann, statt die kleinste Stufe zu senden. Tragen Sie
für solche Lichter auch den Schaltzustand ein. Bei Rollläden und Fenstern sagt die Zahl, wie weit sie
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

Die Lautstärke rechnet das Modul in Prozent des Bereichs, den die Darstellung oder das Profil der
Variable nennt. Beim Sonos-Modul geht er von 0 bis 100, beim HomeAssistant-Modul von 0 bis 1.

Eine Temperatur prüft das Modul gegen den Bereich des Sollwerts, den es aus der Darstellung oder dem
Profil der Variable liest. Liegt sie außerhalb, geht nichts hinaus, und die Antwort nennt den Bereich.
Krumme Werte gehen auf die Schrittweite, aus 21,3 wird bei 0,5er-Schritten 21,5, und die Antwort nennt
den gesendeten Wert. Einträge wie „On“ am Rand eines Homematic-Profils zählen nicht zum Bereich. Der
Knopf **Selbsttest ausführen** zeigt den Bereich je Thermostat.

Die Farbtemperatur nimmt der Assistent in Kelvin an. Zählt die Variable in Mired (Bereich bis 1000, so
etwa Hue über Matter), rechnet das Modul um. Ein Wert außerhalb des Bereichs der Lampe geht auf das
nächste Ende, und die Antwort nennt den gesendeten Wert. Ein ausgeschaltetes Licht wird dabei
eingeschaltet. Bei eingeschaltetem Licht nennt die Abfrage die Farbtemperatur, etwa „an (100 %, 2700 K)“.

Eine Farbe wählt der Assistent aus einer festen Liste von neun Namen. Das Modul schreibt sie als RGB in
die Variable und schaltet ein ausgeschaltetes Licht dabei ein. Die Abfrage nennt bei eingeschaltetem
Licht die nächstliegende Farbe der Liste. Ein Licht ohne eigenen Schalter, etwa eine RGBW-Leiste, gilt als an,
solange seine Farbe nicht schwarz ist, und „aus“ setzt die Farbe mit auf Schwarz. Eine Farbvariable mit Text (Darstellung Farbe mit Kodierung,
etwa beim HomeAssistant-Modul) stellt das Modul noch nicht, die Antwort sagt das.

Ein Befehl für eine Gruppe erreicht nur die Geräte, die ihn verstehen. „Wohnzimmer aus“ schaltet
Lichter und Steckdosen und lässt die Rollläden in Ruhe; die Antwort nennt am Ende, wie viele Geräte
ausgelassen wurden. Nennt der Assistent ein einzelnes Gerät mit einem Befehl, den es nicht versteht,
ändert sich nichts, und die Antwort sagt, was das Gerät kann.

Wie ein Rollladen intern zählt, müssen Sie nicht wissen. Manche Geräte melden 0 für offen, andere 0
für geschlossen. Das Modul liest es aus der Darstellung „Rollladen“ der Variable oder aus ihrem
Profil (ein Profilname mit „.Reversed“ zählt umgekehrt). Rollläden aus dem HomeAssistant-Modul zählen wie
Home Assistant 100 als offen. Der Knopf **Selbsttest ausführen** zeigt für jeden
Rollladen, was erkannt wurde, zum Beispiel „Rollladen Küche Rollladen: offen bei 1, geschlossen bei 0“.
Stimmt das nicht, setzen Sie beim Gerät den Haken „Richtung umkehren“.

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

Rollläden brauchen für ihre Fahrt länger. Das Modul antwortet mit „fährt“, sobald sich zeigt, dass der
Rollladen losgefahren ist: an seiner Position, an seinem Fahrstatus oder daran, dass er sich nach dem
Befehl gemeldet hat (Homematic meldet sich beim Losfahren, auch wenn die Position noch die alte ist).
Meldet er sich gar nicht, etwa ein Velux-Rollladen am KLF200 ohne eingetragenen Fahrstatus, lautet die
Antwort „Fahrbefehl gesendet, noch keine Positionsmeldung“. Fragt der Assistent während der Fahrt
nach, nennt die Antwort das Ziel: „fährt auf geschlossen (derzeit offen)“.

Mit `stop` hält das Modul einen fahrenden Rollladen an und meldet, wo er steht: „angehalten (68 %
offen)“. Angehalten wird über die eingetragene Stoppvariable, sonst über das Modul des Geräts
(Homematic und Velux KLF200); der Selbsttest nennt den Weg je Rollladen. `stop` gibt es nur, wenn
mindestens ein Rollladen einen solchen Weg hat. Meldet der Fahrstatus, dass er schon steht, geht kein
Befehl hinaus, die Antwort sagt dann „stand schon“. Ein Velux meldet seine Position erst rund 2,5 s nach
dem Stopp; die Antwort lautet dann „Stopp gesendet, noch nicht bestätigt“, eine Nachfrage zeigt den
Stand.

Für Skripte gibt es das Schalten und das Stellen als zwei Funktionen:

```php
echo HAGT_SwitchDevices($InstanzID, 'Wohnzimmer', 'light', '', false);
echo HAGT_SetLevel($InstanzID, '', '', 'Deckenpanel', 30);
```

### Szenen starten

**run_scene** startet eine Szene. Die Namen Ihrer Szenen stehen in der Beschreibung des Werkzeugs, der
Assistent kennt sie also von Anfang an. Der Name muss genau eine Szene treffen. Passt er zu mehreren
oder zu keiner, startet das Modul nichts und nennt die Szenen. Ohne eingetragene Szene bietet das
Modul das Werkzeug nicht an.

Die Antwort bestätigt, dass Symcon das Skript gestartet hat. Was es bewirkt hat, zeigt eine Abfrage
danach.

Für Skripte:

```php
echo HAGT_RunScene($InstanzID, '', 'Filmabend');
```

## Grenzen

- Farbe als Text und die Betriebsart von Thermostaten (aus, heizen, automatisch) gehen noch nicht.
- Ein batteriebetriebenes Thermostat übernimmt einen neuen Sollwert oft erst beim nächsten Funkkontakt.
  Die Zentrale meldet ihn trotzdem sofort zurück, „gestellt“ heißt dann „an die Zentrale übergeben“.
- Bei einem Rollladen, der sich erst am Ziel meldet und keinen Fahrstatus hat, weiß das Modul nach
  dem Fahrbefehl nicht, ob er fährt.
- Fehler innerhalb eines Szenen-Skripts sieht der Assistent nicht, er erfährt nur vom Start.
- Lamellen, deren Variable die Darstellung „Rollladen“ mit Rotation trägt, bekommen nur eine
  Prozentzahl ohne „offen“ oder „geschlossen“.
- Der Endpunkt spricht MCP über einfache HTTP-Anfragen und bietet keinen Datenstrom an. Clients, die
  zwingend einen Datenstrom (SSE) verlangen, können sich nicht direkt verbinden. Per OAuth melden sich
  nur Clients mit einem Client ID Metadata Document an, erprobt ist die Claude-App. Für ChatGPT ist
  beides nicht erprobt.
- Tokens und Kopplungscodes sind das einzige Schloss. Über Symcon Connect ist der Endpunkt aus dem
  Internet erreichbar; verwenden Sie deshalb erzeugte Tokens. Abgewiesene Anmeldungen stehen im
  Meldungsprotokoll (die erste einer Minute und danach ihre Anzahl), falsche Kopplungscodes zählen mit.
  Nach 20 abgewiesenen Anmeldungen in einer Minute
  ist der Endpunkt 60 Sekunden lang für alle gesperrt, auch für Assistenten mit richtigem Token.
- Steht das Token in der Adresse, kann es in Logs, Verläufen und Einstellungsdateien des Clients
  landen. Setzen Sie den Haken nur bei Zugängen, deren Client die Anmeldung nicht im Header
  mitschicken kann.
- Die Tokens stehen in der Konfiguration der Instanz. Wer Objekte der Anlage lesen darf, etwa ein
  Assistent über den eingebauten MCP-Server von Symcon, liest sie mit.
- Die Skriptfunktionen kennen weder Standardraum noch Etage. Es gibt sie für Zustand, Schalten,
  Stellen und Szenen, für Stopp, Sollwert, Farbtemperatur, Farbe, Verbrauch, Leistung und Verlauf nicht.

## Begriffe

- **MCP:** Model Context Protocol, die Schnittstelle, über die KI-Assistenten Werkzeuge nutzen.
- **Token:** das Kennwort, mit dem sich der Assistent anmeldet.
- **Zugang:** ein Token samt seinen Einstellungen (Standardraum, nur abfragen).

## Anhang Technik

- Endpunkt `/hook/homeagent/<InstanzID>`, nur POST mit JSON-RPC 2.0, Antwort `application/json`.
  GET wird mit 405 abgewiesen, Benachrichtigungen mit 202 quittiert.
- Anmeldung mit `Authorization: Bearer <Token>`, bei Zugängen mit gesetztem Haken auch über das Token
  als letzten Teil des Pfads. Schickt ein Client beides, zählt der Header.
- Unterstützte Protokollversionen: 2025-11-25, 2025-06-18, 2025-03-26.
- Status 104: kein Zugang mit Token, der Endpunkt weist alle Anfragen ab. Status 201: Die Raumliste ist
  fehlerhaft, oder es gibt keinen fehlerfreien Zugang mehr; das Log nennt jeden betroffenen Eintrag, und
  der Endpunkt bleibt bis zur Korrektur geschlossen. In beiden Fällen antwortet er mit HTTP 503
  („nicht bereit“), nicht mit einem Anmeldefehler. Ein fehlerhafter Zugang (Token zu kurz oder doppelt,
  unbekannter Standardraum), ein Gerät oder eine Szene mit fehlender Variable bzw. fehlendem Skript
  sperrt nur sich selbst; der Rest arbeitet weiter, Formular und Log nennen den Eintrag. Fehlt eine
  Variable der Energiebilanz, ist die ganze Bilanz gesperrt, sonst wäre der Hausverbrauch falsch.
