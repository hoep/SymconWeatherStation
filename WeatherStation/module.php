<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Weather/autoload.php';

use Hoep\Weather\Engines\CameraVision;
use Hoep\Weather\Engines\Forecast;
use Hoep\Weather\Engines\Meteo;
use Hoep\Weather\Engines\StationCodes;
use Hoep\Weather\Engines\UpperAir;
use Hoep\Weather\Engines\WeatherEngine as WE;
use Hoep\Weather\Observation;

/**
 * WeatherStation (WX) — das Wetter des Hauses an einer Stelle.
 *
 * Fuehrt beliebig viele WeatherSource-Instanzen JE GROESSE zusammen, leitet daraus ab, was
 * keine Station misst (Nebel, Gewitter, Bewoelkung, Niederschlagsart, Wetterlage), misst die
 * Sicht an Kamerabildern und besitzt die Variablen, an die alles andere gebunden wird.
 *
 * Zur Zusammenfuehrung: sie folgt strikt der Rangfolge, nicht dem Zeitstempel. Die erste
 * Quelle, die einen FRISCHEN Wert hat, gewinnt; erst wenn sie ihn nicht liefert oder ihr
 * Wert veraltet ist, rueckt die naechste nach. Der umgekehrte Weg — immer der juengste Wert —
 * laesst zwei ungleich genaue Stationen im Sekundentakt abwechseln und macht jede
 * Tendenzberechnung wertlos.
 */
class WeatherStation extends IPSModule
{
    /**
     * Ab dieser Sonnenhoehe (Grad) ist die Kamerasicht als Nebel-Beleg zugelassen.
     * Darunter faellt der Bildkontrast wegen des Lichts, nicht wegen Nebels - gemessen am
     * 19.08.2026 bei klarem Himmel: 44-56 % Sicht und Dunkelkanal 60-73 statt nahe null.
     * Der Regelsatz (Feuchte, Taupunktdifferenz, Wind) arbeitet rund um die Uhr weiter.
     */
    private const CAM_SUN_MIN = 5.0;
    /** Ab dieser Sonnenhoehe abwaerts ist es richtig Nacht: IR leuchtet, der Nacht-Klarwert gilt. */
    private const CAM_NIGHT_MAX = -6.0;
    /** So viele Sichtmessungen fliessen in den Median ein. */
    private const SIGHT_RING = 5;

    /** Messungen im Glaettungsring der Bewoelkung. Fuenf Minuten - kuerzer als jede Wolkenlage. */
    private const CLOUD_RING = 5;

    // Lernen des Klarhimmel-Faktors. Grenzen aus dem eigenen Bestand: gemessen wurden
    // Verhaeltnisse von 0,83 (Sonne 24°) bis 1,04 (Sonne 47°) an einem klaren Tag.
    private const LERN_SCHRITT   = 0.03;   // hoechste Anhebung durch eine einzelne Messung
    private const LERN_VERGESSEN = 0.005;  // Abbau je Tag ohne Bestaetigung
    private const LERN_MIN       = 0.55;
    private const LERN_MAX       = 1.35;
    /** Bis zu dieser Modellbewoelkung gilt der Himmel als klar genug zum Lernen. */
    private const LERN_KLAR_PCT  = 12.0;

    /**
     * Mindestdauer (Sekunden), die eine geaenderte Nebelstufe anhalten muss, bevor sie
     * veroeffentlicht wird. Ohne diese Sperre wechselte der Zustand am 19.08.2026
     * 153-mal an einem Tag, teils im Zehn-Sekunden-Takt.
     */
    private const FOG_DWELL = 300;

    private const GUID_SOURCE   = '{B24C7F1E-9A05-4E63-8D17-3F92C6B0A5D8}';
    /**
     * Ein Empfaenger, der schon selbst eine vollstaendige Beobachtung liefert, darf DIREKT als
     * Quelle stehen. Sonst braeuchte es eine WeatherSource-Instanz, die nichts weiter taete als
     * durchzureichen — eine Instanz mehr im Baum ohne eigene Aufgabe.
     *
     * Warum es die Trennung ueberhaupt gibt: wer UDP empfaengt, MUSS Kind eines Sockets sein.
     * Die allgemeine Quelle darf das nicht, sie liest auch HTTP-Stationen und Variablen. Ein
     * Empfaenger ist also ein Sonderfall, keine Doppelung.
     */
    private const GUID_LISTENER = '{5F8C21D4-6A7B-4E90-B3C2-8D14E7F6A2B9}';

