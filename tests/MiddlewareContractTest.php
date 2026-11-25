<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use App\Middleware\RequestIdMiddleware;
use App\Middleware\RequestSizeMiddleware;
use Magic\Http\Request;
use Magic\Http\Response;
use Magic\Tests\Fixtures\Http\MarkerMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\TestCase;

final class MiddlewareContractTest extends TestCase
{
    public function testConfiguredGlobalMiddlewareIsLoadedFromMiddlewareFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'magic-middleware-');
        self::assertNotFalse($path);
        file_put_contents($path, '<?php return [Magic\\Tests\\Fixtures\\Http\\MarkerMiddleware::class];');
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        $app = new Application(middlewarePath: $path);
        $app->get('/', static fn (): array => ['ok' => true]);

        $response = $app->handle(new Request('GET', '/'));

        self::assertSame('applied', $response->header('X-Middleware-Marker'));
    }

    public function testRouteMiddlewareOnlyRunsForConfiguredRoute(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'magic-middleware-');
        self::assertNotFalse($path);
        file_put_contents($path, <<<'PHP'
<?php
return [
    'groups' => [
        'configured' => [Magic\Tests\Fixtures\Http\MarkerMiddleware::class],
    ],
];
PHP);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        $app = new Application(middlewarePath: $path);
        $app->get('/configured', static fn (): array => ['ok' => true], ['configured']);
        $app->get('/other', static fn (): array => ['ok' => true]);

        $configured = $app->handle(new Request('GET', '/configured'));
        $other = $app->handle(new Request('GET', '/other'));

        self::assertSame('applied', $configured->header('X-Middleware-Marker'));
        self::assertNull($other->header('X-Middleware-Marker'));
    }

    public function testInvalidIncomingRequestIdIsReplacedAndValidIdIsPreserved(): void
    {
        $app = new Application();
        $app->middleware(new RequestIdMiddleware($app->context()));
        $app->get('/', static fn (): array => ['ok' => true]);

        $invalid = $app->handle(new Request('GET', '/', ['x-request-id' => "bad\r\nvalue"]));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', (string) $invalid->header('X-Request-Id'));
        self::assertNotSame("bad\r\nvalue", $invalid->header('X-Request-Id'));

        $valid = $app->handle(new Request('GET', '/', ['x-request-id' => 'trace-123']));
        self::assertSame('trace-123', $valid->header('X-Request-Id'));
    }

    public function testRequestLimitsShortCircuitBeforeTheHandler(): void
    {
        $app = new Application();
        $app->middleware(new RequestIdMiddleware($app->context()));
        $app->middleware(new RequestSizeMiddleware(4, 10));
        $app->post('/', static fn (): array => ['called' => true]);

        $tooLarge = $app->handle(new Request('POST', '/', [], [], '12345'));
        self::assertSame(413, $tooLarge->status());
        self::assertSame('payload_too_large', json_decode($tooLarge->body(), true)['error']['code']);
        self::assertNotNull($tooLarge->header('X-Request-Id'));

        $headersTooLarge = $app->handle(new Request('POST', '/', ['x-long' => '123456789'], [], ''));
        self::assertSame(431, $headersTooLarge->status());
        self::assertSame('headers_too_large', json_decode($headersTooLarge->body(), true)['error']['code']);

        $queryApp = new Application();
        $queryApp->middleware(new RequestIdMiddleware($queryApp->context()));
        $queryApp->middleware(new RequestSizeMiddleware(100, 100, 100, 4));
        $queryApp->post('/upload', static fn (): array => ['called' => true]);

        $queryTooLarge = $queryApp->handle(new Request(
            'POST',
            '/upload',
            [],
            ['q' => '12345'],
            '',
            '/upload?q=12345',
            'q=12345',
        ));
        self::assertSame(414, $queryTooLarge->status());
        self::assertSame('query_too_large', json_decode($queryTooLarge->body(), true)['error']['code']);
    }

    public function testApplicationAssignsRequestIdBeforeAShortCircuitingMiddleware(): void
    {
        $app = new Application();
        $app->middleware(new RequestSizeMiddleware(1, 100));
        $app->middleware(new RequestIdMiddleware($app->context()));
        $app->post('/', static fn (): array => ['called' => true]);

        $response = $app->handle(new Request('POST', '/', [], [], '12'));

        self::assertSame(413, $response->status());
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',
            (string) $response->header('X-Request-Id'),
        );
    }

    public function testApplicationAssignsRequestIdWithoutRequestIdMiddleware(): void
    {
        $app = new Application();
        $app->get('/', static fn (): array => ['ok' => true]);

        $response = $app->handle(new Request('GET', '/'));

        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',
            (string) $response->header('X-Request-Id'),
        );
    }

    public function testRequestParameterLimitUsesRawQueryString(): void
    {
        $app = new Application();
        $app->middleware(new RequestSizeMiddleware(100, 100, 100, 4096, 1));
        $app->get('/search', static fn (): array => ['called' => true]);

        $response = $app->handle(new Request(
            'GET',
            '/search',
            [],
            ['q' => '2'],
            '',
            '/search?q=1&q=2',
            'q=1&q=2',
        ));

        self::assertSame(400, $response->status());
        self::assertSame('too_many_query_parameters', json_decode($response->body(), true)['error']['code']);
    }

    public function testRawQueryParameterCountingIgnoresEmptyFieldsAndEncodedAmpersands(): void
    {
        $app = new Application();
        $app->middleware(new RequestSizeMiddleware(100, 100, 100, 4096, 2));
        $app->get('/search', static fn (): array => ['called' => true]);

        $response = $app->handle(new Request(
            'GET',
            '/search',
            [],
            ['q' => '1&x=2', 'page' => '1'],
            '',
            '/search?&&q=1%26x=2&page=1&&',
            '&&q=1%26x=2&page=1&&',
        ));

        self::assertSame(200, $response->status());
    }

    public function testRawQueryParameterCountingIncludesArrayElements(): void
    {
        $app = new Application();
        $app->middleware(new RequestSizeMiddleware(100, 100, 100, 4096, 1));
        $app->get('/search', static fn (): array => ['called' => true]);

        $response = $app->handle(new Request(
            'GET',
            '/search',
            [],
            ['tag' => ['second']],
            '',
            '/search?tag[]=first&tag[]=second',
            'tag[]=first&tag[]=second',
        ));

        self::assertSame(400, $response->status());
    }

    public function testStoppingRejectsBeforeMiddlewareCanShortCircuit(): void
    {
        $app = new Application();
        $app->middleware(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response('middleware response', 200);
            }
        });

        $app->markStopping();
        $response = $app->handle(new Request('GET', '/'));

        self::assertSame(503, $response->status());
        self::assertSame('Service Unavailable', json_decode($response->body(), true)['error']['message']);
        self::assertNotNull($response->header('X-Request-Id'));
    }

    public function testMiddlewareCanShortCircuitAndUnwindInOrder(): void
    {
        $app = new Application();
        $events = [];
        $app->middleware(new class($events) implements MiddlewareInterface {
            public function __construct(private array &$events) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->events[] = 'outer-before';
                $response = $handler->handle($request);
                $this->events[] = 'outer-after';
                return $response;
            }
        });
        $app->middleware(new class($events) implements MiddlewareInterface {
            public function __construct(private array &$events) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->events[] = 'inner';
                return new Response('short-circuit', 202);
            }
        });
        $app->get('/', static function (): never {
            throw new \LogicException('must not run');
        });

        $response = $app->handle(new Request('GET', '/'));
        self::assertSame(202, $response->status());
        self::assertSame('short-circuit', $response->body());
        self::assertSame(['outer-before', 'inner', 'outer-after'], $events);
    }
}
