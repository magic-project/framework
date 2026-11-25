<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Lifecycle\ApplicationLifecycle;
use PHPUnit\Framework\TestCase;

final class ApplicationLifecycleTest extends TestCase
{
    public function testBootIsIdempotentAndShutdownRunsInReverseOrder(): void
    {
        $lifecycle = new ApplicationLifecycle();
        $events = [];
        $lifecycle->onBoot(static function () use (&$events): void { $events[] = 'boot-1'; });
        $lifecycle->onBoot(static function () use (&$events): void { $events[] = 'boot-2'; });
        $lifecycle->onShutdown(static function () use (&$events): void { $events[] = 'stop-1'; });
        $lifecycle->onShutdown(static function () use (&$events): void { $events[] = 'stop-2'; });

        $lifecycle->boot();
        $lifecycle->boot();
        $lifecycle->shutdown();
        $lifecycle->shutdown();

        self::assertSame(['boot-1', 'boot-2', 'stop-2', 'stop-1'], $events);
        self::assertFalse($lifecycle->isBooted());
    }

    public function testShutdownContinuesAfterHookFailureAndRethrowsFirstFailure(): void
    {
        $lifecycle = new ApplicationLifecycle();
        $events = [];
        $lifecycle->onBoot(static function (): void {});
        $lifecycle->onShutdown(static function () use (&$events): void {
            $events[] = 'first';
            throw new \RuntimeException('first failure');
        });
        $lifecycle->onShutdown(static function () use (&$events): void { $events[] = 'second'; });

        $lifecycle->boot();
        try {
            $lifecycle->shutdown();
            self::fail('Expected shutdown failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('first failure', $exception->getMessage());
        }

        self::assertSame(['second', 'first'], $events);
        self::assertFalse($lifecycle->isBooted());
    }

    public function testWorkerHooksReceiveWorkerId(): void
    {
        $lifecycle = new ApplicationLifecycle();
        $events = [];
        $lifecycle->onWorkerStart(static function (int $id) use (&$events): void { $events[] = ['start', $id]; });
        $lifecycle->onWorkerStop(static function (int $id) use (&$events): void { $events[] = ['stop', $id]; });

        $lifecycle->workerStart(3);
        $lifecycle->workerStop(3);

        self::assertSame([['start', 3], ['stop', 3]], $events);
    }

    public function testWorkerHooksContinueAfterFailureAndRethrowFirstFailure(): void
    {
        $lifecycle = new ApplicationLifecycle();
        $events = [];
        $lifecycle->onWorkerStart(static function () use (&$events): void {
            $events[] = 'start-1';
            throw new \RuntimeException('start failed');
        });
        $lifecycle->onWorkerStart(static function () use (&$events): void { $events[] = 'start-2'; });
        $lifecycle->onWorkerStop(static function () use (&$events): void {
            $events[] = 'stop-1';
            throw new \RuntimeException('stop failed');
        });
        $lifecycle->onWorkerStop(static function () use (&$events): void { $events[] = 'stop-2'; });

        try {
            $lifecycle->workerStart(1);
            self::fail('Expected worker start failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('start failed', $exception->getMessage());
        }

        try {
            $lifecycle->workerStop(1);
            self::fail('Expected worker stop failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('stop failed', $exception->getMessage());
        }

        self::assertSame(['start-1', 'start-2', 'stop-2', 'stop-1'], $events);
    }

    public function testApplicationRejectsRequestsAfterStopping(): void
    {
        $app = new Application();
        $app->get('/live', static fn (): array => ['ok' => true]);
        $app->middleware(new \App\Middleware\RequestIdMiddleware($app->context()));
        $app->markStopping();

        $response = $app->handle(new \Magic\Http\Request('GET', '/live'));

        self::assertSame(503, $response->status());
        self::assertSame('1', $response->header('Retry-After'));
        self::assertNotNull($response->header('X-Request-Id'));
    }
}
