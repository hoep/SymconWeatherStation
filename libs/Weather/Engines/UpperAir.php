<?php

declare(strict_types=1);

namespace Hoep\Weather\Engines;

/**
 * UpperAir — Temperatur und Wind auf 850 hPa, also rund 1500 m ueber dem Meer.
 *
 * Diese beiden Werte entscheiden, ob sich bodennaher Nebel halten kann: ist es oben waermer
 * als unten, liegt eine Sperrschicht darueber und die feuchte Luft bleibt liegen. Ohne sie
 * ist der Fog Stability Index nicht zu rechnen.
 *
 * In der Literatur gilt das als Nachteil des Verfahrens, weil Radiosonden nur zweimal
 * taeglich aufsteigen. Aus einem Vorhersagemodell kommen die Werte stuendlich — der Index
 * ist hier also aktueller als in seiner Originalfassung.
 *
 * Der Abruf gehoert nicht in den Messtakt: einmal je Stunde genuegt, und das Ergebnis wird
 * vom Aufrufer zwischengespeichert (die Engine selbst haelt bewusst keinen Zustand).
 */
final class UpperAir
{
    private function __construct()
    {
    }

    /**
     * @return array{t:float,w:float,ts:int}|null Temperatur in Grad C, Wind in KNOTEN
     */
    public static function fetch(float $lat, float $lon, int $timeout = 6): ?array
    {
        $url = 'https://api.open-meteo.com/v1/forecast'
             . '?latitude=' . $lat . '&longitude=' . $lon
             . '&hourly=temperature_850hPa,wind_speed_850hPa'
             . '&forecast_days=1&timezone=UTC';
        $roh = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => $timeout, 'ignore_errors' => true],
        ]));
        if ($roh === false) {
            return null;
        }
        $j  = json_decode($roh, true);
        $tt = $j['hourly']['temperature_850hPa'] ?? null;
        $ww = $j['hourly']['wind_speed_850hPa'] ?? null;
        $zz = $j['hourly']['time'] ?? null;
        if (!is_array($tt) || !is_array($ww) || $tt === []) {
            return null;
        }
        // Die Zeitreihe deckt den ganzen Tag ab; gesucht ist die laufende Stunde in UTC.
        $i = is_array($zz) ? self::stundeIndex($zz) : (int) gmdate('G');
        $i = max(0, min(count($tt) - 1, $i));
        if (!is_numeric($tt[$i]) || !is_numeric($ww[$i])) {
            return null;
        }
        return ['t' => (float) $tt[$i],
                'w' => round((float) $ww[$i] / 1.852, 1),   // km/h in Knoten
                'ts' => time()];
    }

    /** @param array<int,string> $zeiten ISO-Zeitpunkte in UTC */
    private static function stundeIndex(array $zeiten): int
    {
        $ziel = gmdate('Y-m-d\TH:00');
        foreach ($zeiten as $i => $z) {
            if (strpos((string) $z, $ziel) === 0) {
                return (int) $i;
            }
        }
        return (int) gmdate('G');
    }
}
