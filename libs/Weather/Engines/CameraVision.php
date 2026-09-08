<?php

declare(strict_types=1);

namespace Hoep\Weather\Engines;

/**
 * CameraVision — misst die Sichtweite an einem Kamerabild.
 *
 * MASSGEBLICH IST DIE KONTRASTDICHTE, nicht der rohe Kontrast: Kantenenergie geteilt durch
 * mittlere Helligkeit. Der Grund ist im Betrieb aufgefallen und ist grundsaetzlicher Natur —
 * der rohe Kontrast haengt auch an der BELEUCHTUNG, nicht nur an der Sicht. Sonnenschein
 * wirft harte Schatten, und Schatten sind Kanten: dieselbe Kamera mass bei klarer Sicht in
 * der Sonne 12,8 und bei ebenso klarer Sicht unter Bedeckung 12,6, ihr gelernter Klarwert
 * stand aber nach einem sonnigen Moment auf 17,4 — also haette jeder bedeckte Tag als
 * "Sicht eingeschraenkt" gegolten. Auf die Helligkeit bezogen bleiben dieselben Messungen
 * bei 0,119 und 0,125: fuenf Prozent Unterschied statt siebenundzwanzig.
 *
 * Der Gedanke ist alt und gut belegt: Nebel streut Licht und frisst dadurch den KONTRAST.
 * Kanten, die bei klarer Sicht scharf sind (Zaun, Hecke, Hauskante), verwaschen. Die
 * mittlere Kantenenergie eines festen Bildausschnitts ist damit ein direktes Mass fuer die
 * Sichtweite — und zwar eine MESSUNG, waehrend Taupunkt und Feuchte nur eine Schaetzung
 * zulassen.
 *
 * Zwei Dinge muss man dabei richtig machen, sonst ist das Ergebnis wertlos:
 *
 * 1. KEINE absoluten Schwellen. Eine Kamera auf eine glatte Asphaltflaeche kommt bei bester
 *    Sicht auf eine Kantenenergie von etwa 12, eine auf Buesche auf 30. Massgeblich ist
 *    allein der Abfall gegenueber dem KLARWERT DERSELBEN Kamera.
 * 2. Tag und Nacht getrennt lernen. Nachts leuchtet die Infrarotbeleuchtung, das Bild ist
 *    grau und flach — ein gemeinsamer Klarwert wuerde jede Nacht als Nebel melden.
 *
 * Der Klarwert wird als BESTMARKE gefuehrt, nicht als Mittelwert: er steigt sofort, faellt
 * aber nie von selbst. Wuerde er mitsinken, gewoehnte sich die Anlage waehrend einer langen
 * Nebellage an den Nebel und meldete ihn nicht mehr.
 */
final class CameraVision
{
    /** Breite, auf die vor der Messung verkleinert wird. Mehr bringt nichts und kostet Zeit. */
    private const BREITE = 160;

    // Schwellen der Nebel-Handschrift. VORLAEUFIG aus der Literatur (Dunkelkanal nahe null im
    // klaren Aussenbild, deutlich angehoben bei Streulicht) und einer Messung der eigenen
    // Kameras bei klarem Abend am 19.08.2026. Sie gehoeren nachjustiert, sobald ein echter
    // Nebelmorgen aufgezeichnet ist - bis dahin sind sie bewusst konservativ gesetzt: lieber
    // "keine Aussage" als ein erfundener Nebel.
    private const SIG_MIN_HELL  = 60.0;   // darunter ist das Bild zu dunkel fuer jede Aussage
    private const SIG_DK_KLAR   = 25.0;   // Dunkelkanal: klar
    private const SIG_DK_NEBEL  = 110.0;  // Dunkelkanal: dichter Nebel
    private const SIG_SAT_KLAR  = 0.22;   // Saettigung: klar
    private const SIG_SAT_NEBEL = 0.06;   // Saettigung: Nebel (Grau)
    private const SIG_KON_KLAR  = 0.12;   // Kontrastdichte: klar
    private const SIG_KON_NEBEL = 0.03;   // Kontrastdichte: Nebel

