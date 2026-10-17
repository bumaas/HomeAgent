# Gestaltung der MCP Apps (Regelwerk)

Dieses Regelwerk gilt für die beiden Seiten, die Home Agent in Clients mit MCP Apps zeigt, die Gerätekarten
(`HomeAgent/devices.html`, Werkzeug `show_devices`) und die Diagramme (`HomeAgent/chart.html`, Werkzeug `show_chart`).
Es ist die Referenz für jede Änderung an ihrem Aussehen. Wer davon abweicht, ändert erst diese Datei, dann die Seite.

Die Gestaltung folgt den Entwürfen von pitti aus dem Symcon-Forum (Karten, Ringe) und den Layoutvergleichen vom
13.10.2026, bei denen alle Karten- und Diagrammarten nebeneinander gerendert und angeglichen wurden.

## Grundsätze

- **Ruhig wie die Kachel-Visu.** Weiße bzw. dunkle Flächen, dünne Rahmen, Farbe nur als Hinweis auf Art und Zustand.
  Kein Hintergrundverlauf auf Karten (pitti hat sich dagegen entschieden, er passt nicht zur Kachel-Visu).
- **Eine Farbe je Art, kräftig nur, wenn aktiv.** Was aus, zu oder angehalten ist, ist grau. Das gilt für Symbol,
  Zustandstext, Balken und Griff gleichermaßen.
- **Gleiches sieht gleich aus.** Ein Bedienelement, eine Legende oder eine Achse hat überall dieselbe Form, egal in
  welcher Karte oder welchem Diagramm.
- **Die Seite zeigt, das Modul rechnet.** Werte, Summen, Zustände und Beschriftungen kommen fertig aus
  `structuredContent`. Die Seite formatiert nur Zahlen und Zeiten nach der Sprache des Clients.
- **Hell und dunkel sind gleichwertig.** Jede Farbe, die vom Hintergrund abhängt, ist ein Token mit Wert für beide
  Schemata. Feste Grautöne sind ein Fehler (Layoutvergleich: „geschlossen“ leuchtete im dunklen Modus heller als „offen“).
- **Keine fremden Bibliotheken.** Die CSP der Apps erlaubt nichts Fremdes. Gezeichnet wird mit HTML, CSS und SVG. Geprüft
  wird, dass keine Seite etwas nachlädt (Skript, Stylesheet, `@import`, Bilder über http, `fetch`), in `check-module.php`.
  Die Größe ist dort nur eine Bremse gegen eine eingebettete Bibliothek (unter 100 KB); die Seiten gehen an die App, nicht
  ans Sprachmodell, und kosten keine Tokens.

## Farben

### Tokens (beide Seiten)

Die Seiten übernehmen die Variablen des Hosts (`--color-background-primary` usw. aus `hostContext.styles`) und haben
eigene Ersatzwerte. Das Schema kommt aus `hostContext.theme` und setzt `data-theme` am Wurzelelement.

| Token | Hell | Dunkel | Verwendung |
|---|---|---|---|
| `--bg` | #ffffff | #333438 | Hintergrund, Karten |
| `--fg` | #000000 | #ffffff | Text, Hauptwert |
| `--muted` | #656d76 | #9198a1 | Nebentext, Achsen, Überschriften |
| `--border` / `--grid` | #d8dee4 | #3d444d | Rahmen, Gitterlinien |
| `--inactive` | #afb8c1 | #545d68 | inaktiver Zustand: Balken und Griff auf Karten, Bänder im Diagramm |

### Bezug zur Kachel-Visu

Die Farben richten sich nach den vordefinierten Themes der Kachel-Visu von Symcon (Hinweis pitti, 14.10.2026):

| Theme-Variable | Hell | Dunkel | bei uns |
|---|---|---|---|
| `--accent-color` | #00cdab | #00cdab | Akzent, noch nicht benutzt |
| `--content-color` | #000000 | #ffffff | `--fg` |
| `--card-color` | #ffffff | #333438 | `--bg` der Karten |

