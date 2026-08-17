<?php

declare(strict_types=1);

namespace Hoep\Weather;

/**
 * SourceFactory — kennt alle Treiber und erzeugt sie aus ihrer Kennung.
 *
 * Die Liste ist bewusst fest verdrahtet statt automatisch aus dem Verzeichnis gelesen:
 * ein Treiber, den niemand hier eingetragen hat, taucht auch in keinem Formular auf.
 * Das haelt die Auswahl vorhersagbar und macht das Hinzufuegen zu einer bewussten Tat.
 */
final class SourceFactory
{
    /** @var array<int,class-string<IWeatherSource>> */
    private const KATALOG = [
        Drivers\DavisActData::class,
        Drivers\DavisWeatherLinkLive::class,
        Drivers\TempestUdp::class,
        Drivers\TempestModule::class,
        Drivers\OpenMeteo::class,
        Drivers\BoundVariables::class,
    ];

    private function __construct()
    {
    }

    /** @return array<string,class-string<IWeatherSource>> */
    public static function all(): array
    {
        $o = [];
        foreach (self::KATALOG as $k) {
            $o[$k::id()] = $k;
        }
        return $o;
    }

    /** @return array<int,array{value:string,caption:string}> fuer ein Auswahlfeld */
    public static function options(): array
    {
        $o = [];
        foreach (self::KATALOG as $k) {
            $o[] = ['value' => $k::id(), 'caption' => $k::label()];
        }
        return $o;
    }

    public static function has(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    /** @throws \InvalidArgumentException wenn die Kennung unbekannt ist */
    public static function create(string $id, array $config = []): IWeatherSource
    {
        $alle = self::all();
        if (!isset($alle[$id])) {
            throw new \InvalidArgumentException('Unbekannte Wetterquelle: ' . $id);
        }
        $k = $alle[$id];
        $t = new $k();
        $t->configure($config);
        return $t;
    }

    /** Felder eines Treibers, ohne ihn zu erzeugen. */
    public static function fields(string $id): array
    {
        $alle = self::all();
        return isset($alle[$id]) ? $alle[$id]::fields() : [];
    }
}
