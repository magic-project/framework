<?php

declare(strict_types=1);

namespace Magic\Routing;

use RuntimeException;

final class Route
{
    private static ?Router $router = null;

    /**
     * @deprecated Prefer registering routes through Application or withRouter().
     */
    public static function setRouter(Router $router): void
    {
        self::$router = $router;
    }

    /**
     * Registers routes against a Router without leaking the binding globally.
     */
    public static function withRouter(Router $router, callable $register): void
    {
        $previous = self::$router;
        self::$router = $router;

        try {
            $register();
        } finally {
            self::$router = $previous;
        }
    }

    /**
     * Clears the legacy global binding. Intended for compatibility tests and
     * applications migrating away from setRouter().
     */
    public static function clearRouter(): void
    {
        self::$router = null;
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public static function get(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        self::router()->get($pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public static function post(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        self::router()->post($pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public static function put(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        self::router()->put($pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public static function patch(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        self::router()->patch($pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public static function delete(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        self::router()->delete($pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public static function options(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        self::router()->options($pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public static function head(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        self::router()->head($pattern, $handler, $middleware);
    }

    /**
     * @param list<string> $methods
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public static function match(array $methods, string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        self::router()->map($methods, $pattern, $handler, $middleware);
    }

    private static function router(): Router
    {
        if (self::$router === null) {
            throw new RuntimeException('Route facade has not been initialized.');
        }

        return self::$router;
    }
}