In der Claude-App gelten zuerst die Farben des Hosts. Unsere Ersatzwerte für Text und Hintergrund sind die Theme-Werte, und eine neue
Farbe ohne eigene Bedeutung (etwa Fokus oder gewählter Eintrag) nimmt den Akzent, statt einen eigenen Ton zu erfinden.

### Farbe je Art (Karten, `--tone`)

| Gruppe | Farbe | Arten |
|---|---|---|
| Rollläden `cover` | #5b6cf0 | `blind` |
| Licht `light` | #e8a800 | `light` |
| Schalter und Steckdosen `power` | #12b886 | `switch`, `socket`, `appliance` |
| Klima `climate` | #1c9fe0 | `thermostat`, `humidifier`, `fan`, `temperature`, `humidity`, `air_quality` |
| Messwerte `measure` | #0f9f8f | `measurement` |
| Fenster und Türen `opening` | #7b8794 | `window`, `door` |
| Melder `sensor` | #f08c00 | `presence`, `water_leak` |
| Medien `media` | #9c5de6 | `media` |

### Farben der Diagramme

- **Reihen** (Linien, Geräte im Stapel) in fester Folge `COLORS`: #2f81f7, #e5534b, #3fb950, #d29922, #a371f7.
- **Energie** immer gleich, in Stapeln wie in Ringen (`STACK_COLORS`): Netz #e8603c, PV #f2b705, Batterie #14b8a6,
  Einspeisung #4caf50, übrige Geräte #8c959f, nicht erfasst #d0d7de.
  Ein einzelner Teil der Bilanz (`part`: `grid`, `feed_in`, `pv`, `battery_charge`, `battery_discharge`) trägt dieselbe Farbe.
- **Mehrere Quellen einer Art** (zwei PV-Anlagen, zwei Netzzähler) in Abstufungen ihrer Farbe (`SOURCE_SHADES`, Reihe
  mit `shade` in der Reihenfolge der Liste „Quellen“): erst die Grundfarbe, dann dunkler, heller, noch dunkler, danach von
  vorn. PV #f2b705, #b88a00, #f8d66a, #8a6700; Netz #e8603c, #b5452a, #f29b80, #8c3520; Batterie #14b8a6, #0d7f73,
  #6fd8cb, #0a5f56; Einspeisung #4caf50, #2e7d32, #8fd694, #1f5a23. Die Art bleibt so auf einen Blick erkennbar, die
  Anlagen trennt die Helligkeit. Die Ringe zeigen weiter nur die Art.
- **Zustände in Bändern:** aktiv (an, offen, erkannt) #2f81f7, inaktiv `--inactive`. Eine Auswahl (Batteriemodus,
  Betriebsart) bekommt `CHOICE_COLORS` in der Reihenfolge ihrer Einträge: #2f81f7, #14b8a6, #d29922, #a371f7, #8c959f.
  Rot und Grün bleiben Bedeutungen vorbehalten („Halten“ in Rot sah nach einem Fehler aus). Die Reihenfolge der
  Einträge, nicht ihre Häufigkeit, legt die Farbe fest, damit ein Zustand jeden Tag dieselbe Farbe hat.
- **Vergleich:** gestrichelte Linie in der Farbe der Reihe (`stroke-dasharray: 5 4`, Deckkraft 0,75), beim Stapel eine
  Marke in `--fg` quer über dem Balken.

## Schrift und Maße

- Schrift des Hosts (`--font-sans`), sonst `system-ui`. Grundgröße 13 px, Zeilenhöhe 1,4.
- **Überschriften in Großbuchstaben** (Gruppen, Beschriftungen von Bedienelementen, kurze Zustände): 10,5 bis 11 px,
  fett 600, Laufweite 0,06 em, Farbe `--muted` bzw. die Farbe der Art.
- **Hauptwert:** 20 px, 500, Ziffern gleicher Breite (`tabular-nums`), Einheit klein (12 px) in `--muted` daneben.
- **Achsen und Nebentext:** 11 bis 12 px in `--muted`. Die Zeichnungen sind 640 breit und werden auf schmalen
  Bildschirmen verkleinert; die Schrift darin wächst mit (`--k` = 640 / Breite, 1 bis 1,6), damit sie auch auf dem
  iPhone rund 11 px bleibt (Hardcopy 14.10.2026: 6 bis 7 px). Ringe haben eigene Größen.
