<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Config\Config;
use Magic\Context\ContextStorageInterface;
use Magic\Context\SwooleContextStorage;
use Magic\Context\SynchronousContextStorage;
use App\Middleware\RequestIdMiddleware;
use Magic\Http\Request;
use Magic\Http\Response;
use Magic\Runtime\Swoole\SwooleRequestFactory;
use Magic\Runtime\Swoole\SwooleRuntime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;

final class SwooleRuntimeTest extends TestCase
{
    public function testRuntimePassesConfiguredShutdownTimeoutAndWorkerHooksToServer(): void
    {
        $server = new class {
            /** @var array<string, mixed> */
            public array $settings = [];

            /** @var array<string, callable> */
            public array $callbacks = [];

            public function set(array $settings): bool
            {
                $this->settings = $settings;

                return true;
            }

            public function on(string $event, callable $callback): bool
            {
                $this->callbacks[$event] = $callback;

                return true;
            }

            public function start(): bool
            {
                ($this->callbacks['workerStart'])($this, 3);
                ($this->callbacks['workerStop'])($this, 3);

                return true;
            }
        };
        $runtime = new SwooleRuntime([], static fn (): object => $server);
        $app = new Application(config: Config::fromArray([
            'runtime' => ['shutdown_timeout' => 7],
        ]));
        $events = [];
        $app->onWorkerStart(static function (int $workerId) use (&$events): void {
            $events[] = ['start', $workerId];
        });
        $app->onWorkerStop(static function (int $workerId) use (&$events): void {
            $events[] = ['stop', $workerId];
        });

        $runtime->run($app, '127.0.0.1', 19501);

        self::assertSame(7, $server->settings['max_wait_time']);
        self::assertSame([
            ['start', 3],
            ['stop', 3],
        ], $events);
        self::assertSame(1, $app->metrics()->snapshot()['workers_started_total']);
        self::assertSame(1, $app->metrics()->snapshot()['workers_stopped_total']);
        self::assertSame(3, $app->metrics()->snapshot()['worker_id']);
    }

    public function testExplicitServerSettingOverridesConfiguredShutdownTimeout(): void
    {
        $server = new class {
            public array $settings = [];
            public array $callbacks = [];

            public function set(array $settings): bool
            {
                $this->settings = $settings;

                return true;
            }

            public function on(string $event, callable $callback): bool
            {
                $this->callbacks[$event] = $callback;

                return true;
            }

            public function start(): bool
            {
                return true;
            }
        };
        $runtime = new SwooleRuntime(
            ['max_wait_time' => 2],
            static fn (): object => $server,
        );
        $app = new Application(config: Config::fromArray([
            'runtime' => ['shutdown_timeout' => 7],
        ]));

        $runtime->run($app, '127.0.0.1', 19501);

        self::assertSame(2, $server->settings['max_wait_time']);
    }

