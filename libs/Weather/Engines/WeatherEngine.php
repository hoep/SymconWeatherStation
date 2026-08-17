<?php

declare(strict_types=1);

namespace Hoep\Weather\Engines;

use Hoep\Weather\Observation;

/**
 * WeatherEngine — leitet aus einer Beobachtung die Groessen ab, die keine Station misst.
 *
 * Kein Symcon-Aufruf, kein Zustand ausser dem, was hereingereicht wird. Alles, was die
 * Engine braucht (Hoehenwerte, Blitzspeicher, Kamerasicht), kommt als Parameter herein und
 * geht als Ergebnis heraus. Damit laesst sich jede Ableitung einzeln nachrechnen, und die
 * Beschattung kann sie direkt aufrufen, statt Variablen abzugreifen.
 */
final class WeatherEngine
{
    public const NEBEL_KEIN = 0, NEBEL_DIESIG = 1, NEBEL_NEBEL = 2, NEBEL_DICHT = 3;
    public const GEW_KEIN = 0, GEW_LEUCHTEN = 1, GEW_GEWITTER = 2, GEW_NAH = 3;
    public const NS_KEIN = 0, NS_REGEN = 1, NS_SCHNEEREGEN = 2, NS_SCHNEE = 3;

    /** Voreinstellungen; das Modul reicht die konfigurierten Werte herein. */
    public const STD = [
        'fogHum' => 94.0, 'fogWind' => 11.0, 'fogSpread' => 2.5,
        'sightWarn' => 55, 'sightFog' => 35,
        'stormNearKm' => 10, 'stormNearMin' => 15,
        'stormFarKm' => 25, 'stormFarMin' => 20,
    ];

    private function __construct()
    {
    }

    /**
     * Nebel: erst Regelsatz als Torwaechter, dann Fog Stability Index fuer die Staerke,
     * zuletzt die Kamera als Schiedsrichter.
     *
     * Der Regelsatz (Feuchte, Wind, Taupunktdifferenz) stammt aus vergleichenden Studien zur
     * Nebelvorhersage und schliesst die grosse Mehrzahl der Fehlmeldungen aus. Er sagt
     * allerdings nur "moeglich" — die Staerke kommt aus dem FSI, der die Schichtung ueber
     * dem Boden einbezieht: Nebel braucht eine Sperrschicht, sonst mischt er sich weg.
     *
     * Die KAMERA schlaegt beides. Sie misst, was die Rechnung nur schaetzt. Sieht sie nichts
     * mehr, ist Nebel — auch wenn die Schwellen es nicht hergeben. Sieht sie klar, wird eine
     * gerechnete Stufe zurueckgenommen. Genau dafuer ist sie da.
     *
     * @param array{t:float,w:float}|null $hoehe 850-hPa-Temperatur (Grad C) und -Wind (Knoten)
     * @param float|null $sicht Kamerasicht in Prozent des Klarwerts, null wenn keine Kamera
     * @return array{stufe:int,fsi:float|null,text:string}
     */
    public static function nebel(Observation $o, ?array $hoehe, ?float $sicht, array $cfg = []): array
    {
        $c  = $cfg + self::STD;
        $t  = $o->num('tempC');
        $rh = $o->num('humPct');
        $td = $o->num('dewC');
        $ws = $o->num('windKmh') ?? $o->num('windAvgKmh');
        $mm = $o->num('rainRateMmH') ?? 0.0;

        $stufe = self::NEBEL_KEIN;
        $fsi   = null;
        $spread = ($t !== null && $td !== null) ? $t - $td : null;

        if ($t === null || $rh === null || $spread === null || $ws === null) {
            $text = 'Sensoren unvollständig — Temperatur, Feuchte, Taupunkt und Wind nötig';
        } elseif ($mm > 0.01) {
            $text = 'Niederschlag — das ist Regendunst, kein Nebel';
        } elseif ($rh < $c['fogHum']) {
            $text = sprintf('Luftfeuchte %.0f %% unter %.0f %%', $rh, $c['fogHum']);
        } elseif ($ws > $c['fogWind']) {
            $text = sprintf('Wind %.0f km/h über %.0f km/h — Nebel hält sich nicht', $ws, $c['fogWind']);
        } elseif ($spread >= $c['fogSpread']) {
            $text = sprintf('Taupunktdifferenz %.1f K — ab %.1f K zu trocken', $spread, $c['fogSpread']);
        } elseif ($hoehe === null) {
            $fsi   = round(2.0 * $spread, 1);
            $stufe = self::NEBEL_NEBEL;
            $text  = sprintf('Regelsatz erfüllt (Feuchte %.0f %%, Wind %.0f km/h, Spread %.1f K); '
                           . 'Höhenwerte fehlen, daher ohne Stärkeangabe', $rh, $ws, $spread);
        } else {
            $fsi   = Meteo::fsi($t, $td, $hoehe['t'], $hoehe['w']);
            $stufe = ($fsi < 31.0) ? self::NEBEL_DICHT : (($fsi <= 55.0) ? self::NEBEL_NEBEL : self::NEBEL_DIESIG);
            $text  = sprintf('FSI %.0f aus Taupunktdifferenz %.1f K, Schichtung %.1f K, Höhenwind %.0f kn',
                             $fsi, $spread, $t - $hoehe['t'], $hoehe['w']);
        }

        if ($sicht !== null) {
            if ($sicht <= $c['sightFog']) {
                $stufe = max($stufe, self::NEBEL_DICHT);
                $text .= sprintf(' | Kamera: Sicht %d %% des Klarwerts — gemessen dicht', (int) $sicht);
            } elseif ($sicht <= $c['sightWarn']) {
                $stufe = max($stufe, self::NEBEL_NEBEL);
                $text .= sprintf(' | Kamera: Sicht %d %% — eingeschränkt', (int) $sicht);
            } elseif ($stufe >= self::NEBEL_NEBEL && $sicht > 85) {
                $stufe = self::NEBEL_DIESIG;
                $text .= sprintf(' | Kamera: Sicht %d %% — gerechnete Stufe zurückgenommen', (int) $sicht);
            }
        }
        return ['stufe' => $stufe, 'fsi' => $fsi, 'text' => $text];
    }

