<?php

declare(strict_types=1);

namespace Magic\Provider;

use Magic\Config\Config;
use Magic\Console\CommandRegistry;
use Magic\Console\CommandRunner;
use Magic\Console\Commands\HelpCommand;
use Magic\Console\Commands\StartCommand;
use Magic\Console\Commands\VersionCommand;
use Magic\Container\Container;
use Magic\Container\ServiceProvider;
use Magic\Context\ContextManager;
use Magic\Context\ContextStorageInterface;
use Magic\Context\SynchronousContextStorage;
use Magic\Exception\ExceptionHandlerInterface;
use Magic\Exception\JsonExceptionHandler;
use Magic\Http\HttpFactory;
use Magic\Http\CurrentRequest;
use Magic\Http\Middleware\MiddlewareRegistry;
use App\Middleware\RequestSizeMiddleware;
use Magic\Lifecycle\ApplicationLifecycle;
use Magic\Lifecycle\RequestLifecycle;
use Magic\Logger\StreamLogger;
use Magic\Routing\Router;
use Magic\Runtime\RuntimeMetrics;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/** Registers the framework's built-in services. */
final class CoreServiceProvider extends ServiceProvider
{
    private readonly ContextStorageInterface $contextStorage;
    private readonly Config $config;
    private readonly LoggerInterface $logger;

    public function __construct(
        ?ContextStorageInterface $contextStorage = null,
        ?ExceptionHandlerInterface $exceptionHandler = null,
        ?Config $config = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->contextStorage = $contextStorage ?? new SynchronousContextStorage();
        $this->config = $config ?? Config::fromEnvironment(Config::environment());
        $this->logger = $logger ?? new StreamLogger();
        $this->exceptionHandler = $exceptionHandler;
    }

    private readonly ?ExceptionHandlerInterface $exceptionHandler;

    public function register(Container $container): void
    {
        $container->set(Config::class, $this->config);
        $container->set(LoggerInterface::class, $this->logger);
        $httpFactory = new HttpFactory();
        $container->set(RequestFactoryInterface::class, $httpFactory);
        $container->set(ServerRequestFactoryInterface::class, $httpFactory);
        $container->set(ResponseFactoryInterface::class, $httpFactory);
        $container->set(StreamFactoryInterface::class, $httpFactory);
        $container->set(UploadedFileFactoryInterface::class, $httpFactory);
        $container->set(UriFactoryInterface::class, $httpFactory);
        // Expose the ContextManager through both its concrete type and the
        // storage contract. This keeps the active storage swappable at runtime
        // (for example when Swoole replaces synchronous storage) and preserves the
        // framework's historical alias semantics.
        $container->factory(
            ContextManager::class,
            fn (): ContextManager => new ContextManager($this->contextStorage),
        );
        $container->factory(
            ContextStorageInterface::class,
            static fn (Container $container): ContextStorageInterface => $container->get(ContextManager::class)->storage(),
        );
        $container->factory(
            CurrentRequest::class,
            static fn (Container $container): CurrentRequest => new CurrentRequest(
                $container->get(ContextManager::class),
            ),
        );
        $container->register(MiddlewareRegistry::class, MiddlewareRegistry::class);
        $container->factory(
            RequestSizeMiddleware::class,
            static fn (Container $container): RequestSizeMiddleware => RequestSizeMiddleware::fromConfig(
                $container->get(Config::class),
            ),
        );
        $container->register(RequestLifecycle::class, RequestLifecycle::class);
        $container->register(ApplicationLifecycle::class, ApplicationLifecycle::class);
        $container->register(RuntimeMetrics::class, RuntimeMetrics::class);
        $container->factory(
            Router::class,
            static fn (Container $container): Router => new Router(
                $container,
                $container->get(ContextManager::class),
                $container->get(MiddlewareRegistry::class),
            ),
        );
        $container->register(CommandRegistry::class, CommandRegistry::class);
        $container->register(CommandRunner::class, CommandRunner::class);
        $commands = $container->get(CommandRegistry::class);
        $commands->register(HelpCommand::class);
        $commands->register(StartCommand::class);
        $commands->register(VersionCommand::class);

        if ($this->exceptionHandler !== null) {
            $container->set(ExceptionHandlerInterface::class, $this->exceptionHandler);
        } else {
            $container->factory(
                ExceptionHandlerInterface::class,
                static fn (Container $container): JsonExceptionHandler => new JsonExceptionHandler(
                    $container->get(LoggerInterface::class),
                    $container->get(ContextManager::class),
                ),
            );
        }
    }

}
