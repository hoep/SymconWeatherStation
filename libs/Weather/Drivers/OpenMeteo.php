<?php

declare(strict_types=1);

namespace Hoep\Weather\Drivers;

use Hoep\Weather\IWeatherSource;
use Hoep\Weather\Observation;

/**
 * OpenMeteo — Wetter aus dem Vorhersagemodell, fuer Haeuser ohne eigene Station.
 *
 * Der Dienst ist fuer nicht-gewerbliche Nutzung frei und braucht keinen Schluessel. Er ist
 * die schlechteste der hier moeglichen Quellen und wird auch so behandelt: die Werte
 * beschreiben eine Modellzelle von einigen Kilometern Kantenlaenge, nicht das eigene
 * Grundstueck. Fuer Nebel und Beschattung ist das zu grob — dafuer braucht es eine Station
 * oder eine Kamera. Als Rueckfall und fuer die Hoehenwerte ist er sehr brauchbar.
 *
 * Deshalb wird der Zeitstempel des Modelllaufs uebernommen und nicht die eigene Uhr: eine
 * Stunde alte Modelldaten sollen in der Zusammenfuehrung gegen frische Messwerte verlieren.
 */
final class OpenMeteo implements IWeatherSource
{
    private const FELDER = 'temperature_2m,relative_humidity_2m,dew_point_2m,apparent_temperature,'
                         . 'precipitation,rain,showers,snowfall,weather_code,pressure_msl,'
                         . 'wind_speed_10m,wind_direction_10m,wind_gusts_10m';

    private float  $lat = 0.0;
    private float  $lon = 0.0;
    private int    $timeout = 8;
    private string $fehler = '';

    public static function id(): string
    {
        return 'open-meteo';
    }

    public static function label(): string
    {
        return 'Open-Meteo (Vorhersagemodell, ohne eigene Station)';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'Lat', 'caption' => 'Breite', 'type' => 'Float', 'default' => 0.0],
            ['name' => 'Lon', 'caption' => 'Länge', 'type' => 'Float', 'default' => 0.0],
            ['name' => 'Timeout', 'caption' => 'Zeitgrenze (Sekunden)', 'type' => 'Integer', 'default' => 8],
        ];
    }

    public function configure(array $config): void
    {
        $this->lat     = (float) ($config['Lat'] ?? 0.0);
        $this->lon     = (float) ($config['Lon'] ?? 0.0);
        $this->timeout = max(3, (int) ($config['Timeout'] ?? 8));
    }

    public function lastError(): string
    {
        return $this->fehler;
    }

    public function read(): Observation
    {
        $this->fehler = '';
        $o = new Observation(self::id());
        if ($this->lat === 0.0 && $this->lon === 0.0) {
            $this->fehler = 'kein Standort gesetzt';
            return $o;
        }
        $url = 'https://api.open-meteo.com/v1/forecast'
             . '?latitude=' . $this->lat . '&longitude=' . $this->lon
             . '&current=' . self::FELDER
             . '&timezone=UTC&wind_speed_unit=kmh';
        $roh = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => $this->timeout, 'ignore_errors' => true],
        ]));
        if ($roh === false) {
            $this->fehler = 'Open-Meteo nicht erreichbar';
            return $o;
        }
        $j = json_decode($roh, true);
        $c = $j['current'] ?? null;
        if (!is_array($c)) {
            $this->fehler = 'unerwartete Antwort von Open-Meteo';
            return $o;
        }
        $ts = isset($c['time']) ? (int) strtotime($c['time'] . ' UTC') : time();
        $o = new Observation(self::id(), $ts);

        $o->set('tempC', $this->w($c, 'temperature_2m'), $ts);
        $o->set('humPct', $this->w($c, 'relative_humidity_2m'), $ts);
        $o->set('dewC', $this->w($c, 'dew_point_2m'), $ts);
        $o->set('pressureHpa', $this->w($c, 'pressure_msl'), $ts);
        $o->set('windKmh', $this->w($c, 'wind_speed_10m'), $ts);
        $o->set('windAvgKmh', $this->w($c, 'wind_speed_10m'), $ts);
        $o->set('gustKmh', $this->w($c, 'wind_gusts_10m'), $ts);
        $o->set('windDirDeg', $this->w($c, 'wind_direction_10m'), $ts);
        // Das Modell liefert die Menge der letzten Stunde; als Rate ist das dieselbe Zahl
        // je Stunde. Genauer wird es dadurch nicht, aber die Einheit stimmt.
        $o->set('rainRateMmH', $this->w($c, 'precipitation'), $ts);
        return $o;
    }

    private function w(array $c, string $k): ?float
    {
        return isset($c[$k]) && is_numeric($c[$k]) ? (float) $c[$k] : null;
    }
}
