<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Weather/autoload.php';

use Hoep\Weather\Engines\CameraVision;
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
    private const GUID_SOURCE = '{B24C7F1E-9A05-4E63-8D17-3F92C6B0A5D8}';

    /** Variablen, die als Messreihe etwas taugen — nur die werden archiviert. */
    private const LOGGEN = ['Temp', 'Hum', 'Dew', 'WetBulb', 'Wind', 'WindAvg', 'Gust', 'WindDir',
                            'Pressure', 'RainRate', 'RainDay', 'Radiation', 'UV', 'CloudPct',
                            'FogLevel', 'FogPct', 'FogFSI', 'PrecipType', 'StormLevel', 'StormDist',
                            'StormRate', 'StormTrend', 'StormSpeed', 'StormEta', 'StormApproaching',
                            'SightPct', 'SnowCover', 'Condition',
                            'AppTemp', 'AbsHum', 'TempDamped', 'TempMin', 'TempMax',
                            'WindMin', 'WindMax'];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyFloat('Lat', 0.0);
        $this->RegisterPropertyFloat('Lon', 0.0);
        $this->RegisterPropertyString('Sources', '[]');   // [{InstanceID,Priority,MaxAge,Enabled}]
        $this->RegisterPropertyString('Cameras', '[]');   // [{MediaID,Name,X,Y,W,H,Enabled}]
        $this->RegisterPropertyBoolean('UseCameras', true);
        $this->RegisterPropertyBoolean('Logging', true);
        $this->RegisterPropertyInteger('DampMinutes', 15);   // Fenster der gedaempften Temperatur

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
        $this->RegisterVariableFloat('Radiation', 'Globalstrahlung', 'WX.Strahlung', 19);
        $this->RegisterVariableFloat('UV', 'UV-Index', $this->prof('~UVIndex'), 20);

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

        // --- Abgeleitet ---
        $this->RegisterVariableFloat('WetBulb', 'Feuchtkugel', '~Temperature', 30);
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
        $this->RegisterVariableInteger('DataAge', 'Alter der Messwerte (s)', '', 70);
        $this->RegisterVariableString('SourceMap', 'Herkunft je Größe (JSON)', '', 71);
        $this->RegisterVariableInteger('LastRun', 'Letzte Auswertung', '~UnixTimestamp', 72);

        $this->RegisterAttributeString('CamBase', '{}');
        $this->RegisterAttributeString('StrikeRing', '[]');
        $this->RegisterAttributeString('Upper', '{}');
        $this->RegisterAttributeString('Damp', '[]');

        $this->RegisterTimer('Tick', 0, 'WX_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->maybeProfiles();
        $iv = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Tick', $iv * 1000);
        if ($this->ReadPropertyBoolean('Logging')) {
            $this->applyLogging();
        }
        $this->SetStatus($this->quellen() === [] ? 201 : 102);
    }

    // ==================================================================
    // Oeffentlich
    // ==================================================================

    public function Update(): void
    {
        [$o, $herkunft] = $this->zusammenfuehren();
        $lat = $this->ReadPropertyFloat('Lat');
        $lon = $this->ReadPropertyFloat('Lon');

        $hoehe  = Meteo::sonnenhoehe($lat, $lon);
        $nacht  = $hoehe < -0.833;
        $kamera = $this->kameras($nacht);

        $cfg = ['fogHum' => $this->ReadPropertyFloat('FogHum'),
                'fogWind' => $this->ReadPropertyFloat('FogWind'),
                'fogSpread' => $this->ReadPropertyFloat('FogSpread'),
                'sightWarn' => $this->ReadPropertyInteger('SightWarn'),
                'sightFog' => $this->ReadPropertyInteger('SightFog'),
                'stormNearKm' => $this->ReadPropertyInteger('StormNearKm'),
                'stormNearMin' => $this->ReadPropertyInteger('StormNearMin'),
                'stormFarKm' => $this->ReadPropertyInteger('StormFarKm'),
                'stormFarMin' => $this->ReadPropertyInteger('StormFarMin')];

        $neb = WE::nebel($o, $this->hoehenwerte($lat, $lon), $kamera['sicht'], $cfg);
        $gew = WE::gewitter($o, $this->ringMitQuellen(), $cfg);
        $this->WriteAttributeString('StrikeRing', json_encode($gew['ring']));
        $wol = WE::bewoelkung($o, $lat, $lon);
        $ns  = WE::niederschlag($o);

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
        $this->put('Radiation', $o->num('radiationWm2'));
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
        $this->SetValue('PrecipType', $ns['art']);
        $this->SetValue('FogLevel', $neb['stufe']);
        $this->SetValue('FogFSI', (float) ($neb['fsi'] ?? 0.0));
        // Nebel als DICHTE in Prozent — dafuer, dass Anzeigen eine stetige Groesse brauchen.
        // Liegt eine Kameramessung vor, ist sie massgeblich (Dichte = fehlende Sicht); sonst
        // wird die Stufe auf einen Richtwert abgebildet und das ist auch genau so gemeint.
        $this->SetValue('FogPct', $kamera['sicht'] !== null
            ? round(max(0.0, 100.0 - $kamera['sicht']), 1)
            : (float) [0, 25, 60, 90][$neb['stufe']]);
        $this->SetValue('FogText', $neb['text']);
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
        $this->put('CloudPct', $wol['pct']);
        $this->SetValue('CloudSrc', $wol['quelle']);
        $this->SetValue('Condition', WE::wetterlage($gew['stufe'], $ns, $neb['stufe'],
                                                    $wol['pct'] ?? $this->GetValue('CloudPct')));

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
        $this->SetValue('DataAge', $o->has('tempC') ? $o->age('tempC') : 0);
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

    public function TestRun(): string
    {
        $t0 = microtime(true);
        [$o, $herkunft] = $this->zusammenfuehren();
        $lat = $this->ReadPropertyFloat('Lat');
        $lon = $this->ReadPropertyFloat('Lon');
        $nacht = Meteo::sonnenhoehe($lat, $lon) < -0.833;
        $kam = $this->kameras($nacht);
        $neb = WE::nebel($o, $this->hoehenwerte($lat, $lon), $kam['sicht']);
        $gew = WE::gewitter($o, $this->ring());
        $wol = WE::bewoelkung($o, $lat, $lon);
        $ns  = WE::niederschlag($o);

        $z = sprintf("Dauer: %d ms\n\nZUSAMMENGEFÜHRT\n", (int) round((microtime(true) - $t0) * 1000));
        foreach ($o->idents() as $id) {
            [$label, $einheit] = Observation::QUANTITIES[$id];
            $z .= sprintf("  %-16s %10s %-6s  %4d s alt   aus: %s\n",
                          $id, (string) $o->get($id), $einheit, $o->age($id), $herkunft[$id] ?? '?');
        }
        $z .= "\nKAMERAS\n";
        foreach ($kam['liste'] as $c) {
            $z .= isset($c['fehler'])
                ? sprintf("  %-16s %s\n", $c['name'], $c['fehler'])
                : sprintf("  %-16s Dichte %7s von %7s  =  Sicht %3d %%   (Kontrast %s, Helligkeit %s, %s)\n",
                          $c['name'], $c['dichte'], $c['klarwert'], $c['sicht'],
                          $c['kontrast'], $c['helligkeit'], $c['zeit']);
        }
        return $z . sprintf("\nNebel      %d   %s\nGewitter   %d   %s\nBewölkung  %s   %s\n"
                          . "Nieders.   %s\nWetterlage %s\n",
            $neb['stufe'], $neb['text'], $gew['stufe'], $gew['text'],
            $wol['pct'] === null ? '--' : $wol['pct'] . ' %', $wol['quelle'], $ns['text'],
            WE::wetterlage($gew['stufe'], $ns, $neb['stufe'], $wol['pct']));
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
                $roh = @WXS_GetObservation($iid);
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
    private function kameras(bool $nacht): array
    {
        if (!$this->ReadPropertyBoolean('UseCameras')) {
            return ['liste' => [], 'sicht' => null, 'schnee' => null];
        }
        $cams = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($cams) || $cams === []) {
            return ['liste' => [], 'sicht' => null, 'schnee' => null];
        }
        if (!CameraVision::verfuegbar()) {
            return ['liste' => [['fehler' => 'Bildauswertung nicht möglich: PHP ohne GD']],
                    'sicht' => null, 'schnee' => null];
        }
        $base = json_decode($this->ReadAttributeString('CamBase'), true);
        if (!is_array($base)) {
            $base = [];
        }
        $slot = $nacht ? 'n' : 'd';

        $liste = []; $quoten = []; $schnee = null;
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
            $bin = base64_decode((string) @IPS_GetMediaContent($mid));
            $roi = $this->roi($c);
            $m   = CameraVision::messen($bin, $roi);
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
            $liste[] = ['id' => $mid, 'name' => $name, 'kontrast' => $m['kontrast'],
                        'dichte' => $m['dichte'], 'klarwert' => $s['klarwert'],
                        'sicht' => (int) round($s['sicht']),
                        'helligkeit' => $m['helligkeit'], 'saettigung' => $m['saettigung'],
                        'schnee' => $sn, 'zeit' => $nacht ? 'Nacht' : 'Tag'];
        }
        $this->WriteAttributeString('CamBase', json_encode($base));

        // Sicht = SCHLECHTESTE Kamera. Nebel liegt selten gleichmaessig; eine Kamera, die
        // nichts mehr sieht, ist die wichtigere Meldung als der Durchschnitt.
        return ['liste' => $liste, 'sicht' => $quoten === [] ? null : min($quoten), 'schnee' => $schnee];
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

    // ==================================================================
    // Kleinkram
    // ==================================================================

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
                $l = json_decode((string) @WXS_GetStrikes($iid), true);
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
        $aid = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        if (!$aid) {
            return;
        }
        $von = strtotime('today 00:00');
        foreach ([['Temp', 'TempMin', 'TempMax', 'TempMinTime', 'TempMaxTime'],
                  ['Wind', 'WindMin', 'WindMax', 'WindMinTime', 'WindMaxTime']] as $satz) {
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
     * Summenwerte, die als ZAEHLER archiviert gehoeren, nicht als Mittelwert.
     *
     * Regen und Verdunstung sind aufsummierte Mengen: der Tageswert steigt bis Mitternacht und
     * faengt dann wieder bei null an. Als Mittelwert archiviert kaeme dabei die durchschnittliche
     * FUELLHOEHE des Zaehlers heraus — eine Zahl ohne Bedeutung. Als Zaehler bildet Symcon die
     * Zunahme je Zeitraum, und damit steht in der Stundenaggregation die Regenmenge dieser Stunde.
     *
     * Die Regenrate gehoert NICHT dazu: sie ist bereits eine Rate, ihr Mittelwert ist sinnvoll.
     */
    private const ZAEHLER = ['RainDay'];

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
                ['type' => 'List', 'name' => 'Sources', 'caption' => 'Wetterquellen', 'rowCount' => 5,
                 'add' => true, 'delete' => true, 'sort' => ['column' => 'Priority', 'direction' => 'ascending'],
                 'columns' => [
                     ['caption' => 'Quelle', 'name' => 'InstanceID', 'width' => 'auto', 'add' => 0,
                      'edit' => ['type' => 'SelectInstance', 'moduleID' => self::GUID_SOURCE]],
                     ['caption' => 'Rang', 'name' => 'Priority', 'width' => '70px', 'add' => 1,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                     ['caption' => 'gilt (s)', 'name' => 'MaxAge', 'width' => '90px', 'add' => 900,
                      'edit' => ['type' => 'NumberSpinner', 'minimum' => 30, 'maximum' => 86400]],
                     ['caption' => 'aktiv', 'name' => 'Enabled', 'width' => '70px', 'add' => true,
                      'edit' => ['type' => 'CheckBox']],
                 ]],
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

            ['type' => 'NumberSpinner', 'name' => 'DampMinutes',
             'caption' => 'Fenster der gedämpften Temperatur (Minuten)', 'minimum' => 1, 'maximum' => 180],
            ['type' => 'Label', 'caption' => 'Die gedämpfte Außentemperatur glättet über dieses Fenster. '
                . 'Beschattung und Heizung sollen nicht auf jede Wolke reagieren.'],
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
