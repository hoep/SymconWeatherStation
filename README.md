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
