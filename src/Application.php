<?php

declare(strict_types=1);

namespace Magic;

use Magic\Container\Container;
use Magic\Container\ProviderRegistry;
use Magic\Console\CommandRegistry;
use Magic\Context\ContextManager;
use Magic\Context\ContextStorageInterface;
use Magic\Exception\ExceptionHandlerInterface;
use Magic\Config\Config;
use Magic\Config\MiddlewareLoader;
use Magic\Http\ErrorResponse;
use Psr\Log\LoggerInterface;
use Magic\Http\Middleware\MiddlewareStack;
use Magic\Http\Middleware\MiddlewareRegistry;
use Magic\Http\Request;
use Magic\Http\RequestId;
use Magic\Http\RequestLimitException;
use Magic\Http\Response;
use Magic\Lifecycle\RequestLifecycle;
use Magic\Lifecycle\ApplicationLifecycle;
use Magic\Routing\Router;
use Magic\Runtime\RuntimeInterface;
use Magic\Runtime\RuntimeMetrics;
use Magic\Provider\CoreServiceProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;

final class Application
{
    private readonly Container $container;
    private readonly ContextManager $context;
    private readonly ExceptionHandlerInterface $exceptionHandler;
    private readonly RequestLifecycle $lifecycle;
    private readonly ApplicationLifecycle $applicationLifecycle;
    private readonly Router $router;
    private readonly Config $config;
    private readonly ProviderRegistry $providers;
    private readonly RuntimeMetrics $metrics;
    private readonly MiddlewareRegistry $middlewareRegistry;

    private bool $stopping = false;
    private bool $booted = false;
    private bool $shutdown = false;
    private bool $contextStorageConfigured = false;
    private bool $requestHandlingStarted = false;

    /** @var list<PsrMiddlewareInterface> */
    private array $middleware = [];

    public function __construct(
        ?ContextStorageInterface $contextStorage = null,
        ?ExceptionHandlerInterface $exceptionHandler = null,
        ?Config $config = null,
        ?LoggerInterface $logger = null,
        ?string $autoloadPath = null,
        ?string $middlewarePath = null,
    )
    {
        $this->container = new Container();
        $this->container->set(self::class, $this);
        $this->container->set(Container::class, $this->container);
        $this->providers = new ProviderRegistry($this->container);
        $this->container->set(ProviderRegistry::class, $this->providers);

        $this->providers->register(new CoreServiceProvider(
            $contextStorage,
            $exceptionHandler,
            $config,
            $logger,
        ));

        $autoloadPathWasExplicit = $autoloadPath !== null;
        $autoloadPath ??= dirname(__DIR__) . '/config/autoload.php';
        if ($autoloadPathWasExplicit && !is_file($autoloadPath)) {
            throw new \RuntimeException(sprintf('Configuration autoload file [%s] does not exist.', $autoloadPath));
        }

        $autoload = is_file($autoloadPath) ? require $autoloadPath : [];
        if (!is_array($autoload)) {
            throw new \RuntimeException('Configuration autoload file must return an array.');
        }

        $providers = [];
        if (array_key_exists('providers', $autoload) || array_key_exists('bindings', $autoload)) {
            $providers = $autoload['providers'] ?? [];
            $bindings = $autoload['bindings'] ?? [];
            if (!is_array($providers) || !is_array($bindings)) {
                throw new \RuntimeException('Configuration autoload providers and bindings must be arrays.');
            }
        } else {
            $bindings = $autoload;
        }

        foreach ($bindings as $id => $definition) {
            if (!is_string($id) || $id === '') {
                throw new \InvalidArgumentException('Configuration autoload service IDs must be non-empty strings.');
            }

            $this->container->register($id, $definition);
        }

        if (!array_is_list($providers)) {
            throw new \RuntimeException('Configuration autoload providers must be a list.');
        }

        $this->providers->registerMany($providers);

        $this->context = $this->container->get(ContextManager::class);
        $this->container->setScopeResolver(function (): ?object {
            return $this->context->isActive() ? $this->context->current() : null;
        });
        $this->exceptionHandler = $this->container->get(ExceptionHandlerInterface::class);
        $this->lifecycle = $this->container->get(RequestLifecycle::class);
        $this->applicationLifecycle = $this->container->get(ApplicationLifecycle::class);
        $this->router = $this->container->get(Router::class);
        $this->config = $this->container->get(Config::class);
        $this->metrics = $this->container->get(RuntimeMetrics::class);
        $this->middlewareRegistry = $this->container->get(MiddlewareRegistry::class);
        $this->contextStorageConfigured = $contextStorage !== null;

        $middlewarePathWasExplicit = $middlewarePath !== null;
        $middlewarePath ??= dirname($autoloadPath) . '/middleware.php';
        if ($middlewarePathWasExplicit && !is_file($middlewarePath)) {
            throw new \RuntimeException(sprintf('Middleware configuration file [%s] does not exist.', $middlewarePath));
        }

        if (is_file($middlewarePath)) {
            $loader = new MiddlewareLoader($this->middlewareRegistry, $this->container);
            foreach ($loader->load($this, $middlewarePath) as $middleware) {
                $this->middleware($middleware);
            }
        }
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function context(): ContextManager
    {
        return $this->context;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function providers(): ProviderRegistry
    {
        return $this->providers;
    }

    public function metrics(): RuntimeMetrics
    {
        return $this->metrics;
    }

    public function isContextStorageConfigured(): bool
    {
        return $this->contextStorageConfigured;
    }

    public function logger(): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class);
    }

