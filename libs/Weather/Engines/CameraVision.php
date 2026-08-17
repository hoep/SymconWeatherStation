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

    private function __construct()
    {
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

        $g = []; $sumL = 0.0; $sumS = 0.0; $n = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c  = imagecolorat($s, $x, $y);
                $r  = ($c >> 16) & 255; $gr = ($c >> 8) & 255; $b = $c & 255;
                $l  = 0.299 * $r + 0.587 * $gr + 0.114 * $b;
                $g[$y][$x] = $l;
                $sumL += $l;
                $mx = max($r, $gr, $b); $mn = min($r, $gr, $b);
                $sumS += $mx > 0 ? ($mx - $mn) / $mx : 0.0;
                $n++;
            }
        }
        imagedestroy($s);

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
                'dichte' => round($kontrast / max(1.0, $helligkeit), 4),
                'saettigung' => round($sumS / max(1, $n), 3),
                'breite' => $W, 'hoehe' => $H];
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
