<?php

declare(strict_types=1);

namespace Hoep\Weather\Drivers;

use Hoep\Weather\Engines\Meteo;
use Hoep\Weather\IWeatherSource;
use Hoep\Weather\Observation;

/**
 * TempestModule — liest eine WeatherFlow Tempest ueber ein bereits vorhandenes
 * Tempest-Modul, anhand der Idents unter dessen Instanz.
 *
 * Warum nicht selbst UDP sprechen: die Tempest funkt ihre Saetze als Broadcast auf Port
 * 50222, und ein zweiter Zuhoerer waere technisch moeglich. Aber ein gutes Tempest-Modul
 * gibt es bereits, es haelt den Socket und legt saubere Variablen an — es ein zweites Mal
 * zu bauen, brachte nichts ausser einer weiteren Fehlerquelle.
 *
 * Der Gewinn liegt woanders: die Tempest kann etwas, das eine Davis NICHT kann —
 * Blitzortung. Genau dafuer holen wir sie herein, waehrend Temperatur und Feuchte von der
 * genaueren Station kommen duerfen. Diese Aufteilung je Groesse macht die Zusammenfuehrung
 * in der Station.
 */
final class TempestModule implements IWeatherSource
{
    /** Ident im Tempest-Modul => Groesse in der Beobachtung. */
    private const IDENTS = [
        'Air_Temperature'               => 'tempC',
        'Relative_Humidity'             => 'humPct',
        'Station_Pressure'              => 'pressureHpa',
        'Wind_Avg'                      => 'windAvgKmh',
        'Wind_Gust'                     => 'gustKmh',
        'Wind_Direction'                => 'windDirDeg',
        'Solar_Radiation'               => 'radiationWm2',
        'Illuminance'                   => 'illuminanceLux',
        'UV'                            => 'uvIndex',
        'Precipitation_Type'            => 'precipType',
        'Lightning_Strike_Avg_Distance' => 'strikeDistKm',
        'Lightning_Strike_Count'        => 'strikeCount',
        'dev_voltage'                   => 'batteryV',
    ];

    private int    $instanz = 0;
    private float  $windFaktor = 1.0;
    private bool   $absolut = true;
    private float  $hoehe = 0.0;
    private string $fehler = '';

    public static function id(): string
    {
        return 'tempest-module';
    }

    public static function label(): string
    {
        return 'WeatherFlow Tempest (über vorhandenes Tempest-Modul)';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'InstanceID', 'caption' => 'Tempest-Instanz', 'type' => 'Instance', 'default' => 0,
             'hint' => 'Die Instanz des Tempest-Moduls; die Variablen darunter werden über ihre Idents gefunden.'],
            ['name' => 'WindInMs', 'caption' => 'Wind steht in m/s statt km/h', 'type' => 'Boolean', 'default' => false,
             'hint' => 'Je nach Modulfassung liegt der Wind in m/s vor. Der Probelauf zeigt, was stimmt.'],
            ['name' => 'PressureAbsolute', 'caption' => 'Luftdruck ist Stationsdruck (nicht auf Meeresniveau reduziert)',
             'type' => 'Boolean', 'default' => true,
             'hint' => 'Die Tempest meldet den Druck am Aufstellort. Ohne Umrechnung passt er zu keiner anderen Quelle.'],
            ['name' => 'Altitude', 'caption' => 'Hoehe des Aufstellorts (m ueber NN)', 'type' => 'Float', 'default' => 0.0],
        ];
    }

    public function configure(array $config): void
    {
        $this->instanz    = (int) ($config['InstanceID'] ?? 0);
        $this->windFaktor = !empty($config['WindInMs']) ? 3.6 : 1.0;
        $this->absolut    = (bool) ($config['PressureAbsolute'] ?? true);
        $this->hoehe      = (float) ($config['Altitude'] ?? 0.0);
    }

    public function lastError(): string
    {
        return $this->fehler;
    }

    public function read(): Observation
    {
        $this->fehler = '';
        $o = new Observation(self::id());
        if ($this->instanz <= 0 || !@IPS_InstanceExists($this->instanz)) {
            $this->fehler = 'Tempest-Instanz nicht gesetzt oder nicht vorhanden';
            return $o;
        }
        $gefunden = 0;
        foreach (self::IDENTS as $ident => $ziel) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->instanz);
            if (!$vid || !@IPS_VariableExists($vid)) {
                continue;
            }
            $w = @GetValue($vid);
            if (!is_numeric($w)) {
                continue;
            }
            $w = (float) $w;
            $ts = (int) IPS_GetVariable($vid)['VariableUpdated'];
            if ($ziel === 'windAvgKmh' || $ziel === 'gustKmh') {
                $w = round($w * $this->windFaktor, 1);
            }
            $o->set($ziel, $w, $ts);
            $gefunden++;
        }
        if ($gefunden === 0) {
            $this->fehler = 'keine bekannten Idents unter der Instanz gefunden';
            return $o;
        }

        // Wind: die Tempest meldet Mittel und Boe. Der "aktuelle" Wind ist das Mittel —
        // die Boe als Momentanwert auszugeben, wuerde jede Windschwelle zu frueh ausloesen.
        if ($o->has('windAvgKmh') && !$o->has('windKmh')) {
            $o->set('windKmh', $o->num('windAvgKmh'), $o->ts('windAvgKmh'));
        }

        // Blitze: das Modul schreibt Zaehler und Entfernung im Minutentakt weiter, AUCH wenn
        // es gar nicht blitzt. Wer den Aktualisierungszeitpunkt ungeprueft als Blitzzeitpunkt
        // nimmt, meldet Dauergewitter; wer die Entfernung 0 uebernimmt, meldet den Einschlag
        // im eigenen Garten. Beides nur bei Zaehler groesser null.
        if (($o->num('strikeCount') ?? 0) > 0) {
            $o->set('strikeTime', $o->ts('strikeCount'), $o->ts('strikeCount'));
        } else {
            $o->remove('strikeDistKm');
        }

        // Luftdruck: die Tempest meldet den STATIONSDRUCK, die meisten anderen Quellen den
        // auf Meeresniveau reduzierten. Ungefragt zusammengefuehrt ergibt das einen Sprung
        // von mehreren Dutzend hPa (hier gemessen: 961 gegen 1009). Deshalb wird umgerechnet,
        // sobald eine Hoehe hinterlegt ist — sonst bleibt der Wert weg.
        if ($o->has('pressureHpa') && $this->absolut) {
            if ($this->hoehe > 0.0) {
                $t0 = ($o->num('tempC') ?? 15.0) + 273.15;
                $o->set('pressureHpa',
                        round($o->num('pressureHpa') * pow(1 - (0.0065 * $this->hoehe) / ($t0 + 0.0065 * $this->hoehe), -5.257), 1),
                        $o->ts('pressureHpa'));
            } else {
                $o->remove('pressureHpa');
                $this->fehler = 'Stationsdruck ohne Hoehenangabe - Luftdruck wird nicht geliefert';
            }
        }

        $t = $o->num('tempC');
        $r = $o->num('humPct');
        if ($t !== null && $r !== null) {
            $o->set('dewC', Meteo::taupunkt($t, $r), $o->ts('tempC'));
        }
        return $o;
    }
}
