# Home Agent

[English version](README.en.md)

Dieses Modul gibt dem Haus einen Agenten: Ein KI-Assistent, der das Model Context Protocol (MCP)
spricht, bedient damit Symcon, ob gesprochen, getippt oder aus einem eigenen Ablauf. Er kann fragen
„Ist noch irgendwo ein Fenster offen?“, „Mach das Licht im Wohnzimmer aus“ sagen oder einen
Rollladen halb schließen und bekommt eine kurze, eindeutige Antwort. Er sieht und schaltet dabei nur
die Geräte, die Sie freigeben.

> **Stand:** frühe Vorschau. Abfragen, Schalten, das Stellen von Helligkeit, Farbtemperatur, Farbe, Position,
> Heizungs-Sollwert und Zielfeuchte, Betriebsart und Lüfterstufe von Luftentfeuchtern und Lüftungen, das
> Anhalten von Rollläden, Verbrauch und Leistung auch hinter Zählern und Unterzählern, der Verlauf von Messwerten und Leistungen, Zeitleisten von Zuständen, das Starten von Szenen sowie
> Wiedergabe und Lautstärke von Mediaplayern gehen. Die Geräteliste lässt sich aus einer Alexa-Instanz
> vorschlagen. Die Claude-App meldet sich auf Wunsch per OAuth an. Was nicht geklappt hat, zeigt das
> Formular, ein Protokoll zur Fehlersuche lässt sich einschalten. Erprobt ist das Modul bisher auf drei Anlagen.

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
| Name | Frei wählbar, am besten nach dem, der über den Zugang kommt (siehe unten). Er erscheint im Protokoll, unter „Was nicht geklappt hat“ und im Debug der Instanz. |
| Token | Das Kennwort dieses Zugangs. Ein Zugang ohne Token bekommt beim Übernehmen eines aus 64 Zeichen; ein per Skript gesetztes braucht mindestens 24. |
| Standardraum | Der Raum, in dem der Assistent steht. „Mach das Licht an“ ohne Raum meint dann diesen Raum, und bei gleichen Gerätenamen gewinnt das Gerät dort. Für das ganze Haus sagt der Assistent `room=all`. |
| Nur abfragen | Dieser Zugang darf fragen, aber nichts schalten. Er sieht nur das Werkzeug zum Abfragen, und der Assistent erfährt, dass der Zugang nur lesen darf. |
| Token auch in der Adresse | Für Clients, die keine Anmeldung mitschicken können. Das Token hängt dann an der Adresse. |
| Werkzeugliste | Ob der Assistent die aktuelle Werkzeugliste kennt: **aktuell**, **veraltet** oder **noch nicht geholt**, mit der Uhrzeit des letzten Abholens. Die Liste ändert sich mit einem Update des Moduls und mit Ihren Einstellungen, etwa einem Gerät mit neuem Typ, einem neuen Raum oder einer neuen Szene. Steht dort **veraltet**, öffnen Sie in der Claude-App einen neuen Chat; hilft das nicht, trennen Sie den Connector und verbinden ihn neu. |

Ohne Zugang zeigt die Instanz den Status 104 und weist alle Anfragen ab. Ein neuer Zugang bekommt sein Token beim Übernehmen.

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
Formular.

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

## Räume und Etagen

Räume legen Sie in der Liste **Räume** an, wahlweise mit weiteren Bezeichnungen und einer Etage. Danach
findet „Stube“ das Wohnzimmer, und „alle Lichter im Obergeschoss“ trifft jeden Raum dieser Etage.

Im Dialog eines Geräts wählen Sie den Raum aus dieser Liste, bei einem Gerät ohne Raum „-“. Ein neuer
Raum kommt zuerst in die Raumliste; nach dem Übernehmen steht er in der Auswahl. Räume, die in der
Raumliste fehlen (etwa per Skript eingetragen), werden weiter über ihren Namen gefunden. Das Formular
nennt sie über der Geräteliste, der Selbsttest ebenso.

## Die Freigabeliste

Jede Zeile ist ein Gerät mit seinen Fähigkeiten. Im Dialog zum Bearbeiten wählen Sie den Typ, danach
zeigt der Dialog nur die Variablen, die zu diesem Typ gehören.