    /** Variablen, die als Messreihe etwas taugen — nur die werden archiviert. */
    private const LOGGEN = ['Temp', 'Hum', 'Dew', 'WetBulb', 'WBGT', 'CoolRes', 'VPD', 'ET0', 'ET0Day', 'Wind', 'WindAvg', 'Gust', 'WindDir',
                            'Pressure', 'RainRate', 'RainDay', 'RainMonth', 'RainYear', 'RainLast',
                            'EtDay', 'EtMonth', 'EtYear', 'EtTotal', 'TempIn', 'HumIn', 'Battery',
                            'Radiation', 'Illuminance', 'UV', 'CloudPct',
                            'FogLevel', 'FogPct', 'FogFSI', 'PrecipType', 'StormLevel', 'StormDist',
                            'StormRate', 'StormTrend', 'StormSpeed', 'StormEta', 'StormApproaching',
                            'SightPct', 'SnowCover', 'Condition',
                            'AppTemp', 'AbsHum', 'TempDamped', 'TempMin', 'TempMax', 'RainTotal',
                            'WindMin', 'WindMax', 'HumMin', 'HumMax', 'Sunshine', 'SunshineToday', 'RainDetected'];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 60);
        // Auf jede Quell-Aktualisierung reagieren, statt nur im Takt zu rechnen. Die Quellen
        // lesen ihre Station selbst (Davis alle 5 s, Tempest laufend); der Takt oben bleibt als
        // Sicherheitsnetz, falls eine Quelle verstummt.
        $this->RegisterPropertyBoolean('OnSourceUpdate', true);
        $this->RegisterPropertyInteger('MinGapSeconds', 5);    // kuerzester Abstand zweier Laeufe
        $this->RegisterPropertyInteger('CameraSeconds', 60);   // Bildauswertung hoechstens so oft
        $this->RegisterPropertyInteger('DayValueSeconds', 60); // Tages-Min/Max hoechstens so oft
        $this->RegisterPropertyFloat('Lat', 0.0);
        $this->RegisterPropertyFloat('Lon', 0.0);
        $this->RegisterPropertyString('Sources', '[]');   // [{InstanceID,Priority,MaxAge,Enabled}]
        $this->RegisterPropertyString('Cameras', '[]');   // [{MediaID,Name,X,Y,W,H,Enabled}]
        // Bodenfeuchte: beliebig viele Fuehler, je Zeile mit EIGENER Skala.
        // Typ 0 = Prozent (hoch = feucht, z. B. Gardena), Typ 1 = Zentibar Saugspannung
        // (hoch = TROCKEN, z. B. Davis/Watermark). Die beiden laufen gegenlaeufig - genau
        // deshalb steht der Typ je Fuehler und wird nicht geraten.
        $this->RegisterPropertyString('SoilSensors', '[]'); // [{VarID,Name,Typ,Enabled}]
        $this->RegisterPropertyFloat('WindHeight', 10.0); // Messhoehe des Windgebers in m (fuer ET0)
        $this->RegisterPropertyBoolean('UseCameras', true);
        $this->RegisterPropertyBoolean('Logging', true);
        $this->RegisterPropertyInteger('DampMinutes', 15);   // Fenster der gedaempften Temperatur
        $this->RegisterPropertyBoolean('UseForecast', true); // Vorhersage von Open-Meteo holen
        $this->RegisterPropertyInteger('ForecastMinutes', 30);
        $this->RegisterPropertyInteger('ForecastDays', 7);
        $this->RegisterPropertyFloat('RainTotalStart', 0.0); // Startwert des Gesamtzaehlers (mm)
        $this->RegisterPropertyFloat('EtTotalStart', 0.0);   // Startwert des Verdunstungszaehlers (mm)
        // Optischer Regensensor: ein Boolean, der "es ist nass" meldet. Er misst keine Menge,
        // spricht dafuer sofort an - die Messwippe der Station braucht rund 0,2 mm. Ohne ihn
        // stand die Wetterlage bei Nieselregen minutenlang auf "bedeckt".
        $this->RegisterPropertyInteger('RainSensorId', 0);
        $this->RegisterPropertyInteger('RainSensor2Id', 0);   // zweite Meinung, nachrangig
        $this->RegisterPropertyInteger('RainSensorMaxAge', 3600);

        $this->RegisterPropertyFloat('FogHum', WE::STD['fogHum']);
        $this->RegisterPropertyFloat('FogWind', WE::STD['fogWind']);
        $this->RegisterPropertyFloat('FogSpread', WE::STD['fogSpread']);
        $this->RegisterPropertyInteger('SightWarn', WE::STD['sightWarn']);
        $this->RegisterPropertyInteger('SightFog', WE::STD['sightFog']);
        $this->RegisterPropertyInteger('StormNearKm', WE::STD['stormNearKm']);
        $this->RegisterPropertyInteger('StormNearMin', WE::STD['stormNearMin']);
        $this->RegisterPropertyInteger('StormFarKm', WE::STD['stormFarKm']);
        $this->RegisterPropertyInteger('StormFarMin', WE::STD['stormFarMin']);

        $this->maybeProfiles();

        // --- Messwerte (zusammengefuehrt) ---
        $this->RegisterVariableFloat('Temp', 'Temperatur', $this->prof('~Temperature'), 10);
        $this->RegisterVariableFloat('Hum', 'Luftfeuchte', $this->prof('~Humidity.F'), 11);
        $this->RegisterVariableFloat('Dew', 'Taupunkt', $this->prof('~Temperature'), 12);
        $this->RegisterVariableFloat('Wind', 'Wind', $this->prof('~WindSpeed.kmh'), 13);
        $this->RegisterVariableFloat('WindAvg', 'Wind Mittel', $this->prof('~WindSpeed.kmh'), 14);
        $this->RegisterVariableFloat('Gust', 'Böe', $this->prof('~WindSpeed.kmh'), 15);
        $this->RegisterVariableFloat('WindDir', 'Windrichtung', 'WX.Grad', 15);
        $this->RegisterVariableFloat('Pressure', 'Luftdruck', $this->prof('~AirPressure.F'), 16);
        $this->RegisterVariableFloat('RainRate', 'Regenrate', 'WX.Regenrate', 17);
        $this->RegisterVariableFloat('RainDay', 'Regen heute', $this->prof('~Rainfall'), 18);
        $this->RegisterVariableFloat('RainTotal', 'Regen kumuliert', 'WX.mm', 19);
        $this->RegisterVariableFloat('Radiation', 'Globalstrahlung', 'WX.Strahlung', 19);
        $this->RegisterVariableFloat('UV', 'UV-Index', $this->prof('~UVIndex'), 20);
        // Beleuchtungsstaerke: was das AUGE sieht, nicht was die Solarzelle bekommt. Sie ist
        // deshalb der natuerlichere Massstab fuer Helligkeitsschwellen (Beschattung, Licht)
        // als die Globalstrahlung - die Tempest misst beides getrennt.
        $this->RegisterVariableFloat('Illuminance', 'Beleuchtungsstärke', $this->prof('~Illumination'), 20);

        // --- Weitere Groessen, die die Quellen liefern ---
        $this->RegisterVariableFloat('RainMonth', 'Regen Monat', $this->prof('~Rainfall'), 18);
        $this->RegisterVariableFloat('RainYear', 'Regen Jahr', $this->prof('~Rainfall'), 18);
        $this->RegisterVariableFloat('RainLast', 'Letzter Regen', $this->prof('~Rainfall'), 18);
        // Verdunstung (Evapotranspiration): wie viel Wasser Boden und Pflanzen abgeben. Die
        // Station rechnet sie aus Strahlung, Temperatur, Feuchte und Wind. Fuer die Bewaesserung
        // ist sie die Gegengroesse zum Regen - erst beide zusammen ergeben die Bilanz.
        $this->RegisterVariableFloat('EtDay', 'Verdunstung heute', $this->prof('~Rainfall'), 19);
        $this->RegisterVariableFloat('EtMonth', 'Verdunstung Monat', $this->prof('~Rainfall'), 19);
        $this->RegisterVariableFloat('EtYear', 'Verdunstung Jahr', $this->prof('~Rainfall'), 19);
        $this->RegisterVariableFloat('EtTotal', 'Verdunstung kumuliert', 'WX.mm', 19);
        $this->RegisterVariableFloat('TempIn', 'Innentemperatur', $this->prof('~Temperature'), 21);
        $this->RegisterVariableFloat('HumIn', 'Innenfeuchte', $this->prof('~Humidity.F'), 21);
        $this->RegisterVariableFloat('PressureTrend', 'Luftdrucktendenz (Zahl)', '', 25);
        $this->RegisterVariableFloat('Battery', 'Batteriespannung', $this->prof('~Volt'), 90);

        $this->RegisterVariableFloat('AppTemp', 'Gefühlte Temperatur', $this->prof('~Temperature'), 21);
        $this->RegisterVariableFloat('AbsHum', 'Absolute Feuchte', 'WX.AbsFeuchte', 22);
        $this->RegisterVariableFloat('TempDamped', 'Temperatur gedämpft', $this->prof('~Temperature'), 23);
        $this->RegisterVariableString('WindDirText', 'Windrichtung (Text)', '', 24);
        $this->RegisterVariableString('PressureTrendText', 'Luftdrucktendenz', '', 25);
        $this->RegisterVariableString('ForecastText', 'Vorhersage der Station', '', 26);

        // --- Tageswerte mit Zeitpunkt ---
        $this->RegisterVariableFloat('TempMin', 'Temperatur Minimum heute', $this->prof('~Temperature'), 80);
        $this->RegisterVariableFloat('TempMax', 'Temperatur Maximum heute', $this->prof('~Temperature'), 81);
        $this->RegisterVariableInteger('TempMinTime', 'Zeit Minimum', '~UnixTimestamp', 82);
        $this->RegisterVariableInteger('TempMaxTime', 'Zeit Maximum', '~UnixTimestamp', 83);
        $this->RegisterVariableFloat('WindMin', 'Wind Minimum heute', $this->prof('~WindSpeed.kmh'), 84);
        $this->RegisterVariableFloat('WindMax', 'Wind Maximum heute', $this->prof('~WindSpeed.kmh'), 85);
        $this->RegisterVariableInteger('WindMinTime', 'Zeit Wind Minimum', '~UnixTimestamp', 86);
        $this->RegisterVariableInteger('WindMaxTime', 'Zeit Wind Maximum', '~UnixTimestamp', 87);
        $this->RegisterVariableFloat('HumMin', 'Luftfeuchte Minimum heute', $this->prof('~Humidity.F'), 88);
        $this->RegisterVariableFloat('HumMax', 'Luftfeuchte Maximum heute', $this->prof('~Humidity.F'), 89);
        $this->RegisterVariableInteger('HumMinTime', 'Zeit Feuchte Minimum', '~UnixTimestamp', 90);
        $this->RegisterVariableInteger('HumMaxTime', 'Zeit Feuchte Maximum', '~UnixTimestamp', 91);

        // --- Abgeleitet ---
        $this->RegisterVariableFloat('WetBulb', 'Feuchtkugel', '~Temperature', 30);
        // HITZEBELASTUNG (WBGT, ISO 7243 / DGUV). Ergaenzt die Feuchtkugel: die sagt, ob
        // Schwitzen ueberhaupt noch kuehlen kann, der WBGT ab wann Arbeit gefaehrlich wird.
        $this->RegisterVariableFloat('CoolRes', 'Kühlreserve (Verdunstungskälte)', 'WX.Kelvin', 30);
        $this->RegisterVariableFloat('VPD', 'Dampfdruckdefizit (VPD)', 'WX.Druck', 35);
        $this->RegisterVariableFloat('ET0', 'Verdunstung ET0 (Rate)', 'WX.Regenrate', 37);
        $this->RegisterVariableFloat('ET0Day', 'Verdunstung ET0 heute', '~Rainfall', 37);
        $this->RegisterVariableInteger('SoilLevel', 'Bodenfeuchte', 'WX.Boden', 36);
        $this->RegisterVariableString('SoilText', 'Bodenfeuchte · Fühler', '', 36);
        $this->RegisterVariableString('SoilJson', 'Bodenfeuchte (JSON)', '', 36);
        $this->RegisterVariableString('SoilTable', 'Bodenfeuchte · Tabelle', '', 36);
        $this->RegisterVariableInteger('CoolLevel', 'Kühlreserve · Stufe', 'WX.Kuehlreserve', 30);
        $this->RegisterVariableFloat('WBGT', 'Hitzebelastung · WBGT', '~Temperature', 31);
        $this->RegisterVariableInteger('HeatLevel', 'Hitzebelastung', 'WX.Hitze', 31);
        $this->RegisterVariableString('HeatText', 'Hitzebelastung · Hinweis', '', 31);
        $this->RegisterVariableInteger('PrecipType', 'Niederschlagsart', 'WX.Niederschlag', 31);
        $this->RegisterVariableInteger('FogLevel', 'Nebel', 'WX.Nebel', 32);
        $this->RegisterVariableFloat('FogFSI', 'Nebel · FSI', 'WX.FSI', 33);
        $this->RegisterVariableFloat('FogPct', 'Nebeldichte', '~Intensity.100', 34);
        $this->RegisterVariableString('FogText', 'Nebel · Begründung', '', 34);
        $this->RegisterVariableInteger('StormLevel', 'Gewitter', 'WX.Gewitter', 35);
        $this->RegisterVariableInteger('StormDist', 'Gewitter · Entfernung', '', 36);
        $this->RegisterVariableInteger('StormRate', 'Gewitter · Blitze (30 min)', '', 37);
        $this->RegisterVariableInteger('StormLast', 'Gewitter · letzter Blitz', '~UnixTimestamp', 38);
        $this->RegisterVariableInteger('StormTrend', 'Gewitter · Zug', 'WX.Zug', 39);
        $this->RegisterVariableFloat('StormSpeed', 'Gewitter · Zuggeschwindigkeit', $this->prof('~WindSpeed.kmh'), 40);
        $this->RegisterVariableInteger('StormEta', 'Gewitter · hier in etwa (min)', '', 41);
        $this->RegisterVariableBoolean('StormApproaching', 'Gewitter zieht auf', $this->prof('~Alert'), 42);
        $this->RegisterVariableString('StormText', 'Gewitter · Klartext', '', 43);
        $this->RegisterVariableFloat('CloudPct', 'Bewölkung', '~Intensity.100', 40);
        $this->RegisterVariableString('CloudSrc', 'Bewölkung · Herkunft', '', 41);
        $this->RegisterVariableString('MoonPhaseText', 'Mondphase', '', 64);
        $this->RegisterVariableString('Condition', 'Wetterlage', '', 42);

        // --- Kamera ---
        $this->RegisterVariableInteger('SightPct', 'Sicht (Kamera)', '~Intensity.100', 50);
        $this->RegisterVariableBoolean('SnowCover', 'Schneedecke', '', 51);
        $this->RegisterVariableString('CamTable', 'Kameras (JSON)', '', 52);

        // --- Sonne, Mond, Betrieb ---
        $this->RegisterVariableFloat('SunElev', 'Sonnenhöhe', '', 60);
        $this->RegisterVariableFloat('SunAzimuth', 'Sonnenazimut', '', 61);
        $this->RegisterVariableFloat('MoonIllum', 'Mond beleuchtet', '~Intensity.100', 62);
        $this->RegisterVariableBoolean('IsNight', 'Nacht', '', 63);
        // Sonnenschein: die Entscheidung gehoert hierher, nicht in ein Anzeigeskript. Sie
        // braucht Sonnenhoehe und Globalstrahlung - beides rechnet bzw. misst dieses Modul.
        $this->RegisterVariableBoolean('Sunshine', 'Sonnenschein', '', 64);
        $this->RegisterVariableFloat('SunThreshold', 'Sonnenschein · Schwelle', 'WX.Strahlung', 65);
        $this->RegisterVariableFloat('SunshineToday', 'Sonnenschein heute', 'WX.Stunden', 66);
        $this->RegisterVariableBoolean('RainDetected', 'Regen (Sensor)', '', 18);
        $this->RegisterVariableInteger('DataAge', 'Alter der Messwerte (s)', '', 70);
        $this->RegisterVariableString('Forecast', 'Vorhersage (JSON)', '', 68);
        $this->RegisterVariableInteger('ForecastAge', 'Vorhersage geholt', '~UnixTimestamp', 69);
        $this->RegisterVariableString('SourceMap', 'Herkunft je Größe (JSON)', '', 71);
        $this->RegisterVariableInteger('LastRun', 'Letzte Auswertung', '~UnixTimestamp', 72);

        $this->RegisterAttributeString('CamBase', '{}');
        $this->RegisterAttributeString('SkyBase', '{}');    // gelernter Klarwert R/B je Kamera und Sonnenhoehenfach
        $this->RegisterAttributeString('SunBase', '{}');    // gelernter Klarhimmel-Faktor der Strahlung
        $this->RegisterAttributeString('StrikeRing', '[]');
        $this->RegisterAttributeString('Upper', '{}');
        $this->RegisterAttributeString('Damp', '[]');
        $this->RegisterAttributeFloat('RainDayLast', -1.0);
        $this->RegisterAttributeFloat('EtDayLast', -1.0);
        $this->RegisterAttributeInteger('SunshineLast', 0);   // Zeitpunkt der letzten Sonnenschein-Auswertung
        $this->RegisterAttributeInteger('LastRun', 0);        // Entprellung der Quell-Ereignisse
        $this->RegisterAttributeString('CamCache', '{}');     // letzte Kameraauswertung samt Zeitpunkt
        $this->RegisterAttributeInteger('DayTs', 0);          // letzte Tageswert-Berechnung

        $this->RegisterTimer('Tick', 0, 'WX_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->maybeProfiles();
        $iv = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Tick', $iv * 1000);
        $this->quellenAbonnieren();
        if ($this->ReadPropertyBoolean('Logging')) {
            $this->applyLogging();
        }
        $this->SetStatus($this->quellen() === [] ? 201 : 102);
    }

    /**
     * Auf die Quellen hoeren, statt auf die Uhr zu schauen.
     *
     * Jede Quelle stempelt bei jedem gelungenen Lesen eine Variable: die allgemeine Quelle
     * "Zuletzt gelesen" (LastRead), der UDP-Empfaenger seine Beobachtung (Data). Wer darauf
     * lauscht, rechnet genau dann neu, wenn es etwas Neues GIBT - und nicht 59 Sekunden
     * spaeter. Der Zeittakt bleibt als Sicherheitsnetz bestehen: verstummt eine Quelle, laeuft
     * die Ableitung trotzdem weiter (Sonnenstand, Mond, Tageswechsel haengen nicht an ihr).
     */
    private function quellenAbonnieren(): void
    {
        $will = [];
        if ($this->ReadPropertyBoolean('OnSourceUpdate')) {
            foreach ($this->quellen() as $q) {
                $iid = (int) $q['InstanceID'];
                if ($iid <= 0 || !@IPS_InstanceExists($iid)) {
                    continue;
                }
                foreach (['LastRead', 'Data'] as $ident) {
                    $vid = @IPS_GetObjectIDByIdent($ident, $iid);
                    if ($vid) {
                        $will[$vid] = true;
                        break;          // eine Variable je Quelle genuegt
                    }
                }
            }
        }
        $hat = [];
        foreach ($this->GetMessageList() as $sid => $msgs) {
            foreach ($msgs as $m) {
                if ($m === VM_UPDATE) {
                    $hat[(int) $sid] = true;
                }
            }
        }
        foreach (array_keys($will) as $vid) {
            if (!isset($hat[$vid])) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }
        foreach (array_keys($hat) as $vid) {
            if (!isset($will[$vid])) {
                $this->UnregisterMessage($vid, VM_UPDATE);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        // Entprellen: zwei Quellen, die im selben Moment lesen, sollen EINEN Lauf ausloesen.
        $gap = max(1, $this->ReadPropertyInteger('MinGapSeconds'));
        if (time() - $this->ReadAttributeInteger('LastRun') < $gap) {
            return;
        }
        $this->Update();
    }

    // ==================================================================
    // Oeffentlich
    // ==================================================================

    public function Update(): void
    {
        $this->WriteAttributeInteger('LastRun', time());
        [$o, $herkunft] = $this->zusammenfuehren();
        $lat = $this->ReadPropertyFloat('Lat');
        $lon = $this->ReadPropertyFloat('Lon');

        $hoehe  = Meteo::sonnenhoehe($lat, $lon);
        $nacht  = $hoehe < -0.833;
        $kamera = $this->kameras($nacht, $hoehe, $this->modellWolken());

        // WIE LANGE IST DER LETZTE NIEDERSCHLAG HER?
        //
        // Dafuer braucht es KEINE neue Variable: die Niederschlagsart wechselt beim Aufhoeren
        // auf 0, und VariableChanged haelt genau diesen Zeitpunkt fest - SetValue mit
        // unveraendertem Wert ruehrt ihn nicht an. (Eine neue Variable waere hier ohnehin
        // laestig: sie entstuende erst beim naechsten Modul-Reload.)
        // Faellt gerade Niederschlag, ist die Antwort 0 - dann greift ohnehin schon der
        // Torwaechter im Regelsatz und Nebel ist ausgeschlossen.
        $regenVorS = null;
        $nsVid = @$this->GetIDForIdent('PrecipType');
        if ($nsVid) {
            $vv = @IPS_GetVariable($nsVid);
            if (is_array($vv)) {
                // Bewusst der Variablenwert und nicht $ns - das wird erst weiter unten
                // gerechnet. Der Wert des VORIGEN Laufs genuegt hier vollauf: es geht um ein
                // 90-Minuten-Fenster, nicht um Sekunden.
                $regenVorS = ((int) @GetValue($nsVid) !== WE::NS_KEIN)
                    ? 0 : max(0, time() - (int) ($vv['VariableChanged'] ?? 0));
            }
        }
        $cfg = ['fogHum' => $this->ReadPropertyFloat('FogHum'),
                'rainAgoS' => $regenVorS,
                'fogWind' => $this->ReadPropertyFloat('FogWind'),
                'fogSpread' => $this->ReadPropertyFloat('FogSpread'),
                'sightWarn' => $this->ReadPropertyInteger('SightWarn'),
                'sightFog' => $this->ReadPropertyInteger('SightFog'),
                // Wann darf die Kamera mitreden? Nicht "nur bei Tageslicht" - die Kameras haben
                // INFRAROTBELEUCHTUNG und sehen nachts sehr wohl etwas. Der Klarwert wird
                // ohnehin getrennt fuer Tag und Nacht gelernt (siehe kameras($nacht)), es wird
                // also Gleiches mit Gleichem verglichen. Wertlos ist allein die DAEMMERUNG:
                // dort ist es fuer das Tagbild zu dunkel und fuer das IR-Bild zu hell, die
                // Kamera schaltet mittendrin um, und keiner der beiden Klarwerte passt.
                //
                // Deshalb zwei Fenster statt einer Schwelle: Tag ab +5 Grad Sonnenhoehe,
                // Nacht ab -6 Grad (Ende der buergerlichen Daemmerung, ab da leuchtet das IR).
                // Dazwischen bleibt die Kamera stumm - wie bisher, aber nur noch dort.
                'camUsable' => ($hoehe >= self::CAM_SUN_MIN) || ($hoehe <= self::CAM_NIGHT_MAX),
                // Nachts ist ein MASSVOLLER Sichtverlust kein Beleg: das Infrarot leuchtet nur
                // die Nahzone aus, das Fernfeld lebt vom Himmelslicht. Unter dichter Bewoelkung
                // ist es dort dunkler und kantenaermer als in einer klaren oder mondhellen
                // Nacht - und gegen so eine Nacht ist der Klarwert gelernt (Bestmarke).
                'camNacht' => $hoehe <= self::CAM_NIGHT_MAX,
                'stormNearKm' => $this->ReadPropertyInteger('StormNearKm'),
                'stormNearMin' => $this->ReadPropertyInteger('StormNearMin'),
                'stormFarKm' => $this->ReadPropertyInteger('StormFarKm'),
                'stormFarMin' => $this->ReadPropertyInteger('StormFarMin')];

        $sichtGlatt = $this->sichtGeglaettet($kamera['sicht']);
        $neb = WE::nebel($o, $this->hoehenwerte($lat, $lon), $sichtGlatt, $cfg);
        if ($kamera['sicht'] !== null && $sichtGlatt !== null && (int) $sichtGlatt !== (int) $kamera['sicht']) {
            $neb['text'] .= sprintf(' | geglättet: Median %d %% aus den letzten Messungen (roh %d %%)',
                                    (int) $sichtGlatt, (int) $kamera['sicht']);
        }
        $gew = WE::gewitter($o, $this->ringMitQuellen(), $cfg);
        $this->WriteAttributeString('StrikeRing', json_encode($gew['ring']));
        $wol = WE::bewoelkung($o, $lat, $lon, null, $kamera['wolken'] ?? null,
                              $this->strahlungFaktor($hoehe, $o->num('radiationWm2'), $this->modellWolken()),
                              $this->modellWolken());
        $wolGlatt = $this->wolkenGeglaettet($wol['pct']);
        $nass = $this->regenSensor();
        $ns  = WE::niederschlag($o, $nass);

        // Messwerte durchreichen — nur, was auch da ist. Fehlt eine Groesse, bleibt die
        // Variable auf ihrem letzten Wert statt auf einer erfundenen Null.
        $this->put('Temp', $o->num('tempC'));
        $this->put('Hum', $o->num('humPct'));
        $this->put('Dew', $o->num('dewC'));
        $this->put('Wind', $o->num('windKmh') ?? $o->num('windAvgKmh'));
        $this->put('WindAvg', $o->num('windAvgKmh') ?? $o->num('windKmh'));
        $this->put('Gust', $o->num('gustKmh'));
        $this->put('WindDir', $o->num('windDirDeg'));
        $this->put('Pressure', $o->num('pressureHpa'));
        $this->put('RainRate', $o->num('rainRateMmH'));
        $this->put('RainDay', $o->num('rainDayMm'));
        $this->regenGesamt($o->num('rainDayMm'));
        $this->put('Radiation', $o->num('radiationWm2'));
        $this->put('Illuminance', $o->num('illuminanceLux'));
        $this->put('RainMonth', $o->num('rainMonthMm'));
        $this->put('RainYear', $o->num('rainYearMm'));
        $this->put('RainLast', $o->num('rainLastMm'));
        $this->put('EtDay', $o->num('etDayMm'));
        $this->put('EtMonth', $o->num('etMonthMm'));
        $this->put('EtYear', $o->num('etYearMm'));
        $this->put('TempIn', $o->num('tempInC'));
        $this->put('HumIn', $o->num('humInPct'));
        $this->put('PressureTrend', $o->num('pressureTrend'));
        $this->put('Battery', $o->num('batteryV'));
        $this->summe('EtTotal', 'EtDayLast', 'EtTotalStart', $o->num('etDayMm'));
        $this->put('UV', $o->num('uvIndex'));

        // Gefuehlte Temperatur, absolute Feuchte, Daempfung und die Klartexte.
        $t  = $o->num('tempC');
        $rh = $o->num('humPct');
        $ws = $o->num('windKmh') ?? $o->num('windAvgKmh') ?? 0.0;
        if ($t !== null && $rh !== null) {
            $this->put('AppTemp', Meteo::gefuehlt($t, $rh, $ws));
            $this->put('AbsHum', Meteo::absoluteFeuchte($t, $rh));
        }
        $this->put('TempDamped', $this->daempfen($t));
        if ($o->has('windDirDeg')) {
            $this->SetValue('WindDirText', Meteo::windrichtungText((float) $o->num('windDirDeg')));
        }
        $this->SetValue('PressureTrendText', StationCodes::tendenzText($o->num('pressureTrend')));
        $this->SetValue('ForecastText', StationCodes::vorhersageText($o->num('forecastCode')));
        $this->SetValue('MoonPhaseText', Meteo::mondphaseText((float) Meteo::mond()['phase']));
        $this->tageswerte();

        $this->put('WetBulb', $ns['twet']);
        // WBGT braucht nur Temperatur und Feuchte - beides Pflichtwerte der Station.
        $tW = $o->num('tempC'); $rhW = $o->num('humPct');
        if ($tW !== null && $rhW !== null) {
            $kr = Meteo::kuehlreserve($tW, $rhW);
            $this->put('CoolRes', $kr);
            $this->put('VPD', Meteo::vpd($tW, $rhW));
            // ET0 braucht zusaetzlich Wind, Strahlung und Luftdruck. Fehlt eines davon,
            // wird NICHT gerechnet - eine Verdunstung ohne Strahlungsterm waere Unsinn.
            $wKmh = $o->num('windKmh') ?? $o->num('windAvgKmh');
            $rsW  = $o->num('radiationWm2');
            $pH   = $o->num('pressureHpa');
            if ($wKmh !== null && $rsW !== null && $pH !== null) {
                $u2  = Meteo::windAuf2m($wKmh, $this->ReadPropertyFloat('WindHeight'));
                $rso = Meteo::klarhimmel($hoehe);
                $vor = json_decode((string) $this->GetBuffer('ET0State'), true);
                $alt = is_array($vor) ? $vor : [];
                $r   = Meteo::et0($tW, $rhW, $u2, $rsW, $pH, $rso,
                                  isset($alt['ratio']) ? (float) $alt['ratio'] : null);
                $this->put('ET0', round(max(0.0, $r['et0']), 3));
                // Tagessumme aus der verstrichenen Zeit seit dem letzten Lauf. Negative
                // Stundenwerte (klare Nacht, Taubildung) werden auf 0 begrenzt: sie sind
                // physikalisch Kondensation, keine negative Bewaesserung.
                $nun = time();
                $letzt = (int) ($alt['ts'] ?? 0);
                $tagS  = (string) ($alt['tag'] ?? '');
                $heute = date('Y-m-d');
                $summe = ($tagS === $heute) ? (float) ($alt['sum'] ?? 0.0) : 0.0;
                if ($letzt > 0 && $tagS === $heute) {
                    $dt = min(3600, max(0, $nun - $letzt)) / 3600.0;
                    $summe += max(0.0, $r['et0']) * $dt;
                }
                $this->put('ET0Day', round($summe, 2));
                $this->SetBuffer('ET0State', json_encode([
                    'ts' => $nun, 'tag' => $heute, 'sum' => $summe,
                    'ratio' => $r['ratio'] ?? ($alt['ratio'] ?? null)]));
            }
        }
        $bf = $this->bodenfeuchte();
        $this->SetValue('SoilLevel', $bf['stufe']);
        $this->SetValue('SoilText', $bf['text']);
        $this->SetValue('SoilJson', json_encode($bf['liste'], JSON_UNESCAPED_UNICODE));
        // Dieselben Daten nochmal als 2D-Feld fuer das Tabellen-Widget (Zeile 0 = Kopf).
        $namen = ['nass', 'feucht', 'mäßig', 'trocken', 'sehr trocken', 'staubtrocken'];
        $tab = [['Fühler', 'Messwert', 'Zustand']];
        foreach ($bf['liste'] as $l) {
            if (isset($l['fehler'])) { $tab[] = [$l['name'], '—', $l['fehler']]; continue; }
            $z = $namen[$l['stufe']] ?? '?';
            if (!empty($l['veraltet'])) {
                $z .= ' (veraltet, ' . max(1, (int) round($l['alterS'] / 86400)) . ' d)';
            }
            $tab[] = [$l['name'], $l['text'], $z];
        }
        $this->SetValue('SoilTable', json_encode($tab, JSON_UNESCAPED_UNICODE));
        if ($tW !== null && $rhW !== null) {
            $this->SetValue('CoolLevel', Meteo::kuehlstufe($kr)['stufe']);
            $wb = Meteo::wbgt($tW, $rhW);
            $hs = Meteo::hitzestufe($wb);
            $this->put('WBGT', $wb);
            $this->SetValue('HeatLevel', $hs['stufe']);
            $this->SetValue('HeatText', sprintf('%s (WBGT %.1f °C, ohne Strahlungslast) — %s',
                $hs['name'], $wb, $hs['hinweis']));
        }
        $this->SetValue('PrecipType', $ns['art']);
        $this->SetValue('RainDetected', $nass === true);
        // ENTPRELLEN: eine neue Stufe wird erst veroeffentlicht, wenn sie FOG_DWELL Sekunden
        // anhaelt. Nebel bildet sich und loest sich in Minuten, nicht in Sekunden - eine
        // Anzeige, die im Zehn-Sekunden-Takt springt, ist keine Aussage, sondern Rauschen.
        // Auf dem Weg NACH OBEN wie nach unten gleich, damit keine Richtung bevorzugt wird.
        $roh = (int) $neb['stufe'];
        $ent = json_decode((string) $this->GetBuffer('FogDebounce'), true);
        $jetzt = time();
        $stand = is_array($ent) ? (int) ($ent['stufe'] ?? $roh) : $roh;
        $kand  = is_array($ent) ? (int) ($ent['kand'] ?? $roh) : $roh;
        $seit  = is_array($ent) ? (int) ($ent['seit'] ?? $jetzt) : $jetzt;
        if ($roh !== $kand) { $kand = $roh; $seit = $jetzt; }
        if ($roh !== $stand && ($jetzt - $seit) >= self::FOG_DWELL) { $stand = $roh; }
        $this->SetBuffer('FogDebounce',
            json_encode(['stufe' => $stand, 'kand' => $kand, 'seit' => $seit]));
        if ($stand !== $roh) {
            $neb['text'] .= sprintf(' | gemessen "%s", noch nicht bestätigt (%d s von %d)',
                ['kein Nebel', 'diesig', 'Nebel', 'dichter Nebel'][$roh] ?? $roh,
                $jetzt - $seit, self::FOG_DWELL);
        }
        $this->SetValue('FogLevel', $stand);
        $this->SetValue('FogFSI', (float) ($neb['fsi'] ?? 0.0));
        // Nebel als DICHTE in Prozent — dafuer, dass Anzeigen eine stetige Groesse brauchen.
        // Liegt eine Kameramessung vor, ist sie massgeblich (Dichte = fehlende Sicht); sonst
        // wird die Stufe auf einen Richtwert abgebildet und das ist auch genau so gemeint.
        $this->SetValue('FogPct', $kamera['sicht'] !== null
            ? round(max(0.0, 100.0 - $kamera['sicht']), 1)
            : (float) [0, 25, 60, 90][$neb['stufe']]);
        // Die Streuung der Kameras gehoert in die Begruendung: sie zeigt, ob eine einzelne
        // Kamera abweicht (Linse, Gegenlicht, alter Klarwert) oder ob es wirklich zuzieht.
        $fogText = $neb['text'];
        if (($kamera['sichtAnzahl'] ?? 0) > 1 && $kamera['sichtMin'] !== null
            && abs((float) $kamera['sichtMin'] - (float) $kamera['sicht']) >= 10.0) {
            $fogText .= sprintf(' | %d Kameras, mittlere Sicht %d %%, schlechteste %d %%',
                (int) $kamera['sichtAnzahl'], (int) round((float) $kamera['sicht']),
                (int) round((float) $kamera['sichtMin']));
        }
        $this->SetValue('FogText', $fogText);
        $this->SetValue('StormLevel', $gew['stufe']);
        $this->SetValue('StormDist', $gew['dist']);
        $this->SetValue('StormRate', $gew['rate']);
        $this->SetValue('StormLast', $gew['last']);
        $this->SetValue('StormTrend', (int) $gew['trend']);
        $this->SetValue('StormSpeed', (float) ($gew['speed'] ?? 0.0));
        $this->SetValue('StormEta', (int) ($gew['eta'] ?? 0));
        // Die Warnung, auf die es ankommt: nicht "es blitzt", sondern "es kommt hierher".
        $this->SetValue('StormApproaching', $gew['trend'] < 0 && $gew['stufe'] >= WE::GEW_GEWITTER);
        $this->SetValue('StormText', $gew['text']);
        $this->put('CloudPct', $wolGlatt);
        // Die Herkunft traegt den Weg mit: wer die Zahl liest, soll sehen, WORAUS sie stammt.
        // Genau das fehlte, als die Anzeige "stark bewoelkt" meldete und daneben "Sonne zu
        // tief fuer eine Aussage" stand - zwei Saetze, die einander widersprachen, weil der
        // eine den festgehaltenen Tageswert beschrieb und der andere die aktuelle Lage.
        $quelle = $wol['quelle'];
        if ($wolGlatt !== null && $wol['pct'] !== null && (int) round($wolGlatt) !== (int) round($wol['pct'])) {
            $quelle .= sprintf(' | geglättet %d %% (roh %d %%)', (int) round($wolGlatt), (int) round($wol['pct']));
        }
        if ($wol['pct'] === null) {
            $quelle .= ' | angezeigt bleibt der letzte bekannte Wert';
        }
        $this->SetValue('CloudSrc', $quelle);
        // Dunst bekommt seine Tageszeit: vormittags loest sich Strahlungsnebel auf,
        // abends bildet er sich. Dieselbe Stufe, aber die genauere Aussage - wer sie liest,
        // weiss, ob es besser oder schlechter wird.
        //
        // Bewusst nach der UHR und nicht nach dem Sonnenstand: im Winter steht die Sonne den
        // ganzen Tag tief, "Morgendunst" um 14 Uhr waere Unsinn. Tagsueber heisst es schlicht
        // Dunst - dann ist er weder im Entstehen noch im Vergehen.
        //
        // Grenze 10 Uhr, frueher 11: Strahlungsnebel loest sich im Lauf des Vormittags auf,
        // und um halb elf ist "Morgendunst" keine Aussage ueber ein Vergehen mehr, sondern
        // nur noch eine Uhrzeitbehauptung.
        $std = (int) date('H');
        $tageszeit = ($std < 10) ? 'morgen' : (($std >= 16) ? 'abend' : null);
        // AUSDRUECKLICH OHNE Rueckgriff auf den letzten Wert. Hier stand einmal
        // "$wol['pct'] ?? $this->GetValue('CloudPct')" - und weil put() ein null ueberspringt,
        // war der Ersatzwert der letzte TAGESWERT. Der ueberdauerte die ganze Nacht: am
        // 08.09.2026 meldete die Station um 19 Uhr "stark bewoelkt" bei sternklarem Himmel,
        // aus einer Messung von 84,7 %, die um 19:01 bei 5 Grad Sonnenhoehe entstanden war.
        // Sagt keine Quelle etwas, sagt auch die Wetterlage nichts.
        $this->SetValue('Condition', WE::wetterlage($gew['stufe'], $ns, $neb['stufe'],
                                                    $wolGlatt, $tageszeit));

        $this->put('SightPct', $kamera['sicht']);
        if ($kamera['schnee'] !== null) {
            $this->SetValue('SnowCover', $kamera['schnee']);
        }
        $this->SetValue('CamTable', json_encode($kamera['liste'], JSON_UNESCAPED_UNICODE));

        $mond = Meteo::mond();
        $this->SetValue('SunElev', $hoehe);
        $this->SetValue('SunAzimuth', Meteo::sonnenazimut($lat, $lon));
        $this->SetValue('MoonIllum', round($mond['beleuchtet'] * 100, 1));
        $this->SetValue('IsNight', $nacht);
        $this->sonnenschein($hoehe, $o->num('radiationWm2'));
        $this->SetValue('DataAge', $o->has('tempC') ? $o->age('tempC') : 0);
        $this->vorhersage($lat, $lon);
        $this->SetValue('SourceMap', json_encode($herkunft, JSON_UNESCAPED_UNICODE));
        $this->SetValue('LastRun', time());
    }

    /** Alles auf einmal, fuer Frontends und zur Fehlersuche. */
    public function GetState(): string
    {
        [$o, $h] = $this->zusammenfuehren();
        return json_encode(['beobachtung' => $o->toArray(), 'herkunft' => $h], JSON_UNESCAPED_UNICODE);
    }

    /** Gelernte Kamera-Klarwerte verwerfen — nach Umbau oder Reinigung einer Kamera. */
    public function ResetCameraBaseline(): string
    {
        $this->WriteAttributeString('CamBase', '{}');
        return 'Klarwerte verworfen. Sie werden ab dem nächsten Durchlauf neu gelernt; '
             . 'die Sichtmessung meldet bis dahin volle Sicht.';
    }

    /**
     * Die Kameras samt Messfeld und letzter Messung - Grundlage fuer das Werkzeug,
     * mit dem das Feld gezogen wird.
     *
     * Warum ueberhaupt ein Werkzeug: das Feld steht als vier Prozentzahlen in der
     * Instanz, und vier Prozentzahlen sagen niemandem, was er misst. Am 23.08.2026
     * standen alle vier Kameras auf dem GANZEN Bild - also zum grossen Teil auf dem
     * Himmel, und genau dessen Streulicht hebt den Dunkelkanal an. Bei klarer Sicht
     * mass das Poolhaus 79 von 110, die Schwelle fuer dichten Nebel.
     */
    public function Messfelder(): string
    {
        $cams = json_decode($this->ReadPropertyString('Cameras'), true);
        $base = json_decode($this->ReadAttributeString('CamBase'), true);
        $himBase = $this->attrJson('SkyBase');
        $letzte = [];
        foreach ((json_decode((string) $this->GetValue('CamTable'), true) ?: []) as $z) {
            $letzte[(int) ($z['id'] ?? 0)] = $z;
        }
        $out = [];
        foreach (is_array($cams) ? $cams : [] as $c) {
            $mid = (int) ($c['MediaID'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            // Die Grabber heissen reihum "Image"; das ist kein Name, sondern der
            // Vorgabename des Medienobjekts. Dann lieber die Instanz darueber.
            $name = trim((string) ($c['Name'] ?? ''));
            if (($name === '' || $name === 'Image') && @IPS_ObjectExists($mid)) {
                $eltern = IPS_GetParent($mid);
                $name = ($eltern > 0) ? IPS_GetName($eltern) : IPS_GetName($mid);
            }
            if ($name === '') {
                $name = '#' . $mid;
            }
            $groesse = '';
            if (@IPS_MediaExists($mid)) {
                $g = @getimagesizefromstring(base64_decode((string) @IPS_GetMediaContent($mid)));
                if ($g) { $groesse = $g[0] . 'x' . $g[1]; }
            }
            // Gelernte Himmels-Klarwerte dieser Kamera, je Sonnenhoehenfach. Sie gehoeren
            // ins Werkzeug, weil sie erklaeren, warum eine Kamera (noch) nichts sagt:
            // ohne Reife gibt es kein Urteil, und die Reife steht nur hier.
            $himKlar = [];
            foreach ($himBase as $k => $v) {
                if (str_starts_with((string) $k, (string) $mid) && is_array($v)) {
                    $himKlar[substr((string) $k, strlen((string) $mid))] =
                        ['rb' => (float) ($v['rb'] ?? 0), 'n' => (int) ($v['n'] ?? 0)];
                }
            }
            $out[] = [
                'id' => $mid, 'name' => $name, 'aktiv' => !empty($c['Enabled']),
                'x' => (float) ($c['X'] ?? 0), 'y' => (float) ($c['Y'] ?? 0),
                'w' => (float) ($c['W'] ?? 100), 'h' => (float) ($c['H'] ?? 100),
                // Das Himmelsfeld ist der ZWEITE Ausschnitt derselben Kamera. Breite oder
                // Hoehe auf 0 heisst "diese Kamera sieht keinen Himmel" - der Normalfall
                // fuer alles, was nach unten blickt.
                'sicht' => !isset($c['UseSight']) || !empty($c['UseSight']),
                'hx' => (float) ($c['SX'] ?? 0), 'hy' => (float) ($c['SY'] ?? 0),
                'hw' => (float) ($c['SW'] ?? 0), 'hh' => (float) ($c['SH'] ?? 0),
                'himKlar' => $himKlar,
                'groesse' => $groesse,
                'klarwertTag' => (float) ($base[$mid . 'd'] ?? 0),
                'klarwertNacht' => (float) ($base[$mid . 'n'] ?? 0),
                'letzte' => $letzte[$mid] ?? null,
            ];
        }
        $hoehe = Meteo::sonnenhoehe($this->ReadPropertyFloat('Lat'), $this->ReadPropertyFloat('Lon'));
        return json_encode(['ok' => true, 'kameras' => $out,
                            'verfuegbar' => $this->bildquellen(array_column($out, 'id')),
                            'schwellen' => CameraVision::schwellen(),
                            'himmelSchwellen' => CameraVision::himmelSchwellen(),
                            'fach' => CameraVision::himmelFach($hoehe),
                            'modell' => $this->modellWolken(),
                            'sonne' => round($hoehe, 1)],
                           JSON_UNESCAPED_UNICODE);
    }

    /**
     * Alle Bildquellen im Objektbaum, die sich als Kamera eignen.
     *
     * Gezeigt wird der ganze Weg im Baum, nicht nur der Name: die Grabber heissen
     * reihum "Image", und zwoelf Zeilen "Image" sind keine Auswahl.
     *
     * @param list<int> $schon bereits gebundene Medien
     * @return list<array<string,mixed>>
     */
    private function bildquellen(array $schon): array
    {
        $out = [];
        foreach (IPS_GetMediaListByType(1) as $mid) {   // 1 = Bild
            $datei = (string) (IPS_GetMedia($mid)['MediaFile'] ?? '');
            if ($datei === '') {
                continue;
            }
            // Drei Bedingungen, und jede sortiert etwas anderes aus. Von 81 Bildern
            // im Baum bleiben damit die zwoelf Kameras uebrig:
            //   Eltern = INSTANZ   -> es kommt von Geraet, nicht aus dem Dateisystem
            //                         (Raumbilder, Diagramme, Icons fallen weg)
            //   frisch             -> ein Picon aendert sich nie, ein Kamerabild staendig
            //   gross genug        -> die Mondansicht ist 100x100; darauf laesst sich
            //                         keine Sichtweite messen
            $eltern = IPS_GetParent($mid);
            if ($eltern <= 0 || IPS_GetObject($eltern)['ObjectType'] !== 1) {
                continue;
            }
            // Nur Standbilder. Ein GIF ist im Bestand die Jahresgrafik der
            // Daemmerung - sie erneuert sich, kommt von einer Instanz und ist gross
            // genug, waere also durch alle anderen Pruefungen gerutscht.
            if (!in_array(strtolower((string) pathinfo($datei, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }
            $pfad = IPS_GetKernelDir() . $datei;
            $alter = @is_file($pfad) ? (time() - (int) filemtime($pfad)) : null;
            if ($alter === null || $alter > 86400) {
                continue;
            }
            $g = @getimagesize($pfad);
            if (!$g || $g[0] < 320) {
                continue;
            }
            $baum = [];
            $p = $eltern;
            while ($p > 0) { $baum[] = IPS_GetName($p); $p = IPS_GetParent($p); }
            $out[] = ['id' => $mid,
                      'name' => IPS_GetName($mid),
                      'ort' => implode(' \\ ', array_reverse($baum)),
                      'groesse' => $g[0] . 'x' . $g[1],
                      'alterMin' => (int) round($alter / 60),
                      'gebunden' => in_array($mid, $schon, true)];
        }
        usort($out, static fn(array $a, array $b): int => strnatcasecmp($a['ort'] . $a['name'], $b['ort'] . $b['name']));
        return $out;
    }

    /**
     * Eine Bildquelle als Kamera aufnehmen.
     *
     * Neu aufgenommen wird mit dem GANZEN Bild - das ist die ehrliche Vorgabe: erst
     * messen, dann das Feld setzen. Ein geratener Ausschnitt waere schlimmer als
     * keiner, weil er wie eine Entscheidung aussieht.
     */
    public function KameraBinden(int $MediaID): string
    {
        if ($MediaID <= 0 || !@IPS_MediaExists($MediaID)) {
            return json_encode(['ok' => false, 'fehler' => 'Medienobjekt gibt es nicht']);
        }
        $cams = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($cams)) { $cams = []; }
        foreach ($cams as &$c) {
            if ((int) ($c['MediaID'] ?? 0) === $MediaID) {
                $c['Enabled'] = true;
                unset($c);
                $this->kamerasSchreiben($cams);
                return json_encode(['ok' => true, 'hinweis' => 'war schon gebunden, jetzt aktiv']);
            }
        }
        unset($c);
        $name = IPS_GetName($MediaID);
        $eltern = IPS_GetParent($MediaID);
        if (($name === '' || $name === 'Image') && $eltern > 0) {
            $name = IPS_GetName($eltern);          // "Image" unter "3-Grabber West" sagt nichts
        }
        $cams[] = ['MediaID' => $MediaID, 'Name' => $name, 'X' => 0, 'Y' => 0,
                   'W' => 100, 'H' => 100, 'Enabled' => true];
        $this->kamerasSchreiben($cams);
        return json_encode(['ok' => true, 'hinweis' => 'aufgenommen mit dem ganzen Bild - Feld noch setzen'],
                           JSON_UNESCAPED_UNICODE);
    }

    /** Kamera wieder herausnehmen - samt ihrem gelernten Klarwert. */
    public function KameraLoesen(int $MediaID): string
    {
        $cams = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($cams)) {
            return json_encode(['ok' => false, 'fehler' => 'keine Kameraliste']);
        }
        $neu = array_values(array_filter($cams,
            static fn(array $c): bool => (int) ($c['MediaID'] ?? 0) !== $MediaID));
        if (count($neu) === count($cams)) {
            return json_encode(['ok' => false, 'fehler' => 'Kamera steht nicht in der Liste']);
        }
        $base = json_decode($this->ReadAttributeString('CamBase'), true);
        if (is_array($base)) {
            unset($base[$MediaID . 'd'], $base[$MediaID . 'n']);
            $this->WriteAttributeString('CamBase', json_encode($base));
        }
        $this->kamerasSchreiben($neu);
        return json_encode(['ok' => true, 'hinweis' => 'herausgenommen'], JSON_UNESCAPED_UNICODE);
    }

    /** Kamera stilllegen oder wieder mitrechnen lassen. */
    public function KameraAktiv(int $MediaID, bool $Aktiv): string
    {
        $cams = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($cams)) {
            return json_encode(['ok' => false, 'fehler' => 'keine Kameraliste']);
        }
        $gefunden = false;
        foreach ($cams as &$c) {
            if ((int) ($c['MediaID'] ?? 0) === $MediaID) { $c['Enabled'] = $Aktiv; $gefunden = true; }
        }
        unset($c);
        if (!$gefunden) {
            return json_encode(['ok' => false, 'fehler' => 'Kamera steht nicht in der Liste']);
        }
        $this->kamerasSchreiben($cams);
        return json_encode(['ok' => true, 'hinweis' => $Aktiv ? 'zaehlt wieder mit' : 'stillgelegt'],
                           JSON_UNESCAPED_UNICODE);
    }

    /** @param list<array<string,mixed>> $cams */
    private function kamerasSchreiben(array $cams): void
    {
        IPS_SetProperty($this->InstanceID, 'Cameras', json_encode(array_values($cams)));
        IPS_ApplyChanges($this->InstanceID);
    }

    /**
     * Was ein Feld GERADE misst - ohne es zu speichern.
     *
     * Angaben in Prozent wie in der Instanz, damit im Werkzeug und im Formular
     * dieselben Zahlen stehen.
     */
    public function MessfeldPruefen(int $MediaID, float $X, float $Y, float $W, float $H,
                                    string $Feld = 'sicht'): string
    {
        if ($Feld === 'himmel') {
            $m = $this->messeHimmel($MediaID, $X, $Y, $W, $H);
            if ($m === null) {
                return json_encode(['ok' => false, 'fehler' => 'kein auswertbares Bild']);
            }
            return json_encode(['ok' => true, 'feld' => 'himmel', 'messung' => $m,
                                'himmelSchwellen' => CameraVision::himmelSchwellen()],
                               JSON_UNESCAPED_UNICODE);
        }
        $m = $this->messeFeld($MediaID, $X, $Y, $W, $H);
        if ($m === null) {
            return json_encode(['ok' => false, 'fehler' => 'kein auswertbares Bild']);
        }
        return json_encode(['ok' => true, 'feld' => 'sicht', 'messung' => $m,
                            'schwellen' => CameraVision::schwellen()], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Himmelsfeld messen - samt dem Urteil gegen den GELERNTEN Klarwert dieser Kamera.
     *
     * Das Urteil gehoert dazu, sonst zeigt das Werkzeug eine Zahl ohne Bezug: 0,84 heisst
     * an der einen Kamera wolkenlos und an der naechsten halb bedeckt, je nach Weissabgleich
     * und Blickrichtung. Steht noch kein reifer Klarwert, sagt das Werkzeug genau das - und
     * nicht eine erfundene Bewoelkung.
     *
     * @return array<string,mixed>|null
     */
    private function messeHimmel(int $mid, float $x, float $y, float $w, float $h): ?array
    {
        if ($mid <= 0 || !@IPS_MediaExists($mid)) {
            return null;
        }
        if ($w < 5.0 || $h < 5.0) {
            return null;
        }
        $m = CameraVision::himmelMessen(
            base64_decode((string) @IPS_GetMediaContent($mid)),
            ['x' => $x / 100, 'y' => $y / 100, 'w' => $w / 100, 'h' => $h / 100]);
        if ($m === null) {
            return null;
        }
        $hoehe = Meteo::sonnenhoehe($this->ReadPropertyFloat('Lat'), $this->ReadPropertyFloat('Lon'));
        $fach  = CameraVision::himmelFach($hoehe);
        $stand = $this->attrJson('SkyBase')[$mid . $fach] ?? null;
        $u = CameraVision::himmelWolken($m, is_array($stand) ? $stand : null);
        $m['flaeche'] = round(($w / 100) * ($h / 100), 3);
        $m['fach']    = $fach;
        $m['sonne']   = round($hoehe, 1);
        $m['klar']    = is_array($stand) ? round((float) $stand['rb'], 3) : null;
        $m['gelernt'] = is_array($stand) ? (int) ($stand['n'] ?? 0) : 0;
        $m['wolken']  = $u === null ? null : round($u['wert'] * 100, 1);
        $m['modell']  = $this->modellWolken();
        return $m;
    }

    /**
     * Feld uebernehmen.
     *
     * Der gelernte Klarwert DIESER Kamera faellt dabei weg - er gehoert zum alten
     * Feld. Wer ihn stehen liesse, vergliche die Kontrastdichte eines Zauns mit der
     * einer Wiese und bekaeme am naechsten klaren Morgen "Sicht eingeschraenkt".
     */
    public function MessfeldSetzen(int $MediaID, float $X, float $Y, float $W, float $H,
                                   string $Feld = 'sicht'): string
    {
        $himmel = ($Feld === 'himmel');
        $entfernt = $himmel && ($W < 5.0 || $H < 5.0);
        $cams = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($cams)) {
            return json_encode(['ok' => false, 'fehler' => 'keine Kameraliste']);
        }
        $gefunden = false;
        foreach ($cams as &$c) {
            if ((int) ($c['MediaID'] ?? 0) !== $MediaID) {
                continue;
            }
            if ($himmel) {
                // Breite oder Hoehe unter 5 heisst ausdruecklich "kein Himmelsfeld": die
                // Kamera nimmt dann an der Bewoelkung nicht teil. Das ist eine gueltige
                // Einstellung und kein Fehler - vier der acht Kameras sehen keinen Himmel.
                $aus = ($W < 5.0 || $H < 5.0);
                $c['SX'] = $aus ? 0 : (int) round(max(0, min(95, $X)));
                $c['SY'] = $aus ? 0 : (int) round(max(0, min(95, $Y)));
                $c['SW'] = $aus ? 0 : (int) round(max(5, min(100, $W)));
                $c['SH'] = $aus ? 0 : (int) round(max(5, min(100, $H)));
            } else {
                $c['X'] = (int) round(max(0, min(95, $X)));
                $c['Y'] = (int) round(max(0, min(95, $Y)));
                $c['W'] = (int) round(max(5, min(100, $W)));
                $c['H'] = (int) round(max(5, min(100, $H)));
            }
            $gefunden = true;
        }
        unset($c);
        if (!$gefunden) {
            return json_encode(['ok' => false, 'fehler' => 'Kamera steht nicht in der Liste']);
        }
        // Der gelernte Klarwert gehoert zum ALTEN Feld und faellt weg - bei beiden Feldern
        // aus demselben Grund. Wer ihn stehen liesse, verglich die Kontrastdichte eines
        // Zauns mit der einer Wiese, beziehungsweise die Himmelsfarbe mit der eines Dachs.
        if ($himmel) {
            $hb = $this->attrJson('SkyBase');
            foreach (array_keys($hb) as $k) {
                if (str_starts_with((string) $k, (string) $MediaID)) {
                    unset($hb[$k]);
                }
            }
            $this->attrJsonSchreiben('SkyBase', $hb);
        } else {
            $base = json_decode($this->ReadAttributeString('CamBase'), true);
            if (is_array($base)) {
                unset($base[$MediaID . 'd'], $base[$MediaID . 'n']);
                $this->WriteAttributeString('CamBase', json_encode($base));
            }
        }
        IPS_SetProperty($this->InstanceID, 'Cameras', json_encode(array_values($cams)));
        IPS_ApplyChanges($this->InstanceID);
        return json_encode(['ok' => true, 'hinweis' => $entfernt
            ? 'Himmelsfeld entfernt – diese Kamera zählt bei der Bewölkung nicht mehr mit'
            : ($himmel ? 'Himmelsfeld gesetzt, gelernte Klarwerte dieser Kamera verworfen'
                       : 'Sichtfeld gesetzt, Klarwert dieser Kamera verworfen')],
            JSON_UNESCAPED_UNICODE);
    }

    /**
     * Nimmt diese Kamera an der SICHTMESSUNG teil?
     *
     * Getrennt vom Aktiv-Schalter: "stillgelegt" heisst, die Kamera zaehlt nirgends mit,
     * "keine Sicht" heisst, sie ist eine reine Himmelskamera. Genau das braucht man fuer
     * Blickrichtungen, die viel Himmel und wenig feste Struktur zeigen - sie sollen die
     * Bewoelkung tragen, ohne den Sicht-Median zu verschieben, an dem die Nebelstufe haengt.
     */
    public function KameraSicht(int $MediaID, bool $An): string
    {
        $cams = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($cams)) {
            return json_encode(['ok' => false, 'fehler' => 'keine Kameraliste']);
        }
        $gefunden = false;
        foreach ($cams as &$c) {
            if ((int) ($c['MediaID'] ?? 0) === $MediaID) {
                $c['UseSight'] = $An;
                $gefunden = true;
            }
        }
        unset($c);
        if (!$gefunden) {
            return json_encode(['ok' => false, 'fehler' => 'Kamera steht nicht in der Liste']);
        }
        IPS_SetProperty($this->InstanceID, 'Cameras', json_encode(array_values($cams)));
        IPS_ApplyChanges($this->InstanceID);
        return json_encode(['ok' => true, 'hinweis' => $An
            ? 'zählt wieder bei der Sicht mit' : 'zählt nur noch bei der Bewölkung'],
            JSON_UNESCAPED_UNICODE);
    }

    /**
     * Felder vorschlagen: Kandidaten durchmessen und die besten zurueckgeben.
     *
     * Bewertet wird, was ein Nebelfuehler koennen muss - NICHT, was huebsch aussieht:
     *   Dunkelkanal niedrig   Abstand zur Nebelschwelle; der Himmel verspielt ihn
     *   Kontrastdichte hoch   feste Struktur, an der ein Kontrastverlust auffaellt
     *   hell genug            unter 60 ist jede Aussage geraten
     *   grosszuegig           ein grosses Feld ist unempfindlicher gegen einen Ast im Wind
     *
     * Der Vorschlag taugt nur bei TAGESLICHT. Nachts ist der Dunkelkanal ueberall
     * niedrig, und das Ergebnis waere ein Feld, das tagsueber in den Himmel zeigt.
     */
    public function MessfeldVorschlag(int $MediaID, string $Feld = 'sicht'): string
    {
        if ($Feld === 'himmel') {
            return $this->himmelVorschlag($MediaID);
        }
        $sonne = Meteo::sonnenhoehe($this->ReadPropertyFloat('Lat'), $this->ReadPropertyFloat('Lon'));
        $kand = [];
        foreach ([0.0, 0.15, 0.3, 0.45] as $x) {
            foreach ([0.0, 0.2, 0.35, 0.5] as $y) {
                foreach ([0.35, 0.5, 0.7, 1.0] as $w) {
                    foreach ([0.3, 0.45, 0.65] as $h) {
                        if ($x + $w > 1.0001 || $y + $h > 1.0001) {
                            continue;
                        }
                        $m = $this->messeFeld($MediaID, $x * 100, $y * 100, $w * 100, $h * 100);
                        if ($m === null) {
                            continue;
                        }
                        $kand[] = ['x' => round($x * 100), 'y' => round($y * 100),
                                   'w' => round($w * 100), 'h' => round($h * 100),
                                   'note' => $this->note($m), 'messung' => $m];
                    }
                }
            }
        }
        usort($kand, static fn(array $a, array $b): int => $b['note'] <=> $a['note']);
        return json_encode(['ok' => true, 'tageslicht' => $sonne >= self::CAM_SUN_MIN,
                            'sonne' => round($sonne, 1),
                            'vorschlaege' => array_slice($kand, 0, 5),
                            'geprueft' => count($kand)], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Himmelsfelder vorschlagen.
     *
     * Bewertet wird, was ein Bewoelkungsfuehler koennen muss - und das ist fast das
     * GEGENTEIL dessen, was einen Nebelfuehler ausmacht:
     *   blau            niedriges Rot/Blau. Klarer Himmel liegt bei 0,70 bis 0,85, ein
     *                   Dach, eine Wand oder eine Wiese bei 1,0 und darueber.
     *   verwertbar      hoher Anteil an Pixeln, die hell genug fuer Himmel und nicht
     *                   ausgebrannt sind. Ein Feld voller Aeste faellt hier durch.
     *   nicht ausgebrannt   steht die Sonne im Ausschnitt, sagt er nichts.
     *   grosszuegig     ein grosses Feld ist unempfindlicher gegen einen Ast im Wind.
     *
     * Der Vorschlag taugt nur bei TAGESLICHT und KLAREM Himmel. Nachts liefert die Kamera
     * ein Infrarotbild, in dem Rot durch Blau ueberall exakt 1,00 ist; bei Bedeckung ist
     * echter Himmel genauso grau wie ein Dach, und der Vorschlag zeigte auf das Dach.
     * Beides wird gemeldet, statt still ein schlechtes Feld anzubieten.
     */
    private function himmelVorschlag(int $MediaID): string
    {
        $sonne = Meteo::sonnenhoehe($this->ReadPropertyFloat('Lat'), $this->ReadPropertyFloat('Lon'));
        $modell = $this->modellWolken();
        $kand = [];
        // Das Raster ist bewusst grob: jeder Kandidat kostet eine vollstaendige
        // Bilddecodierung, und ein 2688x1512-Bild braucht dafuer rund 30 ms. Ein feineres
        // Raster mit 384 Kandidaten laege bei ueber zehn Sekunden - fuer einen Knopfdruck
        // zu lang. Rund hundert Kandidaten sind die Groessenordnung, die der Vorschlag
        // fuer das Sichtfeld seit jeher misst.
        //
        // Der Himmel steht OBEN: die y-Werte gehen nur bis knapp ueber ein Fuenftel, und
        // die Hoehen bleiben flach. Ein Feld, das bis zur Bildmitte reicht, faengt sich
        // Dach und Baumkronen ein und faellt am Anteil verwertbarer Pixel ohnehin durch.
        foreach ([0.0, 0.2, 0.4, 0.6, 0.75] as $x) {
            foreach ([0.0, 0.1, 0.2] as $y) {
                foreach ([0.2, 0.35, 0.55] as $w) {
                    foreach ([0.1, 0.18, 0.28] as $h) {
                        if ($x + $w > 1.0001 || $y + $h > 1.0001) {
                            continue;
                        }
                        $m = $this->messeHimmel($MediaID, $x * 100, $y * 100, $w * 100, $h * 100);
                        if ($m === null) {
                            continue;
                        }
                        $kand[] = ['x' => (int) round($x * 100), 'y' => (int) round($y * 100),
                                   'w' => (int) round($w * 100), 'h' => (int) round($h * 100),
                                   'note' => $this->himmelNote($m), 'messung' => $m];
                    }
                }
            }
        }
        usort($kand, static fn(array $a, array $b): int => $b['note'] <=> $a['note']);
        return json_encode(['ok' => true, 'feld' => 'himmel',
                            'tageslicht' => $sonne >= 3.0,
                            'klar' => $modell !== null && $modell <= self::LERN_KLAR_PCT,
                            'modell' => $modell,
                            'sonne' => round($sonne, 1),
                            'vorschlaege' => array_slice($kand, 0, 5),
                            'geprueft' => count($kand)], JSON_UNESCAPED_UNICODE);
    }

    /** Note eines Feldes als Bewoelkungsfuehler, 0..100. */
    private function himmelNote(array $m): int
    {
        $t = CameraVision::himmelSchwellen();
        // Infrarot oder ausgebrannt: das Feld sagt gar nichts, egal wie gut der Rest aussieht.
        if ((float) $m['grau'] > $t['maxGrau'] || (float) $m['weiss'] > $t['maxWeiss']
            || (float) $m['hell'] < $t['minHell']) {
            return 0;
        }
        $blau    = max(0.0, min(1.0, (1.05 - (float) $m['median']) / 0.35));
        $anteil  = max(0.0, min(1.0, (float) $m['anteil'] / 100.0));
        $weiss   = max(0.0, min(1.0, 1.0 - (float) $m['weiss'] / max(1.0, $t['maxWeiss'])));
        $flaeche = max(0.0, min(1.0, (float) ($m['flaeche'] ?? 0) / 0.15));
        return (int) round(100 * (0.40 * $blau + 0.30 * $anteil + 0.18 * $weiss + 0.12 * $flaeche));
    }

    /** Note eines Feldes als Nebelfuehler, 0..100. */
    private function note(array $m): int
    {
        $s = CameraVision::schwellen();
        $dunkel  = max(0.0, min(1.0, 1.0 - ((float) $m['dunkel'] - $s['dkKlar']) / max(1.0, $s['dkNebel'] - $s['dkKlar'])));
        $dichte  = max(0.0, min(1.0, (float) $m['dichte'] / max(0.001, $s['konKlar'] * 2)));
        $hell    = ((float) $m['helligkeit'] >= $s['minHell']) ? 1.0 : 0.0;
        $flaeche = max(0.0, min(1.0, (float) ($m['anteil'] ?? 0) / 0.5));
        return (int) round(100 * (0.45 * $dunkel + 0.35 * $dichte + 0.12 * $hell + 0.08 * $flaeche));
    }

    /** @return array<string,mixed>|null */
    private function messeFeld(int $mid, float $x, float $y, float $w, float $h): ?array
    {
        if ($mid <= 0 || !@IPS_MediaExists($mid)) {
            return null;
        }
        $bin = base64_decode((string) @IPS_GetMediaContent($mid));
        $roi = $this->roi(['X' => $x, 'Y' => $y, 'W' => $w, 'H' => $h]);
        $m = CameraVision::messen($bin, $roi);
        if ($m === null) {
            return null;
        }
        $m['anteil'] = round(($w / 100) * ($h / 100), 3);
        return $m;
    }

    public function TestRun(): string
    {
        $t0 = microtime(true);
        [$o, $herkunft] = $this->zusammenfuehren();
        $lat = $this->ReadPropertyFloat('Lat');
        $lon = $this->ReadPropertyFloat('Lon');
        $nacht = Meteo::sonnenhoehe($lat, $lon) < -0.833;
        $kam = $this->kameras($nacht, Meteo::sonnenhoehe($lat, $lon), $this->modellWolken());
        $neb = WE::nebel($o, $this->hoehenwerte($lat, $lon), $kam['sicht']);
        $gew = WE::gewitter($o, $this->ring());
        $hoeheD = Meteo::sonnenhoehe($lat, $lon);
        $wol = WE::bewoelkung($o, $lat, $lon, null, $kam['wolken'] ?? null,
                              $this->strahlungFaktor($hoeheD, $o->num('radiationWm2'), $this->modellWolken()),
                              $this->modellWolken());
        $ns  = WE::niederschlag($o);

        $z = sprintf("Dauer: %d ms\n\nZUSAMMENGEFÜHRT\n", (int) round((microtime(true) - $t0) * 1000));
        foreach ($o->idents() as $id) {
            [$label, $einheit] = Observation::QUANTITIES[$id];
            $z .= sprintf("  %-16s %10s %-6s  %4d s alt   aus: %s\n",
                          $id, (string) $o->get($id), $einheit, $o->age($id), $herkunft[$id] ?? '?');
        }
        $z .= "\nKAMERAS\n";
        foreach ($kam['liste'] as $c) {
            if (isset($c['fehler'])) {
                $z .= sprintf("  %-16s %s\n", $c['name'], $c['fehler']);
                continue;
            }
            $z .= $c['sicht'] === null
                ? sprintf("  %-16s reine Himmelskamera, keine Sichtmessung\n", $c['name'])
                : sprintf("  %-16s Dichte %7s von %7s  =  Sicht %3d %%   (Kontrast %s, Helligkeit %s, %s)\n",
                          $c['name'], $c['dichte'], $c['klarwert'], $c['sicht'],
                          $c['kontrast'], $c['helligkeit'], $c['zeit']);
            $z .= sprintf("  %-16s Himmel: %s\n", '',
                      $c['wolken'] === null
                          ? ('keine Aussage' . (($c['wolkenText'] ?? '') !== '' ? ' - ' . $c['wolkenText'] : ' - kein Himmelsfeld gezogen'))
                          : sprintf('%d %% bewölkt   (%s)', $c['wolken'], $c['wolkenText']));
        }
        return $z . sprintf("\nNebel      %d   %s\nGewitter   %d   %s\nBewölkung  %s   %s\n"
                          . "Nieders.   %s\nWetterlage %s\n",
            $neb['stufe'], $neb['text'], $gew['stufe'], $gew['text'],
            $wol['pct'] === null ? '--' : $wol['pct'] . ' %', $wol['quelle'], $ns['text'],
            WE::wetterlage($gew['stufe'], $ns, $neb['stufe'], $wol['pct']))
             . sprintf("Quelle     %s\nModell     %s\n", $wol['weg'],
                 $this->modellWolken() === null ? '--' : $this->modellWolken() . ' %');
    }

    // ==================================================================
    // Zusammenfuehrung
    // ==================================================================

    /** @return array{0:Observation,1:array<string,string>} Beobachtung und Herkunft je Groesse */
    private function zusammenfuehren(): array
    {
        $o = new Observation('station');
        $herkunft = [];
        foreach ($this->quellen() as $q) {
            $iid = (int) $q['InstanceID'];
            if (!@IPS_InstanceExists($iid)) {
                continue;
            }
            try {
                $roh = $this->istListener($iid) ? @WXT_GetObservation($iid) : @WXS_GetObservation($iid);
            } catch (\Throwable $e) {
                continue;
            }
            $teil = $this->ausJson(is_string($roh) ? $roh : '');
            if ($teil === null) {
                continue;
            }
            $vorher = $o->idents();
            // Rangfolge schlaegt Frische: uebernommen wird nur, was noch fehlt.
            $o->merge($teil, max(30, (int) ($q['MaxAge'] ?? 900)), PHP_INT_MAX);
            foreach (array_diff($o->idents(), $vorher) as $neu) {
                $herkunft[$neu] = IPS_GetName($iid);
            }
        }
        return [$o, $herkunft];
    }

    /** Ist die Instanz ein Empfaenger (liefert selbst eine Beobachtung) statt einer Quelle? */
    private function istListener(int $iid): bool
    {
        return @IPS_InstanceExists($iid)
            && IPS_GetInstance($iid)['ModuleInfo']['ModuleID'] === self::GUID_LISTENER;
    }

    /** @return array<int,array<string,mixed>> aktive Quellen in Rangfolge */
    private function quellen(): array
    {
        $l = json_decode($this->ReadPropertyString('Sources'), true);
        if (!is_array($l)) {
            return [];
        }
        $l = array_values(array_filter($l, static fn($q) => !empty($q['Enabled']) && (int) ($q['InstanceID'] ?? 0) > 0));
        usort($l, static fn($a, $b) => ((int) ($a['Priority'] ?? 99)) <=> ((int) ($b['Priority'] ?? 99)));
        return $l;
    }

    private function ausJson(string $json): ?Observation
    {
        $a = json_decode($json, true);
        if (!is_array($a)) {
            return null;
        }
        $o = new Observation((string) ($a['_quelle'] ?? ''));
        foreach ($a as $k => $v) {
            if ($k[0] === '_' || !is_array($v) || !isset($v['wert'])) {
                continue;
            }
            $o->set($k, $v['wert'], time() - (int) ($v['alter_s'] ?? 0));
        }
        return $o;
    }

    // ==================================================================
    // Kameras
    // ==================================================================

    /** @return array{liste:array,sicht:float|null,schnee:bool|null} */
    /**
     * Bodenfeuchte aus den gebundenen Fuehlern.
     *
     * Es wird NICHT auf eine gemeinsame Prozentzahl umgerechnet. Eine Saugspannung in
     * Zentibar und ein kapazitiver Prozentwert messen verschiedene Dinge; eine Umrechnung
     * waere eine erfundene Zahl. Stattdessen bekommt jeder Fuehler die Stufe nach SEINER
     * Skala, und die Zone gibt die TROCKENSTE davon wieder - fuer die Bewaesserung zaehlt der
     * durstigste Bereich, nicht der Mittelwert.
     *
     * Veraltete Werte werden ausdruecklich als solche gemeldet statt stillschweigend
     * mitgerechnet: im Bestand lagen Fuehler, deren letzter Wert Monate alt war.
     *
     * @return array{stufe:int,text:string,liste:array}
     */
    private function bodenfeuchte(): array
    {
        $cfg = json_decode($this->ReadPropertyString('SoilSensors'), true);
        if (!is_array($cfg) || $cfg === []) {
            return ['stufe' => 6, 'text' => 'kein Fühler gebunden', 'liste' => []];
        }
        $liste = []; $max = -1; $alt = [];
        foreach ($cfg as $c) {
            if (!is_array($c) || (isset($c['Enabled']) && !$c['Enabled'])) { continue; }
            $vid = (int) ($c['VarID'] ?? 0);
            $name = (string) ($c['Name'] ?? ('#' . $vid));
            if ($vid <= 0 || !@IPS_VariableExists($vid)) {
                $liste[] = ['name' => $name, 'fehler' => 'Variable gibt es nicht'];
                continue;
            }
            $wert = @GetValue($vid);
            if (!is_numeric($wert)) {
                $liste[] = ['name' => $name, 'fehler' => 'kein Zahlenwert'];
                continue;
            }
            $wert = (float) $wert;
            $typ  = (int) ($c['Typ'] ?? 0);
            $alterS = time() - (int) IPS_GetVariable($vid)['VariableUpdated'];
            if ($typ === 1) {
                // Zentibar: 0-10 gesaettigt, bis 30 feucht, bis 60 maessig, bis 100 trocken,
                // darueber sehr trocken (uebliche Bewaesserungsschwellen fuer Watermark-Fuehler).
                // Zentibar (Saugspannung), Staffel fuer Watermark-Fuehler:
                // <10 gesaettigt, <30 feucht, <60 maessig, <100 trocken, <150 sehr trocken,
                // darueber staubtrocken (Pflanzen im Dauerstress).
                $st = ($wert < 10) ? 0 : (($wert < 30) ? 1 : (($wert < 60) ? 2
                    : (($wert < 100) ? 3 : (($wert < 150) ? 4 : 5))));
                $txt = sprintf('%.0f cb', $wert);
            } else {
                // Prozent kapazitiv: hoch = feucht.
                // Prozent (kapazitiv): hoch = feucht, gespiegelte Staffel zur Saugspannung.
                $st = ($wert >= 85) ? 0 : (($wert >= 65) ? 1 : (($wert >= 45) ? 2
                    : (($wert >= 30) ? 3 : (($wert >= 15) ? 4 : 5))));
                $txt = sprintf('%.0f %%', $wert);
            }
            $veraltet = $alterS > 86400;
            // pct und farbe sind reine ANZEIGEwerte fuer die Balkendarstellung: der Balken
            // braucht eine gemeinsame 0..100-Achse, obwohl die Fuehler verschiedene Skalen
            // messen. Die ZAHL daneben bleibt immer der echte Messwert in seiner Einheit -
            // der Balken vergleicht, die Zahl misst.
            $pct = ($typ === 1)
                ? max(0.0, min(100.0, 100.0 * (1.0 - min(200.0, max(0.0, $wert)) / 200.0)))
                : max(0.0, min(100.0, $wert));
            $farben = ['info', 'u-stufe1', 'u-stufe2', 'u-stufe3', 'u-stufe4', 'u-stufe5'];
            $liste[] = ['name' => $name, 'wert' => $wert, 'typ' => $typ, 'stufe' => $st,
                        'text' => $txt, 'alterS' => $alterS, 'veraltet' => $veraltet,
                        'pct' => round($pct, 1), 'farbe' => $farben[$st] ?? 'muted'];
            if ($veraltet) { $alt[] = $name; continue; }      // veraltete Fuehler nicht werten
            if ($st > $max) { $max = $st; }
        }
        if ($max < 0) {
            return ['stufe' => 6, 'liste' => $liste,
                    'text' => $alt === [] ? 'kein brauchbarer Fühler'
                                          : 'alle Fühler veraltet: ' . implode(', ', $alt)];
        }
        $teile = [];
        foreach ($liste as $l) {
            if (isset($l['fehler'])) { $teile[] = $l['name'] . ': ' . $l['fehler']; continue; }
            $teile[] = $l['name'] . ' ' . $l['text'] . ($l['veraltet'] ? ' (veraltet!)' : '');
        }
        return ['stufe' => $max, 'liste' => $liste, 'text' => implode(' | ', $teile)];
    }

    /**
     * Sicht glaetten, bevor daraus eine Stufe wird.
     *
     * Die Kamerasicht schwankt naturgemaess: Belichtungsautomatik, ziehende Wolken, ein
     * Fahrzeug im Bild. Am 26.08.2026 pendelte sie zwischen 43 und 55 % - also genau um die
     * Schwelle "eingeschraenkt ab 45 %". Die Folge war schlimmer als Flattern: die
     * Entprellung verlangt 300 s Ruhe, bekam sie nie, und die Anzeige blieb auf der ALTEN,
     * hoeheren Stufe stehen. Ein Wert, der um eine Schwelle pendelt, wurde damit nie
     * bestaetigt.
     *
     * Median statt Mittelwert: ein einzelner Ausreisser (Auto im Bild, Sonne in der Linse)
     * verschiebt ihn nicht. Gespeichert wird im Puffer, nicht in einem Attribut - ein
     * RegisterAttributeString legt fuer BESTEHENDE Instanzen nichts nach.
     *
     * Nur ECHTE Aenderungen wandern in den Ring: die Bildauswertung hat ihren eigenen Takt
     * und liefert dazwischen den letzten Wert erneut; ohne diese Bedingung waere der Ring
     * binnen Sekunden mit demselben Wert gefuellt und die Glaettung wirkungslos.
     */
    private function sichtGeglaettet(?float $roh): ?float
    {
        if ($roh === null) {
            return null;
        }
        $ring = json_decode((string) $this->GetBuffer('SightRing'), true);
        if (!is_array($ring)) {
            $ring = [];
        }
        if (!$ring || (float) end($ring) !== (float) $roh) {
            $ring[] = (float) $roh;
        }
        $ring = array_slice($ring, -self::SIGHT_RING);
        $this->SetBuffer('SightRing', json_encode($ring));
        $sortiert = $ring;
        sort($sortiert);
        $n = count($sortiert);
        return ($n % 2) ? $sortiert[intdiv($n, 2)]
                        : ($sortiert[$n / 2 - 1] + $sortiert[$n / 2]) / 2.0;
    }

    /**
     * Bewoelkung glaetten — Median der letzten Messungen, wie bei der Sichtweite.
     *
     * Eine einzelne Wolke, die vor die Sonne zieht, ist Wetter; eine einzelne AUFNAHME davon
     * ist Rauschen. Ohne Glaettung wechselt die Wetterlage im Minutentakt zwischen "klar" und
     * "bedeckt", weil die Textstufen an festen Grenzen haengen (1/8, 3/8, 5/8, 7/8) und jeder
     * Messwert einmal darueber und einmal darunter liegt.
     *
     * Fuenf Messungen, nicht mehr: eine echte Aufzugsbewoelkung haelt laenger als fuenf
     * Minuten an und wird durchgelassen. Der Ring liegt im Puffer, nicht in einem Attribut -
     * er soll einen Neustart NICHT ueberdauern, sonst glaettet die Anlage gegen Werte von
     * vorgestern.
     */
    private function wolkenGeglaettet(?float $roh): ?float
    {
        if ($roh === null) {
            return null;
        }
        $ring = json_decode((string) $this->GetBuffer('CloudRing'), true);
        if (!is_array($ring)) {
            $ring = [];
        }
        $ring[] = (float) $roh;
        $ring = array_slice($ring, -self::CLOUD_RING);
        $this->SetBuffer('CloudRing', json_encode($ring));
        sort($ring);
        $n = count($ring);
        return ($n % 2) ? $ring[intdiv($n, 2)] : ($ring[$n / 2 - 1] + $ring[$n / 2]) / 2.0;
    }

    /**
     * Bewoelkung des Vorhersagemodells fuer die LAUFENDE Stunde, in Prozent.
     *
     * Die Rueckfallebene fuer Nacht und Daemmerung. Grob - ein Modellpunkt steht fuer ein
     * paar Kilometer und eine ganze Stunde - aber immer da, und das ist genau die Eigenschaft,
     * die Kamera und Strahlung fehlt. Die Vorhersage wird ohnehin schon geholt und roh
     * abgelegt; hier wird nur gelesen, kein zusaetzlicher Abruf.
     */
    private function modellWolken(): ?float
    {
        $j = json_decode((string) $this->GetValue('Forecast'), true);
        if (!is_array($j) || !isset($j['hourly']['time'], $j['hourly']['cloud_cover'])) {
            return null;
        }
        $i = array_search(date('Y-m-d\\TH:00'), $j['hourly']['time'], true);
        if ($i === false || !isset($j['hourly']['cloud_cover'][$i])) {
            return null;
        }
        $v = $j['hourly']['cloud_cover'][$i];
        return $v === null ? null : max(0.0, min(100.0, (float) $v));
    }

    /**
     * Gelernter Klarhimmel-Faktor der Strahlungsmessung, je Fach der Sonnenhoehe.
     *
     * Derselbe Gedanke wie beim Sicht-Klarwert der Kameras, nur in die andere Richtung: dort
     * die BESTE je gesehene Kontrastdichte, hier das GROESSTE je gesehene Verhaeltnis von
     * gemessener zu theoretischer Strahlung. Beides heisst "so sieht es hier aus, wenn nichts
     * stoert", und beides muss gemessen werden, weil keine Formel den Standort kennt.
     *
     * Zwei Sicherungen:
     *   - Ein einzelner Wert hebt den Faktor um hoechstens LERN_SCHRITT. Wolkenraender
     *     buendeln Licht und lassen die Strahlung kurz UEBER den klaren Wert steigen (bis
     *     +20 %); ohne die Schrittgrenze wuerde ein solcher Blitz den Bezug fuer Wochen
     *     verderben. Ein wirklich klarer Tag liefert Dutzende Messungen je Fach und erreicht
     *     die richtige Hoehe binnen einer halben Stunde.
     *   - Langsames Vergessen. Ein verschmutztes oder gealtertes Sensorglas liefert dauerhaft
     *     weniger; ohne Vergessen bliebe der Faktor auf dem Stand des Neuzustands stehen und
     *     die Anlage meldete jeden klaren Tag als leicht bewoelkt.
     */
    private function strahlungFaktor(float $hoehe, ?float $rad, ?float $modell = null): ?float
    {
        if ($hoehe <= Meteo::STRAHLUNG_MIN_HOEHE) {
            return null;
        }
        $fach  = Meteo::strahlungFach($hoehe);
        $stand = $this->attrJson('SunBase');
        $e     = isset($stand[$fach]) && is_array($stand[$fach]) ? $stand[$fach] : null;
        $wert  = $e === null ? 1.0 : (float) $e['f'];
        $ts    = $e === null ? time() : (int) $e['ts'];

        $tage = max(0.0, (time() - $ts) / 86400.0);
        $wert = max(self::LERN_MIN, $wert - self::LERN_VERGESSEN * $tage);

        // Auch hier gilt: gelernt wird nur, wenn eine unabhaengige Quelle klaren Himmel
        // belegt. Der Hoechstwert allein taugte nicht - Wolkenraender buendeln Licht und
        // treiben die Strahlung kurz UEBER den klaren Wert.
        $klar = Meteo::klarhimmel($hoehe);
        if ($rad !== null && $klar > 40.0 && $modell !== null && $modell <= self::LERN_KLAR_PCT) {
            $r = $rad / $klar;
            if ($r > $wert) {
                $wert = min($r, $wert + self::LERN_SCHRITT);
            }
            $wert = max(self::LERN_MIN, min(self::LERN_MAX, $wert));
            $stand[$fach] = ['f' => round($wert, 4), 'ts' => time()];
            $this->attrJsonSchreiben('SunBase', $stand);
            $e = $stand[$fach];
        }
        // NULL heisst "fuer dieses Fach ist noch nichts gelernt" - und genau das muss die
        // Auswertung wissen, statt eine 1,0 als Kennlinie zu behandeln, die sie nicht ist.
        return $e === null ? null : $wert;
    }

    /**
     * Attribut als Feld lesen - vertraegt Instanzen, die es noch nicht haben.
     *
     * Ein neu hinzugefuegtes RegisterAttributeString legt fuer BESTEHENDE Instanzen nichts
     * nach: ReadAttributeString liefert dann FALSE und wirft dabei nicht. Genau daran brach
     * am 08.09.2026 der erste Lauf nach dem Reload ab - json_decode(false) ist unter PHP 8
     * ein TypeError, und der riss die ganze Auswertung mit. Deshalb wird hier auf den Typ
     * geprueft und nicht nur auf eine Ausnahme gewartet.
     */
    private function attrJson(string $name): array
    {
        try {
            $roh = $this->ReadAttributeString($name);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_string($roh) || $roh === '') {
            return [];
        }
        $v = json_decode($roh, true);
        return is_array($v) ? $v : [];
    }

    private function attrJsonSchreiben(string $name, array $wert): void
    {
        try {
            $this->WriteAttributeString($name, json_encode($wert));
        } catch (\Throwable $e) {
            // Instanz kennt das Attribut noch nicht - dann wird eben nichts gelernt,
            // statt den ganzen Lauf an einer Nebensache scheitern zu lassen.
        }
    }

    private function kameras(bool $nacht, float $hoehe = 0.0, ?float $modell = null): array
    {
        if (!$this->ReadPropertyBoolean('UseCameras')) {
            return ['liste' => [], 'sicht' => null, 'schnee' => null, 'wolken' => null];
        }
        // Bildauswertung ist der teuerste Teil des Laufs: Bild holen, entpacken, Kontrast je
        // Ausschnitt rechnen. Seit die Station auf jede Quell-Aktualisierung reagiert, liefe das
        // im Sekundentakt - fuer eine Groesse, die sich in Minuten aendert. Also eigener Takt,
        // und dazwischen das letzte Ergebnis.
        $takt = max(0, $this->ReadPropertyInteger('CameraSeconds'));
        $c = json_decode($this->ReadAttributeString('CamCache'), true);
        if ($takt > 0 && is_array($c) && isset($c['ts'], $c['res'])
            && (time() - (int) $c['ts']) < $takt && is_array($c['res'])) {
            return $c['res'];
        }
        $cams = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($cams) || $cams === []) {
            return ['liste' => [], 'sicht' => null, 'schnee' => null, 'wolken' => null];
        }
        if (!CameraVision::verfuegbar()) {
            return ['liste' => [['fehler' => 'Bildauswertung nicht möglich: PHP ohne GD']],
                    'sicht' => null, 'schnee' => null, 'wolken' => null];
        }
        $base = json_decode($this->ReadAttributeString('CamBase'), true);
        if (!is_array($base)) {
            $base = [];
        }
        $himBase = $this->attrJson('SkyBase');
        $slot = $nacht ? 'n' : 'd';
        $fach = CameraVision::himmelFach($hoehe);

        $liste = []; $quoten = []; $schnee = null; $wolken = []; $wolkenText = [];
        foreach ($cams as $c) {
            $mid = (int) ($c['MediaID'] ?? 0);
            if ($mid <= 0 || empty($c['Enabled'])) {
                continue;
            }
            $name = (string) ($c['Name'] ?? '');
            if ($name === '') {
                $name = @IPS_ObjectExists($mid) ? IPS_GetName($mid) : ('#' . $mid);
            }
            if (!@IPS_MediaExists($mid)) {
                $liste[] = ['id' => $mid, 'name' => $name, 'fehler' => 'Medienobjekt gibt es nicht'];
                continue;
            }
            // ZWEI GETRENNTE AUFGABEN, ZWEI GETRENNTE BLICKRICHTUNGEN.
            //
            // Nebel misst man am GELAENDE: Zaun, Hecke, Hauskante - dort faellt der Kontrast,
            // wenn die Sicht schlechter wird. Bewoelkung misst man am HIMMEL. Kaum eine
            // Kamera taugt fuer beides: von acht Kameras der Anlage sehen vier ueberhaupt
            // keinen Himmel (sie blicken nach unten oder ins Laub), und die mit dem freiesten
            // Himmelsblick zeigt zu wenig Struktur fuer eine Sichtmessung.
            //
            // Deshalb sagt jede Zeile getrennt, woran sie teilnimmt: das Haekchen "Sicht" an
            // der Nebelmessung, ein gezogenes Himmelsfeld an der Bewoelkung. Eine Kamera darf
            // beides, eines davon - oder als reine Himmelskamera dazukommen, ohne den
            // Sicht-Median zu veraendern, der die Nebelstufe traegt.
            $machtSicht  = !isset($c['UseSight']) || !empty($c['UseSight']);
            $hroi        = $this->himmelRoi($c);
            // Die Kamera ist ein TAGESLICHTVERFAHREN. Nachts sieht sie den Himmel gar nicht,
            // sondern das Infrarotlicht der eigenen Beleuchtung; dann traegt das Modell.
            $machtHimmel = $hroi !== null && !$nacht;
            if (!$machtSicht && !$machtHimmel) {
                continue;
            }
            $bin = base64_decode((string) @IPS_GetMediaContent($mid));

            $m = null; $s = null; $sn = null;
            if ($machtSicht) {
                $m = CameraVision::messen($bin, $this->roi($c));
                if ($m === null) {
                    $liste[] = ['id' => $mid, 'name' => $name, 'fehler' => 'kein auswertbares Bild'];
                    continue;
                }
                $k = $mid . $slot;
                $s = CameraVision::sicht($m['dichte'], (float) ($base[$k] ?? 0.0));
                $base[$k] = $s['klarwert'];
                $quoten[] = $s['sicht'];

                $sn = CameraVision::schneedecke($m, $nacht);
                if ($sn !== null) {
                    $schnee = ($schnee === null) ? $sn : ($schnee || $sn);
                }
            }

            // Himmel im Sichtfeld verdirbt den Dunkelkanal, Gelaende im Himmelsfeld verdirbt
            // das Farbverhaeltnis - deshalb zwei Ausschnitte und nicht einer.
            $hWert = null; $hText = '';
            if ($machtHimmel) {
                $hm = CameraVision::himmelMessen($bin, $hroi);
                if ($hm !== null) {
                    $hk = $mid . $fach;
                    $stand = isset($himBase[$hk]) && is_array($himBase[$hk]) ? $himBase[$hk] : null;
                    // Gelernt wird nur, wenn das Bild etwas hergibt UND das Modell klaren
                    // Himmel belegt. Ohne den zweiten Teil lernte die Anlage waehrend einer
                    // langen bedeckten Lage die Wolkendecke als Klarwert - und meldete danach
                    // nie wieder eine Wolke.
                    if ($hm['hell'] >= 45.0 && $hm['weiss'] <= 35.0 && $hm['anteil'] >= 40.0
                        && $hm['grau'] <= 80.0
                        && $modell !== null && $modell <= self::LERN_KLAR_PCT) {
                        $himBase[$hk] = CameraVision::himmelKlarwert($stand, (float) $hm['median']);
                        $stand = $himBase[$hk];
                    }
                    $w = CameraVision::himmelWolken($hm, $stand);
                    if ($w !== null) {
                        $hWert = $w['wert'] * 100.0;
                        $hText = $w['text'];
                        $wolken[] = $hWert;
                        $wolkenText[] = sprintf('%s %d %% (%s)', $name, (int) round($hWert), $hText);
                    } else {
                        $hText = $hm['hell'] < 45.0 ? 'zu dunkel'
                               : ($hm['weiss'] > 35.0 ? sprintf('überbelichtet (%.0f %% ausgebrannt)', $hm['weiss'])
                               : ($hm['grau'] > 80.0 ? sprintf('Infrarotbild (%.0f %% bitgleich grau)', $hm['grau'])
                               : ($hm['anteil'] < 40.0 ? sprintf('kein Himmel im Feld (nur %.0f %% verwertbar)', $hm['anteil'])
                               : sprintf('Klarwert erst %d von %d Messungen', (int) ($stand['n'] ?? 0), 40))));
                    }
                }
            }

            $liste[] = ['id' => $mid, 'name' => $name,
                        'kontrast' => $m['kontrast'] ?? null,
                        'dichte' => $m['dichte'] ?? null,
                        'klarwert' => $s['klarwert'] ?? null,
                        'sicht' => $s === null ? null : (int) round($s['sicht']),
                        'helligkeit' => $m['helligkeit'] ?? null,
                        'saettigung' => $m['saettigung'] ?? null,
                        'schnee' => $sn, 'zeit' => $nacht ? 'Nacht' : 'Tag',
                        'wolken' => $hWert === null ? null : (int) round($hWert),
                        'wolkenText' => $hText];
        }
        $this->WriteAttributeString('CamBase', json_encode($base));
        $this->attrJsonSchreiben('SkyBase', $himBase);

        // Sicht = MITTLERE Kamera (Median), nicht die schlechteste.
        //
        // Frueher entschied min(): eine einzige Kamera zog das Ergebnis nach unten. Am
        // 19.08.2026 meldeten die vier Kameras 66, 82, 89 und 100 % - drei sahen klar, die
        // Einfahrt nicht, und die Karte schrieb "diesig", waehrend draussen die Sonne schien.
        // Eine verschmutzte Linse, Gegenlicht oder ein veralteter Klarwert reichen fuer so
        // einen Ausreisser; echter Nebel dagegen liegt ueber ALLEN Blickrichtungen, dann
        // faellt auch der Median. Die schlechteste Kamera geht nicht verloren - sie steht in
        // der Tabelle und im Text.
        $sicht = null;
        if ($quoten !== []) {
            sort($quoten);
            $n = count($quoten);
            $sicht = ($n % 2) ? $quoten[intdiv($n, 2)]
                              : ($quoten[$n / 2 - 1] + $quoten[$n / 2]) / 2.0;
        }
        // Bewoelkung: ebenfalls der MEDIAN, aus demselben Grund wie bei der Sicht. Eine
        // einzelne Kamera kann eine Wolke voll im Bild haben, waehrend der Rest des Himmels
        // frei ist - oder umgekehrt in eine Baumkrone schauen. Echte Bedeckung liegt ueber
        // allen Blickrichtungen.
        $wolke = null;
        if ($wolken !== []) {
            sort($wolken);
            $nw = count($wolken);
            $wolke = ['pct' => ($nw % 2) ? $wolken[intdiv($nw, 2)]
                                         : ($wolken[$nw / 2 - 1] + $wolken[$nw / 2]) / 2.0,
                      'anzahl' => $nw, 'text' => implode(', ', $wolkenText)];
        }

        $res = ['liste' => $liste, 'sicht' => $sicht, 'schnee' => $schnee,
                'sichtMin' => $quoten === [] ? null : min($quoten),
                'sichtAnzahl' => count($quoten), 'wolken' => $wolke];
        $this->WriteAttributeString('CamCache', json_encode(['ts' => time(), 'res' => $res],
                                                            JSON_UNESCAPED_UNICODE));
        return $res;
    }

    /** @return array{x:float,y:float,w:float,h:float}|null Bildausschnitt in Anteilen */
    private function roi(array $c): ?array
    {
        $x = (float) ($c['X'] ?? 0); $y = (float) ($c['Y'] ?? 0);
        $w = (float) ($c['W'] ?? 100); $h = (float) ($c['H'] ?? 100);
        if ($x <= 0 && $y <= 0 && $w >= 100 && $h >= 100) {
            return null;
        }
        return ['x' => $x / 100, 'y' => $y / 100, 'w' => $w / 100, 'h' => $h / 100];
    }

    /**
     * @return array{x:float,y:float,w:float,h:float}|null Himmelsausschnitt, null = keiner gezogen
     */
    private function himmelRoi(array $c): ?array
    {
        $w = (float) ($c['SW'] ?? 0); $h = (float) ($c['SH'] ?? 0);
        if ($w < 5.0 || $h < 5.0) {
            return null;                 // kein Himmelsfeld gezogen - diese Kamera sagt nichts
        }
        return ['x' => max(0.0, (float) ($c['SX'] ?? 0)) / 100, 'y' => max(0.0, (float) ($c['SY'] ?? 0)) / 100,
                'w' => min(100.0, $w) / 100, 'h' => min(100.0, $h) / 100];
    }

    // ==================================================================
    // Kleinkram
    // ==================================================================

    /**
     * Vorhersage holen und unveraendert ablegen.
     *
     * Selten genug: ein Vorhersagemodell rechnet stuendlich, oefter zu fragen bringt dieselbe
     * Antwort. Faellt der Abruf aus, bleibt die letzte stehen — eine halbe Stunde alte
     * Vorhersage ist brauchbar, eine geleerte Variable nicht.
     */
    private function vorhersage(float $lat, float $lon): void
    {
        if (!$this->ReadPropertyBoolean('UseForecast')) {
            return;
        }
        $alter = time() - (int) $this->GetValue('ForecastAge');
        if ($alter < max(5, $this->ReadPropertyInteger('ForecastMinutes')) * 60
            && $this->GetValue('Forecast') !== '') {
            return;
        }
        $j = Forecast::fetch($lat, $lon, $this->ReadPropertyInteger('ForecastDays'));
        if ($j !== null) {
            $this->SetValue('Forecast', $j);
            $this->SetValue('ForecastAge', time());
        }
    }

    /** 850-hPa-Werte, hoechstens stuendlich neu geholt. */
    private function hoehenwerte(float $lat, float $lon): ?array
    {
        $c = json_decode($this->ReadAttributeString('Upper'), true);
        if (is_array($c) && isset($c['ts']) && (time() - (int) $c['ts']) < 3600) {
            return ['t' => (float) $c['t'], 'w' => (float) $c['w']];
        }
        $u = UpperAir::fetch($lat, $lon);
        if ($u === null) {
            return null;
        }
        $this->WriteAttributeString('Upper', json_encode($u));
        return ['t' => $u['t'], 'w' => $u['w']];
    }

    private function ring(): array
    {
        $r = json_decode($this->ReadAttributeString('StrikeRing'), true);
        return is_array($r) ? $r : [];
    }

    /**
     * Eigener Ringspeicher plus alle Einzelblitze, die die Quellen hergeben.
     *
     * Ohne das saehe die Station bei einem Takt von einer Minute nur den jeweils letzten Schlag
     * — bei einer aktiven Zelle also einen von zehn. Zugrichtung und Ankunft liessen sich aus
     * solchen Stichproben nicht bestimmen. Zusammengefuehrt wird ueber den Zeitstempel, damit
     * derselbe Blitz aus zwei Quellen nur einmal zaehlt.
     */
    private function ringMitQuellen(): array
    {
        $ring = $this->ring();
        $bekannt = [];
        foreach ($ring as $e) {
            $bekannt[(int) $e['t']] = true;
        }
        foreach ($this->quellen() as $q) {
            $iid = (int) $q['InstanceID'];
            if (!@IPS_InstanceExists($iid)) {
                continue;
            }
            try {
                $l = json_decode((string) ($this->istListener($iid)
                        ? @WXT_GetStrikes($iid) : @WXS_GetStrikes($iid)), true);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($l)) {
                continue;
            }
            foreach ($l as $b) {
                $t = (int) ($b['t'] ?? 0);
                if ($t > 0 && !isset($bekannt[$t])) {
                    $ring[] = ['t' => $t, 'd' => (float) ($b['d'] ?? 0)];
                    $bekannt[$t] = true;
                }
            }
        }
        usort($ring, static fn($a, $b) => $a['t'] <=> $b['t']);
        return $ring;
    }

    /**
     * Fortlaufender Gesamtregen ueber Jahre.
     *
     * Keine Station liefert ihn — sie kennt Tag, Monat und Jahr, und alle drei springen zum
     * Stichtag auf null. Gezaehlt wird deshalb die ZUNAHME des Tageswertes: steigt er, kommt
     * die Differenz dazu; faellt er (Mitternacht), ist der neue Tageswert selbst die Zunahme.
     *
     * Bewusst OHNE Archivabfrage. Die alte Loesung las die beiden juengsten Archivwerte und
     * bildete deren Differenz — das zaehlt doppelt, wenn das Skript oefter laeuft als
     * aufgezeichnet wird, und verliert Regen, wenn es seltener laeuft. Ein gemerkter letzter
     * Wert kennt diese Abhaengigkeit nicht.
     *
     * Der Startwert im Formular uebernimmt einen vorhandenen Zaehlerstand, damit die Reihe
     * nicht bei null neu beginnt.
     */
    private function regenGesamt(?float $tag): void
    {
        $this->summe('RainTotal', 'RainDayLast', 'RainTotalStart', $tag);
    }

    /**
     * Fortlaufender Gesamtzaehler aus einem TAGESWERT, der um Mitternacht auf null faellt.
     *
     * Gezaehlt wird die Zunahme, nicht der Stand: steigt der Tageswert, kommt die Differenz
     * dazu; faellt er (Tageswechsel), zaehlt der neue Tageswert selbst als Zunahme. Damit
     * entsteht eine Reihe, die nie faellt - genau das, was eine Zaehler-Aggregation braucht.
     *
     * Warum ueberhaupt: Tag, Monat und Jahr springen alle zurueck. Wer den Verbrauch ueber
     * einen beliebigen Zeitraum wissen will (letzte 30 Tage, Vorjahresvergleich), braucht eine
     * Reihe ohne Bruch. Regen und Verdunstung verhalten sich darin gleich.
     */
    private function summe(string $ident, string $attr, string $startProp, ?float $tag): void
    {
        if ($tag === null) {
            return;
        }
        $vorher = $this->ReadAttributeFloat($attr);
        $this->WriteAttributeFloat($attr, $tag);

        $stand = (float) $this->GetValue($ident);
        if ($stand <= 0.0) {
            $stand = (float) $this->ReadPropertyFloat($startProp);
        }
        if ($vorher < 0.0) {
            $this->SetValue($ident, round($stand, 2));   // erster Lauf: nur uebernehmen
            return;
        }
        $zu = ($tag >= $vorher) ? ($tag - $vorher) : $tag;     // sonst Tageswechsel
        if ($zu > 0.0) {
            $this->SetValue($ident, round($stand + $zu, 2));
        }
    }

    /**
     * Zustand eines einzelnen Regenmelders, oder null wenn er nichts Brauchbares sagt.
     *
     * NULL heisst "keine Aussage" und ist NICHT dasselbe wie false. Ein Melder, der seit
     * Stunden schweigt, hat nicht "trocken" gemeldet - er hat gar nichts gemeldet, und ein
     * eingefrorenes false wuerde den Regen eines lebenden Sensors ueberstimmen.
     */
    private function melder(int $id, int $maxAlter): ?bool
    {
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return null;
        }
        if ($maxAlter > 0) {
            $upd = (int) (@IPS_GetVariable($id)['VariableUpdated'] ?? 0);
            if ($upd <= 0 || (time() - $upd) > $maxAlter) {
                return null;   // stumm -> keine Stimme
            }
        }
        $v = @GetValue($id);
        if (is_bool($v))    { return $v; }
        if (is_numeric($v)) { return ((float) $v) > 0.0; }
        $t = strtolower(trim((string) $v));
        return in_array($t, ['1', 'true', 'ja', 'nass', 'regen'], true);
    }

    /**
     * Meldet einer der Regensensoren "nass"? RANGFOLGE, nicht Mehrheit.
     *
     * Der erste Sensor ist massgeblich, solange er antwortet. Erst wenn er schweigt, rueckt
     * der zweite nach. Warum nicht ODER ueber beide: die Melder sind unterschiedlich schnell
     * und unterschiedlich zuverlaessig. Der optische spricht in Sekunden an; ein beheizter
     * Flaechensensor braucht laenger und war hier am 16.08. rund viereinhalb Minuten spaeter.
     * Ein ODER wuerde beim ABTROCKNEN den langsameren entscheiden lassen - die Regenmeldung
     * bliebe dann unnoetig lange stehen.
     *
     * Der zweite Melder ist damit Ersatz, nicht Ergaenzung: er traegt genau dann, wenn der
     * erste ausfaellt, und stoert sonst nicht.
     */
    private function regenSensor(): ?bool
    {
        $maxAlter = max(0, $this->ReadPropertyInteger('RainSensorMaxAge'));
        $erster = $this->melder($this->ReadPropertyInteger('RainSensorId'), $maxAlter);
        if ($erster !== null) {
            return $erster;
        }
        return $this->melder($this->ReadPropertyInteger('RainSensor2Id'), $maxAlter);
    }

    /**
     * Sonnenschein: scheint sie gerade, und wie lange heute schon.
     *
     * Die Entscheidung lag frueher in einem Anzeigeskript, das die Schwelle in eine eigene
     * Variable rechnete und sie dort mit der Strahlung verglich. Das gehoert hierher: die
     * Sonnenhoehe rechnet dieses Modul ohnehin, die Strahlung misst es, und eine Kachel soll
     * anzeigen und nicht entscheiden.
     *
     * Die Dauer wird aufsummiert statt aus dem Archiv gerechnet: sie soll auch dann stimmen,
     * wenn jemand das Logging abschaltet. Gezaehlt wird die tatsaechlich verstrichene Zeit
     * seit der letzten Auswertung, gedeckelt auf zehn Minuten - nach einem Neustart oder einer
     * Pause darf keine Stunde Sonne entstehen, die niemand gesehen hat.
     */
    private function sonnenschein(float $hoehe, ?float $strahlung): void
    {
        $schwelle = Meteo::sonnenscheinSchwelle($hoehe);
        $this->SetValue('SunThreshold', $schwelle);

        // Ohne Strahlungsmesser gibt es keine Aussage - dann bleibt es aus, statt zu raten.
        $scheint = ($strahlung !== null && $schwelle > 0.0 && $strahlung >= $schwelle);
        $this->SetValue('Sunshine', $scheint);

        $jetzt   = time();
        $letzte  = $this->ReadAttributeInteger('SunshineLast');
        $tagJetzt = (int) date('Ymd', $jetzt);
        $tagAlt   = (int) date('Ymd', $letzte ?: $jetzt);
        $summe   = ($letzte === 0 || $tagJetzt !== $tagAlt) ? 0.0 : (float) $this->GetValue('SunshineToday');

        if ($scheint && $letzte > 0 && $tagJetzt === $tagAlt) {
            $summe += min(600, max(0, $jetzt - $letzte)) / 3600.0;
        }
        $this->SetValue('SunshineToday', round($summe, 2));
        $this->WriteAttributeInteger('SunshineLast', $jetzt);
    }

    /**
     * Gedaempfte Aussentemperatur: gleitender Mittelwert ueber ein Zeitfenster.
     *
     * Beschattung und Heizung sollen nicht auf jede Boe und jede Wolke reagieren. Die alte
     * Loesung mittelte die letzten DREI Archivwerte — das ist je nach Aufzeichnungsdichte mal
     * eine Minute und mal eine Viertelstunde, also kein definiertes Fenster. Hier ist es eine
     * feste Zeitspanne, unabhaengig davon, wie oft aufgezeichnet wird.
     */
    private function daempfen(?float $wert): ?float
    {
        if ($wert === null) {
            return null;
        }
        $min = max(1, $this->ReadPropertyInteger('DampMinutes'));
        $r = json_decode($this->ReadAttributeString('Damp'), true);
        if (!is_array($r)) {
            $r = [];
        }
        $jetzt = time();
        $r[] = ['t' => $jetzt, 'v' => $wert];
        $r = array_values(array_filter($r, static fn($e) => ($jetzt - (int) $e['t']) <= $min * 60));
        $this->WriteAttributeString('Damp', json_encode(array_slice($r, -200)));
        $summe = 0.0;
        foreach ($r as $e) {
            $summe += (float) $e['v'];
        }
        return round($summe / max(1, count($r)), 1);
    }

    /**
     * Tagesminimum und -maximum samt Zeitpunkt, aus dem Archiv der eigenen Variablen.
     *
     * Bewusst aus dem Archiv und nicht aus mitgefuehrten Merkern: nach einem Neustart waeren
     * Merker leer, das Archiv weiss es noch. Ohne Archiv bleiben die Werte stehen — falsche
     * Extremwerte waeren schlimmer als keine.
     */
    private function tageswerte(): void
    {
        // Tages-Min/Max kommen aus Archiv-Aggregaten - guenstiger als Punktabfragen, aber immer
        // noch zwei Datenbankgriffe. Ein Tagesminimum aendert sich nicht in fuenf Sekunden.
        $takt = max(0, $this->ReadPropertyInteger('DayValueSeconds'));
        if ($takt > 0 && (time() - $this->ReadAttributeInteger('DayTs')) < $takt) {
            return;
        }
        $this->WriteAttributeInteger('DayTs', time());
        $aid = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        if (!$aid) {
            return;
        }
        $von = strtotime('today 00:00');
        foreach ([['Temp', 'TempMin', 'TempMax', 'TempMinTime', 'TempMaxTime'],
                  ['Wind', 'WindMin', 'WindMax', 'WindMinTime', 'WindMaxTime'],
                  ['Hum',  'HumMin',  'HumMax',  'HumMinTime',  'HumMaxTime']] as $satz) {
            [$quelle, $iMin, $iMax, $iMinT, $iMaxT] = $satz;
            $vid = @$this->GetIDForIdent($quelle);
            if (!$vid || !AC_GetLoggingStatus($aid, $vid)) {
                continue;
            }
            $a = @AC_GetAggregatedValues($aid, $vid, 1 /* Tag */, $von, time(), 0);
            if (!is_array($a) || $a === []) {
                continue;
            }
            $t = $a[0];
            $this->SetValue($iMin, (float) $t['Min']);
            $this->SetValue($iMax, (float) $t['Max']);
            $this->SetValue($iMinT, (int) ($t['MinTime'] ?? 0));
            $this->SetValue($iMaxT, (int) ($t['MaxTime'] ?? 0));
        }
    }

    /** Schreibt nur, wenn ein Wert da ist — sonst bleibt der letzte stehen. */
    private function put(string $ident, ?float $wert): void
    {
        if ($wert !== null) {
            $this->SetValue($ident, $wert);
        }
    }

    /**
     * Was als ZAEHLER archiviert gehoert — und was ausdruecklich nicht.
     *
     * Zaehler ist NUR der fortlaufende Gesamtregen: er steigt und faellt nie. Genau darauf ist
     * die Zaehler-Aggregation ausgelegt, sie bildet die Zunahme je Zeitraum.
     *
     * "Regen heute" gehoert NICHT dazu, auch wenn es verlockend aussieht. Der Wert faellt jede
     * Nacht auf null zurueck; als Zaehler archiviert waere er eine Reihe aus Zunahmen mit einem
     * taeglichen Bruch, und der Tageswert selbst — die Zahl, die man eigentlich sehen will —
     * ginge in der Aggregation verloren. Dasselbe gilt fuer Monat, Jahr und Verdunstung.
     * Die Regenrate ist ohnehin schon eine Rate.
     */
    private const ZAEHLER = ['RainTotal', 'EtTotal'];

    private function applyLogging(): void
    {
        $aid = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        if (!$aid) {
            return;
        }
        foreach (self::LOGGEN as $ident) {
            $vid = @$this->GetIDForIdent($ident);
            if (!$vid) {
                continue;
            }
            if (!AC_GetLoggingStatus($aid, $vid)) {
                AC_SetLoggingStatus($aid, $vid, true);
            }
            $zaehler = in_array($ident, self::ZAEHLER, true);
            if (AC_GetAggregationType($aid, $vid) !== ($zaehler ? 1 : 0)) {
                AC_SetAggregationType($aid, $vid, $zaehler ? 1 : 0);
                // Der Ruecksprung auf null um Mitternacht ist ein Zaehler-Neustart und kein
                // Rueckwaertszaehlen — ohne dieses Kennzeichen entstuende dort ein negativer Sprung.
                if ($zaehler) {
                    @AC_SetCounterIgnoreZeros($aid, $vid, true);
                }
                @AC_ReAggregateVariable($aid, $vid);
            }
        }
    }

    /**
     * Ein Profil nur benutzen, wenn es die Symcon-Fassung wirklich mitbringt.
     *
     * Sonst bricht schon das Anlegen der Instanz ab — und zwar wortlos: Symcon meldet nur,
     * dass die Instanz nicht erzeugt werden konnte. Genau daran ist der erste Versuch
     * gescheitert, weil "~Precipitation" entgegen der Erwartung nicht existiert.
     */
    private function prof(string $name): string
    {
        return IPS_VariableProfileExists($name) ? $name : '';
    }

    /** Eigene Einheitenprofile fuer Groessen, fuer die Symcon keines mitbringt. */
    private function maybeUnitProfiles(): void
    {
        $u = [
            'WX.Regenrate' => [' mm/h', 2, 0.0, 100.0],
            'WX.Strahlung' => [' W/m²', 0, 0.0, 1400.0],
            'WX.Grad'      => ['°', 0, 0.0, 360.0],
            'WX.FSI'       => ['', 0, 0.0, 200.0],
            'WX.AbsFeuchte'=> [' g/m³', 2, 0.0, 60.0],
            'WX.Kelvin'    => [' K', 1, 0.0, 40.0],   // Kuehlreserve = Temperaturdifferenz
            'WX.Druck'     => [' hPa', 1, 0.0, 60.0],  // Dampfdruckdefizit
        ];
        foreach ($u as $name => [$suffix, $dig, $min, $max]) {
            if (IPS_VariableProfileExists($name)) {
                continue;
            }
            IPS_CreateVariableProfile($name, 2);           // 2 = Float
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileDigits($name, $dig);
            IPS_SetVariableProfileValues($name, $min, $max, 0);
        }
    }

    private function maybeProfiles(): void
    {
        $this->maybeUnitProfiles();
        $p = [
            'WX.Nebel' => [[0, 'kein Nebel', 0x63757b], [1, 'diesig', 0x8ba0a6],
                           [2, 'Nebel', 0x5ab6ff], [3, 'dichter Nebel', 0x2f7fd6]],
            'WX.Gewitter' => [[0, 'kein Gewitter', 0x63757b], [1, 'Wetterleuchten', 0xf2b441],
                              [2, 'Gewitter', 0xf2a03d], [3, 'Gewitter in der Nähe', 0xf2685a]],
            'WX.Niederschlag' => [[0, 'kein Niederschlag', 0x63757b], [1, 'Regen', 0x5ab6ff],
                                  [2, 'Schneeregen', 0x9db8e6], [3, 'Schnee', 0xe7eef0]],
            'WX.Zug' => [[-1, 'zieht auf', 0xf2685a], [0, 'steht', 0x63757b], [1, 'zieht ab', 0x39d08a]],
            // Farben wie die Gefahrenstufen-Palette der Visualisierung (u-stufe1..5).
            'WX.Boden' => [[0, 'nass', 0x5ab6ff], [1, 'feucht', 0x00cdab],
                           [2, 'mäßig', 0xeab308], [3, 'trocken', 0xf97316],
                           [4, 'sehr trocken', 0xef4444], [5, 'staubtrocken', 0x991b1b],
                           [6, 'kein Fühler', 0x63757b]],
            'WX.Kuehlreserve' => [[0, 'reichlich', 0x00cdab], [1, 'gut', 0xeab308],
                                  [2, 'knapp', 0xf97316], [3, 'kaum', 0xef4444],
                                  [4, 'keine', 0x991b1b]],
            'WX.Hitze' => [[0, 'unbedenklich', 0x00cdab], [1, 'erhöht', 0xeab308],
                           [2, 'hoch', 0xf97316], [3, 'sehr hoch', 0xef4444],
                           [4, 'extrem', 0x991b1b]],
        ];
        foreach ($p as $name => $werte) {
            if (IPS_VariableProfileExists($name)) {
                continue;
            }
            IPS_CreateVariableProfile($name, 1);
            $min = min(array_column($werte, 0));
            IPS_SetVariableProfileValues($name, $min, $min + count($werte) - 1, 1);
            foreach ($werte as [$v, $t, $f]) {
                IPS_SetVariableProfileAssociation($name, $v, $t, '', $f);
            }
        }
    }

    // ==================================================================
    // Formular
    // ==================================================================

    public function GetConfigurationForm()
    {
        $medien = [['caption' => '— keine —', 'value' => 0]];
        foreach (IPS_GetMediaList() as $mid) {
            if ((int) IPS_GetMedia($mid)['MediaType'] !== 1) {
                continue;
            }
            $p = IPS_GetParent($mid);
            $medien[] = ['value' => (int) $mid,
                         'caption' => ($p ? IPS_GetName($p) . ' · ' : '') . IPS_GetName($mid) . '  (#' . $mid . ')'];
        }

        $el = [
            ['type' => 'Label', 'caption' =>
                'Das Wetter des Hauses an einer Stelle: führt beliebig viele Quellen je Größe '
                . 'zusammen, leitet ab, was keine Station misst, und misst die Sicht an '
                . 'Kamerabildern. Alles andere bindet sich an die Variablen dieser Instanz.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Takt (Sekunden, 0 = aus)',
                 'minimum' => 0, 'maximum' => 3600],
                ['type' => 'NumberSpinner', 'name' => 'Lat', 'caption' => 'Breite', 'digits' => 5],
                ['type' => 'NumberSpinner', 'name' => 'Lon', 'caption' => 'Länge', 'digits' => 5],
            ]],
            ['type' => 'Label', 'caption' => 'Der Standort wird für Sonnenstand, Bewölkung und die '
                . 'Höhenwerte gebraucht — ohne ihn gibt es keine Bewölkungsaussage und keinen Nebelindex.'],

            ['type' => 'ExpansionPanel', 'caption' => 'Quellen', 'expanded' => true, 'items' => [
                ['type' => 'Label', 'caption' =>
                    'Rangfolge 1 ist die erste Wahl. Für jede Größe gewinnt die höchstrangige Quelle, '
                    . 'die einen frischen Wert hat; erst wenn sie ihn nicht liefert oder ihr Wert älter '
                    . 'ist als die Geltungsdauer, rückt die nächste nach. So liefert die genauere '
                    . 'Station Temperatur und Feuchte, während eine zweite die Blitze beisteuert.'],
                ['type' => 'Label', 'caption' => 'Eintragen lässt sich eine WeatherSource — oder direkt ein '
                    . 'Empfänger wie der TempestListener, der schon selbst eine vollständige Beobachtung '
                    . 'liefert. Andere Instanzen werden übergangen.'],
                ['type' => 'List', 'name' => 'Sources', 'caption' => 'Wetterquellen', 'rowCount' => 5,
                 'add' => true, 'delete' => true, 'sort' => ['column' => 'Priority', 'direction' => 'ascending'],
                 'columns' => [
                     ['caption' => 'Quelle', 'name' => 'InstanceID', 'width' => 'auto', 'add' => 0,
                      'edit' => ['type' => 'SelectInstance']],
                     ['caption' => 'Rang', 'name' => 'Priority', 'width' => '70px', 'add' => 1,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                     ['caption' => 'gilt (s)', 'name' => 'MaxAge', 'width' => '90px', 'add' => 900,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 30, 'maximum' => 86400]],
                     ['caption' => 'Sicht', 'name' => 'UseSight', 'width' => '70px', 'add' => true,
                      'edit' => ['type' => 'CheckBox']],
                     ['caption' => 'Himmel X %', 'name' => 'SX', 'width' => '90px', 'add' => 0,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 95]],
                     ['caption' => 'Himmel Y %', 'name' => 'SY', 'width' => '90px', 'add' => 0,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 95]],
                     ['caption' => 'Himmel B %', 'name' => 'SW', 'width' => '90px', 'add' => 0,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 100]],
                     ['caption' => 'Himmel H %', 'name' => 'SH', 'width' => '90px', 'add' => 0,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 100]],
                     ['caption' => 'aktiv', 'name' => 'Enabled', 'width' => '70px', 'add' => true,
                      'edit' => ['type' => 'CheckBox']],
                 ]],
                ['type' => 'Label', 'caption' =>
                    'Nebel und Bewölkung sind ZWEI Messungen mit zwei Blickrichtungen. Nebel misst man am '
                    . 'Gelände (Zaun, Hecke, Hauskante) — dort fällt der Kontrast. Bewölkung misst man am '
                    . 'Himmel. Das Häkchen "Sicht" sagt, ob eine Kamera an der Nebelmessung teilnimmt, ein '
                    . 'gezogenes Himmelsfeld sagt, ob sie an der Bewölkung teilnimmt. Eine Kamera darf '
                    . 'beides, eines davon — oder als reine Himmelskamera dazukommen, ohne den Sicht-Median '
                    . 'zu verändern, der die Nebelstufe trägt.'],
                ['type' => 'Label', 'caption' =>
                    'Das HIMMELSFELD ist ein zweiter, getrennter Ausschnitt und misst die Bewölkung über '
                    . 'das Rot/Blau-Verhältnis: klarer Himmel ist blau (Rayleigh), Wolken sind grau bis '
                    . 'weiß (Mie) — unabhängig davon, ob sie hell oder dunkel wirken. Es muss reinen '
                    . 'Himmel zeigen: kein Dach, kein Baum, keine Hauswand, und möglichst nicht in '
                    . 'Richtung Sonne, sonst brennt der Ausschnitt aus. Breite oder Höhe auf 0 heißt '
                    . '"diese Kamera sieht keinen Himmel" — das ist der Normalfall für Kameras, die '
                    . 'nach unten blicken. Der Klarwert wird je Kamera und Sonnenhöhe selbst gelernt; '
                    . 'nach dem Ziehen dauert es ein paar klare Stunden, bis er trägt.'],
            ]],

            ['type' => 'ExpansionPanel', 'caption' => 'Kameras (Sichtmessung)', 'expanded' => true, 'items' => [
                ['type' => 'Label', 'caption' =>
                    'Nebel frisst den Kontrast. Gemessen wird deshalb der Kontrastverlust gegenüber dem '
                    . 'gelernten Klarwert DERSELBEN Kamera, getrennt für Tag und Nacht. Absolute Schwellen '
                    . 'taugen nicht: eine Kamera auf glatten Asphalt liegt bei bester Sicht weit unter '
                    . 'einer auf Büsche. Der Klarwert ist eine Bestmarke — er steigt sofort und sinkt nie, '
                    . 'sonst gewöhnte sich die Anlage während einer langen Nebellage an den Nebel.'],
                ['type' => 'Label', 'caption' =>
                    'Der Ausschnitt in Prozent grenzt den ausgewerteten Bildbereich ein. Sinnvoll, um '
                    . 'Himmel, eine nahe Wand oder eine Zeitleiste auszuschließen — dort ändert sich der '
                    . 'Kontrast auch bei bester Sicht.'],
                ['type' => 'CheckBox', 'name' => 'UseCameras', 'caption' => 'Sicht über Kamerabilder messen'],
                ['type' => 'List', 'name' => 'Cameras', 'caption' => 'Kameras', 'rowCount' => 6,
                 'add' => true, 'delete' => true, 'columns' => [
                     ['caption' => 'Bild', 'name' => 'MediaID', 'width' => 'auto', 'add' => 0,
                      'edit' => ['type' => 'Select', 'options' => $medien]],
                     ['caption' => 'Bezeichnung', 'name' => 'Name', 'width' => '160px', 'add' => '',
                      'edit' => ['type' => 'ValidationTextBox']],
                     ['caption' => 'X %', 'name' => 'X', 'width' => '60px', 'add' => 0,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 95]],
                     ['caption' => 'Y %', 'name' => 'Y', 'width' => '60px', 'add' => 0,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 95]],
                     ['caption' => 'Breite %', 'name' => 'W', 'width' => '80px', 'add' => 100,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 5, 'maximum' => 100]],
                     ['caption' => 'Höhe %', 'name' => 'H', 'width' => '80px', 'add' => 100,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 5, 'maximum' => 100]],
                     ['caption' => 'aktiv', 'name' => 'Enabled', 'width' => '70px', 'add' => true,
                      'edit' => ['type' => 'CheckBox']],
                 ]],
                ['type' => 'Label', 'caption' =>
                    'Bodenfeuchte: Fühler frei binden. Die Skalen laufen GEGENLÄUFIG — Prozent heißt '
                    . 'hoch = feucht (kapazitiv, z. B. Gardena), Zentibar heißt hoch = TROCKEN '
                    . '(Saugspannung, z. B. Davis/Watermark). Deshalb steht der Typ je Zeile. '
                    . 'Gewertet wird der TROCKENSTE Fühler, nicht der Mittelwert; Werte älter als '
                    . 'einen Tag gelten als veraltet und zählen nicht mit.'],
                ['type' => 'List', 'name' => 'SoilSensors', 'caption' => 'Bodenfeuchte-Fühler', 'rowCount' => 6,
                 'add' => true, 'delete' => true, 'columns' => [
                     ['caption' => 'Variable', 'name' => 'VarID', 'width' => 'auto', 'add' => 0,
                      'edit' => ['type' => 'SelectVariable']],
                     ['caption' => 'Bezeichnung', 'name' => 'Name', 'width' => '180px', 'add' => '',
                      'edit' => ['type' => 'ValidationTextBox']],
                     ['caption' => 'Skala', 'name' => 'Typ', 'width' => '220px', 'add' => 0,
                      'edit' => ['type' => 'Select', 'options' => [
                          ['caption' => 'Prozent (hoch = feucht)', 'value' => 0],
                          ['caption' => 'Zentibar (hoch = trocken)', 'value' => 1]]]],
                     ['caption' => 'aktiv', 'name' => 'Enabled', 'width' => '70px', 'add' => true,
                      'edit' => ['type' => 'CheckBox']],
                 ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'SightWarn', 'caption' => 'diesig ab (% vom Klarwert)',
                     'minimum' => 10, 'maximum' => 95],
                    ['type' => 'NumberSpinner', 'name' => 'SightFog', 'caption' => 'Nebel ab (% vom Klarwert)',
                     'minimum' => 5, 'maximum' => 90],
                ]],
                ['type' => 'Button', 'caption' => 'Gelernte Klarwerte verwerfen',
                 'onClick' => 'echo WX_ResetCameraBaseline($id);'],
            ]],

            ['type' => 'ExpansionPanel', 'caption' => 'Schwellen für Nebel und Gewitter', 'expanded' => false, 'items' => [
                ['type' => 'Label', 'caption' =>
                    'Der Nebel-Regelsatz ist ein Torwächter: Fällt eine der drei Bedingungen, ist es kein '
                    . 'Nebel, und der Grund steht im Klartext in der Variablen. Die Stärke kommt danach aus '
                    . 'dem Fog Stability Index, der die Schichtung über dem Boden einbezieht.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'FogHum', 'caption' => 'Feuchte ab %', 'digits' => 0],
                    ['type' => 'NumberSpinner', 'name' => 'FogWind', 'caption' => 'Wind bis km/h', 'digits' => 0],
                    ['type' => 'NumberSpinner', 'name' => 'FogSpread', 'caption' => 'Taupunktdifferenz bis K', 'digits' => 1],
                ]],
                ['type' => 'Label', 'caption' =>
                    'Beim Gewitter zählt nicht nur der letzte Blitz: ein einzelner Schlag in 40 km ist keine '
                    . 'Lage. Ausgewertet werden Entfernung UND Häufigkeit über einen Ringspeicher der letzten Stunde.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'StormNearKm', 'caption' => 'in der Nähe unter km', 'minimum' => 1, 'maximum' => 50],
                    ['type' => 'NumberSpinner', 'name' => 'StormNearMin', 'caption' => 'binnen Minuten', 'minimum' => 1, 'maximum' => 120],
                    ['type' => 'NumberSpinner', 'name' => 'StormFarKm', 'caption' => 'Gewitter unter km', 'minimum' => 1, 'maximum' => 80],
                    ['type' => 'NumberSpinner', 'name' => 'StormFarMin', 'caption' => 'binnen Minuten', 'minimum' => 1, 'maximum' => 120],
                ]],
            ]],

            ['type' => 'CheckBox', 'name' => 'UseForecast',
             'caption' => 'Vorhersage von Open-Meteo holen (Stunden und Tage als JSON)'],
            ['type' => 'Label', 'caption' => 'Eine Wetterstation misst, sie sagt nicht vorher — der '
                . 'eingebaute Ausblick einer Davis ist eine Faustregel über den Luftdruckverlauf. '
                . 'Die Vorhersage wird unverändert abgelegt, damit Anzeigen sie ohne Umweg lesen können.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'ForecastMinutes', 'caption' => 'Abruf alle (Minuten)',
                 'minimum' => 5, 'maximum' => 360],
                ['type' => 'NumberSpinner', 'name' => 'ForecastDays', 'caption' => 'Tage', 'minimum' => 1, 'maximum' => 16],
            ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'RainSensorId',
                 'caption' => 'Regensensor (meldet nass)'],
                ['type' => 'SelectVariable', 'name' => 'RainSensor2Id',
                 'caption' => 'Ersatz-Regensensor'],
                ['type' => 'NumberSpinner', 'name' => 'RainSensorMaxAge',
                 'caption' => 'gilt als stumm nach (Sekunden)', 'minimum' => 0, 'maximum' => 86400],
            ]],
            ['type' => 'Label', 'caption' => 'Optional. Ein optischer Regensensor spricht sofort an, '
                . 'die Messwippe der Station erst nach rund 0,2 mm — bei Nieselregen liegen Minuten '
                . 'dazwischen, in denen die Wetterlage noch "bedeckt" sagt. Gebunden zählt der Sensor '
                . 'als Nachweis, DASS es niederschlägt; die Menge kommt weiterhin nur aus der Station, '
                . 'ob Regen oder Schnee entscheidet die Feuchtkugel. Der erste Sensor ist maßgeblich; '
                . 'der Ersatz rückt nur nach, wenn der erste länger als die eingestellte Zeit schweigt — '
                . 'ein ausgefallener Melder soll nicht "trocken" behaupten.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'RainTotalStart',
                 'caption' => 'Startwert Regen kumuliert (mm)', 'digits' => 2],
                ['type' => 'NumberSpinner', 'name' => 'EtTotalStart',
                 'caption' => 'Startwert Verdunstung kumuliert (mm)', 'digits' => 2],
            ]],
            ['type' => 'Label', 'caption' => 'Der fortlaufende Gesamtregen zählt die Zunahme des '
                . 'Tageswertes. Wer schon einen Zählerstand hat, trägt ihn hier ein, damit die Reihe '
                . 'nicht bei null neu beginnt.'],
            ['type' => 'NumberSpinner', 'name' => 'DampMinutes',
             'caption' => 'Fenster der gedämpften Temperatur (Minuten)', 'minimum' => 1, 'maximum' => 180],
            ['type' => 'Label', 'caption' => 'Die gedämpfte Außentemperatur glättet über dieses Fenster. '
                . 'Beschattung und Heizung sollen nicht auf jede Wolke reagieren.'],
            ['type' => 'CheckBox', 'name' => 'OnSourceUpdate',
             'caption' => 'Bei jeder Aktualisierung einer Quelle neu rechnen'],
            ['type' => 'Label', 'caption' => 'Sonst wird nur im Zeittakt oben gerechnet — die Werte '
                . 'hinken dann bis zu einem Takt hinterher, obwohl die Station längst gemeldet hat. '
                . 'Der Zeittakt bleibt als Sicherheitsnetz, falls eine Quelle verstummt.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'MinGapSeconds',
                 'caption' => 'Mindestabstand zweier Läufe (s)', 'minimum' => 1, 'maximum' => 600],
                ['type' => 'NumberSpinner', 'name' => 'CameraSeconds',
                 'caption' => 'Bildauswertung höchstens alle (s)', 'minimum' => 0, 'maximum' => 3600],
                ['type' => 'NumberSpinner', 'name' => 'DayValueSeconds',
                 'caption' => 'Tages-Min/Max höchstens alle (s)', 'minimum' => 0, 'maximum' => 3600],
            ]],
            ['type' => 'CheckBox', 'name' => 'Logging', 'caption' => 'Messreihen archivieren (Temperatur, Feuchte, Wind, Nebel, Bewölkung …)'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => 'Jetzt auswerten', 'onClick' => 'WX_Update($id);'],
                ['type' => 'Button', 'caption' => 'Probelauf (nur rechnen)', 'onClick' => 'echo WX_TestRun($id);'],
            ]],
        ];

        return json_encode([
            'elements' => $el,
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Läuft'],
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'Keine Quelle eingetragen'],
            ],
        ]);
    }
}
