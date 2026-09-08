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
    /** So lange nach dem letzten Niederschlag zaehlt eine mittlere Kamera-Sicht nicht
     *  als Dunst (nasse Optik, ausgewaschene Luft, flaches Licht). 90 Minuten. */
    public const CAM_NASS_S = 5400;
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

        // Kann hier ueberhaupt Nebel stehen? Nebel ist Kondensation an der Luft: er braucht
        // Saettigung, Windstille und darf kein Regen sein. Ist eine dieser Bedingungen
        // verletzt, ist die Frage entschieden - dann darf auch keine Kamera "Nebel" daraus
        // machen (siehe unten). Vorher konnte sie das, und genau daran kippte die Anzeige
        // am 19.08.2026 bei 56 % Luftfeuchte und 8 K Taupunktdifferenz auf "Nebel".
        $moeglich = false;

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
        } elseif (($moeglich = true) && $hoehe === null) {
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

        // ZWEI TORE VOR DER KAMERA.
        //
        // 1. LICHT: Die Sichtmessung vergleicht die Kantenenergie mit einem bei TAGESLICHT
        //    gelernten Klarwert. In der Daemmerung faellt der Kontrast, weil das Licht fehlt,
        //    nicht weil Nebel da waere - der Vergleich ist dann sinnlos. Am 19.08.2026 abends
        //    gemessen: bei klarem Himmel und untergegangener Sonne meldeten die vier Kameras
        //    44-56 % Sicht und einen Dunkelkanal von 60-73 statt nahe null (Verstaerkung und
        //    IR-Licht heben das ganze Bild an). Beide Kennzahlen sind ohne Sonne unbrauchbar.
        // 2. PHYSIK: Schliesst der Regelsatz Nebel aus (zu trocken, zu windig, Regen), darf die
        //    Kamera die Stufe NICHT anheben. Ein dunkles oder kontrastarmes Bild ist dann eine
        //    Beobachtung ueber das Licht, keine ueber die Luft.
        //
        // Herabstufen darf die Kamera weiterhin immer: freie Sicht widerlegt gerechneten Nebel.
        $camLicht = !array_key_exists('camUsable', $c) || (bool) $c['camUsable'];
        if ($sicht !== null && !$camLicht) {
            $text .= sprintf(' | Kamera: Sicht %d %%, bei tiefer Sonne nicht beurteilbar — nicht gewertet',
                             (int) $sicht);
        } elseif ($sicht !== null) {
            // DIE MESSUNG GILT, NICHT DIE SCHAETZUNG.
            //
            // Frueher durfte die Kamera die gerechnete Stufe nur ANHEBEN (max()) oder bei
            // freier Sicht ganz verwerfen. Sie konnte sie aber nicht auf das herunterziehen,
            // was sie tatsaechlich sieht - und damit widersprach der Code seiner eigenen
            // Praemisse. Ergebnis am 26.08.2026 um 07:58: Kamera misst 53 % Sicht, also
            // "eingeschraenkt", der FSI schaetzt 17 und damit "dicht" - angezeigt wurde
            // "dichter Nebel", waehrend draussen leichter Dunst lag.
            //
            // Jetzt setzt die Kamera die Stufe, sobald Nebel physikalisch moeglich ist.
            // Ist er ausgeschlossen, darf sie weiterhin nur herabstufen (Zweige unten).
            if ($moeglich && ($sicht <= $c['sightFog'] || $sicht <= $c['sightWarn'])) {
                $stufe = ($sicht <= $c['sightFog']) ? self::NEBEL_DICHT : self::NEBEL_NEBEL;
                $text .= sprintf(' | Kamera: Sicht %d %% des Klarwerts — %s (Messung schlägt Rechnung)',
                                 (int) $sicht, ($sicht <= $c['sightFog']) ? 'gemessen dicht' : 'eingeschränkt');
            } elseif ($sicht <= $c['sightWarn']) {
                $text .= sprintf(' | Kamera: Sicht %d %%, aber Nebel ist hier ausgeschlossen — nicht gewertet',
                                 (int) $sicht);
            } elseif ($stufe >= self::NEBEL_DIESIG) {
                // DIE KAMERA SIEHT NACH, DER REGELSATZ RECHNET NUR.
                //
                // Feuchte und Taupunktdifferenz sagen, ob Nebel entstehen KANN - ob er da ist,
                // sieht man. Steht die gemessene Sicht deutlich ueber der Warnschwelle, wird die
                // gerechnete Stufe zurueckgenommen; frueher geschah das erst ab fest verdrahteten
                // 85 %% und nur bis "diesig". Bei 96 %% Luftfeuchte, 0,6 K Spread und drei Kameras
                // mit 83-92 %% Sicht stand deshalb "Nebel" auf der Karte, waehrend man bis zum
                // Zaun sah.
                //
                // Die Grenze fuer "deutlich klar" leitet sich aus der eingestellten Warnschwelle
                // ab statt aus einer zweiten festen Zahl: die Mitte zwischen ihr und 100 %.
                // Bei sightWarn 55 sind das 77,5 %% - darueber ist kein Nebel, darunter bleibt
                // Dunst als Zwischenstufe stehen.
                //
                // Die Bedingung lautet bewusst ">= DIESIG" und nicht ">= NEBEL": sonst wird die
                // Kamera bei gerechnetem Dunst gar nicht erst gefragt, und "diesig" bleibt
                // stehen, obwohl sie freie Sicht meldet.
                $klar = ($c['sightWarn'] + 100.0) / 2.0;
                // NACHTS zaehlt nur der klare Befund, nicht die Zwischenlage. Das Infrarot
                // leuchtet die Nahzone aus, das Fernfeld haengt am Himmelslicht: unter dichter
                // Bewoelkung ist es dort dunkler und kantenaermer als in der mondhellen Nacht,
                // gegen die der Klarwert als Bestmarke gelernt wurde. Ein Wert zwischen "dicht"
                // und "klar" ist dann eine Aussage ueber die WOLKEN, nicht ueber die Luft.
                // Gemessen am 25.08.2026: 67 % bei stark bewoelktem, aber klar sichtigem Himmel.
                $nachts = !empty($c['camNacht']);
                // NACH REGEN GILT DASSELBE ARGUMENT WIE NACHTS.
                //
                // Frisch nach Niederschlag ist ein MASSVOLLER Sichtverlust eine Aussage ueber
                // die OPTIK, nicht ueber die Luft: Tropfen auf Kuppel und Scheibe, ausgewaschene
                // Luft und das flache Licht unter der abziehenden Bewoelkung senken den Kontrast,
                // gegen den der Klarwert als Bestmarke gelernt wurde.
                // Gemessen am 05.09.2026, 10:27, kurz nach 9,9 mm/h: 95 % Feuchte, 0,8 K Spread,
                // windstill, 100 % Bewoelkung, vier Kameras mit 51-66 % Sicht - die Anlage meldete
                // Dunst, draussen war es schlicht stark bewoelkt.
                // Ein KLAR unterschrittener Wert bleibt unangetastet: die Zweige darueber setzen
                // bei sightFog/sightWarn weiterhin Nebel bzw. dichten Nebel, und echter Nebel
                // nach Regen wird dadurch nicht uebersehen. Nur die ZWISCHENLAGE faellt weg.
                $nass = isset($c['rainAgoS']) && $c['rainAgoS'] !== null
                     && (int) $c['rainAgoS'] < self::CAM_NASS_S;
                if ($sicht > $klar) {
                    $stufe = self::NEBEL_KEIN;
                    $text .= sprintf(' | Kamera: Sicht %d %% (klar ab %.0f %%) — gemessen klar, gerechnete Stufe verworfen', (int) $sicht, $klar);
                } elseif ($nass) {
                    $stufe = self::NEBEL_KEIN;
                    $text .= sprintf(' | Kamera: Sicht %d %% (klar ab %.0f %%) — Niederschlag vor %d min, '
                                   . 'nasse Optik und flaches Licht; Zwischenlage nicht gewertet',
                                   (int) $sicht, $klar, (int) round(((int) $c['rainAgoS']) / 60));
                } elseif ($nachts) {
                    $stufe = self::NEBEL_KEIN;
                    $text .= sprintf(' | Kamera: Sicht %d %% (klar ab %.0f %%) — nachts nicht aussagekräftig, '
                                   . 'Bewölkung verdunkelt das Fernfeld; gerechnete Stufe verworfen', (int) $sicht, $klar);
                } else {
                    $stufe = self::NEBEL_DIESIG;
                    $text .= sprintf(' | Kamera: Sicht %d %% (klar ab %.0f %%) — gerechnete Stufe auf Dunst zurückgenommen', (int) $sicht, $klar);
                }
            }
        }

        // OHNE SICHTMESSUNG IST DAS EINE RECHNUNG, KEINE BEOBACHTUNG.
        //
        // Der FSI ist ein VORHERSAGE-Index: er sagt, wie stabil eine Nebelschicht waere,
        // wenn sie sich bildet - nicht, ob gerade Nebel steht. In einer klaren, windstillen
        // Nacht mit 94 % Feuchte und 1 K Taupunktdifferenz faellt er unter 31, und die Anlage
        // meldete daraufhin "dichter Nebel", waehrend man bis zum Zaun sah (25.08.2026).
        //
        // Hat keine Kamera etwas beigetragen, darf daraus hoechstens ein HINWEIS werden.
        // Behaupten, was man nicht gesehen hat, ist der Fehler - nicht die Rechnung selbst.
        if ($stufe > self::NEBEL_KEIN && ($sicht === null || !$camLicht)) {
            $stufe = self::NEBEL_KEIN;
            $text .= ' | keine Sichtmessung — gerechnet, nicht gesehen, daher keine Meldung';
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
     * Zusaetzlich wird der ZUG der Zelle bestimmt: eine Ausgleichsgerade durch die Entfernungen
     * der letzten Schlaege ueber der Zeit. Sinkt sie, zieht das Gewitter auf, und aus der
     * Steigung fallen Zuggeschwindigkeit und ungefaehre Ankunft ab. Das ist die Angabe, die
     * zaehlt — "Blitz in 27 km" sagt nicht, ob man Fenster schliessen oder weiterarbeiten soll.
     *
     * @param array<int,array{t:int,d:float}> $ring bisheriger Speicher
     * @return array{stufe:int,dist:int,rate:int,last:int,text:string,ring:array,
     *               trend:int,speed:float|null,eta:int|null}
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

        $zug = self::zug($ring, $nun);

        $text = 'kein Gewitter';
        if ($stufe > 0) {
            $text = ['', 'Wetterleuchten', 'Gewitter', 'Gewitter in der Nähe'][$stufe]
                  . ($dl !== null ? sprintf(', letzter Blitz %.0f km', $dl) : '')
                  . sprintf(' vor %d min', max(0, (int) round(($nun - $last) / 60)))
                  . ($rate > 1 ? sprintf(', %d Blitze in 30 min', $rate) : '');
            if ($zug['trend'] < 0) {
                $text .= sprintf(' — zieht auf mit %.0f km/h', $zug['speed']);
                if ($zug['eta'] !== null) {
                    $text .= sprintf(', hier in etwa %d min', $zug['eta']);
                }
            } elseif ($zug['trend'] > 0) {
                $text .= $zug['speed'] !== null
                    ? sprintf(' — zieht ab mit %.0f km/h', $zug['speed'])
                    : ' — zieht ab';
            }
        }
        return ['stufe' => $stufe, 'dist' => $dl === null ? 0 : (int) round($dl),
                'rate' => $rate, 'last' => $last, 'text' => $text, 'ring' => $ring,
                'trend' => $zug['trend'], 'speed' => $zug['speed'], 'eta' => $zug['eta']];
    }

    /**
     * Zug der Gewitterzelle aus den Entfernungen der letzten Schlaege.
     *
     * Ausgleichsgerade (kleinste Quadrate) der Entfernung ueber der Zeit. Die Steigung ist die
     * Zuggeschwindigkeit: negativ heisst naeher kommend. Bewusst erst ab vier Schlaegen und
     * einer Spanne von fuenf Minuten — aus zwei Blitzen laesst sich keine Zugrichtung ablesen,
     * und eine erfundene Ankunftszeit waere schlimmer als gar keine.
     *
     * Die Entfernungsangabe der Station ist grob gestuft; deshalb gilt erst ab etwa 8 km/h ein
     * Zug als erkannt, darunter heisst es "steht".
     *
     * @param array<int,array{t:int,d:float}> $ring
     * @return array{trend:int,speed:float|null,eta:int|null}
     */
    private static function zug(array $ring, int $nun): array
    {
        $p = array_values(array_filter($ring, static fn($e) => ($nun - (int) $e['t']) <= 2700));
        if (count($p) < 4) {
            return ['trend' => 0, 'speed' => null, 'eta' => null];
        }

        // Blitze EINER Zelle streuen stark: sie schlagen am nahen wie am fernen Rand ein, bei
        // einer 15 km grossen Zelle also ueber 15 km Spanne. Eine Ausgleichsgerade durch alle
        // Einzelwerte folgt dieser Streuung statt der Zugbewegung und liefert Phantasiewerte
        // (im Betrieb gemessen: 118 km/h fuer eine Zelle, die tatsaechlich mit etwa 80 heranzog).
        // Deshalb erst je Fuenf-Minuten-Fenster den MEDIAN bilden — der ist gegen Ausreisser an
        // beiden Raendern unempfindlich.
        $fenster = [];
        foreach ($p as $e) {
            $fenster[(int) floor((int) $e['t'] / 300)][] = (float) $e['d'];
        }
        ksort($fenster);
        $punkte = [];
        foreach ($fenster as $k => $werte) {
            sort($werte);
            $n = count($werte);
            $punkte[] = ['t' => $k * 300 + 150,
                         'd' => $n % 2 ? $werte[intdiv($n, 2)]
                                       : ($werte[$n / 2 - 1] + $werte[$n / 2]) / 2];
        }
        $anz = count($punkte);
        if ($anz < 3) {
            return ['trend' => 0, 'speed' => null, 'eta' => null];
        }

        // WENDEPUNKT ZUERST. Ein Gewitter zieht heran, steht kurz ueber einem und zieht weiter —
        // die Entfernung faellt also erst und steigt danach wieder. Eine Gerade ueber die ganze
        // Dreiviertelstunde wird von der langen Anmarschphase beherrscht und meldet noch
        // "zieht auf", waehrend die Zelle laengst abzieht. Massgeblich ist deshalb, ob die
        // dichteste Annaeherung schon VORBEI ist: liegt sie mindestens ein Fenster zurueck und
        // ist die Entfernung seither um mehr als 3 km gestiegen, zieht das Gewitter ab.
        $iMin = 0;
        for ($i = 1; $i < $anz; $i++) {
            if ($punkte[$i]['d'] < $punkte[$iMin]['d']) {
                $iMin = $i;
            }
        }
        $jetztD = (float) $punkte[$anz - 1]['d'];
        $minD   = (float) $punkte[$iMin]['d'];
        if ($iMin < $anz - 1 && ($jetztD - $minD) > 3.0) {
            $dt = max(1.0, ($punkte[$anz - 1]['t'] - $punkte[$iMin]['t']) / 60.0);
            $kmh = round(($jetztD - $minD) / $dt * 60.0, 1);
            return ['trend' => 1, 'speed' => ($kmh > 90.0 ? null : $kmh), 'eta' => null];
        }

        // Sonst die Entwicklung der LETZTEN etwa 20 Minuten, nicht des ganzen Fensters: was vor
        // einer halben Stunde war, sagt ueber die naechsten Minuten wenig.
        $j = array_slice($punkte, -4);
        $n = count($j);
        $t0 = (int) $j[0]['t'];
        $sx = $sy = $sxy = $sxx = 0.0;
        foreach ($j as $e) {
            $x = ((int) $e['t'] - $t0) / 60.0;
            $y = (float) $e['d'];
            $sx += $x; $sy += $y; $sxy += $x * $y; $sxx += $x * $x;
        }
        $nenner = $n * $sxx - $sx * $sx;
        if (abs($nenner) < 1e-9) {
            return ['trend' => 0, 'speed' => null, 'eta' => null];
        }
        $steig = ($n * $sxy - $sx * $sy) / $nenner;              // km je Minute
        $kmh   = abs($steig) * 60.0;

        // Ueber 90 km/h zieht keine Gewitterzelle. So ein Wert heisst nicht "sehr schnell",
        // sondern "die Daten geben keine Zugbewegung her" — dann lieber nichts behaupten.
        if ($kmh > 90.0) {
            return ['trend' => 0, 'speed' => null, 'eta' => null];
        }
        if ($kmh < 8.0) {
            return ['trend' => 0, 'speed' => round($kmh, 1), 'eta' => null];
        }
        if ($steig >= 0) {
            return ['trend' => 1, 'speed' => round($kmh, 1), 'eta' => null];
        }
        $eta = (int) round($jetztD / abs($steig));
        return ['trend' => -1, 'speed' => round($kmh, 1),
                'eta' => ($eta > 0 && $eta <= 180) ? $eta : null];
    }

    /**
     * Bewoelkung — aus DREI Quellen, in der Reihenfolge ihrer Zustaendigkeit.
     *
     * Keine einzelne Quelle traegt den ganzen Tag:
     *
     *   KAMERA (Rot/Blau)   sieht die Wolken wirklich, statt sie aus einer Wirkung zu
     *                       erschliessen. Massgeblich, solange Tageslicht da ist. Blind bei
     *                       Nacht und wertlos, wenn die Sonne im Ausschnitt steht.
     *   STRAHLUNG           misst eine WIRKUNG der Wolken, nicht die Wolken. Brauchbar bei
     *                       hohem Sonnenstand und gelerntem Klarhimmel, darunter nicht.
     *   MODELL              immer verfuegbar, aber raeumlich und zeitlich grob — die richtige
     *                       Rueckfallebene fuer Nacht und Daemmerung, nicht mehr.
     *
     * Genau daran scheiterte die Anlage am 08.09.2026: die Strahlung war die einzige Quelle,
     * ihr Klarhimmel war geraten statt gelernt, und unterhalb der Aussagegrenze hielt die
     * Anzeige den letzten Tageswert fest. Ergebnis war "stark bewoelkt" bei sternklarem
     * Himmel. Deshalb wird hier NICHTS mehr festgehalten: sagt keine Quelle etwas, ist das
     * Ergebnis null, und die Wetterlage sagt das auch.
     *
     * @param array{pct:float,anzahl:int,text:string}|null $kamera Kameraurteil, falls vorhanden
     * @param float $strahlFaktor gelernter Klarhimmel-Faktor der Strahlungsmessung
     * @param float|null $modell Bewoelkung des Vorhersagemodells in Prozent
     * @return array{pct:float|null,quelle:string,weg:string}
     */
    public static function bewoelkung(Observation $o, float $lat, float $lon, ?int $zeit = null,
                                      ?array $kamera = null, float $strahlFaktor = 1.0,
                                      ?float $modell = null): array
    {
        $h   = Meteo::sonnenhoehe($lat, $lon, $zeit);
        $rad = $o->num('radiationWm2');

        // Die Strahlung wird IMMER mitgerechnet, auch wenn die Kamera entscheidet: sie steht
        // dann als Gegenprobe im Herkunftstext. Weichen zwei Messungen voneinander ab, will
        // man das sehen und nicht raten muessen, welche gerade gegolten hat.
        $strahl = ($rad === null) ? null : Meteo::bewoelkung($rad, $h, $strahlFaktor);

        if ($kamera !== null && $kamera['anzahl'] > 0) {
            $q = sprintf('%d Kamera%s: %s', $kamera['anzahl'],
                         $kamera['anzahl'] === 1 ? '' : 's', $kamera['text']);
            if ($strahl !== null) {
                $q .= sprintf(' | Strahlung sagt %.0f %%', $strahl * 100);
            }
            return ['pct' => round($kamera['pct'], 1), 'quelle' => $q, 'weg' => 'kamera'];
        }

        if ($strahl !== null) {
            return ['pct' => round($strahl * 100, 1), 'weg' => 'strahlung',
                    'quelle' => sprintf('Strahlung %.0f von %.0f W/m² klar, Sonne %.1f°%s',
                        $rad, Meteo::klarhimmel($h) * $strahlFaktor, $h,
                        abs($strahlFaktor - 1.0) < 0.005 ? ' (Klarhimmel noch ungelernt)'
                                                         : sprintf(' (Klarhimmel gelernt, ×%.2f)', $strahlFaktor))];
        }

        if ($modell !== null) {
            return ['pct' => round($modell, 1), 'weg' => 'modell',
                    'quelle' => sprintf('Modell — %s', $h <= Meteo::STRAHLUNG_MIN_HOEHE
                        ? sprintf('Sonne %.1f°, keine Kamera und keine Strahlungsaussage', $h)
                        : 'weder Kamera noch Strahlung verwertbar')];
        }

        return ['pct' => null, 'weg' => 'keine',
                'quelle' => $rad === null ? 'keine Strahlungsmessung'
                                          : sprintf('Sonne %.1f° — keine Quelle kann etwas sagen', $h)];
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
    public static function niederschlag(Observation $o, ?bool $sensorNass = null): array
    {
        $mm = $o->num('rainRateMmH') ?? 0.0;
        $t  = $o->num('tempC');
        $rh = $o->num('humPct');
        // Ein optischer Regensensor meldet SOFORT, eine Wippe erst nach rund 0,2 mm. Bei
        // Nieselregen liegen dazwischen Minuten, in denen die Station "kein Niederschlag"
        // sagt, waehrend es draussen nass wird. Der Sensor zaehlt deshalb als Nachweis, DASS
        // es niederschlaegt - eine Menge liefert er nicht und es wird auch keine erfunden.
        $nass = ($sensorNass === true) || ($mm > 0.01);
        if ($t === null || $rh === null) {
            return ['art' => $nass ? self::NS_REGEN : self::NS_KEIN, 'twet' => null,
                    'text' => 'ohne Temperatur und Feuchte nicht unterscheidbar'];
        }
        $tw = Meteo::feuchtkugel($t, $rh);
        if (!$nass) {
            return ['art' => self::NS_KEIN, 'twet' => $tw, 'text' => 'kein Niederschlag'];
        }
        if ($mm <= 0.01) {
            // Nur der Sensor spricht an: die Art bestimmt weiterhin die Feuchtkugel, aber der
            // Text sagt offen, woher die Aussage kommt und dass keine Menge dahintersteht.
            $art = $tw < 0.5 ? self::NS_SCHNEE : ($tw <= 1.5 ? self::NS_SCHNEEREGEN : self::NS_REGEN);
            return ['art' => $art, 'twet' => $tw,
                    'text' => sprintf('Regensensor meldet nass, Messwippe noch ohne Ausschlag (Feuchtkugel %.1f °C)', $tw)];
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
    /**
     * @param string|null $tageszeit 'morgen'|'abend' bei tiefem Sonnenstand, sonst null.
     *        Aus "diesig" wird damit Morgen- oder Abenddunst - dieselbe Stufe, aber die
     *        genauere Aussage: morgens loest sich Strahlungsnebel auf, abends bildet er
     *        sich. Wer das liest, weiss, ob es besser oder schlechter wird.
     */
    public static function wetterlage(int $gewitter, array $ns, int $nebel, ?float $wolkenPct, ?string $tageszeit = null): string
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
        $dunst = ($tageszeit === 'morgen') ? 'Morgendunst'
               : (($tageszeit === 'abend') ? 'Abenddunst' : 'Dunst');
        if ($wolkenPct === null) {
            return $nebel === self::NEBEL_DIESIG ? $dunst : 'keine Bewölkungsaussage';
        }
        $b = $wolkenPct / 100.0;
        $txt = ($b < 0.125) ? 'klar' : (($b < 0.375) ? 'heiter'
             : (($b < 0.625) ? 'wolkig' : (($b < 0.875) ? 'stark bewölkt' : 'bedeckt')));
        // Alle drei sind Substantive: "Dunst", "Morgendunst", "Abenddunst". Frueher stand
        // hier das Adjektiv "diesig" - "bedeckt, diesig" mischte zwei Wortarten, und der
        // Tagesfall las sich anders als Morgen und Abend.
        if ($nebel === self::NEBEL_DIESIG) { $txt .= ', ' . $dunst; }
        if ($gewitter === self::GEW_LEUCHTEN) { $txt .= ', Wetterleuchten'; }
        return $txt;
    }
}