    // Himmel/Bewoelkung. Alle vier am eigenen Bestand gemessen (08.09.2026, wolkenloser Abend),
    // nicht aus der Literatur uebernommen - Weissabgleich und Blickrichtung machen jede
    // fremde Zahl hier wertlos.
    private const HIM_MIN_BLAU   = 8;      // darunter traegt der Blaukanal kein Verhaeltnis
    private const HIM_MIN_PIXEL  = 55.0;   // Helligkeit je Pixel: darunter ist es Laub, nicht Himmel
    private const HIM_MIN_HELL   = 45.0;   // mittlere Helligkeit: darunter ist es Nacht
    private const HIM_MAX_WEISS  = 35.0;   // % ausgebrannter Pixel, ab da sagt der Ausschnitt nichts
    private const HIM_MIN_ANTEIL = 40.0;   // % verwertbarer Pixel, darunter zeigt das Feld keinen Himmel
    private const HIM_SPANNE     = 0.25;   // R/B ueber dem Klarwert = voll bedeckt
    private const HIM_MIN_LERN   = 40;     // bestaetigte Messungen, bevor geurteilt wird
    private const HIM_ALTER      = 45;     // Tage, nach denen ein Klarwert verworfen wird

    private function __construct()
    {
    }

    /**
     * Die Schwellen, gegen die gerechnet wird.
     *
     * Nach aussen gegeben, damit das Werkzeug zum Ziehen des Messfeldes DIESELBEN
     * Zahlen anzeigt. Zwei Quellen fuer eine Schwelle waeren zwei Wahrheiten - und
     * die eine davon veraltet beim naechsten Kalibrieren still.
     *
     * @return array{minHell:float,dkKlar:float,dkNebel:float,satKlar:float,satNebel:float,konKlar:float,konNebel:float}
     */
    public static function schwellen(): array
    {
        return ['minHell' => self::SIG_MIN_HELL,
                'dkKlar' => self::SIG_DK_KLAR, 'dkNebel' => self::SIG_DK_NEBEL,
                'satKlar' => self::SIG_SAT_KLAR, 'satNebel' => self::SIG_SAT_NEBEL,
                'konKlar' => self::SIG_KON_KLAR, 'konNebel' => self::SIG_KON_NEBEL];
    }

    public static function verfuegbar(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecolorat');
    }

