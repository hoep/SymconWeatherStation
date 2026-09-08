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
     * Schwelle der Globalstrahlung, ab der SONNENSCHEIN gezaehlt wird (W/m2).
     *
     * Nicht dasselbe wie "es ist hell": die Weltorganisation fuer Meteorologie definiert
     * Sonnenscheindauer ueber die DIREKTE Strahlung (ab 120 W/m2). Ein Globalstrahlungs-
     * messer sieht Direkt- und Streulicht zusammen und misst an einem trueben Sommertag
     * mehr als an einem klaren Wintermorgen - eine feste Zahl taugt deshalb nicht.
     *
     * Gebraeuchlich ist stattdessen die Naeherung von Carpentier: rund drei Viertel des
     * Klarhimmelwerts, jahreszeitlich korrigiert. Wird sie ueberschritten, kommt genug
     * direkte Strahlung an, dass ein Schatten fiele.
     *
     * Unter 3 Grad Sonnenhoehe gibt es keine Schwelle (0): so flach faellt kein Licht mehr
     * ein, das man Sonnenschein nennen wuerde, und die Formel wuerde beliebig klein.
     */
    public static function sonnenscheinSchwelle(float $sonnenhoehe, ?int $zeit = null): float
    {
        if ($sonnenhoehe <= 3.0) {
            return 0.0;
        }
        $tag = (int) date('z', $zeit ?? time()) + 1;
        $jahreszeit = 0.73 + 0.06 * cos(deg2rad(360.0 * $tag / 365.0));
        return round($jahreszeit * 1080.0 * pow(sin(deg2rad($sonnenhoehe)), 1.25), 0);
    }

    /**
     * Bewoelkungsgrad 0..1 aus gemessener und theoretischer Strahlung (Kasten & Czeplak 1980,
     * nach Bedeckungsgrad aufgeloest).
     *
     * DER KLARHIMMELWERT MUSS GELERNT SEIN, sonst ist das Ergebnis wertlos. Haurwitz ist eine
     * weltweite Faustformel; sie kennt weder Seehoehe noch Horizont, Truebung oder ein
     * gealtertes Sensorglas. Und die Umkehrung zieht die dritte Wurzel: schon anderthalb
     * Prozent zu wenig Strahlung ergeben zweiunddreissig Prozent Bewoelkung. Gemessen am
     * eigenen Bestand am 08.09.2026, einem Tag mit 0 % Modellbewoelkung bis 14 Uhr:
     *
     *   09:00  Sonne 24°  327 von 393 W/m2 nach Haurwitz  ->  62 % Bewoelkung
     *   11:00  Sonne 40°  640 von 650                     ->  33 %
     *   13:00  Sonne 47°  777 von 746                     ->   0 %
     *
     * Also meldete die Anlage an einem wolkenlosen Vormittag zwei Drittel Bewoelkung. Der
     * Faktor korrigiert genau das: er ist das gelernte Verhaeltnis "gemessen zu Haurwitz" bei
     * klarem Himmel, je Fach der Sonnenhoehe, und macht aus der Weltformel eine Kennlinie
     * DIESES Standorts mit DIESEM Sensor.
     *
     * Gibt NULL zurueck, wenn die Sonne zu tief steht. Die Grenze liegt bei 10 Grad, frueher
     * bei 5: bei 5 Grad Sonnenhoehe stand am selben Abend ein Klarhimmelwert von 57 W/m2
     * gegen 23 gemessene - daraus wurden 93 % Bewoelkung bei sternklarem Himmel. So flach
     * laeuft das Licht durch die zehnfache Luftmasse und streift jeden Baum am Horizont;
     * eine ehrliche Nichtaussage ist besser als eine erfundene Zahl.
     *
     * @param float $faktor gelerntes Verhaeltnis gemessen/Haurwitz bei klarem Himmel (1.0 = ungelernt)
     */
    public static function bewoelkung(float $gemessen, float $sonnenhoehe, float $faktor = 1.0): ?float
    {
        if ($sonnenhoehe <= self::STRAHLUNG_MIN_HOEHE) {
            return null;
        }
        $klar = self::klarhimmel($sonnenhoehe) * max(0.55, min(1.35, $faktor));
        if ($klar <= 40.0) {
            return null;
        }
        $kt = max(0.0, min(1.05, $gemessen / $klar));
        if ($kt >= 1.0) {
            return 0.0;
        }
        return round(max(0.0, min(1.0, pow(max(0.0, (1.0 - $kt) / 0.75), 1.0 / 3.4))), 3);
    }

    /** Unter dieser Sonnenhoehe traegt die Globalstrahlung keine Bewoelkungsaussage mehr. */
    public const STRAHLUNG_MIN_HOEHE = 10.0;

    /**
     * Fach der Sonnenhoehe fuer den gelernten Klarhimmel-Faktor.
     *
     * Getrennt gelernt, weil die Abweichung von Haurwitz mit dem Sonnenstand waechst: bei
     * hohem Stand passt die Formel gut (13:00 kt = 1,04), bei flachem laeuft das Licht durch
     * ein Vielfaches an Luft und die Formel ueberschaetzt (09:00 kt = 0,83). Ein gemeinsamer
     * Faktor wuerde den Mittag verderben, um den Vormittag zu retten.
     */
    public static function strahlungFach(float $sonnenhoehe): string
    {
        foreach ([15.0, 22.0, 30.0, 40.0, 50.0] as $i => $g) {
            if ($sonnenhoehe < $g) {
                return 's' . $i;
            }
        }
        return 's5';
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

    /**
     * Dampfdruck in hPa aus Temperatur und relativer Feuchte (Magnus).
     * Zwischengroesse fuer die absolute Feuchte und die gefuehlte Temperatur.
     */
    public static function dampfdruck(float $t, float $rh): float
    {
        $a = ($t >= 0.0) ? 17.62 : 22.46;
        $b = ($t >= 0.0) ? 243.12 : 272.62;
        return round(max(0.0, min(100.0, $rh)) / 100.0 * 6.112 * exp(($a * $t) / ($b + $t)), 4);
    }

    /**
     * Absolute Feuchte in Gramm je Kubikmeter: AH = 216,69 * e / T.
     *
     * Der Faktor ist 10^5 * M(Wasser) / R = 100000 * 18,016 / 8314,3. Anders als die relative
     * Feuchte haengt sie nicht von der Temperatur ab und ist deshalb das richtige Mass, wenn
     * man Innen- und Aussenluft vergleicht — etwa fuer die Frage, ob Lueften die Raumluft
     * trockener macht oder feuchter.
     */
    public static function absoluteFeuchte(float $t, float $rh): float
    {
        return round(216.69 * self::dampfdruck($t, $rh) / ($t + 273.15), 2);
    }

    /**
     * Gefuehlte Temperatur in Grad C.
     *
     * Drei Verfahren, je nach Lage — ein einziges gibt es nicht, weil bei Hitze die Feuchte
     * und bei Kaelte der Wind entscheidet:
     *
     * - ab 27 Grad der HITZEINDEX nach Rothfusz (Feuchte staut die Waerme, Schweiss verdunstet
     *   nicht mehr),
     * - unter 10 Grad bei mehr als 4,8 km/h Wind der WINDCHILL nach JAG/TI 2001 (Wind traegt
     *   die waermende Grenzschicht ab),
     * - dazwischen die AUSTRALISCHE Scheinbare Temperatur nach Steadman, die beides milder
     *   verrechnet: AT = T + 0,33e − 0,70v − 4,00 mit e in hPa und v in m/s.
     */
    public static function gefuehlt(float $t, float $rh, float $windKmh): float
    {
        if ($t >= 27.0) {
            $tf = $t * 9 / 5 + 32;
            $hi = -42.379 + 2.04901523 * $tf + 10.14333127 * $rh
                - 0.22475541 * $tf * $rh - 0.00683783 * $tf * $tf
                - 0.05481717 * $rh * $rh + 0.00122874 * $tf * $tf * $rh
                + 0.00085282 * $tf * $rh * $rh - 0.00000199 * $tf * $tf * $rh * $rh;
            return round(($hi - 32) * 5 / 9, 1);
        }
        if ($t <= 10.0 && $windKmh > 4.8) {
            $v = pow($windKmh, 0.16);
            return round(13.12 + 0.6215 * $t - 11.37 * $v + 0.3965 * $t * $v, 1);
        }
        return round($t + 0.33 * self::dampfdruck($t, $rh) - 0.70 * ($windKmh / 3.6) - 4.00, 1);
    }

    /**
     * WBGT — Wet Bulb Globe Temperature, das arbeitsmedizinische Mass fuer HITZEBELASTUNG
     * (ISO 7243, in Deutschland Grundlage der DGUV-Grenzwerte).
     *
     * Die Messgroesse ist WBGT = 0,7*Tnw + 0,2*Tg + 0,1*Ta aus drei Fuehlern: einem feuchten,
     * frei beluefteten Thermometer (70 Prozent Gewicht!), einer schwarzen Kugel und einem
     * trockenen. Ohne Strahlungslast nimmt die Kugel die Lufttemperatur an (Tg = Ta), und die
     * Definition faellt zusammen zu der Form, die wir hier rechnen:
     *
     *     WBGT = 0,7*Tw + 0,3*Ta          Tw = Feuchtkugel (psychrometrisch)
     *
     * Die Feuchtkugel haben wir bereits gemessen bzw. gerechnet - das ist die ehrlichste
     * Grundlage und braucht keine fremden Anpassungskoeffizienten.
     *
     * VERWORFEN: die verbreitete Naeherung WBGT = 0,567*T + 0,393*e + 3,94 (Australisches BoM).
     * Sie wird oft als "Schattenwert" ausgegeben, ist es aber NICHT: sie unterstellt maessig
     * hohe Strahlung bei schwachem Wind und ueberschaetzt daher bei Bewoelkung, Wind und vor
     * allem nachts. Gemessen am 19.08.2026 um 20:55 (Sonne unter dem Horizont, 24,1 Grad,
     * Feuchtkugel 19,5): Naeherung 25,4 Grad, ISO-Form 20,9 Grad - 4,5 K Unterschied, und die
     * Naeherung haette faelschlich "erhoehte Belastung" gemeldet.
     *
     * OFFEN: der Wert IN DER SONNE. Dafuer braucht es die Kugeltemperatur aus der
     * Strahlungsbilanz (Liljegren 2008 iterativ, oder Dimiceli/Piltz/Amburn geschlossen).
     * Beide brauchen Globalstrahlung, Wind und Sonnenzenit - alles vorhanden, aber die
     * Koeffizienten gehoeren aus der Originalarbeit belegt, nicht geraten. Bis dahin gilt der
     * hier gerechnete Wert OHNE Strahlungslast; in praller Sonne liegt der echte WBGT hoeher.
     *
     * Unterschied zur FEUCHTKUGEL selbst: die sagt, ob Schwitzen physikalisch noch kuehlen KANN
     * (Ueberlebensgrenze 35 Grad). Der WBGT sagt, ab wann Arbeit oder Sport gefaehrlich wird -
     * die Schwellen liegen deshalb viel tiefer.
     */
    public static function wbgt(float $t, float $rh): float
    {
        return round(0.7 * self::feuchtkugel($t, $rh) + 0.3 * $t, 1);
    }

    /**
     * ET0 — Gras-Referenzverdunstung nach FAO-56 Penman-Monteith, STUNDENFORM (Gl. 53),
     * Ergebnis in mm je Stunde.
     *
     * Das ist die Wassermenge, die eine gleichmaessig 12 cm hohe, gut versorgte Grasflaeche
     * unter den gemessenen Bedingungen verdunstet - der internationale Bezugswert fuer
     * Bewaesserung. Anders als die Verdunstungsangabe der Davis ist sie nachrechenbar und
     * zwischen Anlagen vergleichbar.
     *
     *   ET0 = [0,408*D*(Rn-G) + g*(37/(T+273))*u2*(es-ea)] / [D + g*(1 + 0,34*u2)]
     *
     * D  Steigung der Saettigungsdampfdruckkurve [kPa/K]
     * g  Psychrometerkonstante 0,665e-3 * P [kPa/K]
     * Rn Nettostrahlung [MJ/m2/h], G Bodenwaermestrom (0,1*Rn tags, 0,5*Rn nachts)
     * u2 Wind in 2 m Hoehe [m/s], es-ea Dampfdruckdefizit [kPa]
     *
     * NETTOSTRAHLUNG: Rn = (1-0,23)*Rs - Rnl, mit der langwelligen Ausstrahlung
     *   Rnl = s*(T+273,16)^4 * (0,34 - 0,14*sqrt(ea)) * (1,35*Rs/Rso - 0,35).
     * Der Term Rs/Rso beschreibt die Bewoelkung. NACHTS ist er nicht bestimmbar (Rso = 0);
     * FAO-56 sagt dafuer ausdruecklich, das Verhaeltnis der letzten Tagesstunden zu verwenden -
     * genau dafuer ist $ratio da. Fehlt es, wird 0,8 (leicht bewoelkt) angenommen; das ist
     * eine Annahme und keine Messung, sie steht deshalb im Rueckgabewert.
     *
     * @param float      $u2    Wind in 2 m Hoehe in m/s (siehe windAuf2m())
     * @param float      $rs    gemessene Globalstrahlung W/m2
     * @param float      $rso   Klarhimmelstrahlung W/m2 (aus klarhimmel())
     * @param float|null $ratio zuletzt tagsueber gemessenes Rs/Rso, fuer die Nachtstunden
     * @return array{et0:float,ratio:float|null,nacht:bool}
     */
    public static function et0(float $t, float $rh, float $u2, float $rs,
                               float $pHpa, float $rso, ?float $ratio = null): array
    {
        $es = self::dampfdruck($t, 100.0) / 10.0;        // kPa
        $ea = self::dampfdruck($t, $rh) / 10.0;          // kPa
        $D  = 4098.0 * $es / pow($t + 237.3, 2);         // kPa/K
        $g  = 0.000665 * ($pHpa / 10.0);                 // kPa/K
        $u2 = max(0.0, $u2);

        $rsMJ  = max(0.0, $rs) * 3600.0 / 1e6;           // W/m2 -> MJ/m2/h
        $rsoMJ = max(0.0, $rso) * 3600.0 / 1e6;
        $tag   = $rsoMJ > 0.01;
        $q     = $tag ? max(0.0, min(1.0, $rsMJ / $rsoMJ)) : null;
        $qEff  = $tag ? $q : ($ratio ?? 0.8);

        $rns = 0.77 * $rsMJ;                              // Albedo Gras 0,23
        $rnl = 2.043e-10 * pow($t + 273.16, 4)
             * (0.34 - 0.14 * sqrt(max(0.0, $ea)))
             * (1.35 * max(0.0, min(1.0, $qEff)) - 0.35);
        $rn  = $rns - $rnl;
        $G   = $tag ? 0.1 * $rn : 0.5 * $rn;

        $zaehler = 0.408 * $D * ($rn - $G) + $g * (37.0 / ($t + 273.0)) * $u2 * ($es - $ea);
        $nenner  = $D + $g * (1.0 + 0.34 * $u2);
        return ['et0' => round($nenner > 0 ? $zaehler / $nenner : 0.0, 4),
                'ratio' => $q, 'nacht' => !$tag];
    }

    /**
     * Wind auf 2 m Hoehe umrechnen (FAO-56 Gl. 47): u2 = uz * 4,87 / ln(67,8z - 5,42).
     * Ohne diesen Schritt ueberschaetzt ein 10-m-Mast die Verdunstung deutlich.
     */
    public static function windAuf2m(float $windKmh, float $hoeheM): float
    {
        $u = max(0.0, $windKmh) / 3.6;
        if ($hoeheM <= 0.0 || abs($hoeheM - 2.0) < 0.01) { return $u; }
        return $u * 4.87 / log(67.8 * $hoeheM - 5.42);
    }

    /**
     * DAMPFDRUCKDEFIZIT (VPD) in hPa: Saettigungsdampfdruck minus tatsaechlicher Dampfdruck.
     *
     * Das Mass fuer den Trocknungsdruck der Luft. Pflanzen steuern danach ihre Spaltoeffnungen:
     * unter etwa 4 hPa steht die Luft (Pilzdruck, kaum Verdunstung), 4-12 hPa ist der
     * Wohlfuehlbereich, ueber 16 hPa schliessen viele Pflanzen die Spalten und stellen das
     * Wachstum ein - dann nuetzt auch Giessen wenig, weil die Wurzel nicht nachliefern kann.
     *
     * Anders als die relative Feuchte ist es die Groesse, die tatsaechlich antreibt: 60 % bei
     * 30 Grad trocknen weit staerker als 60 % bei 10 Grad.
     */
    public static function vpd(float $t, float $rh): float
    {
        $sat = self::dampfdruck($t, 100.0);
        return round(max(0.0, $sat - self::dampfdruck($t, $rh)), 2);
    }

    /**
     * KUEHLRESERVE (Verdunstungskaelte) in Kelvin: T - Tw.
     *
     * Der Abstand zwischen Luft- und Feuchtkugeltemperatur ist genau die Kuehlung, die
     * Verdunstung noch leisten KANN - beim Menschen also das Schwitzen. Er faellt mit
     * steigender Luftfeuchte gegen null; bei null ist die Luft gesaettigt, Schweiss verdunstet
     * nicht mehr und die Koerperkerntemperatur steigt unweigerlich.
     *
     * Die Feuchtkugel liegt immer zwischen Taupunkt und Lufttemperatur - deshalb sind
     * Taupunkt, Temperatur und Feuchtkugel dieselbe Aussage aus drei Blickwinkeln, und die
     * Differenz ist die eigentliche Kennzahl der Hitzebelastung durch Schwuele.
     */
    public static function kuehlreserve(float $t, float $rh): float
    {
        return round($t - self::feuchtkugel($t, $rh), 1);
    }

    /**
     * Stufe der Kuehlreserve. Die Grenzen folgen der Aussage der Feuchtkugel-Gefahrenzonen:
     * viel Abstand = Schweiss kuehlt wirksam, kein Abstand = keine Kuehlung mehr moeglich.
     * @return array{stufe:int,name:string,hinweis:string}
     */
    public static function kuehlstufe(float $dt): array
    {
        if ($dt >= 10.0) { return ['stufe' => 0, 'name' => 'reichlich', 'hinweis' => 'Schweiß kühlt wirksam']; }
        if ($dt >= 6.0)  { return ['stufe' => 1, 'name' => 'gut',       'hinweis' => 'Kühlung funktioniert']; }
        if ($dt >= 3.0)  { return ['stufe' => 2, 'name' => 'knapp',     'hinweis' => 'schwül, Kühlreserve sinkt']; }
        if ($dt >= 1.0)  { return ['stufe' => 3, 'name' => 'kaum',      'hinweis' => 'Anstrengung meiden']; }
        return ['stufe' => 4, 'name' => 'keine', 'hinweis' => 'Luft gesättigt — Schwitzen kühlt nicht mehr'];
    }

    /**
     * Belastungsstufe aus dem WBGT, angelehnt an ISO 7243 / DGUV fuer mittlere Arbeitsschwere
     * bei akklimatisierten Personen. 0 unbedenklich, 1 erhoeht, 2 hoch, 3 sehr hoch, 4 extrem.
     * @return array{stufe:int,name:string,hinweis:string}
     */
    public static function hitzestufe(float $wbgt): array
    {
        if ($wbgt < 25.0) { return ['stufe' => 0, 'name' => 'unbedenklich', 'hinweis' => 'keine Einschränkung']; }
        if ($wbgt < 28.0) { return ['stufe' => 1, 'name' => 'erhöht',       'hinweis' => 'regelmäßig trinken']; }
        if ($wbgt < 30.0) { return ['stufe' => 2, 'name' => 'hoch',         'hinweis' => 'Pausen im Schatten']; }
        if ($wbgt < 32.0) { return ['stufe' => 3, 'name' => 'sehr hoch',    'hinweis' => 'schwere Arbeit meiden']; }
        return ['stufe' => 4, 'name' => 'extrem', 'hinweis' => 'Arbeit im Freien einstellen'];
    }

    /** Windrichtung als Himmelsrichtung, 16 Sektoren. */
    public static function windrichtungText(float $grad): string
    {
        $r = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO',
              'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        return $r[(int) round(fmod($grad + 360.0, 360.0) / 22.5) % 16];
    }

    /** Mondphase als Name. Die Grenzen sind die ueblichen Achtel des Umlaufs. */
    public static function mondphaseText(float $phase): string
    {
        $n = ['Neumond', 'zunehmende Sichel', 'Erstes Viertel', 'zunehmender Mond',
              'Vollmond', 'abnehmender Mond', 'Letztes Viertel', 'abnehmende Sichel'];
        return $n[((int) floor(fmod($phase + 1 / 16, 1.0) * 8)) % 8];
    }

    /** Windgeschwindigkeit km/h in Knoten. */
    public static function kmhInKnoten(float $kmh): float
    {
        return round($kmh / 1.852, 1);
    }

    /**
     * Mondphase 0..1 (0 = Neumond, 0,5 = Vollmond) und Beleuchtungsgrad 0..1.
     *
     * ACHTUNG BEIM BEZUGSPUNKT: der uebliche Ausgangspunkt J2000.0 (1.1.2000, 12 Uhr) ist
     * KEIN Neumond — der naechste lag am 6.1.2000 um 18:14 UTC. Wer die Tage seit J2000 einfach
     * durch die synodische Umlaufzeit teilt, liegt dauerhaft um rund 0,19 Phasen daneben, also
     * fast sechs Tage: eine zunehmende Sichel wird so zum zunehmenden Dreiviertelmond, und der
     * Beleuchtungsgrad stimmt entsprechend nicht. Deshalb wird ab dem echten Neumond gerechnet.
     *
     * Genauigkeit etwa ein halber Tag — die Umlaufzeit schwankt real um einige Stunden. Fuer
     * eine Anzeige reicht das; fuer eine Finsternisvorhersage nicht.
     *
     * @return array{phase:float,beleuchtet:float,zunehmend:bool}
     */
    public static function mond(?int $zeit = null): array
    {
        $jd = ($zeit ?? time()) / 86400.0 + 2440587.5;
        $seit = $jd - 2451550.1;                       // Neumond 6.1.2000, 18:14 UTC
        $phase = fmod(fmod($seit, 29.530588853) + 29.530588853, 29.530588853) / 29.530588853;
        return ['phase' => round($phase, 4),
                'beleuchtet' => round((1 - cos(2 * M_PI * $phase)) / 2, 4),
                'zunehmend' => $phase < 0.5];
    }
}
