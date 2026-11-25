<?php

declare(strict_types=1);

namespace Magic\Tests;

use InvalidArgumentException;
use Magic\Container\Container;
use Magic\Http\Request;
use Magic\Routing\Router;
use Magic\Tests\Fixtures\Routing\TestAction;
use Magic\Tests\Fixtures\Routing\TestController;
use Magic\Tests\Fixtures\Routing\StatefulController;
use Magic\Tests\Fixtures\Routing\DependencyController;
use Magic\Tests\Fixtures\Routing\UnsafeController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testControllerMethodRouteIsResolvedThroughContainer(): void
    {
        $router = new Router(new Container());
        $router->get('/users/{id}', [TestController::class, 'show']);

        $response = $router->dispatch(new Request('GET', '/users/42'));

        self::assertSame(200, $response->status());
        self::assertSame([
            'method' => 'GET',
            'id' => '42',
        ], json_decode($response->body(), true));
    }

    public function testInvokableActionRouteIsResolvedThroughContainer(): void
    {
        $router = new Router(new Container());
        $router->get('/actions/{name}', TestAction::class);

        $response = $router->dispatch(new Request('GET', '/actions/demo'));

        self::assertSame(200, $response->status());
        self::assertSame([
            'path' => '/actions/demo',
            'params' => ['name' => 'demo'],
        ], json_decode($response->body(), true));
    }

    public function testControllerMethodDependenciesAreResolvedPerRequest(): void
    {
        $app = new \Magic\Application();
        $app->get('/dependencies/{id}', [DependencyController::class, 'show']);

        $response = $app->handle(new Request('GET', '/dependencies/42'));

        self::assertSame([
            'path' => '/dependencies/42',
            'id' => '42',
            'same' => true,
        ], json_decode($response->body(), true));
    }

    public function testControllerCannotInjectRequestInConstructor(): void
    {
        $app = new \Magic\Application();
        $app->get('/unsafe', [UnsafeController::class, 'show']);

        $this->expectException(\Magic\Container\ContainerException::class);
        $app->handle(new Request('GET', '/unsafe'));
    }

    public function testControllerInstanceIsFreshForEachRequest(): void
    {
        $app = new \Magic\Application();
        $app->get('/stateful', [StatefulController::class, 'show']);

        $first = $app->handle(new Request(
            'GET',
            '/stateful',
            ['x-test' => '1'],
            ['value' => 'first'],
            '',
            '/stateful?value=first',
        ));
        $second = $app->handle(new Request(
            'GET',
            '/stateful',
            ['x-test' => '2'],
            ['value' => 'second'],
            '',
            '/stateful?value=second',
        ));

        self::assertSame(['previous' => null, 'current' => 'first'], json_decode($first->body(), true));
        self::assertSame(['previous' => null, 'current' => 'second'], json_decode($second->body(), true));
    }

    public function testExplicitControllerBindingCanOptIntoSharedLifecycle(): void
    {
        $app = new \Magic\Application();
        $controller = new StatefulController();
        $app->container()->set(StatefulController::class, $controller);
        $app->get('/shared', [StatefulController::class, 'show']);

        $first = $app->handle(new Request('GET', '/shared', [], ['value' => 'first']));
        $second = $app->handle(new Request('GET', '/shared', [], ['value' => 'second']));

        self::assertSame(['previous' => null, 'current' => 'first'], json_decode($first->body(), true));
        self::assertSame(['previous' => 'first', 'current' => 'second'], json_decode($second->body(), true));
    }

    public function testStaticControllerMethodIsRejectedAtRegistration(): void
    {
        $router = new Router(new Container());

        $this->expectException(InvalidArgumentException::class);
        $router->get('/invalid', [self::class, 'staticAction']);
    }

    public static function staticAction(): string
    {
        return 'invalid';
    }

    public function testStaticRoutesTakePriorityOverParameterRoutes(): void
    {
        $router = new Router();
        $router->get('/{id}', static fn (): array => ['route' => 'parameter']);
        $router->get('/health', static fn (): array => ['route' => 'static']);

        $response = $router->dispatch(new Request('GET', '/health'));

        self::assertSame(['route' => 'static'], json_decode($response->body(), true));
    }

    public function testMostSpecificPathClaimsMethodNegotiation(): void
    {
        $router = new Router();
        $router->post('/health', static fn (): array => ['route' => 'static']);
        $router->get('/{id}', static fn (): array => ['route' => 'parameter']);

        $response = $router->dispatch(new Request('GET', '/health'));

        self::assertSame(405, $response->status());
        self::assertSame('POST, OPTIONS', $response->header('Allow'));
    }

    public function testTrailingSlashIsNormalizedForRoutesAndRequests(): void
    {
        $router = new Router();
        $router->get('/health/', static fn (): string => 'ok');

        self::assertSame('ok', $router->dispatch(new Request('GET', '/health'))->body());
        self::assertSame('ok', $router->dispatch(new Request('GET', '/health/'))->body());
    }

    public function testEncodedRouteParametersAreDecoded(): void
    {
        $router = new Router();
        $router->get('/users/{name}', static fn (Request $request, array $params): array => $params);

        $response = $router->dispatch(new Request('GET', '/users/hello%20world'));

        self::assertSame(['name' => 'hello world'], json_decode($response->body(), true));
    }

    public function testEncodedStaticPathSegmentsAreMatched(): void
    {
        $router = new Router();
        $router->get('/中文', static fn (): string => 'ok');

        $response = $router->dispatch(new Request('GET', '/%E4%B8%AD%E6%96%87'));

        self::assertSame('ok', $response->body());
    }

    public function testEncodedSlashStaysInsideAParameterSegment(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn (Request $request, array $params): array => $params);

        $response = $router->dispatch(new Request('GET', '/users/foo%2Fbar'));

        self::assertSame(200, $response->status());
        self::assertSame(['id' => 'foo/bar'], json_decode($response->body(), true));
    }

    public function testAdjacentParametersAreRejected(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $router->get('/a/{left}{right}', static fn (): string => 'never');
    }

    #[DataProvider('invalidPatternProvider')]
    public function testInvalidParameterSyntaxIsRejected(string $pattern): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $router->get($pattern, static fn (): string => 'never');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPatternProvider(): iterable
    {
        yield 'optional parameter syntax' => ['/a/{id?}'];
        yield 'constraint syntax' => ['/a/{id:[0-9]+}'];
        yield 'invalid parameter name' => ['/a/{9id}'];
        yield 'unclosed parameter' => ['/a/{id'];
        yield 'unopened parameter' => ['/a/id}'];
        yield 'reserved optional segment syntax' => ['/a/[id]'];
    }

    public function testRoutesWithSameShapeAndMethodAreRejected(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn (): string => 'first');

        $this->expectException(InvalidArgumentException::class);
        $router->get('/users/{name}', static fn (): string => 'second');
    }

    public function testMethodsAreTrimmedAndEmptyMethodsAreRejected(): void
    {
        $router = new Router();
        $router->add(' get ', '/health', static fn (): string => 'ok');

        self::assertSame('ok', $router->dispatch(new Request('GET', '/health'))->body());

        $this->expectException(InvalidArgumentException::class);
        $router->add([], '/empty', static fn (): string => 'never');
    }

    public function testHeadPrefersTheMostSpecificGetRoute(): void
    {
        $router = new Router();
        $router->get('/{id}', static fn (): array => ['route' => 'parameter']);
        $router->get('/health', static fn (): array => ['route' => 'static']);

        $response = $router->dispatch(new Request('HEAD', '/health'));

        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
    }

    public function testMalformedUriEncodingReturnsBadRequest(): void
    {
        $router = new Router();
        $router->get('/health', static fn (): string => 'ok');

        $response = $router->dispatch(new Request('GET', '/health%ZZ'));

        self::assertSame(400, $response->status());
        self::assertSame('bad_request', json_decode($response->body(), true)['error']['code']);
    }

    public function testInternalPathDispatchMethodIsReserved(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $router->add('__MAGIC_PATH__', '/reserved', static fn (): string => 'never');
    }
}
