<?php

declare(strict_types=1);

namespace Hoep\Weather\Engines;

/**
 * Meteo — die meteorologischen Formeln, rein und ohne Seiteneffekte.
 *
 * Keine Symcon-Aufrufe, keine Zustaende, keine Konfiguration: Zahlen rein, Zahlen raus.
 * Damit laesst sich jede einzelne gegen Literaturwerte pruefen, ohne eine Anlage zu haben.
 * Jede Funktion nennt ihre Quelle und ihren Gueltigkeitsbereich.
 */
final class Meteo
{
    private function __construct()
    {
    }

    /**
     * Taupunkt aus Temperatur und relativer Feuchte (Magnus, Koeffizienten nach Sonntag 1990).
     * Gueltig etwa -45..+60 Grad C, Fehler unter 0,1 K.
     */
    public static function taupunkt(float $t, float $rh): float
    {
        $rh = max(0.5, min(100.0, $rh));
        $a  = ($t >= 0.0) ? 17.62 : 22.46;
        $b  = ($t >= 0.0) ? 243.12 : 272.62;
        $g  = log($rh / 100.0) + ($a * $t) / ($b + $t);
        return round(($b * $g) / ($a - $g), 1);
    }

    /**
     * Feuchtkugeltemperatur (Stull 2011), Fehler unter 0,3 K bei -20..+50 Grad und 5..99 %.
     *
     * Sie ist das Mass fuer "Regen oder Schnee", NICHT die Lufttemperatur: eine fallende
     * Flocke kuehlt sich durch Verdunstung selbst. Bei 3 Grad Luft und 40 % Feuchte liegt
     * die Feuchtkugel bei etwa -1,5 Grad — es schneit, obwohl das Thermometer ueber null steht.
     */
    public static function feuchtkugel(float $t, float $rh): float
    {
        $rh = max(1.0, min(100.0, $rh));
        return round(
            $t * atan(0.151977 * sqrt($rh + 8.313659))
            + atan($t + $rh) - atan($rh - 1.676331)
            + 0.00391838 * pow($rh, 1.5) * atan(0.023101 * $rh)
            - 4.686035, 1);
    }

    /** Relative Feuchte aus Temperatur und Taupunkt — die Umkehrung von taupunkt(). */
    public static function feuchte(float $t, float $td): float
    {
        return round(max(0.0, min(100.0,
            100.0 * exp((17.625 * $td) / (243.04 + $td)) / exp((17.625 * $t) / (243.04 + $t)))), 1);
    }

    /**
     * Sonnenhoehe in Grad ueber dem Horizont (NOAA-Kurzformeln, Genauigkeit etwa 0,1 Grad).
     * Ausreichend fuer Bewoelkung und Tag/Nacht; fuer Beschattung sollte der Azimut aus
     * derselben Rechnung kommen, damit beides zusammenpasst.
     */
    public static function sonnenhoehe(float $lat, float $lon, ?int $zeit = null): float
    {
        [$dec, $H] = self::sonnenwinkel($lat, $lon, $zeit ?? time());
        return round(rad2deg(asin(
            sin(deg2rad($lat)) * sin(deg2rad($dec))
            + cos(deg2rad($lat)) * cos(deg2rad($dec)) * cos(deg2rad($H)))), 2);
    }

    /** Sonnenazimut in Grad, 0 = Nord, 90 = Ost. */
    public static function sonnenazimut(float $lat, float $lon, ?int $zeit = null): float
    {
        [$dec, $H] = self::sonnenwinkel($lat, $lon, $zeit ?? time());
        $az = rad2deg(atan2(
            sin(deg2rad($H)),
            cos(deg2rad($H)) * sin(deg2rad($lat)) - tan(deg2rad($dec)) * cos(deg2rad($lat))));
        return round(fmod($az + 180.0 + 360.0, 360.0), 2);
    }

