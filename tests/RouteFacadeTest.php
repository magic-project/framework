<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Http\Request;
use Magic\Routing\Route;
use Magic\Routing\Router;
use Magic\Tests\Fixtures\Routing\TestController;
use PHPUnit\Framework\TestCase;

final class RouteFacadeTest extends TestCase
{
    public function testRouteFacadeRegistersRoutesOnBoundRouter(): void
    {
        $router = new Router();
        Route::withRouter($router, static function (): void {
            Route::get('/health', static fn (): array => ['ok' => true]);
        });

        $response = $router->dispatch(new Request('GET', '/health'));

        self::assertSame(200, $response->status());
        self::assertSame(['ok' => true], json_decode($response->body(), true));
    }

    public function testRouteFacadeBindingIsRestoredAfterScopedRegistration(): void
    {
        Route::clearRouter();
        $router = new Router();

        Route::withRouter($router, static function (): void {
            Route::get('/health', static fn (): array => ['ok' => true]);
        });

        $this->expectException(\RuntimeException::class);
        Route::get('/leaked', static fn (): string => 'must not register');
    }

    public function testBootstrapUsesLaravelStyleRouteFacadeWithoutLeakingGlobalState(): void
    {
        Route::clearRouter();
        $app = require dirname(__DIR__) . '/bootstrap/app.php';

        self::assertSame(200, $app->handle(new Request('GET', '/health'))->status());
        self::assertSame(200, $app->handle(new Request('GET', '/'))->status());
        self::assertSame('1', $app->handle(new Request('GET', '/test'))->body());

        self::assertSame(
            '9',
            $app->handle(new Request('GET', '/test', [], ['page' => '9']))->body(),
        );

        $this->expectException(\RuntimeException::class);
        Route::get('/must-not-leak', static fn (): string => 'never');
    }

    public function testBootstrapResolvesControllerRoutes(): void
    {
        Route::clearRouter();
        $app = require dirname(__DIR__) . '/bootstrap/app.php';
        $app->router()->get('/fixture/{id}', [TestController::class, 'show']);

        self::assertSame(
            ['method' => 'GET', 'id' => '7'],
            json_decode($app->handle(new Request('GET', '/fixture/7'))->body(), true),
        );
    }

}