| Spalte | Bedeutung |
|---|---|
| Raum | Ein Raum aus der Raumliste oder „-“. In Anfragen sind Groß- und Kleinschreibung und Umlaute egal („Küche“ = „kueche“). |
| Name, Aliase | Der Name und weitere Bezeichnungen, durch Komma getrennt. Ein Teilwort genügt beim Suchen. Wird ein Gerät über einen Alias gefunden, steht er in der Antwort in Klammern hinter dem Namen. |
| Typ | Licht, Schalter, Steckdose, Gerät, Rollladen, Thermostat, Mediaplayer, Luftbe-/entfeuchter, Lüftung/Ventilator, Fenster, Tür, Anwesenheit, Wasserleck, Temperatur, Luftfeuchte, Luftqualität, sonstiger Messwert oder Zähler. |
| Zustand oder Messwert | Die Variable für an/aus, offen/zu oder erkannt, bei Messwerten der Wert selbst. Beim Thermostat die Ist-Temperatur. |
| Sollwert | Nur beim Thermostat: die Variable mit der Solltemperatur. Sie braucht eine Aktion. |
| Farbtemperatur | Nur beim Licht, wahlweise: die Variable mit der Farbtemperatur in Kelvin oder Mired. Sie braucht eine Aktion. |
| Farbe | Nur beim Licht, wahlweise: die Variable mit der Farbe als Ganzzahl (RGB, etwa Profil `~HexColor`). Sie braucht eine Aktion. |
| Ist-Feuchte, Zielfeuchte, Modus, Lüfterstufe | Beim Luftbe-/entfeuchter: die gemessene Luftfeuchte, die Zielfeuchte, die der Assistent stellen darf (Bereich und Schrittweite aus Darstellung oder Profil), und zwei Auswahlvariablen, deren Einträge er per Name setzt. Bei der Lüftung nur Modus und Lüfterstufe. Der Zustand heißt dort „Ein/Aus“. |
| Wiedergabe, Lautstärke, Stumm, Titel, Interpret | Nur beim Mediaplayer. Es sind die Variablen, die Symcon in der Darstellung „Media Player“ zusammenfasst: die Wiedergabe mit einem der Profile `~Playback`, `~PlaybackNoStop`, `~PlaybackPreviousNext`, `~PlaybackPreviousNextNoStop` oder `~PlayerStatus`, die Lautstärke (in Prozent ihres Bereichs angesagt), Stumm als Wahrheitswert, Titel und Interpret als Text. Beim Mediaplayer heißt der Zustand „Ein/Aus“. |
| Helligkeit/Position | Beim Licht die Helligkeit. Sie wird bei eingeschaltetem Licht mit angesagt und lässt sich in Prozent stellen. Beim Rollladen die Position, beim Fenster der Antrieb. |
| Fahrstatus | Nur bei Rollladen und Fenster mit Antrieb und nur, wenn es so eine Variable gibt: sie meldet, ob er gerade fährt, etwa `WORKING` bei Homematic oder der Laufstatus beim Velux KLF200. Dann antwortet das Modul sofort mit „fährt“, auch wenn die Position erst am Ziel kommt. |
| Stopp | Nur bei Rollladen und Fenster mit Antrieb, wahlweise: eine Variable, die die Fahrt anhält, also ein Wahrheitswert mit Aktion oder eine Auswahl mit dem Eintrag „Stop“ (etwa „Aktion“ beim HomeAssistant-Modul). Homematic- und Velux-KLF200-Geräte hält das Modul auch ohne sie an. |
| Leistung | Nur bei Licht, Schalter, Steckdose, Gerät, Luftbe-/entfeuchter und Lüftung, wahlweise: die aktuelle Leistung, etwa `POWER` eines Homematic-Messkanals. Sie steht in jeder Antwort hinter dem Zustand, zum Beispiel „an (26.1 W)“. |
| Zähler | Ebenso wahlweise: der Energiezähler, etwa `ENERGY_COUNTER`. Für den Verbrauch über Zeiträume muss er im Archiv als Zähler aggregiert werden. Hängt ein Gerät an einer Messsteckdose, dürfen beide Zeilen dieselbe Leistung und denselben Zähler tragen; Verbrauch und Leistung eines Raums oder des Hauses zählen jede Variable nur einmal, und der Selbsttest weist darauf hin. |
| Gemessen von | Bei allem mit Leistung oder Zähler, wahlweise: der Zähler, hinter dem das Gerät oder der Zähler hängt, etwa die Unterverteilung OG. Zur Auswahl stehen die Zähler der gespeicherten Liste, ein neuer also erst nach dem Übernehmen. |
| Umgekehrt | Haken setzen, wenn `true` „zu“ oder „aus“ bedeutet. Viele Fensterkontakte melden `true` für geschlossen. Bei Fenster, Tür und Meldern mit einer Zahl gilt sonst alles über 0 als offen bzw. erkannt, mit dem Haken nur die 0; so passt etwa der Türstatus von Matter (0 Geöffnet, 1 Geschlossen). Weitere Einträge wie „Angelehnt“ nennt die Antwort in Klammern. Bei Rollläden erkennt das Modul selbst, welcher Wert „offen“ bedeutet; der Haken dreht das um, falls auf und zu vertauscht sind. |
| Kippfenster | Nur beim Fenster mit Antrieb: Haken setzen, wenn der Antrieb das Fenster nur kippt. Die Antwort sagt dann „gekippt“ und „50 % gekippt“ statt „offen“ und „50 % offen“. |
| Nur abfragen | Der Assistent darf den Zustand ansagen, das Gerät aber nicht schalten oder stellen. Gedacht für Geräte wie das Garagentor oder die Heizung im Kinderzimmer. |