    /** @return array{0:float,1:float} Deklination und Stundenwinkel in Grad */
    private static function sonnenwinkel(float $lat, float $lon, int $zeit): array
    {
        $nn  = ($zeit / 86400.0 + 2440587.5) - 2451545.0;
        $L   = fmod(280.460 + 0.9856474 * $nn, 360.0);
        $g   = fmod(357.528 + 0.9856003 * $nn, 360.0);
        $lam = fmod($L + 1.915 * sin(deg2rad($g)) + 0.020 * sin(deg2rad(2 * $g)), 360.0);
        $eps = 23.439 - 0.0000004 * $nn;
        $ra  = rad2deg(atan2(cos(deg2rad($eps)) * sin(deg2rad($lam)), cos(deg2rad($lam))));
        $dec = rad2deg(asin(sin(deg2rad($eps)) * sin(deg2rad($lam))));
        $gm  = fmod(18.697374558 + 24.06570982441908 * $nn, 24.0);
        $lst = fmod($gm * 15 + $lon + 360.0, 360.0);
        return [$dec, fmod($lst - $ra + 540.0, 360.0) - 180.0];
    }

    /**
     * Globalstrahlung bei wolkenlosem Himmel (Haurwitz 1945), W/m2.
     * Liefert 0, wenn die Sonne unter dem Horizont steht.
     */
    public static function klarhimmel(float $sonnenhoehe): float
    {
        if ($sonnenhoehe <= 0.0) {
            return 0.0;
        }
        $cz = sin(deg2rad($sonnenhoehe));
        return round(1098.0 * $cz * exp(-0.059 / max(0.001, $cz)), 1);
    }

    /**
     * Bewoelkungsgrad 0..1 aus gemessener und theoretischer Strahlung (Kasten & Czeplak 1980,
     * nach Bedeckungsgrad aufgeloest).
     *
     * Gibt NULL zurueck, wenn die Sonne zu tief steht: unter etwa 5 Grad ist der Klarhimmel-
     * wert so klein, dass jede Messungenauigkeit den Bedeckungsgrad zwischen 0 und 8 Achteln
     * springen laesst. Eine ehrliche Nichtaussage ist besser als eine erfundene Zahl.
     */
    public static function bewoelkung(float $gemessen, float $sonnenhoehe): ?float
    {
        if ($sonnenhoehe <= 5.0) {
            return null;
        }
        $klar = self::klarhimmel($sonnenhoehe);
        if ($klar <= 40.0) {
            return null;
        }
        $kt = max(0.0, min(1.05, $gemessen / $klar));
        if ($kt >= 1.0) {
            return 0.0;
        }
        return round(max(0.0, min(1.0, pow(max(0.0, (1.0 - $kt) / 0.75), 1.0 / 3.4))), 3);
    }

    /**
     * Fog Stability Index (Flugmeteorologie): FSI = 2*(T-Td) + 2*(T-T850) + W850.
     * T850 in Grad C, W850 in KNOTEN. Schwellen: < 31 hohe, 31..55 mittlere, > 55 geringe
     * Nebelwahrscheinlichkeit.
     *
     * Die Literatur setzt Radiosondenaufstiege voraus (zweimal taeglich). Mit stuendlichen
     * 850-hPa-Werten aus einem Vorhersagemodell ist der Index sogar aktueller als im Original.
     */
    public static function fsi(float $t, float $td, float $t850, float $w850kn): float
    {
        return round(2.0 * ($t - $td) + 2.0 * ($t - $t850) + $w850kn, 1);
    }

    /** Windgeschwindigkeit km/h in Knoten. */
    public static function kmhInKnoten(float $kmh): float
    {
        return round($kmh / 1.852, 1);
    }

    /**
     * Mondphase 0..1 (0 = Neumond, 0,5 = Vollmond) und Beleuchtungsgrad 0..1.
     * Genauigkeit etwa 0,5 Prozent — fuer eine Anzeige mehr als genug.
     * @return array{phase:float,beleuchtet:float,zunehmend:bool}
     */
    public static function mond(?int $zeit = null): array
    {
        $nn    = (($zeit ?? time()) / 86400.0 + 2440587.5) - 2451545.0;
        $phase = fmod((fmod($nn, 29.530588853) + 29.530588853) / 29.530588853, 1.0);
        return ['phase' => round($phase, 4),
                'beleuchtet' => round((1 - cos(2 * M_PI * $phase)) / 2, 4),
                'zunehmend' => $phase < 0.5];
    }
}