    public function testDisablingCoroutinesFailsBeforeServerStarts(): void
    {
        $app = new Application();
        $runtime = new SwooleRuntime(['enable_coroutine' => false], static function (): object {
            throw new \RuntimeException('server should not be created');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires enable_coroutine=true');
        $runtime->run($app, '127.0.0.1', 19501);
    }

    public function testConfiguredContextStorageIsNotReplacedBySwooleRuntime(): void
    {
        $storage = new SynchronousContextStorage();
        $app = new Application($storage);
        $server = new class {
            public array $callbacks = [];

            public function set(array $settings): bool { return true; }

            public function on(string $event, callable $callback): bool
            {
                $this->callbacks[$event] = $callback;

                return true;
            }

            public function start(): bool { return true; }
        };
        $runtime = new SwooleRuntime([], static fn (): object => $server);

        $runtime->run($app, '127.0.0.1', 19501);

        $response = $app->handle(new Request('GET', '/health'));

        self::assertSame(404, $response->status());
        self::assertInstanceOf(ContextStorageInterface::class, $app->container()->get(ContextStorageInterface::class));
    }

    public function testRuntimeBootsApplicationBeforeStartingServer(): void
    {
        $app = new Application();
        $server = new class($app) {
            public array $callbacks = [];

            public function __construct(private readonly Application $app)
            {
            }

            public function set(array $settings): bool
            {
                return true;
            }

            public function on(string $event, callable $callback): bool
            {
                $this->callbacks[$event] = $callback;

                return true;
            }

            public function start(): bool
            {
                if (!$this->app->isBooted()) {
                    throw new \RuntimeException('Application was not booted.');
                }

                return true;
            }
        };
        $runtime = new SwooleRuntime([], static fn (): object => $server);

        $runtime->run($app, '127.0.0.1', 19501);

        self::assertTrue($app->container()->isFrozen());
    }

    public function testStopMarksApplicationBeforeAskingServerToShutdown(): void
    {
        $app = new Application();
        $server = new class($app) {
            public bool $observedStoppingState = false;

            public function __construct(private readonly Application $app)
            {
            }

            public function shutdown(): void
            {
                $this->observedStoppingState = $this->app->isStopping();
            }
        };
        $runtime = new SwooleRuntime();
        $runtimeReflection = new \ReflectionClass($runtime);
        $applicationProperty = $runtimeReflection->getProperty('application');
        $applicationProperty->setValue($runtime, $app);
        $serverProperty = $runtimeReflection->getProperty('server');
        $serverProperty->setValue($runtime, $server);

        $runtime->stop();

        self::assertTrue($server->observedStoppingState);
    }

    public function testSigtermHandlerStopsTheOwningServerProcess(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            self::markTestSkipped('PCNTL and POSIX extensions are required.');
        }

        $app = new Application();
        $server = new class {
            public bool $shutdown = false;

            public function shutdown(): void
            {
                $this->shutdown = true;
            }
        };
        $runtime = new SwooleRuntime();
        $reflection = new \ReflectionClass($runtime);
        $reflection->getProperty('application')->setValue($runtime, $app);
        $reflection->getProperty('server')->setValue($runtime, $server);

        $registerSignals = $reflection->getMethod('registerSignals');
        $registerSignals->invoke($runtime, false);

        try {
            posix_kill(getmypid(), SIGTERM);
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
        }

        self::assertTrue($app->isStopping());
        self::assertTrue($server->shutdown);
    }

    public function testServerShutdownFailureIsLoggedAndRethrown(): void
    {
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                ];
            }
        };
        $app = new Application(logger: $logger);
        $server = new class {
            public function shutdown(): void
            {
                throw new \RuntimeException('server shutdown failed');
            }
        };
        $runtime = new SwooleRuntime();
        $runtimeReflection = new \ReflectionClass($runtime);
        $applicationProperty = $runtimeReflection->getProperty('application');
        $applicationProperty->setValue($runtime, $app);
        $serverProperty = $runtimeReflection->getProperty('server');
        $serverProperty->setValue($runtime, $server);

        try {
            $runtime->stop();
            self::fail('Expected server shutdown failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('server shutdown failed', $exception->getMessage());
        }

        self::assertSame([
            ['level' => 'error', 'message' => 'Swoole server shutdown failed.'],
        ], $records);
    }

    public function testSwooleRequestIsConvertedToFrameworkRequest(): void
    {
        $request = new class {
            public array $server = [
                'request_method' => 'post',
                'request_uri' => '/users/42?active=1',
            ];

            public array $header = [
                'X-Request-Id' => 'fixed-id',
            ];

            public array $get = [
                'active' => '1',
            ];

            public function rawContent(): string
            {
                return '{"name":"Ada"}';
            }
        };

        $converted = SwooleRequestFactory::from($request);

        self::assertSame('POST', $converted->method());
        self::assertSame('/users/42', $converted->path());
        self::assertSame('/users/42?active=1', $converted->uri());
        self::assertSame('active=1', $converted->queryString());
        self::assertSame('fixed-id', $converted->header('x-request-id'));
        self::assertSame(['active' => '1'], $converted->query());
        self::assertSame('{"name":"Ada"}', $converted->body());
    }

    public function testSwooleRequestFactoryUsesRawQueryParameterCount(): void
    {
        $request = new class {
            public array $server = [
                'request_method' => 'GET',
                'request_uri' => '/search?q=1&q=2',
            ];

            public array $header = [];
            public array $get = ['q' => '2'];
        };

        $this->expectException(\Magic\Http\RequestLimitException::class);
        $this->expectExceptionMessage('Too many query parameters.');

        SwooleRequestFactory::from($request, null, null, null, 1);
    }

    public function testSwooleRequestFactoryDoesNotSplitEncodedAmpersand(): void
    {
        $request = new class {
            public array $server = [
                'request_method' => 'GET',
                'request_uri' => '/search?q=1%26page=2',
            ];

            public array $header = [];
            public array $get = ['q' => '1&page=2'];
        };

        $converted = SwooleRequestFactory::from($request, null, null, null, 1);

        self::assertSame('q=1%26page=2', $converted->queryString());
    }

    public function testRuntimeWritesFrameworkResponseToSwooleResponse(): void
    {
        $app = new Application();
        $app->get('/health', static fn (): array => ['ok' => true]);
        $runtime = new SwooleRuntime();

        $request = new class {
            public array $server = [
                'request_method' => 'GET',
                'request_uri' => '/health',
            ];

            public array $header = [];
            public array $get = [];

            public function rawContent(): string
            {
                return '';
            }
        };

        $response = new class {
            public int $statusCode = 0;
            public array $headers = [];
            public string $body = '';

            public function status(int $status): void
            {
                $this->statusCode = $status;
            }

            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            public function end(string $body): void
            {
                $this->body = $body;
            }
        };

        $runtime->handle($app, $request, $response);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',
            (string) $response->headers['X-Request-Id'],
        );
        self::assertSame(['ok' => true], json_decode($response->body, true));
    }

    public function testRuntimeLogsRequestConversionFailures(): void
    {
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                ];
            }
        };
        $app = new Application(logger: $logger);
        $runtime = new SwooleRuntime();

        $request = new class {
            public array $server = [
                'request_method' => 'POST',
                'request_uri' => '/upload',
            ];

            public array $header = ['X-Request-Id' => 'conversion-failure-id'];
            public array $get = [];

            public function rawContent(): string
            {
                throw new \RuntimeException('request body read failed');
            }
        };

        $response = new class {
            public int $statusCode = 0;
            public array $headers = [];
            public string $body = '';

            public function status(int $status): void
            {
                $this->statusCode = $status;
            }

            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            public function end(string $body): void
            {
                $this->body = $body;
            }
        };

        $runtime->handle($app, $request, $response);

        self::assertSame(500, $response->statusCode);
        self::assertSame('conversion-failure-id', $response->headers['X-Request-Id']);
        self::assertSame([
            ['level' => 'error', 'message' => 'Swoole request handling failed.'],
        ], $records);
    }

    public function testRuntimeRendersUnhandledExceptionsAsJson(): void
    {
        $app = new Application();
        $app->middleware(new RequestIdMiddleware($app->context()));
        $app->get('/explode', static function (): never {
            throw new \RuntimeException('boom');
        });
        $runtime = new SwooleRuntime();

        $request = new class {
            public array $server = [
                'request_method' => 'GET',
                'request_uri' => '/explode',
            ];

            public array $header = ['X-Request-Id' => 'fixed-id'];
            public array $get = [];

            public function rawContent(): string
            {
                return '';
            }
        };

        $response = new class {
            public int $statusCode = 0;
            public array $headers = [];
            public string $body = '';

            public function status(int $status): void
            {
                $this->statusCode = $status;
            }

            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            public function end(string $body): void
            {
                $this->body = $body;
            }
        };

        $runtime->handle($app, $request, $response);

        self::assertSame(500, $response->statusCode);
        self::assertSame([
            'error' => [
                'code' => 'internal_error',
                'message' => 'Internal Server Error',
            ],
        ], json_decode($response->body, true));
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('fixed-id', $response->headers['X-Request-Id']);
    }

    public function testEarlyRequestLimitResponseIncludesRequestId(): void
    {
        $app = new Application(config: Config::fromArray([
            'http' => ['max_body_bytes' => 3],
        ]));
        $runtime = new SwooleRuntime();

        $request = new class {
            public array $server = [
                'request_method' => 'POST',
                'request_uri' => '/upload',
            ];

            public array $header = ['X-Request-Id' => 'early-fixed-id'];
            public array $get = [];

            public function rawContent(): string
            {
                return 'too-large';
            }
        };

        $response = new class {
            public int $statusCode = 0;
            public array $headers = [];
            public string $body = '';

            public function status(int $status): void
            {
                $this->statusCode = $status;
            }

            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            public function end(string $body): void
            {
                $this->body = $body;
            }
        };

        $runtime->handle($app, $request, $response);

        self::assertSame(413, $response->statusCode);
        self::assertSame('early-fixed-id', $response->headers['X-Request-Id']);
    }

    public function testLongRunningRequestsDoNotShareRequestState(): void
    {
        $app = new Application();
        $app->get('/scope', static function (Request $request, array $params) use ($app): array {
            $previous = $app->context()->get('marker', 'absent');
            $current = $request->header('x-marker', 'not-provided');
            $app->context()->set('marker', $current);

            return [
                'previous' => $previous,
                'current' => $current,
            ];
        });

        for ($index = 1; $index <= 50; $index++) {
            $response = $app->handle(new Request('GET', '/scope', ['x-marker' => 'request-' . $index]));
            self::assertSame([
                'previous' => 'absent',
                'current' => 'request-' . $index,
            ], json_decode($response->body(), true));
        }

        self::assertFalse($app->context()->isActive());
    }

    public function testConcurrentSwooleRequestsKeepSeparateContexts(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole is not installed.');
        }

        $app = new Application(new SwooleContextStorage());
        $app->get('/scope', static function (Request $request, array $params) use ($app): array {
            $marker = $request->header('x-marker', 'not-provided');
            $before = $app->context()->get('marker', 'absent');
            $app->context()->set('marker', $marker);
            \Swoole\Coroutine::sleep(0.001);
            $after = $app->context()->get('marker', 'missing');

            return [
                'before' => $before,
                'after' => $after,
            ];
        });

        $results = [];
        \Swoole\Coroutine\run(function () use ($app, &$results): void {
            go(function () use ($app, &$results): void {
                $response = $app->handle(new Request('GET', '/scope', ['x-marker' => 'A']));
                $results['A'] = json_decode($response->body(), true);
            });

            go(function () use ($app, &$results): void {
                $response = $app->handle(new Request('GET', '/scope', ['x-marker' => 'B']));
                $results['B'] = json_decode($response->body(), true);
            });
        });

        self::assertSame(['before' => 'absent', 'after' => 'A'], $results['A']);
        self::assertSame(['before' => 'absent', 'after' => 'B'], $results['B']);
    }

    public function testStoppingRejectsNewRequestsWithoutInterruptingInFlightRequest(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole is not installed.');
        }

        $app = new Application(new SwooleContextStorage());
        $entered = new \Swoole\Coroutine\Channel(1);
        $release = new \Swoole\Coroutine\Channel(1);
        $app->get('/slow', static function () use ($entered, $release): array {
            $entered->push(true);
            $release->pop();

            return ['ok' => true];
        });
        $responses = [];

        \Swoole\Coroutine\run(function () use ($app, $entered, $release, &$responses): void {
            go(function () use ($app, $release, &$responses): void {
                $responses['in_flight'] = $app->handle(new Request('GET', '/slow'));
            });

            $entered->pop();
            $app->markStopping();
            $responses['new'] = $app->handle(new Request('GET', '/slow'));
            $release->push(true);
        });

        self::assertSame(200, $responses['in_flight']->status());
        self::assertSame(503, $responses['new']->status());
    }

    public function testStoppingDoesNotRejectAnAdmittedRequestBeforeItReachesTheRouter(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole is not installed.');
        }

        $app = new Application(new SwooleContextStorage());
        $entered = new \Swoole\Coroutine\Channel(1);
        $release = new \Swoole\Coroutine\Channel(1);
        $app->middleware(new class($entered, $release) implements MiddlewareInterface {
            public function __construct(
                private readonly \Swoole\Coroutine\Channel $entered,
                private readonly \Swoole\Coroutine\Channel $release,
            ) {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->entered->push(true);
                $this->release->pop();

                return $handler->handle($request);
            }
        });
        $app->get('/slow', static fn (): array => ['ok' => true]);
        $response = null;

        \Swoole\Coroutine\run(function () use ($app, $entered, $release, &$response): void {
            go(function () use ($app, &$response): void {
                $response = $app->handle(new Request('GET', '/slow'));
            });

            $entered->pop();
            $app->markStopping();
            $release->push(true);
        });

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status());
    }

    public function testNestedSwooleRequestsAreRejectedWithoutReplacingContext(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole is not installed.');
        }

        $app = new Application(new SwooleContextStorage());
        $app->get('/inner', static fn (): array => ['ok' => true]);
        $app->get('/outer', static function () use ($app): array {
            $app->context()->set('marker', 'outer');

            try {
                $app->handle(new Request('GET', '/inner'));
            } catch (\RuntimeException $exception) {
                return [
                    'message' => $exception->getMessage(),
                    'marker' => $app->context()->get('marker'),
                ];
            }

            return ['message' => 'not rejected'];
        });

        $result = null;
        \Swoole\Coroutine\run(function () use ($app, &$result): void {
            $result = json_decode(
                $app->handle(new Request('GET', '/outer'))->body(),
                true,
            );
        });

        self::assertSame([
            'message' => 'Nested request contexts are not supported.',
            'marker' => 'outer',
        ], $result);
    }
}