Welche Variablen ein Gerät braucht, hängt vom Typ ab:

| Typ | Variablen |
|---|---|
| Licht | Schaltzustand, Helligkeit oder beides. Ein Dimmer ohne eigenen Schalter hat nur die Helligkeit. Wahlweise Leistung, Zähler, Farbtemperatur und Farbe. |
| Schalter, Steckdose | Schaltzustand, wahlweise Leistung und Zähler |
| Gerät | Schaltzustand oder Leistung, wahlweise der Zähler. Ein Gerät nur mit Leistung, etwa eine Wärmepumpe, lässt sich abfragen, aber nicht schalten. |
| Rollladen | Position, dazu wahlweise Fahrstatus und Stopp |
| Thermostat | Sollwert, wahlweise die Ist-Temperatur, Modus und Lüfterstufe (Klimagerät), Leistung und Zähler |
| Mediaplayer | Ein/Aus, Wiedergabe oder Lautstärke, wahlweise Stumm, Titel, Interpret, Leistung und Zähler |
| Luftbe-/entfeuchter | Ein/Aus oder Zielfeuchte, wahlweise Ist-Feuchte, Modus und Lüfterstufe |
| Lüftung/Ventilator | Ein/Aus oder Lüfterstufe, wahlweise Modus |
| Fenster | Kontakt, Antrieb oder beides, zum Antrieb wahlweise Fahrstatus und Stopp |
| Tür | Kontakt |
| Anwesenheit, Wasserleck | Melder |
| Temperatur, Luftfeuchte, Luftqualität, sonstiger Messwert | Messwert |
| Zähler | Zähler, wahlweise Leistung |

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

Ein Klimagerät tragen Sie als Thermostat ein, dazu Modus und Lüfterstufe. Die Zeile lautet dann etwa
„Soll 24 °C (derzeit 20.0 °C), Aus, Lüfter Auto“, und der Assistent stellt beides per Name wie beim
Luftentfeuchter. „Schalte das Klimagerät aus“ stellt den Modus auf Aus, eingeschaltet wird über einen Modus wie
Kühlen oder Heizen. Beide Variablen brauchen eine Aktion und eine Auswahl in der
Darstellung oder im Profil.

Ein Luftentfeuchter meldet sich mit „an (51 %, Soll 55 %, Smart, Lüfter Niedrig)“ oder „aus“ und zählt als
aktiv, solange er läuft. Eine Lüftung meldet sich ebenso, nur ohne Feuchte: „an (Nacht, Lüfter niedrig)“.

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
Tageswerten. Mit Uhrzeit (JJJJ-MM-TT HH:MM) geht es stundengenau, etwa „heute zwischen 13 und 15 Uhr“ mit
`from=2026-10-12 13:00` und `to=2026-10-12 15:00`; dann ist `to` das Ende, ohne `to` gilt bis jetzt. Gerechnet
wird mit ganzen Stunden aus den Stundenwerten, höchstens 48 Stunden am Stück. Die erste Zeile
nennt die Summe, etwa „88.5 kWh verbraucht durch 3 von 3 Gerät(en) mit Zähler“, darunter steht der
Verbrauch je Gerät. Die Werte kommen aus den Aggregaten des Archivs, der laufende Tag, die laufende
Woche usw. zählen bis jetzt. Ist ein Zähler nicht als Zähler archiviert, sagt die Zeile „kein Verlauf
archiviert“, und der Selbsttest weist darauf hin. Er prüft auch, ob das Archiv zum Zählerstand passt: Ein einzelner Fehlwert nach oben und zurück zählt im Archiv als Verbrauch, dann nennt der Selbsttest den Zähler und die Monate, etwa „Das Archiv zählt mehr, als der Zählerstand gestiegen ist (01/2024 +659.8 kWh)“. Den Fehlwert löschen Sie im Archiv und aggregieren die Variable neu. Setzt ein Zähler zurück, etwa nach einem Stromausfall, zählt das Archiv richtig weiter, das ist kein Befund. Ein Zähler in einer anderen Einheit als Wh, kWh oder MWh, etwa ein Wasser- oder Gaszähler, gilt nicht als Energiezähler: Seine Zeile sagt „kein Energiezähler (Einheit m³)“, und er zählt nicht mit. Ohne Einheit gilt kWh. Geräte ohne Zähler bleiben außen vor. Den Parameter
bekommt der Assistent nur, wenn mindestens ein freigegebenes Gerät einen Zähler hat oder die
Energiebilanz eingetragen ist.