    /**
     * Kennzahlen eines Bildes.
     *
     * @param string $binaer Bilddaten (JPEG oder PNG)
     * @param array{x:float,y:float,w:float,h:float}|null $roi Bildausschnitt in Anteilen 0..1;
     *        sinnvoll, um Himmel oder eine nahe Wand auszuschliessen
     * @return array{kontrast:float,helligkeit:float,dichte:float,saettigung:float,breite:int,hoehe:int}|null
     */
    public static function messen(string $binaer, ?array $roi = null): ?array
    {
        if ($binaer === '' || !self::verfuegbar()) {
            return null;
        }
        $img = @imagecreatefromstring($binaer);
        if (!$img) {
            return null;
        }
        $W = imagesx($img);
        $H = imagesy($img);

        $sx = 0; $sy = 0; $sw = $W; $sh = $H;
        if ($roi !== null) {
            $sx = (int) round(max(0.0, min(0.95, (float) $roi['x'])) * $W);
            $sy = (int) round(max(0.0, min(0.95, (float) $roi['y'])) * $H);
            $sw = (int) round(max(0.05, min(1.0, (float) $roi['w'])) * $W);
            $sh = (int) round(max(0.05, min(1.0, (float) $roi['h'])) * $H);
            $sw = min($sw, $W - $sx);
            $sh = min($sh, $H - $sy);
        }

        $w = min(self::BREITE, max(16, $sw));
        $h = max(8, (int) round($sh * $w / max(1, $sw)));
        $s = imagecreatetruecolor($w, $h);
        imagecopyresampled($s, $img, 0, 0, $sx, $sy, $w, $h, $sw, $sh);
        imagedestroy($img);

        $g = []; $dk = []; $sumL = 0.0; $sumS = 0.0; $n = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c  = imagecolorat($s, $x, $y);
                $r  = ($c >> 16) & 255; $gr = ($c >> 8) & 255; $b = $c & 255;
                $l  = 0.299 * $r + 0.587 * $gr + 0.114 * $b;
                $g[$y][$x] = $l;
                $mn = min($r, $gr, $b);
                $dk[$y][$x] = $mn;               // dunkelster Farbkanal des Pixels
                $sumL += $l;
                $mx = max($r, $gr, $b);
                $sumS += $mx > 0 ? ($mx - $mn) / $mx : 0.0;
                $n++;
            }
        }
        imagedestroy($s);

        // DUNKELKANAL (dark channel prior, He et al. 2009).
        //
        // In einem klaren Aussenbild enthaelt fast jeder Bildausschnitt irgendwo etwas sehr
        // Dunkles - Schatten, dunkles Laub, eine Fensteroeffnung. Der ueber kleine Kacheln
        // gebildete Minimalwert liegt deshalb nahe null. Nebel legt Streulicht ADDITIV
        // darueber und hebt genau dieses Minimum an: je dichter, desto hoeher.
        //
        // Das ist der Unterschied, den die Kantenenergie NICHT sehen kann. Kontrast faellt bei
        // Nebel UND bei Daemmerung. Der Dunkelkanal steigt bei Nebel und FAELLT bei Dunkelheit
        // - erst damit sind die beiden Faelle unterscheidbar. Genau daran kippte die Anzeige
        // am 19.08.2026 abends 153-mal zwischen "Nebel" und "kein Nebel".
        $patch = max(3, (int) round(min($w, $h) / 12));
        $dsum = 0.0; $dn = 0;
        for ($y = 0; $y + $patch <= $h; $y += $patch) {
            for ($x = 0; $x + $patch <= $w; $x += $patch) {
                $mnP = 255;
                for ($yy = $y; $yy < $y + $patch; $yy++) {
                    for ($xx = $x; $xx < $x + $patch; $xx++) {
                        if ($dk[$yy][$xx] < $mnP) { $mnP = $dk[$yy][$xx]; }
                    }
                }
                $dsum += $mnP; $dn++;
            }
        }
        $dunkel = $dn > 0 ? $dsum / $dn : 0.0;

        // Kantenenergie: mittlerer Helligkeitsunterschied zum rechten und unteren Nachbarn.
        // Bewusst kein Sobel — der reagiert staerker auf Bildrauschen, und genau das steigt
        // bei schwachem Licht an. Der einfache Nachbarvergleich ist hier der robustere.
        $e = 0.0; $m = 0;
        for ($y = 0; $y < $h - 1; $y++) {
            for ($x = 0; $x < $w - 1; $x++) {
                $e += abs($g[$y][$x] - $g[$y][$x + 1]) + abs($g[$y][$x] - $g[$y + 1][$x]);
                $m++;
            }
        }
        $kontrast   = $e / max(1, $m);
        $helligkeit = $sumL / max(1, $n);
        return ['kontrast' => round($kontrast, 2),
                'helligkeit' => round($helligkeit, 1),
                'dunkel' => round($dunkel, 1),
                'dichte' => round($kontrast / max(1.0, $helligkeit), 4),
                'saettigung' => round($sumS / max(1, $n), 3),
                'breite' => $W, 'hoehe' => $H];
    }

    /**
     * Sieht dieses Bild nach Nebel aus? Liefert 0..1 und die Begruendung.
     *
     * Nebel hat eine eigene Handschrift, die sich von Dunkelheit unterscheidet:
     *   Nebel      Helligkeit HOCH, Dunkelkanal HOCH, Saettigung NIEDRIG, Kontrast NIEDRIG
     *   Daemmerung Helligkeit NIEDRIG, Dunkelkanal NIEDRIG, Saettigung NIEDRIG, Kontrast NIEDRIG
     *   klar/Tag   Helligkeit HOCH, Dunkelkanal NIEDRIG, Saettigung normal, Kontrast HOCH
     *
     * Der Dunkelkanal traegt die Entscheidung, die Helligkeit ist die Eintrittskarte: ein
     * dunkles Bild wird gar nicht erst bewertet - dort ist jede Aussage geraten.
     * Rueckgabe null heisst ausdruecklich "nicht beurteilbar", nicht "kein Nebel".
     *
     * @param array{kontrast:float,helligkeit:float,dunkel:float,saettigung:float} $m
     * @return array{wert:float,text:string}|null
     */
    public static function nebelSignatur(array $m): ?array
    {
        $hell = (float) ($m['helligkeit'] ?? 0);
        $dkl  = (float) ($m['dunkel'] ?? 0);
        $sat  = (float) ($m['saettigung'] ?? 0);
        if ($hell < self::SIG_MIN_HELL) {
            return null;                                    // zu dunkel - keine Aussage
        }
        // Jede Teilaussage 0..1, dann gewichtet. Der Dunkelkanal zaehlt doppelt, weil er als
        // Einziger nebelspezifisch ist; Saettigung und Kontrast stuetzen nur.
        $fDk  = self::rampe($dkl, self::SIG_DK_KLAR, self::SIG_DK_NEBEL);
        $fSat = self::rampe(self::SIG_SAT_KLAR - $sat, 0.0, self::SIG_SAT_KLAR - self::SIG_SAT_NEBEL);
        $fKon = self::rampe(self::SIG_KON_KLAR - (float) ($m['dichte'] ?? 0),
                            0.0, self::SIG_KON_KLAR - self::SIG_KON_NEBEL);
        $wert = min(1.0, (2.0 * $fDk + $fSat + $fKon) / 4.0);
        return ['wert' => round($wert, 3),
                'text' => sprintf('Dunkelkanal %.0f, Helligkeit %.0f, Sättigung %.2f', $dkl, $hell, $sat)];
    }

    /** Lineare Rampe: <=$a -> 0, >=$b -> 1. */
    private static function rampe(float $v, float $a, float $b): float
    {
        if ($b <= $a) { return $v >= $b ? 1.0 : 0.0; }
        return max(0.0, min(1.0, ($v - $a) / ($b - $a)));
    }

    /**
     * Sicht in Prozent des gelernten Klarwerts, und der fortgeschriebene Klarwert.
     *
     * Gerechnet wird mit der KONTRASTDICHTE (Kantenenergie je Helligkeitseinheit), also mit
     * Werten in der Groessenordnung 0,1 — nicht mit der rohen Kantenenergie um 12 bis 30.
     * Die Mindestmarke muss entsprechend klein sein, sonst gilt jede Messung als "noch nichts
     * gelernt" und das Verfahren meldet stumm dauerhaft volle Sicht.
     *
     * @param float $dichte   gemessene Kontrastdichte
     * @param float $klarwert bisher gelernte Bestmarke (0 = noch nichts gelernt)
     * @return array{sicht:float,klarwert:float,gelernt:bool}
     */
    public static function sicht(float $dichte, float $klarwert): array
    {
        $neu = $dichte > $klarwert ? $dichte : $klarwert;
        // Solange nichts gelernt ist, gilt volle Sicht — lieber keine Meldung als eine
        // falsche, bis die Kamera einen klaren Tag gesehen hat.
        $sicht = $neu > 0.005 ? max(0.0, min(1.0, $dichte / $neu)) * 100.0 : 100.0;
        return ['sicht' => round($sicht, 1), 'klarwert' => round($neu, 4),
                'gelernt' => $neu > $klarwert];
    }

    /**
     * Schneedecke: hell UND farblos. Beides zusammen, weil jedes fuer sich taeuscht — eine
     * ueberbelichtete Szene ist hell ohne Schnee, eine Nachtaufnahme farblos ohne Schnee.
     *
     * Bewusst konservativ und nur tagsueber sinnvoll. Im Infrarotbild der Nacht ist ohnehin
     * alles grau; dort wird gar nicht geurteilt.
     */
    public static function schneedecke(array $mess, bool $nacht): ?bool
    {
        if ($nacht) {
            return null;
        }
        return $mess['helligkeit'] > 170.0 && $mess['saettigung'] < 0.10;
    }

    // ==================================================================
    // Himmel: Bewoelkung aus dem Rot/Blau-Verhaeltnis
    // ==================================================================

    /**
     * MASSGEBLICH IST ROT DURCH BLAU, nicht die Helligkeit.
     *
     * Klarer Himmel ist blau, weil die Luft kurze Wellenlaengen streut (Rayleigh): Blau kommt
     * vielfach gestreut aus allen Richtungen, Rot laeuft weitgehend durch. Eine Wolke besteht
     * aus Troepfchen, die GROSS sind gegen die Wellenlaenge — die streuen alle Farben gleich
     * (Mie). Deshalb ist eine Wolke grau bis weiss, und zwar unabhaengig davon, ob sie hell
     * oder dunkel erscheint. Genau das ist der Punkt: die Helligkeit taeugt (eine Gewitterwand
     * ist dunkler als klarer Himmel, eine Schleierwolke heller), das Farbverhaeltnis nicht.
     *
     * Das Verfahren ist der Stand der Technik bei Ganzhimmelskameras und heisst dort schlicht
     * Rot/Blau-Verhaeltnis. Gemessen am eigenen Bestand am 08.09.2026 bei wolkenlosem Himmel:
     * Himmel 0,71 bis 0,82 — weisse Hauswand 1,21, gelbe Hauswand 1,02, Wiese 1,13.
     * Die Trennung ist deutlich, die absolute Lage aber NICHT allgemeingueltig.
     *
     * Deshalb wird KEINE feste Schwelle verwendet. Drei Dinge verschieben das Verhaeltnis,
     * ohne dass eine Wolke im Bild waere:
     *   - der Weissabgleich der Kamera (jedes Modell anders, manche regeln nach),
     *   - die Sonnenhoehe (in der Daemmerung roetet sich der Himmel, R/B steigt gegen 1),
     *   - die Blickrichtung (Richtung Sonne heller und weisser als vom Sonnenpunkt weg).
     * Am selben Abend mass dieselbe Anlage 0,71 nach Sueden und 1,09 nach Westen in die
     * Sonne — beide bei wolkenlosem Himmel. Eine feste Schwelle haette den Westblick
     * vollstaendig als "bedeckt" gemeldet.
     *
     * Also wird der Klarwert GELERNT, wie schon bei der Sichtweite: je Kamera und je Fach der
     * Sonnenhoehe die blaueste je gesehene Lage. Gegen sie wird gemessen.
     *
     * @param string $binaer Bilddaten
     * @param array{x:float,y:float,w:float,h:float}|null $roi Himmelsausschnitt in Anteilen 0..1
     * @return array{p10:float,median:float,hell:float,weiss:float,n:int}|null
     */
    public static function himmelMessen(string $binaer, ?array $roi = null): ?array
    {
        if ($binaer === '' || !self::verfuegbar()) {
            return null;
        }
        $img = @imagecreatefromstring($binaer);
        if (!$img) {
            return null;
        }
        $W = imagesx($img);
        $H = imagesy($img);
        $sx = 0; $sy = 0; $sw = $W; $sh = $H;
        if ($roi !== null) {
            $sx = (int) round(max(0.0, min(0.95, (float) $roi['x'])) * $W);
            $sy = (int) round(max(0.0, min(0.95, (float) $roi['y'])) * $H);
            $sw = min((int) round(max(0.05, min(1.0, (float) $roi['w'])) * $W), $W - $sx);
            $sh = min((int) round(max(0.05, min(1.0, (float) $roi['h'])) * $H), $H - $sy);
        }
        $w = min(self::BREITE, max(16, $sw));
        $h = max(8, (int) round($sh * $w / max(1, $sw)));
        $s = imagecreatetruecolor($w, $h);
        imagecopyresampled($s, $img, 0, 0, $sx, $sy, $w, $h, $sw, $sh);
        imagedestroy($img);

        // NICHT JEDER PIXEL IM AUSSCHNITT IST HIMMEL. Ein Ast, ein Dachfirst, eine Baumkrone
        // am Rand - alles dunkel, und dunkle Pixel haben ein voellig anderes Rot/Blau als
        // Himmel. Beim ersten Versuch zog genau das den Kennwert der Sued-Kamera von 0,72 auf
        // 0,57: gemessen wurde nicht der Himmel, sondern das Laub davor. Also zaehlt nur, was
        // hell genug ist, um Himmel zu sein, und nicht so hell, dass der Sensor ausgebrannt
        // ist. Wieviel des Ausschnitts das war, geht als Anteil mit hinaus - liegt er zu
        // niedrig, zeigt das Feld eben keinen Himmel und das Urteil entfaellt.
        $rb = []; $sumL = 0.0; $ges = 0; $weiss = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($s, $x, $y);
                $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
                $ges++;
                $l = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $sumL += $l;
                if (max($r, $g, $b) >= 254) {
                    $weiss++;
                    continue;
                }
                if ($l >= self::HIM_MIN_PIXEL && $b >= self::HIM_MIN_BLAU) {
                    $rb[] = $r / $b;
                }
            }
        }
        imagedestroy($s);
        if ($rb === []) {
            return null;
        }
        sort($rb);
        $n = count($rb);
        $q = static fn (float $p): float => $rb[(int) max(0, min($n - 1, round($p * ($n - 1))))];
        return ['p10' => round($q(0.10), 4), 'median' => round($q(0.50), 4),
                'hell' => round($sumL / max(1, $ges), 1),
                'weiss' => round(100.0 * $weiss / max(1, $ges), 1),
                'anteil' => round(100.0 * $n / max(1, $ges), 1), 'n' => $n];
    }

    /**
     * Bewoelkungsgrad 0..1 aus einer Himmelsmessung und dem gelernten Klarwert.
     *
     * Der gelernte Klarwert ist die BLAUESTE je gesehene Lage (kleinstes p10) dieser Kamera in
     * diesem Fach der Sonnenhoehe — also "so sieht wolkenloser Himmel hier aus". Gemessen wird
     * der Abstand nach oben: um HIM_SPANNE darueber gilt der Himmel als voll bedeckt. Der
     * Wert 0,25 stammt aus dem eigenen Bestand (Himmel 0,75 bis 0,82, geschlossene weisse
     * Flaeche ab etwa 1,0) und ist damit gemessen, nicht geraten.
     *
     * Rueckgabe NULL heisst ausdruecklich "nicht beurteilbar", nicht "wolkenlos". Drei Faelle:
     *   - zu dunkel: nachts sieht die Kamera keinen Himmel, nur Schwarz oder Infrarotlicht,
     *   - ausgebrannt: steht die Sonne im Bild, ist der Ausschnitt weiss und sagt nichts,
     *   - noch nichts gelernt: ohne Klarwert gibt es keinen Bezug.
     *
     * @param array{p10:float,median:float,hell:float,weiss:float,n:int} $m
     * @return array{wert:float,text:string}|null
     */
    public static function himmelWolken(array $m, ?array $klar): ?array
    {
        if ((float) ($m['hell'] ?? 0.0) < self::HIM_MIN_HELL) {
            return null;
        }
        if ((float) ($m['weiss'] ?? 0.0) > self::HIM_MAX_WEISS) {
            return null;
        }
        if ((float) ($m['anteil'] ?? 0.0) < self::HIM_MIN_ANTEIL) {
            return null;                    // ueberwiegend kein Himmel im Ausschnitt
        }
        // REIFEGRAD. Solange der Klarwert nur aus wenigen Bildern stammt, misst er nicht "so
        // sieht klarer Himmel aus", sondern "so sah dieses eine Bild aus" - das Urteil waere
        // dann die Streuung INNERHALB des Ausschnitts und nichts weiter. Beim ersten Versuch
        // meldete die Grundstueckskamera so 19,9 % bei wolkenlosem Himmel. Erst ab
        // HIM_MIN_LERN bestaetigten Messungen wird geurteilt.
        if ($klar === null || ($klar['rb'] ?? 0.0) <= 0.0
            || (int) ($klar['n'] ?? 0) < self::HIM_MIN_LERN) {
            return null;
        }
        $rb = (float) $klar['rb'];
        $b  = max(0.0, min(1.0, ((float) $m['median'] - $rb) / self::HIM_SPANNE));
        return ['wert' => round($b, 3),
                'text' => sprintf('R/B %.2f gegen klar %.2f', (float) $m['median'], $rb)];
    }

    /**
     * Klarwert nachfuehren — die blaueste Lage, die diese Kamera in diesem Fach je gesehen hat.
     *
     * ZWEI DINGE MACHEN DEN UNTERSCHIED zwischen brauchbar und wertlos:
     *
     * 1. GELERNT WIRD NUR, WENN EINE UNABHAENGIGE QUELLE KLAREN HIMMEL BELEGT. Sonst lernt
     *    die Anlage waehrend einer langen bedeckten Lage die Wolkendecke als "so sieht klarer
     *    Himmel aus" und meldet danach jeden echten Sonnentag als wolkenlos - bei gleichzeitig
     *    stehendem Bezug also nie wieder eine Wolke. Der Beleg ist das Vorhersagemodell: es
     *    ist zu grob, um die Bewoelkung zu MELDEN, aber genau gut genug, um zu sagen, ob
     *    gerade gelernt werden darf.
     * 2. VERGESSEN NACH ZEIT, nicht nach Zaehlern. Ein Klarwert aelter als HIM_ALTER Tage
     *    beschreibt eine Kamera, die es so nicht mehr gibt - Weissabgleich nachgeregelt,
     *    Linse verschmutzt, Ast gewachsen. Er wird verworfen und neu gelernt.
     *
     * GELERNT WIRD DER MEDIAN, nicht das untere Zehntel - und zwar deshalb, weil gegen den
     * Median geurteilt wird. Der erste Entwurf lernte das untere Zehntel und verglich es mit
     * dem Median desselben Bildes; die Differenz war dann nicht die Bewoelkung, sondern der
     * HELLIGKEITSVERLAUF im Ausschnitt: Himmel ist zum Horizont hin heller und weisser als
     * oben. Die Grundstueckskamera las so dauerhaft 19,9 % bei wolkenlosem Himmel. Bezug und
     * Messgroesse muessen dieselbe Groesse sein, sonst misst man den Bildaufbau.
     *
     * @param array{rb:float,ts:int,n:int}|null $stand bisheriger Stand
     * @return array{rb:float,ts:int,n:int}
     */
    public static function himmelKlarwert(?array $stand, float $median, ?int $jetzt = null): array
    {
        $jetzt = $jetzt ?? time();
        if ($stand === null || ($stand['rb'] ?? 0.0) <= 0.0
            || ($jetzt - (int) ($stand['ts'] ?? 0)) > self::HIM_ALTER * 86400) {
            return ['rb' => round($median, 4), 'ts' => $jetzt, 'n' => 1];
        }
        $alt = (float) $stand['rb'];
        $n   = (int) ($stand['n'] ?? 1) + 1;
        if ($median < $alt) {
            return ['rb' => round($median, 4), 'ts' => $jetzt, 'n' => $n];
        }
        return ['rb' => round($alt, 4), 'ts' => $jetzt, 'n' => $n];
    }

    /**
     * Fach der Sonnenhoehe fuer den gelernten Klarwert.
     *
     * Getrennt gelernt, weil sich die Himmelsfarbe mit dem Sonnenstand aendert und nicht mit
     * dem Wetter: mittags tiefblau, in der Daemmerung rot. Ein gemeinsamer Klarwert wuerde
     * jeden Abend als bewoelkt melden — derselbe Fehler, der bei der Sichtweite schon einmal
     * Tag und Nacht zusammengeworfen hat.
     */
    public static function himmelFach(float $sonnenhoehe): string
    {
        foreach ([2.0, 6.0, 12.0, 20.0, 30.0, 45.0] as $i => $g) {
            if ($sonnenhoehe < $g) {
                return 'h' . $i;
            }
        }
        return 'h6';
    }
}
