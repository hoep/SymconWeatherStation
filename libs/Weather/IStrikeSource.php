<?php

declare(strict_types=1);

namespace Hoep\Weather;

/**
 * IStrikeSource — eine Quelle, die EINZELNE Blitze liefern kann, nicht nur den letzten.
 *
 * Getrennt von IWeatherSource, weil es die wenigsten koennen: die meisten Stationen und
 * Dienste geben nur "letzter Blitz" und eine mittlere Entfernung heraus. Wer die Einzelereignisse
 * hat, soll sie aber weiterreichen duerfen — sonst gehen zwischen zwei Abfragen genau die
 * Schlaege verloren, aus denen sich Zugrichtung und Ankunft einer Zelle ergeben.
 */
interface IStrikeSource
{
    /** @return array<int,array{t:int,d:float}> Blitze der letzten Stunde, aufsteigend nach Zeit */
    public function strikes(): array;
}