    /**
     * Gewitter aus einem Ringspeicher von Blitzereignissen.
     *
     * Eine Ereignisvariable kennt immer nur den LETZTEN Schlag. Damit laesst sich "ein Blitz
     * in 40 km" nicht von "zwoelf Blitzen in 5 km" unterscheiden — beides waere "letzter Blitz
     * vor 2 Minuten". Erst der Ringspeicher macht Entfernung UND Haeufigkeit auswertbar, und
     * erst damit ist die Meldung eine Aussage ueber die Lage statt ueber ein Ereignis.
     *
     * @param array<int,array{t:int,d:float}> $ring bisheriger Speicher
     * @return array{stufe:int,dist:int,rate:int,last:int,text:string,ring:array}
     */
    public static function gewitter(Observation $o, array $ring, array $cfg = [], ?int $jetzt = null): array
    {
        $c  = $cfg + self::STD;
        $nun = $jetzt ?? time();

        $bt = (int) ($o->num('strikeTime') ?? 0);
        $bd = $o->num('strikeDistKm');
        if ($bt > 0 && ($nun - $bt) < 7200 && $bd !== null) {
            $neu = true;
            foreach ($ring as $e) {
                if ((int) $e['t'] === $bt) { $neu = false; break; }
            }
            if ($neu) {
                $ring[] = ['t' => $bt, 'd' => (float) $bd];
            }
        }
        // Aelter als eine Stunde interessiert nicht mehr und laesst den Speicher nicht wachsen.
        $ring = array_values(array_filter($ring, static fn($e) => ($nun - (int) $e['t']) <= 3600));

        $nahS = $c['stormNearMin'] * 60;
        $farS = $c['stormFarMin'] * 60;
        $nah = null; $fern = null; $rate = 0; $last = 0; $dl = null;
        foreach ($ring as $e) {
            $alt = $nun - (int) $e['t'];
            $d   = (float) $e['d'];
            if ($alt <= 1800) { $rate++; }
            if ((int) $e['t'] > $last) { $last = (int) $e['t']; $dl = $d; }
            if ($alt <= $nahS && $d < $c['stormNearKm']) { $nah = $nah === null ? $d : min($nah, $d); }
            if ($alt <= $farS && $d < $c['stormFarKm'])  { $fern = $fern === null ? $d : min($fern, $d); }
        }

        $stufe = self::GEW_KEIN;
        if ($nah !== null) {
            $stufe = self::GEW_NAH;
        } elseif ($fern !== null) {
            $stufe = self::GEW_GEWITTER;
        } elseif ($last > 0 && ($nun - $last) <= 1800) {
            $stufe = self::GEW_LEUCHTEN;
        }

        $text = 'kein Gewitter';
        if ($stufe > 0) {
            $text = ['', 'Wetterleuchten', 'Gewitter', 'Gewitter in der Nähe'][$stufe]
                  . ($dl !== null ? sprintf(', letzter Blitz %.0f km', $dl) : '')
                  . sprintf(' vor %d min', max(0, (int) round(($nun - $last) / 60)))
                  . ($rate > 1 ? sprintf(', %d Blitze in 30 min', $rate) : '');
        }
        return ['stufe' => $stufe, 'dist' => $dl === null ? 0 : (int) round($dl),
                'rate' => $rate, 'last' => $last, 'text' => $text, 'ring' => $ring];
    }

