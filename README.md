# WeatherStation für IP-Symcon

Führt beliebig viele Wetterquellen **je Größe** zusammen, leitet daraus ab, was keine
Station misst — Nebel, Gewitter, Bewölkung, Niederschlagsart — und **misst die Sichtweite an
Kamerabildern**. Das Ergebnis sind Variablen, an die sich Visualisierung, Beschattung,
Bewässerung und Prognose binden.

## Warum zwei Module

**WeatherSource** ist eine Quelle: eine Station, ein Dienst. Wer eine Davis und eine Tempest
hat, legt zwei an. **WeatherStation** führt sie zusammen und besitzt die Variablen.

Der Schnitt kommt aus der Praxis: keine Station kann alles. Eine Davis Vantage Pro 2 hat
keine Blitzortung, eine Tempest hat sie. Die Frage „welche Station soll es sein?" ist deshalb
falsch gestellt — richtig ist eine Zusammenführung je Größe, mit Rangfolge und Verfallszeit.
Fällt die erste Station aus, rückt die zweite von selbst nach.

## Quellen

| Treiber | liest | erprobt |
|---|---|---|
| `davis-actdata` | `actData.txt` der Eusotec-/Eusoport-Software | ja, im Dauerbetrieb |
| `davis-wll` | Davis WeatherLink Live, `/v1/current_conditions` | nein, nach Gerätebeschreibung |
| `tempest-udp` | die Tempest direkt, UDP-Broadcast Port 50222 | ja, im Dauerbetrieb |
| `tempest-module` | Variablen eines vorhandenen Tempest-Moduls | ja, im Dauerbetrieb |
| `open-meteo` | Vorhersagemodell, ohne eigene Station | ja |
| `bound-variables` | beliebige vorhandene Symcon-Variablen | ja |

Jede Quelle liefert dieselbe normierte Beobachtung: Grad C, km/h, hPa, mm, mm/h, W/m².
**Jeder Wert trägt seinen eigenen Zeitstempel**, und was eine Station nicht kennt, ist `null`
und nicht `0`. Beides ist keine Förmelei: ohne eigene Zeitstempel lässt sich eine
eingefrorene Quelle nicht erkennen, und eine Station ohne Strahlungssensor meldete sonst
dauerhaft „bedeckt".

## Die Tempest direkt hören

Das Modul **TempestListener** hängt sich als Kind an einen UDP-Socket auf Port 50222 und liest
die Sätze der Station unmittelbar mit — ohne Cloud, ohne Konto, ohne fremdes Modul. Mehrere
Zuhörer stören einander nicht: ein Socket darf mehrere Kinder haben, und ein Broadcast erreicht
sie alle. Ein vorhandenes Tempest-Modul kann unverändert weiterlaufen.

Der Gewinn ist nicht die Unabhängigkeit, sondern die **Auflösung**:

- **Jeder Blitz einzeln.** Die Station sendet für jeden Schlag ein eigenes `evt_strike` mit
  eigenem Zeitpunkt und eigener Entfernung. Wer stattdessen Sammelvariablen liest, bekommt
  einen Zähler je Minute und eine mittlere Entfernung — damit lässt sich ein einzelner Blitz
  in 30 km nicht von einer Zellenpassage über dem Haus unterscheiden. Genau das ist aber die
  Gewitterlage.
- **Wind im Drei-Sekunden-Takt** statt im Minutenmittel. Für Beschattung und Markisenschutz ist
  das der Unterschied zwischen rechtzeitig und zu spät.

## Blitze mit Richtung: Blitzortung

Die Tempest misst je Schlag nur die Entfernung, nicht die Richtung. Das Modul
**BlitzortungListener** holt dazu die Blitze aus dem Netz von Blitzortung.org, das jeden Blitz
aus den Laufzeiten vieler Stationen ortet. Ein Community-Dienst verteilt sie als MQTT, gegliedert
nach Geohash — ohne Konto.

- **Aufbau:** Client Socket (`blitzortung.ha.sed.pl`, Port 1883) → MQTT Client → BlitzortungListener.
  Die Abos des MQTT Clients setzt das Modul selbst: nur die Geohash-Zellen, die den Kreis um den
  Standort berühren (Radius einstellbar, Vorgabe 100 km). Der Weltstrom mit mehreren Blitzen je
  Sekunde kommt so gar nicht erst an.
