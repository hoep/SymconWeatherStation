<?php

declare(strict_types=1);

namespace Hoep\Weather;

/**
 * Observation — eine Wetterbeobachtung in EINER normierten Form, egal von welcher Station.
 *
 * Zwei Entwurfsentscheidungen, die den Rest tragen:
 *
 * 1. JEDER Wert traegt seinen eigenen Zeitstempel. Eine Station liefert Temperatur alle
 *    10 Sekunden und Blitze nur, wenn es blitzt — ein gemeinsamer "Stand von" waere gelogen.
 *    Erst damit kann die Zusammenfuehrung entscheiden, welche Quelle je Groesse gewinnt.
 *
 * 2. Fehlt ein Wert, ist er NULL und nicht 0. Eine Station ohne Strahlungssensor meldet
 *    nicht "0 W/m2" — sie meldet nichts. Der Unterschied entscheidet, ob nachts die
 *    Bewoelkung als "bedeckt" oder als "keine Aussage" herauskommt.
 */
final class Observation
{
    /** Einheiten sind Teil des Vertrags: Grad C, km/h, hPa, mm, mm/h, W/m2, Grad, km. */
    public const QUANTITIES = [
        'tempC'          => ['Aussentemperatur', '°C', '~Temperature'],
        'humPct'         => ['Luftfeuchte', '%', '~Humidity.F'],
        'dewC'           => ['Taupunkt', '°C', '~Temperature'],
        'windKmh'        => ['Wind', 'km/h', '~WindSpeed.kmh'],
        'windAvgKmh'     => ['Wind Mittel', 'km/h', '~WindSpeed.kmh'],
        'gustKmh'        => ['Boe', 'km/h', '~WindSpeed.kmh'],
        'windDirDeg'     => ['Windrichtung', '°', '~WindDirection.Fahrenheit'],
        'pressureHpa'    => ['Luftdruck', 'hPa', '~AirPressure.F'],
        'pressureTrend'  => ['Luftdrucktendenz', '', ''],
        'rainRateMmH'    => ['Regenrate', 'mm/h', '~Precipitation'],
        'rainDayMm'      => ['Regen Tag', 'mm', '~Rainfall'],
        'rainMonthMm'    => ['Regen Monat', 'mm', '~Rainfall'],
        'rainYearMm'     => ['Regen Jahr', 'mm', '~Rainfall'],
        'radiationWm2'   => ['Globalstrahlung', 'W/m²', '~Illumination'],
        'uvIndex'        => ['UV-Index', '', '~UVIndex'],
        'illuminanceLux' => ['Beleuchtungsstaerke', 'lx', '~Illumination'],
        'tempInC'        => ['Innentemperatur', '°C', '~Temperature'],
        'humInPct'       => ['Innenfeuchte', '%', '~Humidity.F'],
        'etDayMm'        => ['Verdunstung Tag', 'mm', '~Rainfall'],
        'strikeTime'     => ['Letzter Blitz', '', '~UnixTimestamp'],
        'strikeDistKm'   => ['Blitzentfernung', 'km', ''],
        'strikeCount'    => ['Blitze', '', ''],
        'precipType'     => ['Niederschlagsart (Station)', '', ''],
        'batteryV'       => ['Batterie', 'V', '~Volt'],
    ];

    /** @var array<string,array{0:float|int|null,1:int}> ident => [Wert, Zeitstempel] */
    private array $werte = [];

    private string $quelle;
    private int    $gelesen;

    public function __construct(string $quelle = '', int $gelesen = 0)
    {
        $this->quelle  = $quelle;
        $this->gelesen = $gelesen > 0 ? $gelesen : time();
    }

    /**
     * Setzt einen Wert. NULL wird bewusst NICHT gespeichert — "nicht vorhanden" und
     * "vorhanden mit unbekanntem Wert" sollen sich nicht vermischen.
     */
    public function set(string $ident, $wert, ?int $ts = null): self
    {
        if ($wert === null || !isset(self::QUANTITIES[$ident])) {
            return $this;
        }
        $this->werte[$ident] = [$wert, $ts ?? $this->gelesen];
        return $this;
    }

    /**
     * Nimmt einen Wert wieder heraus. Notwendig, weil set() mit NULL bewusst nichts tut:
     * ein Treiber, der einen bereits gesetzten Wert nachtraeglich verwirft (Blitzentfernung
     * ohne Blitz, Stationsdruck ohne Hoehe), braucht dafuer einen ausdruecklichen Weg.
     */
    public function remove(string $ident): self
    {
        unset($this->werte[$ident]);
        return $this;
    }

    public function has(string $ident): bool
    {
        return isset($this->werte[$ident]);
    }

    /** @return float|int|null */
    public function get(string $ident)
    {
        return $this->werte[$ident][0] ?? null;
    }

    public function num(string $ident): ?float
    {
        $v = $this->get($ident);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Zeitstempel des Wertes, 0 wenn nicht vorhanden. */
    public function ts(string $ident): int
    {
        return $this->werte[$ident][1] ?? 0;
    }

    /** Alter des Wertes in Sekunden, PHP_INT_MAX wenn nicht vorhanden. */
    public function age(string $ident): int
    {
        $t = $this->ts($ident);
        return $t > 0 ? max(0, time() - $t) : PHP_INT_MAX;
    }

    /** @return array<int,string> */
    public function idents(): array
    {
        return array_keys($this->werte);
    }

    public function quelle(): string
    {
        return $this->quelle;
    }

    public function leer(): bool
    {
        return $this->werte === [];
    }

    /** @return array<string,mixed> flache Darstellung fuer Anzeige und Fehlersuche */
    public function toArray(): array
    {
        $o = ['_quelle' => $this->quelle, '_gelesen' => $this->gelesen];
        foreach ($this->werte as $k => [$v, $t]) {
            $o[$k] = ['wert' => $v, 'alter_s' => max(0, time() - $t)];
        }
        return $o;
    }

    /**
     * Fuehrt eine zweite Beobachtung ein: uebernommen wird je Groesse nur, was hier fehlt
     * ODER was dort merklich frischer ist. "Merklich" verhindert Flattern zwischen zwei
     * Quellen, die sich sekundenweise abwechseln.
     */
    public function merge(Observation $andere, int $maxAlter = 0, int $frischerAb = 60): self
    {
        foreach ($andere->idents() as $id) {
            if ($maxAlter > 0 && $andere->age($id) > $maxAlter) {
                continue;
            }
            $habe = $this->ts($id);
            if ($habe === 0 || $andere->ts($id) > $habe + $frischerAb) {
                $this->werte[$id] = [$andere->get($id), $andere->ts($id)];
            }
        }
        return $this;
    }
}
