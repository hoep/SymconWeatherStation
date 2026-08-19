<?php

declare(strict_types=1);

namespace Hoep\Weather\Engines;

/**
 * Forecast — Stunden- und Tagesvorhersage von Open-Meteo.
 *
 * Eine Wetterstation misst, sie sagt nicht vorher. Der eingebaute Ausblick einer Davis
 * ("teilweise wolkig") stammt aus einer Faustregel ueber den Luftdruckverlauf und taugt
 * fuer die naechsten Stunden, nicht fuer die Woche. Wer eine Vorhersage will, braucht ein
 * Modell — hier Open-Meteo, frei nutzbar und ohne Schluessel.
 *
 * Das Ergebnis wird UNVERAENDERT durchgereicht, nicht in ein eigenes Format uebersetzt.
 * Anzeigen, die Open-Meteo lesen koennen, kommen damit ohne Umweg zurecht; und was hier
 * nicht umgeschrieben wird, kann auch nicht falsch umgeschrieben werden.
 *
 * Abgefragt wird selten (Voreinstellung halbstuendlich): ein Modell rechnet stuendlich,
 * oefter zu fragen bringt dieselbe Antwort und belastet nur einen fremden Dienst.
 */
final class Forecast
{
    /** Genau die Felder, die eine Wetterkarte braucht — nicht mehr. */
    private const HOURLY = 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,'
                         . 'precipitation_probability,weather_code,pressure_msl,cloud_cover,'
                         . 'wind_speed_10m,wind_gusts_10m,wind_direction_10m,uv_index';
    private const DAILY  = 'weather_code,temperature_2m_max,temperature_2m_min,sunrise,sunset,'
                         . 'precipitation_sum,precipitation_probability_max,uv_index_max,'
                         . 'wind_speed_10m_max,wind_gusts_10m_max,wind_direction_10m_dominant';
    private const CURRENT = 'temperature_2m,relative_humidity_2m,apparent_temperature,'
                          . 'precipitation,weather_code,wind_speed_10m,wind_direction_10m';

    private function __construct()
    {
    }

    /**
     * @return string|null Rohantwort von Open-Meteo, oder null bei Fehler
     */
    public static function fetch(float $lat, float $lon, int $tage = 7, int $timeout = 8): ?string
    {
        if ($lat === 0.0 && $lon === 0.0) {
            return null;
        }
        $url = 'https://api.open-meteo.com/v1/forecast'
             . '?latitude=' . $lat . '&longitude=' . $lon
             . '&current=' . self::CURRENT
             . '&hourly=' . self::HOURLY
             . '&daily=' . self::DAILY
             // Ortszeit, nicht UTC: die Anzeige liest die Zeitangaben ohne Zonenzusatz und
             // deutet sie als lokal. Mit UTC waeren alle Stunden um den Zonenversatz verschoben.
             . '&timezone=auto&forecast_days=' . max(1, min(16, $tage))
             . '&wind_speed_unit=kmh';
        $roh = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => $timeout, 'ignore_errors' => true],
        ]));
        if ($roh === false || $roh === '') {
            return null;
        }
        $j = json_decode($roh, true);
        if (!is_array($j) || !isset($j['daily']['time'])) {
            return null;   // Fehlermeldung des Dienstes oder abgeschnittene Antwort
        }
        return $roh;
    }

    /** Kurzfassung fuer den Probelauf: wie viele Stunden und Tage stecken drin. */
    public static function summary(?string $json): string
    {
        if ($json === null || $json === '') {
            return 'keine Vorhersage';
        }
        $j = json_decode($json, true);
        if (!is_array($j)) {
            return 'unlesbar';
        }
        $h = count($j['hourly']['time'] ?? []);
        $d = count($j['daily']['time'] ?? []);
        $von = $j['daily']['time'][0] ?? '?';
        $bis = $d ? ($j['daily']['time'][$d - 1] ?? '?') : '?';
        return sprintf('%d Stunden, %d Tage (%s bis %s), %s', $h, $d, $von, $bis,
                       ($j['timezone'] ?? 'ohne Zeitzone'));
    }
}
