<?php

declare(strict_types=1);

namespace Hoep\Weather;

/**
 * Profiles — Variablenprofile fuer die Wettergroessen.
 *
 * Zwei Aufgaben, beide aus Schaden gelernt:
 *
 * 1. `exists()` prueft, ob ein Symcon-Standardprofil ueberhaupt vorhanden ist. Ein unbekanntes
 *    Profil laesst `IPS_CreateInstance` WORTLOS scheitern — Symcon meldet nur, dass die Instanz
 *    nicht erzeugt werden konnte, und man sucht den Fehler ueberall, nur nicht dort. `~Precipitation`
 *    zum Beispiel gibt es entgegen der Erwartung nicht.
 * 2. `ensure()` legt eigene Einheitenprofile an fuer alles, wofuer Symcon keines mitbringt.
 */
final class Profiles
{
    /** Name => [Suffix, Nachkommastellen, Minimum, Maximum] */
    private const EIGENE = [
        'WX.Regenrate' => [' mm/h', 2, 0.0, 100.0],
        'WX.Strahlung' => [' W/m²', 0, 0.0, 1400.0],
        'WX.Grad'      => ['°', 0, 0.0, 360.0],
        'WX.FSI'       => ['', 0, 0.0, 200.0],
        'WX.km'        => [' km', 0, 0.0, 100.0],
        'WX.mm'        => [' mm', 2, 0.0, 5000.0],
    ];

    private function __construct()
    {
    }

    public static function ensure(): void
    {
        foreach (self::EIGENE as $name => [$suffix, $dig, $min, $max]) {
            if (IPS_VariableProfileExists($name)) {
                continue;
            }
            IPS_CreateVariableProfile($name, 2);            // 2 = Float
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileDigits($name, $dig);
            IPS_SetVariableProfileValues($name, $min, $max, 0);
        }
    }

    /** Liefert den Profilnamen nur, wenn es ihn gibt — sonst den leeren Namen. */
    public static function exists(string $name): string
    {
        return ($name !== '' && IPS_VariableProfileExists($name)) ? $name : '';
    }

    /** Passendes Profil zu einer Groesse aus dem Katalog, abgesichert. */
    public static function forQuantity(string $ident): string
    {
        $p = Observation::QUANTITIES[$ident][2] ?? '';
        return self::exists($p);
    }
}
