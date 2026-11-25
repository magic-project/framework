<?php

declare(strict_types=1);

use Magic\Application;
use Magic\Config\Config;
use Magic\Routing\Route;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new Application(
    config: Config::fromFile(
        dirname(__DIR__) . '/config/config.php',
        Config::environment(),
    ),
    autoloadPath: dirname(__DIR__) . '/config/autoload.php',
);

$registerRoutes = require dirname(__DIR__) . '/config/routers.php';
Route::withRouter($app->router(), static function () use ($registerRoutes, $app): void {
    $registerRoutes($app);
});

return $app;
