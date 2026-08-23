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
}
