<?php

declare(strict_types=1);

namespace Hoep\Weather\Drivers;

use Hoep\Weather\IWeatherSource;
use Hoep\Weather\Observation;

/**
 * DavisWeatherLinkLive — liest eine Davis WeatherLink Live im eigenen Netz.
 *
 * Der heutige Standardweg bei Davis: das Geraet beantwortet `GET /v1/current_conditions`
 * ohne Anmeldung und ohne Cloud, im Sekundentakt aktuell. Kein Zwischenrechner, keine
 * fremde Software, kein Dienstkonto.
 *
 * ACHTUNG, EHRLICH GESAGT: Dieser Treiber ist an echter Hardware NICHT erprobt — in der
 * Anlage, in der dieses Modul entstanden ist, haengt die Station an einer aelteren
 * Steuersoftware (siehe DavisActData). Aufbau und Feldnamen folgen der oeffentlichen
 * Geraetebeschreibung von Davis. Wer eine WeatherLink Live hat, sollte den Probelauf im
 * Formular benutzen und Abweichungen melden.
 *
 * Die WeatherLink Live rechnet durchgehend in angelsaechsischen Einheiten; hier wird alles
 * auf Grad C, km/h, hPa und mm gebracht.
 */
final class DavisWeatherLinkLive implements IWeatherSource
{
    /** Regenwippen-Groesse laut Geraet: 1 = 0,01", 2 = 0,2 mm, 3 = 0,1 mm, 4 = 0,001". */
    private const WIPPE_MM = [1 => 0.254, 2 => 0.2, 3 => 0.1, 4 => 0.0254];

    private string $host = '';
    private int    $timeout = 5;
    private string $fehler = '';

    public static function id(): string
    {
        return 'davis-wll';
    }

    public static function label(): string
    {
        return 'Davis WeatherLink Live (im eigenen Netz, ungetestet)';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'Host', 'caption' => 'Adresse der WeatherLink Live', 'type' => 'String', 'default' => '',
             'hint' => 'Nur Name oder IP, z. B. 192.168.1.20 — der Pfad wird angehaengt.'],
            ['name' => 'Timeout', 'caption' => 'Zeitgrenze (Sekunden)', 'type' => 'Integer', 'default' => 5],
        ];
    }

    public function configure(array $config): void
    {
        $this->host    = trim((string) ($config['Host'] ?? ''));
        $this->timeout = max(2, (int) ($config['Timeout'] ?? 5));
    }

    public function lastError(): string
    {
        return $this->fehler;
    }

    public function read(): Observation
    {
        $this->fehler = '';
        if ($this->host === '') {
            $this->fehler = 'keine Adresse konfiguriert';
            return new Observation(self::id());
        }
        $url = (stripos($this->host, 'http') === 0 ? $this->host : 'http://' . $this->host)
             . '/v1/current_conditions';
        $roh = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => $this->timeout, 'ignore_errors' => true],
        ]));
        if ($roh === false) {
            $this->fehler = 'nicht erreichbar: ' . $url;
            return new Observation(self::id());
        }
        return $this->parse($roh);
    }

    public function parse(string $roh): Observation
    {
        $j = json_decode($roh, true);
        $c = $j['data']['conditions'] ?? null;
        if (!is_array($c)) {
            $this->fehler = 'unerwartete Antwort (kein conditions-Block)';
            return new Observation(self::id());
        }
        $ts = (int) ($j['data']['ts'] ?? time());
        $o  = new Observation(self::id(), $ts);

        foreach ($c as $b) {
            switch ((int) ($b['data_structure_type'] ?? 0)) {
                case 1:  // Aussensensor
                    $o->set('tempC', $this->f2c($b['temp'] ?? null), $ts);
                    $o->set('humPct', $this->z($b['hum'] ?? null), $ts);
                    $o->set('dewC', $this->f2c($b['dew_point'] ?? null), $ts);
                    $o->set('windKmh', $this->mph($b['wind_speed_last'] ?? null), $ts);
                    $o->set('windAvgKmh', $this->mph($b['wind_speed_avg_last_10_min'] ?? null), $ts);
                    $o->set('gustKmh', $this->mph($b['wind_speed_hi_last_10_min'] ?? null), $ts);
                    $o->set('windDirDeg', $this->z($b['wind_dir_last'] ?? null), $ts);
                    $o->set('radiationWm2', $this->z($b['solar_rad'] ?? null), $ts);
                    $o->set('uvIndex', $this->z($b['uv_index'] ?? null), $ts);
                    // Regen zaehlt das Geraet in Wippenschlaegen; ohne die Wippengroesse
                    // waere jede Millimeterangabe geraten.
                    $mm = self::WIPPE_MM[(int) ($b['rain_size'] ?? 0)] ?? null;
                    if ($mm !== null) {
                        $o->set('rainRateMmH', $this->skal($b['rain_rate_last'] ?? null, $mm), $ts);
                        $o->set('rainDayMm', $this->skal($b['rainfall_daily'] ?? null, $mm), $ts);
                        $o->set('rainMonthMm', $this->skal($b['rainfall_monthly'] ?? null, $mm), $ts);
                        $o->set('rainYearMm', $this->skal($b['rainfall_year'] ?? null, $mm), $ts);
                    }
                    break;
                case 3:  // Luftdruck
                    $v = $this->z($b['bar_sea_level'] ?? null);
                    $o->set('pressureHpa', $v === null ? null : round($v * 33.8639, 1), $ts);
                    $o->set('pressureTrend', $this->z($b['bar_trend'] ?? null), $ts);
                    break;
                case 4:  // Innensensor
                    $o->set('tempInC', $this->f2c($b['temp_in'] ?? null), $ts);
                    $o->set('humInPct', $this->z($b['hum_in'] ?? null), $ts);
                    break;
            }
        }
        if ($o->leer()) {
            $this->fehler = 'Antwort gelesen, aber kein bekannter Sensorblock enthalten';
        }
        return $o;
    }

    private function z($v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function f2c($v): ?float
    {
        $v = $this->z($v);
        return $v === null ? null : round(($v - 32) * 5 / 9, 1);
    }

    private function mph($v): ?float
    {
        $v = $this->z($v);
        return $v === null ? null : round($v * 1.609344, 1);
    }

    private function skal($v, float $mm): ?float
    {
        $v = $this->z($v);
        return $v === null ? null : round($v * $mm, 2);
    }
}
