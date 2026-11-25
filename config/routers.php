<?php

declare(strict_types=1);

use App\Controller\Index;
use Magic\Application;
use Magic\Routing\Route;

return static function (Application $app): void {
    Route::get('/health', static fn (): array => []);
    Route::get('/ready', static fn (): array => []);

    Route::get('/', static function (): string {
        return 'hello world';
    });

    Route::get('/test', [Index::class, 'test']);
};