- **Standort:** eigene Eigenschaften oder, wenn leer, die Location-Instanz von Symcon.
- **Variablen:** nächster Blitz (km), Richtung (Grad und Text, Kreismittel gewichtet nach Nähe),
  Blitze in 30 Minuten, letzter Blitz, Gewitter in der Nähe, dazu zwei JSON-Listen für Anzeigen:
  Blitze je 5 Minuten und Blitze mit Richtung, Entfernung und Alter.
- **Last:** Empfang hängt jeden Blitz nur an einen Puffer, ausgewertet wird alle 10 Sekunden.
- **Nutzung:** Blitzortung.org erlaubt die Daten für private, nicht kommerzielle Zwecke.

## Was abgeleitet wird

**Nebel** in zwei Stufen. Zuerst ein Regelsatz als Torwächter — Luftfeuchte ab 94 %, Wind bis
11 km/h, Taupunktdifferenz unter 2,5 K, kein Niederschlag. Fällt eine Bedingung, ist es kein
Nebel, und der Grund steht im Klartext in der Variablen. Die Stärke kommt danach aus dem
**Fog Stability Index** `FSI = 2(T−Td) + 2(T−T850) + W850`: Nebel braucht eine Sperrschicht
über sich, sonst mischt er sich weg. Die 850-hPa-Werte kommen stündlich von Open-Meteo — in
der Literatur gilt der Bedarf an Radiosonden als Nachteil des Verfahrens, hier ist der Index
dadurch sogar aktueller als im Original.

**Gewitter** aus einem Ringspeicher der letzten Stunde. Eine Ereignisvariable kennt immer nur
den letzten Schlag; damit lässt sich ein einzelner Blitz in 40 km nicht von zwölf Blitzen in
5 km unterscheiden. Erst der Ringspeicher macht Entfernung *und* Häufigkeit auswertbar.

**Zieht es auf?** Das ist die Frage, die zählt — „Blitz in 27 km" sagt nicht, ob man Fenster
schließen oder weiterarbeiten soll. Dafür wird je Fünf-Minuten-Fenster der Median der
Entfernungen gebildet und durch diese Fenster eine Ausgleichsgerade gelegt. Fällt sie, zieht
es auf; aus der Steigung fallen Annäherungsgeschwindigkeit und ungefähre Ankunft ab.

Der Median ist dabei nicht Zierde: Blitze **einer** Zelle schlagen am nahen wie am fernen Rand
ein, bei einer 15 km großen Zelle also über 15 km Spanne. Eine Gerade durch alle Einzelwerte
folgt dieser Streuung statt der Zugbewegung — im Betrieb gemessen: 118 km/h für eine Zelle,
die tatsächlich mit knapp 80 heranzog. Über 90 km/h wird gar keine Aussage gemacht: so ein
Wert heißt nicht „sehr schnell", sondern „die Daten geben keine Zugbewegung her".

Gemessen wird streng genommen, wie schnell sich die *Blitztätigkeit* nähert — bei neu
entstehenden Zellen kann das schneller sein als die Zuggeschwindigkeit der Zelle selbst. Für
die praktische Frage, wieviel Zeit bleibt, ist genau das die richtige Größe.

**Bewölkung** aus gemessener Strahlung gegen den Klarhimmelwert nach Haurwitz, umgerechnet
nach Kasten & Czeplak. Steht die Sonne unter 5 Grad, gibt es keine Aussage statt einer
erfundenen Zahl.

**Niederschlagsart** aus der Feuchtkugeltemperatur, nicht aus der Lufttemperatur: eine
fallende Flocke kühlt sich durch Verdunstung selbst. Bei 3 Grad Luft und 40 % Feuchte liegt
die Feuchtkugel bei −1,5 Grad — es schneit, obwohl das Thermometer über null steht.

## Sichtmessung über Kameras

