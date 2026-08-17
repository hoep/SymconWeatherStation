<?php

declare(strict_types=1);

namespace Hoep\Weather\Engines;

/**
 * StationCodes — die Zahlenschluessel, die Davis-Stationen fuer Tendenz und Vorhersage senden.
 *
 * Beides sind Geraetekonventionen, keine Meteorologie: die Station hat eine eingebaute
 * Vorhersageregel und meldet deren Ergebnis als Bitmuster. Deshalb stehen sie hier und nicht
 * in Meteo — dort gehoert nur hinein, was aus Messwerten folgt.
 */
final class StationCodes
{
    private function __construct()
    {
    }

    /** Luftdrucktendenz der Vantage-Reihe. */
    public static function tendenzText(?float $code): string
    {
        if ($code === null) {
            return '';
        }
        switch ((int) $code) {
            case 196: return 'fallend schnell';
            case 236: return 'fallend langsam';
            case 0:   return 'stabil';
            case 20:  return 'steigend langsam';
            case 60:  return 'steigend schnell';
            default:  return 'unbekannt';
        }
    }

    /**
     * Vorhersage der Station als Bitmuster: 8 sonnig, 4 teilweise, 2 wolkig, 1 Regen,
     * 16 Schnee. 127 heisst "keine Aussage".
     */
    public static function vorhersageText(?float $code): string
    {
        if ($code === null) {
            return '';
        }
        $fc = (int) $code;
        if ($fc === 127 || $fc < 0) {
            return '';
        }
        $t = [];
        if ($fc & 8) { $t[] = 'sonnig'; }
        if ($fc & 4) { $t[] = 'teilweise'; }
        if ($fc & 2) { $t[] = 'wolkig'; }
        if (($fc & 17) === 17) {
            $t[] = 'Regen oder Schnee in den nächsten 12 Stunden';
        } elseif ($fc & 1) {
            $t[] = 'Regen in den nächsten 12 Stunden';
        } elseif ($fc & 16) {
            $t[] = 'Schnee in den nächsten 12 Stunden';
        }
        return $t === [] ? '' : implode(' ', $t);
    }
}
