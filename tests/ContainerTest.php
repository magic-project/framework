<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Config\Config;
use Magic\Container\Container;
use Magic\Container\ServiceProvider;
use Magic\Context\ContextStorageInterface;
use Magic\Http\Request;
use Magic\Exception\ExceptionHandlerInterface;
use Magic\Tests\Fixtures\Container\AutowireService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContainerTest extends TestCase
{
    public function testContainerAutowiresConcreteClassesAsSingletons(): void
    {
        $container = new Container();

        $first = $container->get(AutowireService::class);
        $second = $container->get(AutowireService::class);

        self::assertSame($first, $second);
        self::assertSame('autowired', $first->value());
    }

    public function testApplicationRegistersCoreServicesThroughProvider(): void
    {
        $app = new Application();

        self::assertSame($app->config(), $app->container()->get(Config::class));
        self::assertSame($app->context()->storage(), $app->container()->get(ContextStorageInterface::class));
        self::assertSame($app->logger(), $app->container()->get(LoggerInterface::class));
        self::assertInstanceOf(ExceptionHandlerInterface::class, $app->container()->get(ExceptionHandlerInterface::class));
    }

    public function testRegisteredDefinitionsCanBeWarmedUp(): void
    {
        $container = new Container();
        $container->register(AutowireService::class);
        $container->warmup();

        self::assertSame('autowired', $container->get(AutowireService::class)->value());
    }

    public function testApplicationRegistersAndBootsServiceProvidersInOrder(): void
    {
        $state = new \stdClass();
        $state->registered = false;
        $state->booted = false;

        $provider = new class($state) extends ServiceProvider {
            public function __construct(private readonly object $state)
            {
            }

            public function register(Container $container): void
            {
                $this->state->registered = true;
                $container->set('test.provider.service', 'ready');
            }

            public function boot(Container $container): void
            {
                $this->state->booted = true;
                $this->state->service = $container->get('test.provider.service');
            }
        };

        $app = new Application();
        $app->providers()->register($provider);

        self::assertTrue($state->registered);
        self::assertFalse($state->booted);

        $app->boot();
        self::assertTrue($state->booted);
        self::assertSame('ready', $state->service);
        self::assertTrue($app->container()->isFrozen());

        $app->boot();
        self::assertTrue($state->booted);

        $this->expectException(\RuntimeException::class);
        $app->container()->set('after.boot', true);
    }

    public function testProviderCanBeResolvedFromContainerByClassName(): void
    {
        $app = new Application();
        $app->providers()->register(TestProvider::class);

        self::assertSame('test-provider', $app->container()->get(TestProvider::SERVICE_ID));
    }

    public function testNamedLifetimesAreAvailable(): void
    {
        $app = new Application();
        $container = $app->container();
        $container->requestScoped('request.value', static fn (): object => new \stdClass());
        $container->prototype('prototype.value', static fn (): object => new \stdClass());

        $app->get('/lifetimes', static function () use ($container): array {
            $scopedA = $container->get('request.value');
            $scopedB = $container->get('request.value');
            $prototypeA = $container->get('prototype.value');
            $prototypeB = $container->get('prototype.value');

            return [
                'scoped_same' => $scopedA === $scopedB,
                'prototype_same' => $prototypeA === $prototypeB,
            ];
        });

        $response = $app->handle(new Request('GET', '/lifetimes'));

        self::assertSame([
            'scoped_same' => true,
            'prototype_same' => false,
        ], json_decode($response->body(), true));
    }
}

final class TestProvider extends ServiceProvider
{
    public const SERVICE_ID = 'test.provider.class';

    public function register(Container $container): void
    {
        $container->set(self::SERVICE_ID, 'test-provider');
    }
}