    /**
     * Bewoelkung aus der gemessenen Strahlung. Nachts nicht bestimmbar — dann null, und die
     * Anzeige haelt den letzten Tageswert, statt "wolkenlos" zu behaupten.
     *
     * @return array{pct:float|null,quelle:string}
     */
    public static function bewoelkung(Observation $o, float $lat, float $lon, ?int $zeit = null): array
    {
        $rad = $o->num('radiationWm2');
        if ($rad === null) {
            return ['pct' => null, 'quelle' => 'keine Strahlungsmessung'];
        }
        $h = Meteo::sonnenhoehe($lat, $lon, $zeit);
        $n = Meteo::bewoelkung($rad, $h);
        if ($n === null) {
            return ['pct' => null, 'quelle' => sprintf('Sonne %.1f° — zu tief für eine Aussage', $h)];
        }
        return ['pct' => round($n * 100, 1),
                'quelle' => sprintf('Strahlung %.0f von %.0f W/m² klar, Sonne %.1f°',
                                    $rad, Meteo::klarhimmel($h), $h)];
    }

    /**
     * Niederschlagsart aus der FEUCHTKUGEL, nicht aus der Lufttemperatur.
     *
     * Eine Regenwippe zaehlt Schnee erst, wenn er geschmolzen ist, und kein Haushaltssensor
     * unterscheidet Regen von Schnee. Die Feuchtkugel tut es: unter 0,5 Grad faellt Schnee,
     * ueber 1,5 Grad Regen, dazwischen beides.
     *
     * @return array{art:int,twet:float|null,text:string}
     */
    public static function niederschlag(Observation $o): array
    {
        $mm = $o->num('rainRateMmH') ?? 0.0;
        $t  = $o->num('tempC');
        $rh = $o->num('humPct');
        if ($t === null || $rh === null) {
            return ['art' => $mm > 0.01 ? self::NS_REGEN : self::NS_KEIN, 'twet' => null,
                    'text' => 'ohne Temperatur und Feuchte nicht unterscheidbar'];
        }
        $tw = Meteo::feuchtkugel($t, $rh);
        if ($mm <= 0.01) {
            return ['art' => self::NS_KEIN, 'twet' => $tw, 'text' => 'kein Niederschlag'];
        }
        if ($tw < 0.5) {
            return ['art' => self::NS_SCHNEE, 'twet' => $tw, 'text' => sprintf('Schnee (Feuchtkugel %.1f °C)', $tw)];
        }
        if ($tw <= 1.5) {
            return ['art' => self::NS_SCHNEEREGEN, 'twet' => $tw, 'text' => sprintf('Schneeregen (Feuchtkugel %.1f °C)', $tw)];
        }
        return ['art' => self::NS_REGEN, 'twet' => $tw, 'text' => sprintf('Regen (Feuchtkugel %.1f °C)', $tw)];
    }

    /**
     * Die Wetterlage in einem Satzstueck. Reihenfolge nach AUFFAELLIGKEIT, nicht nach
     * Datenherkunft: was jemand beim Blick aus dem Fenster zuerst benennen wuerde, steht vorn.
     * Ein Gewitter ist wichtiger als der Bedeckungsgrad, auch wenn die Wolken laenger da sind.
     */
    public static function wetterlage(int $gewitter, array $ns, int $nebel, ?float $wolkenPct): string
    {
        if ($gewitter >= self::GEW_GEWITTER) {
            return ($gewitter === self::GEW_NAH ? 'Gewitter in der Nähe' : 'Gewitter')
                 . ($ns['art'] > self::NS_KEIN ? ' mit Regen' : '');
        }
        if ($ns['art'] === self::NS_SCHNEE || $ns['art'] === self::NS_SCHNEEREGEN) {
            return $ns['art'] === self::NS_SCHNEEREGEN ? 'Schneeregen' : 'Schneefall';
        }
        if ($ns['art'] === self::NS_REGEN) {
            return 'Regen';
        }
        if ($nebel >= self::NEBEL_NEBEL) {
            return $nebel === self::NEBEL_DICHT ? 'dichter Nebel' : 'Nebel';
        }
        if ($wolkenPct === null) {
            return $nebel === self::NEBEL_DIESIG ? 'diesig' : 'keine Bewölkungsaussage';
        }
        $b = $wolkenPct / 100.0;
        $txt = ($b < 0.125) ? 'klar' : (($b < 0.375) ? 'heiter'
             : (($b < 0.625) ? 'wolkig' : (($b < 0.875) ? 'stark bewölkt' : 'bedeckt')));
        if ($nebel === self::NEBEL_DIESIG) { $txt .= ', diesig'; }
        if ($gewitter === self::GEW_LEUCHTEN) { $txt .= ', Wetterleuchten'; }
        return $txt;
    }
}
