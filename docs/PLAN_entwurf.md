# Wetter-Domäne — Entwurf

Stand 17.08.2026. Ziel: eine Wetterdomäne, die in JEDEM Haus läuft und über GitHub
weitergegeben werden kann — nicht ein Formular um die Variablen-IDs dieser Anlage herum.

## Das Problem mit dem ersten Anlauf

Der erste Entwurf war ein Modul mit acht `SelectVariable`-Feldern (Temperatur, Feuchte,
Taupunkt …) und der Rechenlogik direkt in `module.php`. Das funktioniert hier und nirgends
sonst gut:

- Wer eine Tempest hat, klickt acht Variablen zusammen, die sein Stationsmodul ohnehin
  schon strukturiert liefert. Wer zwei Stationen hat, kann nur eine benutzen.
- Die Rechenlogik ist an `IPSModule` gekettet und damit weder einzeln prüfbar noch von
  Beschattung oder Prognose direkt aufrufbar.
- Genau die Fälle, die den Nutzen ausmachen, fehlen: Davis hat keine Blitzerkennung,
  Tempest hat sie; die Tempest-Feuchtkugel ist tot, die Davis-Werte sind gut. Ein Haus
  mit beiden Stationen braucht eine **Zusammenführung je Größe**, keine Auswahl.

## Aufbau

Drei Schichten, wie im Rest von HomeSuite (Treiber → Engine → Modul):

### 1. Quelle — `WeatherSource` (HSWS), eine Instanz je Station

Ein Treiber je Stationstyp, registriert über die bestehende `DriverFactory`:

| Treiber | liest | Anmerkung |
|---|---|---|
| `davis-actdata` | `actData.txt` über HTTP | Textformat der WeatherLink-/Eusotec-Software, 90 Felder |
| `davis-loop` | LOOP-Pakete direkt vom Datenlogger, TCP | die Station selbst, ohne Zwischenrechner |
| `davis-wll` | WeatherLink Live, `/v1/current_conditions` | heutiger Standard bei Davis, JSON im eigenen Netz |
| `tempest-bound` | Variablen des Tempest-Moduls (Idents `Air_Temperature` …) | das Modul ist gut, es bleibt |
| `generic-bound` | frei gewählte Variablen | für jede sonstige Station, das ist der Rückfall |
| `openmeteo` | API | wenn gar keine eigene Station da ist |

### Befund zur Davis in dieser Anlage (17.08.2026)

- „Davis Vantage Pro 2" #<ID> ist **kein Modul**, sondern eine Dummy-Instanz. Gefüllt wird
  sie vom Skript „Abfragen" #<ID>, das **alle 2 Sekunden** `http://192.168.1.50/actData.txt`
  holt und über die Klasse `PHPDavisWeather` auf rund 40 Variablen verteilt.
- 192.168.1.50 ist ein **Raspberry Pi** mit Apache; die Anwendung dort stammt vom 11.12.2019.
  Er liest den Datenlogger und schreibt die Textdatei. Ein unbeaufsichtigter Zwischenschritt,
  von dem die gesamte Wetterauswertung des Hauses abhängt.
- Der Client Socket „Davis" #<ID> auf **192.168.1.0:10001** (Seriell-über-Netz) ist
  geschlossen, Status 104 — Altlast. Die Gegenstelle **antwortet aber auf Ping**. Das ist der
  Weg zur Station selbst.
- Die Zeitstempel in `actData.txt` sind **UTC**, nicht Ortszeit (geprüft: Datei meldet 08:53,
  während es 10:53 war). Wer sie ungeprüft übernimmt, bekommt zwei Stunden alte Daten.
- Feldbelegung gesichert: `[2]` Luftdruck, `[3]/[4]` innen T/rF, `[5]` Außentemperatur,
  `[6]/[7]/[8]` Wind/Mittel/Richtung, `[24]` Außenfeuchte, `[32]` Regen/h, `[33]` UV,
  `[34]` Strahlung, `[35..39]` Regen letzter/seit/Tag/Monat/Jahr, `[40..42]` Verdunstung,
  `[51]` Trend, `[70]` Vorhersage, `[72]/[73]` Sonnenauf-/untergang. Dezimalkomma,
  `---` bedeutet „Sensor nicht vorhanden" und muss `null` werden, nicht 0.

Jede Quelle liefert dieselbe **normierte Beobachtung** (`Drivers/Weather/types.php`):
Temperatur, Feuchte, Taupunkt, Wind, Böe, Windrichtung, Luftdruck, Regenrate, Tagesregen,
Globalstrahlung, UV, Blitzzeit, Blitzentfernung, Blitzzahl — jeder Wert mit **Zeitstempel**
und mit `null`, wenn die Station ihn nicht kennt. Kein Erfinden von Werten.

### 2. Rechnen — `Engines/WeatherEngine.php`, reine Funktionen

Kein `IPS_`-Aufruf, keine Instanz: rein Beobachtung rein, Ableitung raus. Damit prüfbar,
und Beschattung/Prognose können sie direkt aufrufen statt Variablen abzugreifen.

- Feuchtkugel (Stull 2011) → Niederschlagsart Regen/Schneeregen/Schnee
- Nebel: Regelsatz (Feuchte ≥ 94 %, Wind ≤ 11 km/h, Spread < 2,5 K) + Fog Stability Index
  `FSI = 2(T−Td) + 2(T−T850) + W850`, 850 hPa stündlich von Open-Meteo
- Gewitter gestuft aus einem Blitz-Ringspeicher (Entfernung UND Häufigkeit)
- Bewölkung: gemessene Strahlung gegen Klarhimmel nach Haurwitz, umgerechnet nach
  Kasten & Czeplak
- Wetterlage im Klartext, Reihenfolge nach Auffälligkeit

### 3. Zusammenführen und anzeigen — `WeatherStation` (HSWX), eine Instanz je Haus

Nimmt alle Quellen, führt sie **je Größe** zusammen und besitzt die Variablen, an die
Wetter+, Sonnenszene, Beschattung und Prognose gebunden werden.

Zusammenführung nach Rangfolge je Größe, mit Verfallszeit: die erste Quelle, die einen
frischen Wert hat, gewinnt. Fällt die Davis aus, rückt die Tempest nach — ohne Zutun.
In dieser Anlage also: Temperatur/Feuchte/Taupunkt/Regen von der Davis, Blitze von der
Tempest, Strahlung von der, die sie frischer hat.

Zusätzlich die **Kamera-Sichtmessung** (`Engines/CameraVision.php`): Kantenenergie je
Kamera gegen den gelernten Klarwert derselben Kamera, getrennt Tag/Nacht. Optional, braucht
GD (in Symcon vorhanden). Sie ist die einzige echte MESSUNG der Sicht — alles andere ist
Ableitung, deshalb schlägt sie im Zweifel die Rechnung.

## Was das für die bestehende Anlage heißt

- Skript #<ID> und seine zehn Variablen werden vom Modul abgelöst. Die alten Variablen
  bleiben zunächst stehen und werden vom Modul mitgeschrieben, bis alle Bindungen
  (Widgets, Ereignisse, das Symbol-Skript des Betreibers) umgezogen sind.
- Die Widgets `weather`, `weatherplus` und `sunscene` binden danach auf die Modulvariablen.
- Fremde Skripte des Betreibers werden nicht angefasst.

## Offen, bevor gebaut wird

1. Eigenes Repo oder Teil von HomeSuite?
2. Tempest gebunden (heute) oder gleich UDP (unabhängig, aber mehr Arbeit)?
3. Wie weit soll `davis-bound` raten dürfen, statt Variablen klicken zu lassen?
