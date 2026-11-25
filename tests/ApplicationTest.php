<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Tests\Fixtures\Container\AutowireService;
use App\Middleware\RequestIdMiddleware;
use Magic\Http\Request;
use Magic\Http\Response;
use Magic\Runtime\RuntimeInterface;
use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testExplicitAutoloadPathLoadsApplicationBindings(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'magic-autoload-');
        self::assertNotFalse($file);
        file_put_contents($file, '<?php return ["bindings" => ["Magic\\Tests\\Fixtures\\Container\\AutowireService" => "Magic\\Tests\\Fixtures\\Container\\AutowireService"]];');
        register_shutdown_function(static function () use ($file): void {
            @unlink($file);
        });

        $app = new Application(autoloadPath: $file);

        self::assertInstanceOf(AutowireService::class, $app->container()->get(AutowireService::class));
    }

    public function testMissingExplicitAutoloadPathFailsFast(): void
    {
        $this->expectException(\RuntimeException::class);
        new Application(autoloadPath: sys_get_temp_dir() . '/magic-autoload-missing.php');
    }

    public function testDirectHandleBootsApplicationLifecycleOnce(): void
    {
        $app = new Application();
        $bootCount = 0;
        $app->onBoot(static function () use (&$bootCount): void {
            $bootCount++;
        });
        $app->get('/health', static fn (): array => ['ok' => true]);

        $app->handle(new Request('GET', '/health'));
        $app->handle(new Request('GET', '/health'));

        self::assertSame(1, $bootCount);
        self::assertTrue($app->container()->isFrozen());
    }

    public function testApplicationCannotHandleRequestsAfterShutdown(): void
    {
        $app = new Application();
        $app->get('/health', static fn (): array => ['ok' => true]);
        $app->boot();
        $app->shutdown();

        $this->expectException(\RuntimeException::class);
        $app->handle(new Request('GET', '/health'));
    }

    public function testContextStorageCanOnlyBeConfiguredOnce(): void
    {
        $app = new Application();
        $app->useContextStorage(new \Magic\Context\SynchronousContextStorage());

        $this->expectException(\RuntimeException::class);
        $app->useContextStorage(new \Magic\Context\SynchronousContextStorage());
    }

    public function testHealthRouteReturnsJson(): void
    {
        $app = new Application();
        $app->middleware(new RequestIdMiddleware($app->context()));
        $app->get('/health', static fn (): array => ['ok' => true]);

        $response = $app->handle(new Request('GET', '/health'));

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        self::assertTrue(json_decode($response->body(), true)['ok']);
        self::assertNotNull($response->header('X-Request-Id'));
    }

    public function testHeadersAreCaseInsensitive(): void
    {
        $request = new Request('get', '/health', ['X-Request-Id' => 'fixed-id']);

        self::assertSame('GET', $request->method());
        self::assertSame('fixed-id', $request->header('x-request-id'));
    }

    public function testRouteParametersArePassedToHandler(): void
    {
        $app = new Application();
        $app->get('/users/{id}', static fn (Request $request, array $params): array => [
            'id' => $params['id'],
        ]);

        $response = $app->handle(new Request('GET', '/users/42'));

        self::assertSame(200, $response->status());
        self::assertSame(['id' => '42'], json_decode($response->body(), true));
    }

    public function testDuplicateRouteParameterNamesAreRejectedAtRegistration(): void
    {
        $app = new Application();

        $this->expectException(\InvalidArgumentException::class);
        $app->get('/users/{id}/posts/{id}', static fn (): array => []);
    }

    public function testCommonHttpMethodsCanBeRegistered(): void
    {
        $app = new Application();
        $app->post('/users', static fn (): array => ['method' => 'POST']);
        $app->put('/users/42', static fn (): array => ['method' => 'PUT']);
        $app->patch('/users/42', static fn (): array => ['method' => 'PATCH']);
        $app->delete('/users/42', static fn (): array => ['method' => 'DELETE']);
        $app->map(['GET', 'POST'], '/batch', static fn (): array => ['method' => 'BATCH']);

        self::assertSame(['method' => 'POST'], json_decode($app->handle(new Request('POST', '/users'))->body(), true));
        self::assertSame(['method' => 'PUT'], json_decode($app->handle(new Request('PUT', '/users/42'))->body(), true));
        self::assertSame(['method' => 'PATCH'], json_decode($app->handle(new Request('PATCH', '/users/42'))->body(), true));
        self::assertSame(['method' => 'DELETE'], json_decode($app->handle(new Request('DELETE', '/users/42'))->body(), true));
        self::assertSame(['method' => 'BATCH'], json_decode($app->handle(new Request('POST', '/batch'))->body(), true));
    }

    public function testMethodNotAllowedIncludesAllowHeader(): void
    {
        $app = new Application();
        $app->get('/users', static fn (): array => ['ok' => true]);
        $app->post('/users', static fn (): array => ['ok' => true]);

        $response = $app->handle(new Request('DELETE', '/users'));

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD, POST, OPTIONS', $response->header('Allow'));
        self::assertSame([
            'error' => [
                'code' => 'method_not_allowed',
                'message' => 'Method Not Allowed',
            ],
        ], json_decode($response->body(), true));
    }

    public function testUnknownPathReturnsNotFoundWithoutAllowHeader(): void
    {
        $app = new Application();
        $app->get('/users', static fn (): array => ['ok' => true]);

        $response = $app->handle(new Request('GET', '/missing'));

        self::assertSame(404, $response->status());
        self::assertNull($response->header('Allow'));
        self::assertSame([
            'error' => [
                'code' => 'not_found',
                'message' => 'Not Found',
            ],
        ], json_decode($response->body(), true));
    }

    public function testHeadFallsBackToGetAndSuppressesResponseBody(): void
    {
        $app = new Application();
        $app->get('/health', static fn (): array => ['ok' => true]);

        $response = $app->handle(new Request('HEAD', '/health'));

        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
    }

    public function testResponseHeadersMergeCaseInsensitivelyAndNoContentStatusesHaveNoBody(): void
    {
        $response = Response::json(['ok' => true], 204, ['content-type' => 'text/plain']);

        self::assertSame('', $response->body());
        self::assertSame('text/plain', $response->header('Content-Type'));
        self::assertCount(1, $response->headers());

        $notModified = new Response('body', 304);
        self::assertSame('', $notModified->body());
    }

    public function testRuntimeMetricsTrackRequestDurationAndErrors(): void
    {
        $app = new Application();
        $app->get('/ok', static fn (): array => ['ok' => true]);

        $app->handle(new Request('GET', '/ok'));
        $metrics = $app->metrics()->snapshot();

        self::assertSame(1, $metrics['requests_total']);
        self::assertSame(0, $metrics['request_errors_total']);
        self::assertSame(0, $metrics['active_requests']);
        self::assertGreaterThanOrEqual(0.0, $metrics['request_duration_seconds_total']);
    }

    public function testInvalidJsonBodyReturns400InsteadOf500(): void
    {
        $app = new Application();
        $app->post('/echo', static fn (Request $request, array $params): array => ['value' => $request->post()]);

        $response = $app->handle(new Request(
            'POST',
            '/echo',
            ['content-type' => 'application/json'],
            [],
            '{invalid',
        ));

        self::assertSame(400, $response->status());
        self::assertSame('invalid_json', json_decode($response->body(), true)['error']['code']);
    }

    public function testResponseJsonSubstitutesInvalidUtf8(): void
    {
        $response = Response::json(['value' => "bad\xFFutf8"]);

        self::assertSame(200, $response->status());
        self::assertStringContainsString("\xEF\xBF\xBD", $response->body());
    }

    public function testDuplicateRoutesAreRejected(): void
    {
        $app = new Application();

        $this->expectException(\InvalidArgumentException::class);
        $app->get('/same', static fn (): array => ['handler' => 'first']);
        $app->get('/same', static fn (): array => ['handler' => 'second']);
    }

    public function testOptionsReturnsAllowHeaderForKnownPath(): void
    {
        $app = new Application();
        $app->get('/health', static fn (): array => ['ok' => true]);
        $app->post('/health', static fn (): array => ['ok' => true]);

        $response = $app->handle(new Request('OPTIONS', '/health'));

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
        self::assertSame('GET, HEAD, POST, OPTIONS', $response->header('Allow'));
    }

    public function testRequestContextDoesNotLeakBetweenRequests(): void
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

        $first = $app->handle(new Request('GET', '/scope', ['x-marker' => 'first']));
        $second = $app->handle(new Request('GET', '/scope', ['x-marker' => 'second']));

        self::assertSame(['previous' => 'absent', 'current' => 'first'], json_decode($first->body(), true));
        self::assertSame(['previous' => 'absent', 'current' => 'second'], json_decode($second->body(), true));
        self::assertFalse($app->context()->isActive());
    }

    public function testRequestContextIsClearedWhenHandlerThrows(): void
    {
        $app = new Application();
        $app->get('/explode', static function (): never {
            throw new \RuntimeException('boom');
        });

        try {
            $app->handle(new Request('GET', '/explode'));
            self::fail('Expected handler exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertFalse($app->context()->isActive());
    }

    public function testShutdownFailureIsLoggedAndRethrownWhenRuntimeStopsNormally(): void
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
        $app->onShutdown(static function (): void {
            throw new \RuntimeException('shutdown failed');
        });
        $runtime = new class implements RuntimeInterface {
            public function name(): string
            {
                return 'test';
            }

            public function run(Application $application, string $host, int $port): void
            {
            }

            public function stop(): void
            {
            }
        };

        try {
            $app->run($runtime);
            self::fail('Expected application shutdown failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('shutdown failed', $exception->getMessage());
        }

        self::assertSame([
            ['level' => 'error', 'message' => 'Application shutdown failed.'],
        ], $records);
    }

    public function testSafeHandlingRendersUnhandledExceptionsAsJson(): void
    {
        $app = new Application();
        $app->middleware(new RequestIdMiddleware($app->context()));
        $app->get('/explode', static function (): never {
            throw new \RuntimeException('boom');
        });

        $response = $app->handleSafely(new Request('GET', '/explode', ['X-Request-Id' => 'fixed-id']));

        self::assertSame(500, $response->status());
        self::assertSame([
            'error' => [
                'code' => 'internal_error',
                'message' => 'Internal Server Error',
            ],
        ], json_decode($response->body(), true));
        self::assertSame('fixed-id', $response->header('X-Request-Id'));
        self::assertFalse($app->context()->isActive());
    }

    public function testSafeHandlingKeepsGeneratedRequestIdOnExceptionResponse(): void
    {
        $app = new Application();
        $app->middleware(new RequestIdMiddleware($app->context()));
        $app->get('/explode', static function (): never {
            throw new \RuntimeException('boom');
        });

        $response = $app->handleSafely(new Request('GET', '/explode'));

        self::assertNotSame('', $response->header('X-Request-Id'));
        self::assertFalse($app->context()->isActive());
    }

    public function testNestedRequestsAreRejectedWithoutDestroyingOuterContext(): void
    {
        $app = new Application();
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

        $response = $app->handle(new Request('GET', '/outer'));

        self::assertSame([
            'message' => 'Nested request contexts are not supported.',
            'marker' => 'outer',
        ], json_decode($response->body(), true));
    }

    public function testContextCanStoreNullValues(): void
    {
        $app = new Application();
        $app->get('/null', static function () use ($app): array {
            $app->context()->set('value', null);

            return [
                'has' => $app->context()->has('value'),
                'value' => $app->context()->get('value', 'fallback'),
            ];
        });

        self::assertSame([
            'has' => true,
            'value' => null,
        ], json_decode($app->handle(new Request('GET', '/null'))->body(), true));
    }

    public function testRequestIdHeaderReplacesExistingHeaderCaseInsensitively(): void
    {
        $app = new Application();
        $app->middleware(new RequestIdMiddleware($app->context()));
        $app->get('/', static fn (): Response => new Response('ok', 200, ['x-request-id' => 'route']));

        $response = $app->handle(new Request('GET', '/', ['x-request-id' => 'incoming']));

        self::assertSame(['X-Request-Id' => 'incoming'], $response->headers());
    }

    public function testResponseRejectsHeaderInjectionCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('ok', 200, ['X-Test' => "bad\r\nvalue"]);
    }
}