    public function onRequestStart(callable $hook): self
    {
        $this->lifecycle->onStart($hook);

        return $this;
    }

    public function onRequestEnd(callable $hook): self
    {
        $this->lifecycle->onEnd($hook);

        return $this;
    }

    public function onBoot(callable $hook): self
    {
        $this->applicationLifecycle->onBoot($hook);

        return $this;
    }

    public function onShutdown(callable $hook): self
    {
        $this->applicationLifecycle->onShutdown($hook);

        return $this;
    }

    public function onWorkerStart(callable $hook): self
    {
        $this->applicationLifecycle->onWorkerStart($hook);

        return $this;
    }

    public function onWorkerStop(callable $hook): self
    {
        $this->applicationLifecycle->onWorkerStop($hook);

        return $this;
    }

    public function middleware(PsrMiddlewareInterface $middleware): self
    {
        $this->assertConfigurable();
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function get(string $pattern, callable|array|string $handler, array $middleware = []): self
    {
        $this->router->get($pattern, $handler, $middleware);

        return $this;
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function post(string $pattern, callable|array|string $handler, array $middleware = []): self
    {
        $this->router->post($pattern, $handler, $middleware);

        return $this;
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function put(string $pattern, callable|array|string $handler, array $middleware = []): self
    {
        $this->router->put($pattern, $handler, $middleware);

        return $this;
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function patch(string $pattern, callable|array|string $handler, array $middleware = []): self
    {
        $this->router->patch($pattern, $handler, $middleware);

        return $this;
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function delete(string $pattern, callable|array|string $handler, array $middleware = []): self
    {
        $this->router->delete($pattern, $handler, $middleware);

        return $this;
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function options(string $pattern, callable|array|string $handler, array $middleware = []): self
    {
        $this->router->options($pattern, $handler, $middleware);

        return $this;
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function head(string $pattern, callable|array|string $handler, array $middleware = []): self
    {
        $this->router->head($pattern, $handler, $middleware);

        return $this;
    }

    /**
     * @param list<string> $methods
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function map(array $methods, string $pattern, callable|array|string $handler, array $middleware = []): self
    {
        $this->router->map($methods, $pattern, $handler, $middleware);

        return $this;
    }

    public function run(RuntimeInterface $runtime, string $host = '127.0.0.1', int $port = 9501): void
    {
        if ($this->shutdown) {
            throw new \RuntimeException('Application cannot be run after shutdown.');
        }
        $this->stopping = false;
        $this->boot();
        $failure = null;

        try {
            $runtime->run($this, $host, $port);
        } catch (\Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                $this->shutdown();
            } catch (\Throwable $exception) {
                $this->logger()->error('Application shutdown failed.', ['exception' => $exception]);
                if ($failure === null) {
                    throw $exception;
                }
            }
        }
    }

    public function boot(): void
    {
        if ($this->shutdown) {
            throw new \RuntimeException('Application cannot boot after shutdown.');
        }
        if ($this->booted) {
            return;
        }
        $this->providers->boot();
        $this->applicationLifecycle->boot();
        $this->middlewareRegistry->freeze();
        $this->router->freeze();
        $this->lifecycle->freeze();
        $this->applicationLifecycle->freeze();
        $this->container->get(CommandRegistry::class)->freeze();
        $this->container->freeze();
        $this->booted = true;
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }

        $this->stopping = true;
        try {
            $this->applicationLifecycle->shutdown();
        } finally {
            $this->shutdown = true;
        }
    }

    public function markStopping(): void
    {
        $this->stopping = true;
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    public function workerStarted(int $workerId): void
    {
        $this->metrics->workerStarted($workerId);
        $this->applicationLifecycle->workerStart($workerId);
    }

    public function workerStopped(int $workerId): void
    {
        try {
            $this->applicationLifecycle->workerStop($workerId);
        } finally {
            $this->metrics->workerStopped($workerId);
        }
    }

    public function isBooted(): bool
    {
        return $this->booted && !$this->shutdown;
    }

    public function useContextStorage(ContextStorageInterface $storage): void
    {
        if ($this->shutdown) {
            throw new \RuntimeException('Application context storage cannot change after shutdown.');
        }
        if ($this->contextStorageConfigured || $this->requestHandlingStarted) {
            throw new \RuntimeException('Application context storage cannot change after request handling starts.');
        }

        $this->context->setStorage($storage);
        $this->contextStorageConfigured = true;
    }

    private function assertConfigurable(): void
    {
        if ($this->booted) {
            throw new \RuntimeException('Application configuration is frozen after boot.');
        }
    }

    public function handle(Request $request): Response
    {
        return $this->handleInternal($request, false);
    }

    public function handleSafely(Request $request): Response
    {
        return $this->handleInternal($request, true);
    }

    private function handleInternal(Request $request, bool $safe): Response
    {
        $this->boot();
        $requestContext = $this->context->begin($request);
        $this->requestHandlingStarted = true;
        $startedAt = hrtime(true);
        $this->metrics->requestStarted();
        $requestId = RequestId::resolve($request->header('x-request-id'));
        $requestContext->set('request_id', $requestId);
        $admitted = !$this->stopping;
        $failure = null;
        $response = null;

        try {
            $this->lifecycle->start($requestContext);
            if (!$admitted) {
                $response = ErrorResponse::json(
                    'service_unavailable',
                    'Service Unavailable',
                    503,
                    ['Retry-After' => '1'],
                );
            } else {
                $stack = new MiddlewareStack(
                    $this->middleware,
                    fn (ServerRequestInterface $request): Response => $this->router->dispatch(Request::fromPsr($request)),
                );

                $response = $this->toResponse($stack->handle($request));
            }
        } catch (RequestLimitException $exception) {
            // A rejected request (malformed JSON, oversized payload) is a client
            // error, not a server failure: respond 4xx without escalating.
            $response = ErrorResponse::json($exception->errorCode(), $exception->getMessage(), $exception->statusCode());
        } catch (\Throwable $exception) {
            $failure = $exception;

            if ($safe) {
                $response = $this->exceptionHandler->render($exception, $request);
            }
        } finally {
            try {
                $this->lifecycle->end($requestContext);
            } catch (\Throwable $exception) {
                if ($failure === null) {
                    $failure = $exception;
                    if ($safe) {
                        $response = $this->exceptionHandler->render($exception, $request);
                    }
                } else {
                    $this->logger()->error('Request end hook failed.', ['exception' => $exception]);
                }
            } finally {
                try {
                    $this->context->end();
                } catch (\Throwable $exception) {
                    if ($failure === null) {
                        $failure = $exception;
                        if ($safe) {
                            $response = $this->exceptionHandler->render($exception, $request);
                        }
                    } else {
                        $this->logger()->error('Request context cleanup failed.', ['exception' => $exception]);
                    }
                }

                $this->metrics->requestFinished(hrtime(true) - $startedAt, $failure !== null);
            }
        }

        if ($failure !== null && !$safe) {
            throw $failure;
        }

        $response ??= ErrorResponse::json('internal_error', 'Internal Server Error', 500);
        if (is_string($requestId) && $requestId !== '') {
            $response = $response->withHeader('X-Request-Id', $requestId);
        }

        return $response;
    }

    private function toResponse(\Psr\Http\Message\ResponseInterface $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = $values;
        }

        return new Response(
            (string) $response->getBody(),
            $response->getStatusCode(),
            $headers,
            $response->getReasonPhrase(),
        );
    }
}
