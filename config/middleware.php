<?php

declare(strict_types=1);

use App\Middleware\RequestIdMiddleware;
use App\Middleware\RequestSizeMiddleware;

return [
    'global' => [
        RequestIdMiddleware::class,
        RequestSizeMiddleware::class,
    ],

    'groups' => [],
    'aliases' => [],
];
