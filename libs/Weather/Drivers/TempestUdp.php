<?php

declare(strict_types=1);

namespace Hoep\Weather\Drivers;

use Hoep\Weather\IStrikeSource;
use Hoep\Weather\IWeatherSource;
use Hoep\Weather\Observation;

/**
 * TempestUdp — nimmt die Beobachtung von einem TempestListener entgegen.
 *
 * Der Listener haengt am UDP-Socket und hoert der Station unmittelbar zu; dieser Treiber holt
 * nur ab, was dort angekommen ist. Die Trennung ist noetig, weil Empfangen ein Dauerzustand
 * ist und Abfragen ein Zeitpunkt: eine Quelle, die erst beim Abruf lauschen wuerde, muesste
 * bis zu einer Minute auf den naechsten Beobachtungssatz warten.
 *
 * Gegenueber dem Weg ueber ein fremdes Stationsmodul gewinnt man zweierlei: Wind im
 * Drei-Sekunden-Takt statt im Minutenmittel, und JEDEN Blitz einzeln mit eigenem Zeitpunkt
 * und eigener Entfernung.
 */
final class TempestUdp implements IWeatherSource, IStrikeSource
{
    private int    $instanz = 0;
    private bool   $absolut = true;
    private float  $hoehe = 0.0;
    private string $fehler = '';

    public static function id(): string
    {
        return 'tempest-udp';
    }

    public static function label(): string
    {
        return 'WeatherFlow Tempest direkt über UDP (TempestListener)';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'ListenerID', 'caption' => 'TempestListener-Instanz', 'type' => 'Instance', 'default' => 0,
             'hint' => 'Die Instanz des TempestListener, die am UDP-Socket auf Port 50222 hängt.'],
            ['name' => 'PressureAbsolute', 'caption' => 'Luftdruck ist Stationsdruck', 'type' => 'Boolean', 'default' => true,
             'hint' => 'Die Tempest meldet den Druck am Aufstellort. Ohne Umrechnung passt er zu keiner anderen Quelle.'],
            ['name' => 'Altitude', 'caption' => 'Höhe des Aufstellorts (m über NN)', 'type' => 'Float', 'default' => 0.0],
        ];
    }

    public function configure(array $config): void
    {
        $this->instanz = (int) ($config['ListenerID'] ?? 0);
        $this->absolut = (bool) ($config['PressureAbsolute'] ?? true);
        $this->hoehe   = (float) ($config['Altitude'] ?? 0.0);
    }

    public function lastError(): string
    {
        return $this->fehler;
    }

    /**
     * Alle Blitze der letzten Stunde, einzeln. Ohne diesen Weg saehe die Station bei einem
     * Abruftakt von einer Minute nur jeden x-ten Schlag — und aus Stichproben laesst sich
     * keine Zugrichtung bestimmen.
     */
    public function strikes(): array
    {
        if ($this->instanz <= 0 || !@IPS_InstanceExists($this->instanz)) {
            return [];
        }
        try {
            $r = json_decode((string) @WXT_GetStrikes($this->instanz), true);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($r)) {
            return [];
        }
        $o = [];
        foreach ($r as $e) {
            if (isset($e['t'], $e['d'])) {
                $o[] = ['t' => (int) $e['t'], 'd' => (float) $e['d']];
            }
        }
        return $o;
    }

    public function read(): Observation
    {
        $this->fehler = '';
        if ($this->instanz <= 0 || !@IPS_InstanceExists($this->instanz)) {
            $this->fehler = 'kein TempestListener gewählt';
            return new Observation(self::id());
        }
        try {
            $roh = @WXT_GetObservation($this->instanz);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
            return new Observation(self::id());
        }
        $a = json_decode(is_string($roh) ? $roh : '', true);
        if (!is_array($a)) {
            $this->fehler = 'Listener liefert nichts Lesbares';
            return new Observation(self::id());
        }
        $o = new Observation(self::id());
        foreach ($a as $k => $v) {
            if ($k === '' || $k[0] === '_' || !is_array($v) || !isset($v['wert'])) {
                continue;
            }
            $o->set($k, $v['wert'], time() - (int) ($v['alter_s'] ?? 0));
        }
        if ($o->leer()) {
            $this->fehler = 'Listener hat noch keinen Messsatz empfangen (kommt einmal pro Minute)';
            return $o;
        }

        // Stationsdruck auf Meeresniveau bringen — sonst passt er zu keiner anderen Quelle.
        if ($o->has('pressureHpa') && $this->absolut) {
            if ($this->hoehe > 0.0) {
                $t0 = ($o->num('tempC') ?? 15.0) + 273.15;
                $o->set('pressureHpa',
                        round($o->num('pressureHpa') * pow(1 - (0.0065 * $this->hoehe) / ($t0 + 0.0065 * $this->hoehe), -5.257), 1),
                        $o->ts('pressureHpa'));
            } else {
                $o->remove('pressureHpa');
                $this->fehler = 'Stationsdruck ohne Höhenangabe - Luftdruck wird nicht geliefert';
            }
        }
        return $o;
    }
}