- Abstände in Schritten von 2 bis 4 px, Karten mit 12 px Innenabstand, 8 px Lücke, Radius 10 px; Knöpfe und Listen
  Radius 5 px, Symbolfläche 28 px mit Radius 7 px.

## Gerätekarten

### Aufbau

Jede Karte hat von oben nach unten dieselben Teile; was ein Gerät nicht hat, fällt weg.

1. **Name**, fett, darunter der Raum in `--muted`, nur wenn die Karten aus mehreren Räumen kommen.
2. **Kopfzeile:** links das Symbol, rechts die Bedienung des Geräts als Ganzes (Schalter; Auf/Stopp/Zu bei Rollläden
   und Fenstern mit Antrieb; Zurück/Play/Weiter beim Mediaplayer) oder das Schild „zu“ am geschlossenen Fenster.
3. **Zustandszeile:** links der Zustand, rechts der Hauptwert. Kurze Zustände (bis 14 Zeichen) in Großbuchstaben,
   längere normal. Messwerte ohne Zustand zeigen „AKTUELL“. Ohne Hauptwert hat die Zeile trotzdem dessen Höhe, damit
   einfache Karten einer Zeile gleich hoch sind.
4. **Balken** für Prozentwerte (Helligkeit, Öffnung, Lautstärke). Er ist zugleich Schieberegler.
5. **Weitere Bedienung**, jede mit Beschriftung in Großbuchstaben darüber: Sollwert („SOLLWERT“, „ZIELFEUCHTE“) mit
   −/+, dann Auswahlen („MODUS“, „LÜFTERSTUFE“). Beim Licht die Farbtemperatur („FARBTEMPERATUR“, rechts daneben der
   Wert in K) und die Farbe („FARBE“, Tupfer).

Ein Licht mit bekanntem Farbmodus nennt im Zustand nur den gültigen Wert: weiß „AN (2732 K)“, farbig „AN (ROT)“. Der
andere Teil der Bedienung bleibt da, aber blass (Deckkraft 0,45), damit man sieht, dass er gerade nicht gilt. Ohne
Farbmodus gilt beides.

Jede Karte behält ihre eigene Höhe (`align-items: start`); höher werden nur Karten mit weiterer Bedienung.

### Zustand und Farbe

- Aktiv heißt `card.active === true`: Licht an, Schalter an, Fenster offen, Player spielt, Klimagerät in einem anderen
  Modus als „off“. Rollläden, Klima und Messwerte ohne an/aus (`active === null`) gelten als aktiv.
- Aktiv färbt Symbol (Fläche mit 16 % der Farbe), Zustandstext und Balken in der Farbe der Art; der Schalter ist dann
  ebenfalls in dieser Farbe.
- Nicht aktiv ist alles grau, Balken und Griff gleichermaßen in `--inactive`.
- Der Hauptwert bleibt immer `--fg`.

### Bedienelemente

