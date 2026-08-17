<?php

declare(strict_types=1);

namespace Hoep\Weather;

/**
 * IWeatherSource — was eine Wetterquelle koennen muss.
 *
 * Bewusst schmal: konfigurieren, lesen, sich selbst beschreiben. Alles Rechnen liegt in
 * der WeatherEngine, alles Schreiben im Modul. Ein Treiber, der mehr tut, ist falsch
 * geschnitten.
 */
interface IWeatherSource
{
    /** Kennung im Katalog, z. B. 'davis-actdata'. */
    public static function id(): string;

    /** Anzeigename fuer das Formular. */
    public static function label(): string;

    /**
     * Welche Felder der Treiber zum Arbeiten braucht.
     * @return array<int,array{name:string,caption:string,type:string,default:mixed,hint?:string}>
     */
    public static function fields(): array;

    /** @param array<string,mixed> $config Werte zu den Feldern aus fields() */
    public function configure(array $config): void;

    /**
     * Liest die Station. Wirft NICHT bei Netzfehlern — eine unerreichbare Station ist ein
     * Betriebszustand, kein Programmfehler. Dann kommt eine leere Beobachtung zurueck und
     * lastError() sagt, warum.
     */
    public function read(): Observation;

    /** Letzter Fehlertext, leer wenn der letzte Lesevorgang gelang. */
    public function lastError(): string;
}
