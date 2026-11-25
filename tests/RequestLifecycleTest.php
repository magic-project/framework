<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Context\RequestContext;
use Magic\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestLifecycleTest extends TestCase
{
    public function testDeferredCleanupRunsInReverseOrder(): void
    {
        $app = new Application();
        $events = [];
        $app->get('/defer', static function () use ($app, &$events): array {
            $app->context()->defer(static function () use (&$events): void {
                $events[] = 'first';
            });
            $app->context()->defer(static function () use (&$events): void {
                $events[] = 'second';
            });

            return ['ok' => true];
        });

        $app->handle(new Request('GET', '/defer'));

        self::assertSame(['second', 'first'], $events);
        self::assertFalse($app->context()->isActive());
    }

    public function testLifecycleHooksRunAroundRequestAndCleanupRunsOnException(): void
    {
        $app = new Application();
        $events = [];
        $app->onRequestStart(static function (RequestContext $context) use (&$events): void {
            $events[] = 'start';
            $context->defer(static function () use (&$events): void {
                $events[] = 'defer';
            });
        });
        $app->onRequestEnd(static function (RequestContext $context) use (&$events): void {
            $events[] = 'end';
        });
        $app->get('/explode', static function (): never {
            throw new RuntimeException('boom');
        });

        try {
            $app->handle(new Request('GET', '/explode'));
            self::fail('Expected handler exception was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame(['start', 'end', 'defer'], $events);
        self::assertFalse($app->context()->isActive());
    }

    public function testCleanupCallbacksContinueAfterOneCallbackFails(): void
    {
        $app = new Application();
        $events = [];
        $app->get('/cleanup-error', static function () use ($app, &$events): array {
            $app->context()->defer(static function () use (&$events): void {
                $events[] = 'first';
            });
            $app->context()->defer(static function () use (&$events): void {
                $events[] = 'failing';
                throw new RuntimeException('cleanup failed');
            });
            $app->context()->defer(static function () use (&$events): void {
                $events[] = 'last';
            });

            return ['ok' => true];
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cleanup failed');
        try {
            $app->handle(new Request('GET', '/cleanup-error'));
        } finally {
            self::assertSame(['last', 'failing', 'first'], $events);
            self::assertFalse($app->context()->isActive());
        }
    }
}