| Element | Form |
|---|---|
| Schalter | 34 × 20 px, runder Knopf, aktiv in der Farbe der Art |
| Knöpfe (Tasten, −/+, Einträge) | 11 px, Fläche `--muted` mit 12 %, ohne Rahmen, Radius 5 px |
| Auswahl bis fünf kurze Einträge (zusammen bis 24 Zeichen) | Knopfleiste, gewählter Eintrag in der Farbe der Art mit 22 % Fläche |
| Auswahl mit mehr Einträgen | Aufklappliste im Stil der Knöpfe (graue Fläche, kein Rahmen) |
| Balken / Schieberegler | 6 px hoch, Füllung in der Farbe der Art, runder Griff 14 px immer sichtbar; reine Anzeige ohne Griff |
| Sollwert | −, Wert mit Einheit, + in einer Zeile unter der Beschriftung |
| Farbtemperatur | Schieberegler wie der Balken, Fläche von warm (#ffb46b) nach kalt (#cfe3ff) statt der Farbe der Art, Griff in `--fg`; Bereich der Lampe, höchstens 2000 bis 6500 K |
| Farbe | Tupfer 18 px rund in einer Zeile (umbrechend), gewählter Tupfer mit Ring 2 px in `--fg`, Name als Tooltip; Weiß mit Rand `--border` |

### Gruppen

Feste Reihenfolge: Rollläden, Licht, Schalter und Steckdosen, Klima, Messwerte, Fenster und Türen, Melder, Medien.
Überschriften erscheinen erst ab zwei Gruppen. Die Zuordnung steht in `DeviceIndex::CARD_GROUPS`, die Beschriftungen in
`CARD_GROUP_LABELS`.

## Diagramme

### Gemeinsam

- **Titelzeile** über jedem Diagramm mit dem Zeitraum in der Sprache der App, fett: ein Tag mit Wochentag („Montag,
  12. Oktober 2026“), ein Monat mit Namen, ein Jahr als Zahl, sonst von–bis („01.09.–12.10.2026“), mit Uhrzeit, wenn der
  Zeitraum nicht um Mitternacht beginnt oder endet; beim Vergleich „· Vergleich <Zeitraum>“, bei „Leistung jetzt“ Datum
  und Uhrzeit. Die Daten liefern `range`, `compare.range` und `at`.
- **Zeichenfläche** 640 px breit (skaliert), linker Rand 44 px für die Werteachse, rechter Rand 10 px. Bänder und
  Linien nutzen dieselben Ränder, damit Zeitpunkte untereinander stehen.
- **Zeitachse über den ganzen Zeitraum** (`range` bzw. aufgefüllte `times`), auch wenn er noch läuft: heute bis
  Mitternacht, diese Woche sieben Tage, dieses Jahr zwölf Monate. Künftige Abschnitte bleiben leer.
- **Zeitmarken** auf runden Zeitpunkten (volle Stunden, ganze Tage, Monatsanfänge), höchstens sechs.
- **Legende** als HTML über der Zeichnung: „Raum · Name“ bzw. Name des Teils, davor die Marke (Strich bei Linien,
  Kästchen bei Balken und Bändern, gestrichelt beim Vergleich). Bänder haben dieselbe Legende, je Band eine Zeile.
- **Fußzeile** links in `--muted`: Stufe und Einheit („Stunde: kWh“, „Tag: Minimum / Mittel / Maximum“).
- **Zahlen** nach der Sprache des Clients. An der Werteachse so viele Nachkommastellen, wie die Schrittweite braucht
  (0,025 / 0,5 / 5), sonst Werte unter 1 mit zwei, größere mit einer Nachkommastelle; Leistung ab 1000 W in kW.

### Arten

- **Verlauf:** je Einheit ein Diagramm, Linie für das Mittel, darunter das Band von Minimum bis Maximum (bei mehreren
  Linien anfangs aus, ein Haken schaltet es um). Vergleich gestrichelt. Linie, Vergleich und Band als weiche Kurve
  (`smoothPath`, monoton nach Steffen: zwischen zwei Punkten nie darüber hinaus, Minimum und Maximum bleiben echt;
  Burkhard 14.10.2026: „der verlauf der linien und des bandes könnte etwas weicher sein“).
- **Stapel (Verbrauch):** ein Balken je Stunde, Tag oder Monat, positive Teile übereinander, die Einspeisung darunter,
  aber erst ab 3 % des höchsten Balkens. Vergleich als Marke je Balken.
- **Zeitleiste (Bänder):** je Gerät ein Streifen von 14 px, Abschnitte in der Farbe ihres Zustands, dahinter eine
  blasse Fläche für die Zeit mit bekanntem Wert. Tooltip mit Zustand, von, bis und Dauer.
- **Punktwolke:** je Abschnitt ein Punkt (blau, halbtransparent, Radius 3), beide Achsen als Zahlen mit runden Schritten,
  die Einheit der x-Achse unten rechts. Darüber die Klassenmittel als Linie in `--fg` mit kleinen Punkten und die
  Modellkurve gestrichelt in Gelb (`SCATTER_TREND` #d29922, Knickpunkt als eigene Stützstelle). Legende „y (Einheit)
  gegen x (Einheit)“, Klassenmittel, Trend; Fußzeile mit Raster und Anzahl der Punkte. Tooltip mit Zeitpunkt und
  beiden Werten.
- **Heatmap:** Stunden nach rechts (Beschriftung alle drei Stunden), Tage nach unten (Beschriftung jede zweite Zeile, mit
  Wochentag) bzw. sieben Zeilen Montag bis Sonntag; Kästchen mit Radius 2 und 1 px Abstand, leer ohne Wert. Verbrauch und
  Leistung von `--bg` nach Blau (#2f81f7), die Skala endet beim 95. Perzentil (`cap`), damit einzelne Spitzen nicht alles
  blass machen; Temperatur von Blau über `--heat-mid` (hell #f2f4f7, dunkel #3a3f46) nach Rot über die volle Spanne. Darunter
  die Farbskala mit Minimum und Maximum bzw. „≥ cap“. Tooltip mit Tag, Stunde und Wert.
- **Summenkurve:** der aufaddierte Verbrauch bzw. die Kosten als blaue Linie ab 0 über den ganzen Zeitraum, die
  Hochrechnung gepunktet in derselben Farbe bis zum Ende mit einem Punkt (nach dem Vorjahresverlauf als Kurve), der Vergleichszeitraum gestrichelt in `--muted`.
  Legende „aufaddiert (Einheit)“, bei Kosten „Netzbezug (€)“, Hochrechnung, Vergleich; bei Kosten die Vergütung der
  Einspeisung als grüne Linie (Farbe der Einspeisung, #4caf50); Achse über ein Jahr in Monaten. Tooltip je Abschnitt über
  die ganze Höhe wie beim Stapel: Datum, Stand bzw. danach die Hochrechnung, Vergütung und Vergleich an derselben Stelle.
- **Tooltip im Verlauf:** je Punkt das Mittel, Minimum und Maximum nur bei eingeschaltetem Band, dazu der Vergleich an
  derselben Stelle; Vergleichspunkte ohne eigenen Punkt (nach heute) haben einen eigenen Tooltip (Burkhard 13.10.2026).
- **Ringe:** drei Viertel eines Kreises, von oben im Uhrzeigersinn, innen kleiner; Teile links neben dem Anfang ihres
  Bogens, Skala außen (Prozent oder ganze kW), die Zahl groß in der Mitte. Bögen unter 1 % entfallen. Die Teile heißen
  in allen Ringen gleich kurz: PV bzw. PV direkt, Batterie, Netz, Einspeisung. Ringkarten füllen die Breite. Ohne
  Batterie in der Bilanz fehlt ihre Zeile. Mit Vergleich steht dessen Anteil rechts in der Kopfzeile der Ringkarte
  („2025: 79 %“, Farbe `--muted`, Zeitraum wie in der Titelzeile, Tooltip „Vergleich …“); in der Mitte ist dafür kein
  Platz, unten rechts liegt der innerste Bogen (Hardcopy 15.10.2026).

## Texte

- Beschriftungen kommen übersetzt aus dem Modul (`labels` in `structuredContent`, `locale.json`). Die Seite selbst hat
  keine Texte außer Ersatzwerten.
- Kurz und ohne Satzzeichen am Ende. Zustände wie im Text von `get_state` („an“, „geschlossen“, „angehalten“).
- Raum- und Gerätenamen erscheinen, wie der Anwender sie einträgt.

## Prüfen vor dem Commit

1. Die betroffenen Karten bzw. Diagramme mit echten Daten rendern, hell und dunkel (Werkzeuge in `dev/`, nicht im
   Repo: `showcase.php` für die Übersicht aller Arten, `devices_render.php` und `chart_render2.php` einzeln).
2. Mit diesem Regelwerk vergleichen: Farben, Formen, Ränder, Legenden, Achsen.
3. Was Daten betrifft (Gruppe, aktiv, Beschriftung, Zeitraum), bekommt einen Test mit Rot/Grün-Nachweis; reine
   Gestaltung wird an den Bildern geprüft und im Commit so benannt.
4. Neue Regeln oder Abweichungen hier eintragen.

## Bekannte Abweichungen

Keine.