Nebel streut Licht und frisst dadurch den Kontrast. Die mittlere Kantenenergie eines festen
Bildausschnitts ist deshalb ein direktes Maß für die Sichtweite — und zwar eine **Messung**,
während Taupunkt und Feuchte nur eine Schätzung zulassen. Sie schlägt im Zweifel die
Rechnung: sieht die Kamera nichts mehr, ist Nebel; sieht sie klar, wird eine gerechnete
Stufe zurückgenommen.

Zwei Dinge sind dabei entscheidend:

- **Keine absoluten Schwellen.** Eine Kamera auf glatten Asphalt kommt bei bester Sicht auf
  eine Kantenenergie von etwa 12, eine auf Büsche auf 30. Maßgeblich ist allein der Abfall
  gegenüber dem Klarwert derselben Kamera.
- **Tag und Nacht getrennt lernen.** Nachts leuchtet die Infrarotbeleuchtung, das Bild ist
  grau und flach — ein gemeinsamer Klarwert meldete jede Nacht Nebel.

Der Klarwert ist eine **Bestmarke**: er steigt sofort und sinkt nie von selbst. Würde er
mitsinken, gewöhnte sich die Anlage während einer langen Nebellage an den Nebel und meldete
ihn nicht mehr. Nach Umbau oder Reinigung einer Kamera lässt er sich im Formular verwerfen.

Braucht GD in PHP (in IP-Symcon enthalten). Eine Messung kostet 25 bis 50 ms je Kamera.

## Was im Baum sichtbar ist

Jede Quelle zeigt alles, was sie liefert, als eigene Variablen — zusätzlich zur
zusammengeführten Station. Die Werte stehen damit doppelt im Baum, und das ist Absicht: sonst
sieht man einer Anlage nicht an, was eine einzelne Station eigentlich misst und worin sich zwei
Stationen unterscheiden. Gebunden wird an die Station, nachgesehen wird an der Quelle. Beides
lässt sich im Formular abschalten.

Archiviert werden die Messreihen der Station und die jeder Quelle getrennt. Auch das doppelt,
aus demselben Grund: erst getrennte Reihen zeigen im Nachhinein, ob eine Station driftet,
aussetzt oder systematisch anders misst. Zähler und Kennungen bleiben außen vor — die sind
Zustand, keine Messreihe.

## Aufbau

```
libs/Weather/
  Observation.php        normierte Beobachtung, Wert + Zeitstempel je Größe
  IWeatherSource.php     Vertrag jeder Quelle
  SourceFactory.php      Katalog der Treiber
  Drivers/               je Stationstyp einer
  Engines/
    Meteo.php            reine Formeln, ohne Symcon-Bezug, einzeln prüfbar
    WeatherEngine.php    Nebel, Gewitter, Bewölkung, Niederschlag, Wetterlage
    CameraVision.php     Bildauswertung
    UpperAir.php         850 hPa
```

`Meteo` und `WeatherEngine` enthalten keinen einzigen `IPS_`-Aufruf. Sie lassen sich damit
gegen Literaturwerte prüfen, ohne eine Anlage zu besitzen, und andere Module können sie
direkt aufrufen, statt Variablen abzugreifen.


## Installation

Konsole → *Kern-Instanzen* → **Modules** → Hinzufuegen:

```
https://github.com/hoep/SymconWeatherStation
```

Voraussetzung ist IP-Symcon ab Kernel 7.1 und mindestens eine Wetterquelle -
eine Davis- oder Tempest-Station im Netz oder eine der unterstuetzten
Online-Quellen. Welche das sind, steht unter *Quellen*.

## Einrichtung

1. Je Station eine **WeatherSource**-Instanz anlegen, Art wählen, Felder ausfüllen. Der
   Probelauf zeigt jeden ankommenden Wert samt Alter — auch die, die fehlen.
2. Eine **WeatherStation**-Instanz anlegen, Standort eintragen, Quellen mit Rangfolge
   eintragen. Rang 1 ist die erste Wahl je Größe.
3. Optional Kameras eintragen. Der Ausschnitt in Prozent grenzt den ausgewerteten Bereich
   ein — sinnvoll, um Himmel, eine nahe Wand oder eine eingeblendete Uhr auszuschließen.

Der Abruftakt sollte zur Quelle passen. Schneller abzufragen, als die Quelle neue Werte
bildet, kostet nur Last.

## Lizenz

MIT
