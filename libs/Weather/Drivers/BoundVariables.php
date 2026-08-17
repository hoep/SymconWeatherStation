<?php

declare(strict_types=1);

namespace Hoep\Weather\Drivers;

use Hoep\Weather\Engines\Meteo;
use Hoep\Weather\IWeatherSource;
use Hoep\Weather\Observation;

/**
 * BoundVariables — liest gewoehnliche Symcon-Variablen.
 *
 * Der Rueckfall fuer jede Station, fuer die es kein eigenes Verfahren gibt: hat jemand
 * sein Wetter schon irgendwie in Symcon, zeigt er hier einfach darauf. Das ist zugleich
 * der Weg, ein vorhandenes Stationsmodul eines Dritten einzubinden, ohne dessen Code
 * anzufassen.
 *
 * Der Zeitstempel kommt aus `VariableUpdated` der jeweiligen Variablen, nicht aus der
 * Uhr — nur so erkennt die Zusammenfuehrung eine eingefrorene Quelle. Genau daran
 * scheitern Aufbauten, die stumpf den letzten Wert weiterreichen: eine tote Station
 * sieht dann bis in alle Ewigkeit aus wie eine lebende.
 *
 * Fehlt der Taupunkt, wird er aus Temperatur und Feuchte gerechnet.
 */
final class BoundVariables implements IWeatherSource
{
    /** @var array<string,int> ident => VariablenID */
    private array $map = [];
    private string $fehler = '';

    public static function id(): string
    {
        return 'bound-variables';
    }

    public static function label(): string
    {
        return 'Vorhandene Symcon-Variablen (jede Station, auch Fremdmodule)';
    }

    public static function fields(): array
    {
        $f = [];
        foreach (Observation::QUANTITIES as $ident => [$label, $einheit, $profil]) {
            $f[] = ['name' => 'Var_' . $ident, 'type' => 'Variable', 'default' => 0,
                    'caption' => $label . ($einheit !== '' ? ' (' . $einheit . ')' : '')];
        }
        return $f;
    }

    public function configure(array $config): void
    {
        $this->map = [];
        foreach (Observation::QUANTITIES as $ident => $_) {
            $id = (int) ($config['Var_' . $ident] ?? 0);
            if ($id > 0) {
                $this->map[$ident] = $id;
            }
        }
    }

    public function lastError(): string
    {
        return $this->fehler;
    }

    public function read(): Observation
    {
        $this->fehler = '';
        $o = new Observation(self::id());
        if ($this->map === []) {
            $this->fehler = 'keine Variablen zugeordnet';
            return $o;
        }
        $tot = 0;
        foreach ($this->map as $ident => $vid) {
            if (!@IPS_VariableExists($vid)) {
                $tot++;
                continue;
            }
            $v = @GetValue($vid);
            if (!is_numeric($v)) {
                continue;
            }
            $o->set($ident, (float) $v, (int) IPS_GetVariable($vid)['VariableUpdated']);
        }
        if ($tot > 0) {
            $this->fehler = $tot . ' zugeordnete Variablen gibt es nicht mehr';
        }
        $t = $o->num('tempC');
        $r = $o->num('humPct');
        if ($t !== null && $r !== null && !$o->has('dewC')) {
            $o->set('dewC', Meteo::taupunkt($t, $r), $o->ts('tempC'));
        }
        return $o;
    }
}