Den Verbrauch des ganzen Hauses rechnet das Modul aus der **Energiebilanz**:
Netzbezug + PV-Ertrag + Batterie entladen − Einspeisung − Batterie geladen. Im Abschnitt „Energiebilanz“
des Formulars steht dafür die Liste **Quellen**, eine Zeile je Netzanschluss, PV-Anlage und Batterie. Der Dialog
einer Zeile zeigt je Art die passenden Felder: beim Netz Bezug und Einspeisung, bei der PV-Anlage den Ertrag, bei
der Batterie entladen und geladen, jeweils als Zähler und wahlweise als Leistung. Jeder Zähler muss als Zähler
archiviert sein. Ohne PV und Batterie genügt eine Zeile Netz mit dem Bezug. Fragt der Assistent ohne Raum, Etage, Typ und Name (bei einem Zugang
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

Mehrere PV-Anlagen, Batterien oder Netzanschlüsse bekommen je eine eigene Zeile. Die Bilanz zählt die Zeilen einer
Art zusammen, auch rückwirkend aus dem Archiv, und die Antwort nennt sie dann mit Namen, etwa „PV-Ertrag …
(davon Dach …, Garage …)“. Im Diagramm des Hausverbrauchs steht „PV direkt“ dann je Anlage in Abstufungen der
PV-Farbe, aufgeteilt nach ihrem Ertrag im Abschnitt; welche Anlage einspeist oder die Batterie lädt, misst niemand.
Ein Teil der Bilanz (`part`) zeigt jede Quelle als eigene Reihe. Hat eine Zeile einen Fehler, ist die ganze Bilanz gesperrt. Stand die Bilanz bisher in
einzelnen Feldern, finden Sie sie nach dem Update als Zeilen „Netz“, „PV“ und „Batterie“ in der Liste; die Namen
dürfen Sie ändern.

Eine Unterverteilung, eine Wallbox oder einen Wohnungszähler tragen Sie als Typ **Zähler** ein, mit dem
Energiezähler und wahlweise der Leistung. Ein Zähler zählt nie in einer Gerätesumme, er hat seine eigene Zeile.
Im Feld „Gemessen von“ wählen Sie bei Geräten und Zählern den Zähler darüber, beliebig tief, etwa das Terrarium am
Kinderzimmer und das Kinderzimmer an der Unterverteilung OG. Die Zeile eines Zählers nennt dann, was darunter
gemessen ist, und den Rest: „Haushalt 13.4 kWh, davon 2.1 kWh von 9 Zähler(n) oder Gerät(en) darunter gemessen,
11.3 kWh nicht erfasst“. Fürs ganze Haus zählt die oberste Ebene, also alles ohne „Gemessen von“: „Davon 13.4 kWh
durch 2 Zähler und 0 Gerät(e) auf oberster Ebene, 0 kWh nicht erfasst.“ Ebenso bei der Leistung mit `period=now`;
ein Zähler ohne eigene Leistung zählt dort mit dem, was darunter gemessen ist. Ein Verweis auf etwas, das kein
Zähler der Liste ist, und Zähler, die sich im Kreis messen, sperren die Zeile. Liegt unter einem Zähler in den
letzten 30 Tagen mehr als 105 % seines eigenen Werts, weist der Selbsttest darauf hin. Das Diagramm eines Zählers
zeigt, was unter ihm hängt, und den Rest als „nicht erfasst“; das Diagramm des Hauses je Gerät zeigt mit Zählern die
oberste Ebene. Die Erzeugung einer PV-Anlage gehört in die Energiebilanz, nicht in einen Zähler.

Tragen Sie in der Energiebilanz den **Strompreis** ein (eine archivierte Variable in ct/kWh oder €/kWh, bei einem
dynamischen Tarif etwa der aktuelle Preis aus dem Tibber-Modul) und die Einspeisevergütung, nennt der Hausverbrauch
auch die Kosten: „Kosten 38.21 € für 122.7 kWh Netzbezug (im Mittel 31.1 ct/kWh bezahlt, Durchschnittspreis 35.4 ct/kWh).
Einspeisung 60.47 €. Ohne PV und Batterie wären es 259.20 € gewesen.“ Gerechnet wird je Stunde Netzbezug mal
Stundenmittel des Preises. Das sind reine Arbeitskosten ohne Grundgebühr; gegen die Abrechnung des Versorgers weicht
das um wenige Prozent ab, weil der eigene Zähler etwas anders misst und das Archiv nur Stundenmittel kennt. Im
Diagramm zeigt `costs` den Hausverbrauch in Euro, als Stapel, als Heatmap und mit Vergleich. Eine Summenkurve
(`cumulative`) addiert Verbrauch oder Kosten Tag für Tag auf und rechnet einen laufenden Zeitraum bis zu seinem Ende
hoch, etwa „Bisher 10239.5 kWh, hochgerechnet 13079.9 kWh für den ganzen Zeitraum. Vergleich zur selben Zeit 9066.1 kWh
(+13 %).“ Hochgerechnet wird mit dem Verlauf desselben Zeitraums im Vorjahr, so kommt der teure Winter richtig hinein; ohne
Vorjahresdaten linear mit dem bisherigen Mittel. In Euro steht die Vergütung der Einspeisung als grüne Linie daneben, der
Text nennt den Saldo („Einspeisung 786.59 €, Saldo 491.01 €.“).

Mit `part` zeigt das Diagramm statt des Hausverbrauchs nur einen Zähler der Energiebilanz: `grid` (Netzbezug), `feed_in`
(Einspeisung), `pv`, `battery_charge` oder `battery_discharge`. Das geht als Balken, Summenkurve, Heatmap und mit
Vergleich, mit `costs` bei `grid` in Euro und bei `feed_in` als Vergütung. So beantwortet der Assistent etwa „Was habe
ich dieses Jahr an Einspeisevergütung verdient, und wo lande ich am Jahresende?“ mit einem Diagramm.

Mit `period=now` nennt **get_state** die Leistung jetzt in W, aufgebaut wie der Verbrauch: je Gerät mit
Leistung der aktuelle Wert, dazu die Summe. Fürs ganze Haus rechnet das Modul die Hausleistung aus den
Leistungen der Quellen, etwa „Hausleistung 1548 W (alle Geräte, period=now):
Netzbezug 27, PV-Ertrag 0, Batterie entladen 1521, Einspeisung 0, Batterie geladen 0 W.“ und „Davon
750 W durch 11 von 11 Gerät(en) mit Leistungsmessung, 798 W nicht erfasst.“ W, kW und MW werden
umgerechnet, ohne Einheit gilt W. Haben Sie für das Netz nur einen Sensor mit Vorzeichen, gehört er
ins Feld „Netzbezug Leistung“ (negativ heißt Einspeisung); ebenso ein Batteriesensor ins Feld „Batterie
entladen Leistung“ (negativ heißt laden). Meldet ein Sensor andersherum, etwa eine Batterie, die beim Laden
positiv zählt, setzen Sie in der Zeile den Haken „Vorzeichen umkehren“. Der Selbsttest nennt dann je Quelle den
aktuellen Wert und wie er zählt („Energiebilanz: Batterie entladen Leistung "Marstek" Battery Power meldet jetzt
1112 W und zählt als Batterie geladen 1112 W, durch den Haken umgekehrt“).

Für Messwerte (Temperatur, Feuchte, Luftqualität, sonstige Messwerte und die Ist-Temperatur eines
Thermostats) liefern dieselben Zeiträume den **Verlauf**: je Stunde, Tag oder Monat Minimum, Mittel und
Maximum, etwa „Wie warm war es die letzten 30 Tage im Gästezimmer?“. Die erste Zeile lautet dann
„Verlauf von 1 Messwert(en) (room=Gast, type=temperature, from=2026-09-10, to=2026-10-09), je Tag:
Minimum / Mittel / Maximum.“, darunter je Messwert eine Zeile mit dem tiefsten und höchsten Wert des ganzen
Zeitraums samt Zeitpunkt („Minimum 20.6 °C (2026-09-30 05:40), Maximum 23.4 °C (2026-10-02 16:15)“) und je Zeitraum eine Zeile
wie „2026-09-28 22.2 / 22.6 / 23.1“. Heute und gestern kommen stündlich, Wochen und Monate täglich,
Jahre monatlich; bei `from` und `to` bis zu zwei Tage stündlich, bis zu 100 Tage täglich, darüber
monatlich. Der Verlauf gilt, wenn nach einem Messwert-Typ gefragt wird oder unter den Treffern kein
Gerät mit Zähler ist; fürs ganze Haus bleibt es beim Verbrauch. Höchstens fünf Messwerte gehen auf
einmal, sonst bittet die Antwort um einen Raum oder Namen. Liest ein Thermostat denselben Fühler wie
ein Temperaturgerät, kommt der Verlauf nur einmal. Der Messwert muss im Archiv geloggt sein, sonst sagt
die Zeile „kein Verlauf archiviert“.

Nach der **Leistung über einen Zeitraum** fragt `type=power` zusammen mit `period` oder `from`/`to`, etwa „Wann hat
der Trockner letzten Monat am meisten gezogen?“. Gemeint sind alle Treffer mit Leistung, auch Zähler. Je Gerät nennt
eine Zeile die kleinste und die größte Leistung mit dem Zeitpunkt aus den Rohwerten des Archivs („Minimum 20.8 W
(2026-10-08 22:40), Maximum 336.9 W (2026-10-01 20:46)“), bei nur einem Gerät folgt die Tabelle je Stunde, Tag oder
Monat. Bis zu 20 Geräte gehen auf einmal. Ohne Raum und Name steht mit Energiebilanz dort die Hausleistung aus
den Zählern der Bilanz mit dem niedrigsten und höchsten Stundenmittel, dazu Netzbezug und Einspeisung mit ihren
Spitzen in kW. Stunden, in denen die Bilanz einen Hausverbrauch unter 0 ergibt, passen die Zähler nicht zusammen; sie
zählen nicht mit, und die Antwort nennt ihre Anzahl und die Tage mit den meisten davon. Minimum und Maximum von Geräten,
Netzbezug und Einspeisung sind als Momentanwerte gekennzeichnet; kein Mittel über 15 Minuten kann darüber liegen. Die Spitzen mehrerer Sensoren lassen sich aus dem Archiv nicht sekundengenau zusammenrechnen, deshalb
gilt fürs Haus das Stundenmittel. Ohne Zeitraum liefert `type=power` die Leistung jetzt. Die Leistung muss im Archiv
geloggt sein.

Für Geräte mit einem Zustand (an/aus, offen/zu, Bewegung, eine Auswahl wie die Betriebsart einer Batterie)
liefern dieselben Zeiträume eine **Zeitleiste**: wie lange jeder Zustand galt und, bei bis zu zwölf Abschnitten,
wann. Auf „Wann war die Haustür heute offen?“ antwortet das Modul etwa mit
„Flur | Haustür | door | offen 12 min, geschlossen 15 h 36 min“ und darunter „offen 08:32, 09:17-09:18, …“.
Hat ein Gerät auch einen Zähler, stehen Verbrauch und Zeiten in einer Zeile („0.2 kWh, an 3 h 31 min, aus
12 h 18 min“). Bei mehr als fünf Geräten, mehr als zwölf Abschnitten oder mehr als zwei Tagen stehen nur die
Summen mit der Anzahl da. Der Zustand muss im Archiv geloggt sein; ein Zustand ohne Archiv wird ausgelassen
und gezählt. Fürs ganze Haus gibt es keine Zeitleiste.

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
Variable nennt. Beim Sonos-Modul geht er von 0 bis 100, beim HomeAssistant-Modul von 0 bis 1, bei einem
Denon-Receiver von -80 bis 18 dB. Dort sind 50 % also -31 dB, und die Abfrage nennt beides
(„Lautstärke 64 % = -17.5 dB“). Gesendet wird in der Schrittweite der Variable, beim Denon in halben dB.

Eine Temperatur prüft das Modul gegen den Bereich des Sollwerts, den es aus der Darstellung oder dem
Profil der Variable liest. Liegt sie außerhalb, geht nichts hinaus, und die Antwort nennt den Bereich.
Krumme Werte gehen auf die Schrittweite, aus 21,3 wird bei 0,5er-Schritten 21,5, und die Antwort nennt
den gesendeten Wert. Hat das Profil keine Schrittweite, gilt der Abstand seiner Einträge, etwa 15,0, 15,5, 16,0. Einträge wie „On“ am Rand eines Homematic-Profils zählen nicht zum Bereich. Der
Knopf **Selbsttest ausführen** zeigt den Bereich je Thermostat.

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

### Diagramme, Gerätekarten und Vorlagen in der Claude-App

Fragt man die Claude-App nach einem Diagramm, etwa „Zeig mir die Temperatur im Gästezimmer der letzten 30 Tage
als Diagramm“, zeichnet das Modul den Verlauf direkt im Chat: je Einheit ein Diagramm, ein Band von Minimum bis
Maximum und eine Linie für das Mittel. Bei mehreren Linien in einem Diagramm fehlt das Band anfangs, ein Haken unter
dem Diagramm blendet es ein und aus. Die Werte gehen nur an das Diagramm, das Sprachmodell bekommt Minimum und
Maximum. Mit `type=power` zeichnet es ebenso die Leistung. Mehrere Räume lassen sich in einem Diagramm vergleichen, etwa „Vergleiche die Temperatur im Gästezimmer
und draußen in der letzten Woche“. Zustände wie an/aus, offen/zu oder ein Batteriemodus erscheinen als Bänder
über der Zeitachse, gefärbt nach Zustand, etwa bei „Zeig mir, wann die Haustür heute offen war“. Zwei Zeiträume
lassen sich vergleichen, etwa „Hausverbrauch heute im Vergleich zu gestern“ oder „Temperatur im Gästezimmer diesen
Monat gegen denselben Monat im Vorjahr“: beim Verlauf als gestrichelte Linie, beim Verbrauch als Marke je Balken.
Läuft der Zeitraum noch, nennt die Antwort dazu den Verbrauch des Vergleichszeitraums bis zur selben Zeit. Für
Zeitleisten gibt es keinen Vergleich. Eine Punktwolke zeigt, wie zwei Werte zusammenhängen, etwa „Leistung der
Wärmepumpe gegen die Außentemperatur im September“: ein Punkt je Tag (bis zwei Tage je Stunde), dazu der Mittelwert
je Temperaturklasse und eine Trendlinie. Steigt der Verbrauch erst unterhalb einer Grenze, findet das Modul diese
Heizgrenze selbst, und der Assistent bekommt den Zusammenhang in Worten („unter etwa 16 °C je Grad kälter rund
0,06 kW mehr, erklärt 84 %“). Auf der y-Achse steht ein Messwert, ein Zähler oder eine Leistung, ohne Gerät der
Hausverbrauch. Eine Heatmap zeigt, wann am Tag etwas passiert, etwa „Hausverbrauch im September als Heatmap“: ein
Kästchen je Stunde, eine Zeile je Tag, bei mehr als 62 Tagen eine Zeile je Wochentag mit dem Mittel. Der Assistent
bekommt die stärkste und die schwächste Stunde und den Unterschied zwischen Werktag und Wochenende. Über jedem Diagramm steht der Zeitraum, etwa „Montag, 12. Oktober 2026“ oder „September 2026“. Das Werkzeug heißt `show_chart` und erscheint in den Einstellungen des Connectors unter „Interaktive
Tools“; dort lässt es sich auf „Immer erlauben“ stellen. Andere Assistenten sehen es nicht.

Ist die Energiebilanz eingetragen, zeichnet das Modul auf Wunsch auch den Hausverbrauch, etwa „Zeig mir unseren
Stromverbrauch in diesem Monat als Diagramm“: je Stunde, Tag oder Monat ein Balken aus Netz, PV direkt und Batterie,
die Einspeisung darunter. Zusammen ergibt jeder Balken den Hausverbrauch der Bilanz; lädt die Batterie aus dem
Netz, zählt das als Netz. Darüber stehen zwei Ringe: Die Autarkie teilt den Hausverbrauch in PV direkt, Batterie und
Netz, der Eigenverbrauch teilt den PV-Ertrag in direkt verbraucht, in die Batterie geladen und eingespeist. Mit
„Zeig mir die Leistung jetzt als Diagramm“ kommen dieselben Ringe aus den Leistungen der Bilanz, dazu ein Ring mit
der Hausleistung aus PV, Batterie und Netz. Ohne Batterie in der Bilanz fehlt sie in den Ringen. Mit einem Vergleich,
etwa „verglichen mit dem Vorjahr“, steht oben rechts in jedem Ring der Anteil des Vergleichszeitraums, in einem
laufenden Zeitraum bis zur selben Zeit.

Ebenso geht der Verbrauch je Gerät, etwa „Welche Geräte haben im September am meisten verbraucht? Als Diagramm“:
die fünf größten Geräte mit Zähler übereinander, der Rest als „übrige Geräte“, fürs ganze Haus dazu „nicht
erfasst“ aus der Energiebilanz. Hat ein Raum neben Zählern auch Messwerte, zeigt das Diagramm zunächst die
Messwerte; nach dem Verbrauch muss man dann ausdrücklich fragen. Ein Zähler, der nur einmal am Tag weiterzählt,
steht in einem Stundendiagramm mit dem ganzen Tag in einer Stunde.

Mit „Zeig mir die Lichter im Wohnzimmer“ zeigt die Claude-App die Geräte als Karten, nach Art
gruppiert. Jede Karte nennt den Zustand und groß den wichtigsten Wert (Helligkeit, Öffnungsgrad, Messwert oder Leistung);
was an ist, ist farbig. Dazu kommen je nach Gerät ein Schalter, ein Balken für Helligkeit oder Öffnungsgrad, den man ziehen kann, Knöpfe für Auf, Stopp und Zu, der Sollwert mit
Plus und Minus, die Wiedergabe mit Lautstärke, eine Auswahl für Betriebsart und Lüfterstufe (wenige Einträge als Knöpfe) und beim Licht ein Regler für die Farbtemperatur und Farbtupfer. Fährt ein Rollladen noch, holt die Karte den Stand nach, bis er steht. Ein Tippen auf der Karte schaltet sofort, ohne Umweg über das Sprachmodell, und
die Karte zeigt danach den Zustand, den das Gerät zurückgemeldet hat; eine Meldung steht dort nur, wenn etwas nicht geklappt hat. Der Assistent erfährt im weiteren Gespräch, was auf den
Karten bedient wurde. Bei einem Zugang mit **Nur abfragen** zeigen die Karten nur den Zustand.

Über „+“ → „Konnektoren“ → „Von … hinzufügen“ bietet die Claude-App fertige Vorlagen an: **Haus verlassen** und
**Gute Nacht** prüfen, was noch offen oder an ist, **Energiebericht** nennt Verbrauch, Autarkie und die größten
Verbraucher von gestern. Welche es gibt, richtet sich nach der Freigabeliste.

Nach einem Update kann es eine Weile dauern, bis die Claude-App die neuen Werkzeuge kennt. Schneller geht es, wenn
Sie den Connector neu hinzufügen.

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
- Ein batteriebetriebenes Thermostat übernimmt einen neuen Sollwert oft erst beim nächsten Funkkontakt.
  Die Zentrale meldet ihn trotzdem sofort zurück, „gestellt“ heißt dann „an die Zentrale übergeben“.
- Bei einem Rollladen, der sich erst am Ziel meldet und keinen Fahrstatus hat, weiß das Modul nach
  dem Fahrbefehl nicht, ob er fährt.
- Fehler innerhalb eines Szenen-Skripts sieht der Assistent nicht, er erfährt nur vom Start.
- Die Prüfung der Zähler im Selbsttest findet nur Fehlwerte, die wieder verschwinden. Springt ein Zähler dauerhaft nach oben, etwa beim Tausch gegen einen Zähler mit höherem Stand, zählt das Archiv den Sprung als Verbrauch, ohne dass der Selbsttest es merkt. Kleine Fehlwerte unter einem Fünftel eines üblichen Monats und Monate kurz nach einer Rücksetzung prüft er nicht.
- Die Hausleistung mit `type=power` umfasst höchstens 400 Tage.
- Die Spitze der Hausleistung ist ein Stundenmittel aus den Zählern der Bilanz. Kurze Spitzen, etwa beim Anlaufen eines Geräts, zeigt nur die Netzleistung oder das einzelne Gerät.
- Lamellen, deren Variable die Darstellung „Rollladen“ mit Rotation trägt, bekommen nur eine
  Prozentzahl ohne „offen“ oder „geschlossen“.
- Der Endpunkt spricht MCP über einfache HTTP-Anfragen und bietet keinen Datenstrom an. Clients, die
  zwingend einen Datenstrom (SSE) verlangen, können sich nicht direkt verbinden. Per OAuth melden sich
  nur Clients mit einem Client ID Metadata Document an, erprobt ist die Claude-App. ChatGPT verbindet
  sich mit dem Token, mit OAuth ist es nicht erprobt.
- Anfragen aus einem Browser mit fremder Herkunft (`Origin`) weist der Endpunkt ab, auch mit richtigem Token. Als eigen gelten die IP-Adressen der Anlage, `localhost`, ihr Rechnername (auch mit `.local`, `.fritz.box`, `.lan`, `.home.arpa`, `.home` oder `.internal`) und die Connect-Adresse.
- Tokens und Kopplungscodes sind das einzige Schloss. Über Symcon Connect ist der Endpunkt aus dem
  Internet erreichbar; verwenden Sie deshalb erzeugte Tokens. Abgewiesene Anmeldungen stehen im
  Meldungsprotokoll (die erste einer Minute und danach ihre Anzahl), falsche Kopplungscodes zählen mit.
  Nach 20 abgewiesenen Anmeldungen in einer Minute ist der Endpunkt für diesen Absender 60 Sekunden lang
  gesperrt, auch mit richtigem Token. Über Symcon Connect zählt der Absender aus dem Internet, Ihre eigenen
  Assistenten kommen also weiter durch. Nach 100 abgewiesenen Anmeldungen in einer Minute, gleich von wem,
  ist der Endpunkt 60 Sekunden lang für alle gesperrt.
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
  („nicht bereit“), nicht mit einem Anmeldefehler. Ein fehlerhafter Zugang (Token zu kurz oder doppelt,
  unbekannter Standardraum), ein Gerät oder eine Szene mit fehlender Variable bzw. fehlendem Skript
  sperrt nur sich selbst; der Rest arbeitet weiter, Formular und Log nennen den Eintrag. Fehlt eine
  Variable der Energiebilanz, ist die ganze Bilanz gesperrt, sonst wäre der Hausverbrauch falsch.
