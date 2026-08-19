<?php

declare(strict_types=1);

/**
 * Laedt die Wetter-Bibliothek. Bewusst eine feste Liste statt eines Autoloaders:
 * Symcon laedt Module in einem eigenen Prozessraum, und ein registrierter Autoloader
 * ueberlebt den Modulwechsel nicht zuverlaessig. Die Liste ist kurz und ueberschaubar.
 */
$b = __DIR__ . '/';
foreach ([
    'Observation.php',
    'IWeatherSource.php',
    'Profiles.php',
    'IStrikeSource.php',
    'Engines/Meteo.php',
    'Engines/UpperAir.php',
    'Engines/Forecast.php',
    'Engines/StationCodes.php',
    'Engines/WeatherEngine.php',
    'Engines/CameraVision.php',
    'Drivers/DavisActData.php',
    'Drivers/DavisWeatherLinkLive.php',
    'Drivers/TempestModule.php',
    'Drivers/TempestUdp.php',
    'Drivers/OpenMeteo.php',
    'Drivers/BoundVariables.php',
    'SourceFactory.php',
] as $f) {
    require_once $b . $f;
}
