<?php

declare(strict_types=1);

namespace Magic\Runtime\Swoole;

use Magic\Application;
use Magic\Context\SwooleContextStorage;
use Magic\Http\ErrorResponse;
use Magic\Http\RequestId;
use Magic\Http\RequestLimitException;
use Magic\Http\Response;
use Magic\Runtime\RuntimeInterface;
use RuntimeException;
use Swoole\Timer;
use Throwable;

final class SwooleRuntime implements RuntimeInterface
{
    private ?object $server = null;
    private ?Application $application = null;
    private bool $running = false;
    private int $masterPid = 0;
    private bool $signalStopping = false;
    private bool $signalsRegistered = false;

    /** @var array<string, mixed> */
    private readonly array $settings;

    /** @var \Closure(string, int): object */
    private readonly \Closure $serverFactory;

    private readonly bool $customServerFactory;
    private readonly bool $useSwooleSignals;

    /** @param array<string, mixed> $settings */
    public function __construct(
        array $settings = [],
        ?callable $serverFactory = null,
        bool $useSwooleSignals = true,
    )
    {
        $this->settings = $settings;
        $this->customServerFactory = $serverFactory !== null;
        $this->useSwooleSignals = $useSwooleSignals;
        $this->serverFactory = $serverFactory === null
            ? static fn (string $host, int $port): object => new \Swoole\Http\Server($host, $port)
            : $serverFactory(...);
    }

    public function name(): string
    {
        return 'swoole';
    }

    public function run(Application $application, string $host, int $port): void
    {
        $this->assertAvailable();
        $this->signalStopping = false;
        $this->signalsRegistered = false;
        $application->boot();
        $enableCoroutine = $this->settings['enable_coroutine'] ?? true;
        if ($enableCoroutine !== true) {
            throw new RuntimeException(
                'SwooleRuntime requires enable_coroutine=true to preserve coroutine-isolated request context.',
            );
        }
        if (!$application->isContextStorageConfigured()) {
            $application->useContextStorage(new SwooleContextStorage());
        }
        $this->application = $application;
        $this->masterPid = getmypid();

        $server = ($this->serverFactory)($host, $port);
        $this->server = $server;
        $server->set(array_merge([
            'worker_num' => 1,
            'enable_coroutine' => true,
            'reload_async' => true,
            'max_request' => $this->runtimeInt($application, 'max_request', 10_000),
            'max_coroutine' => $this->runtimeInt($application, 'max_coroutine', 10_000),
            'package_max_length' => $this->packageMaxLength($application),
            'max_wait_time' => $this->shutdownTimeout($application),
        ], $this->settings));

        $server->on('request', function (object $request, object $response) use ($application): void {
            $this->handle($application, $request, $response);
        });
        $server->on('workerStart', function (object $server, int $workerId) use ($application): void {
            $application->workerStarted($workerId);
        });
        $server->on('workerStop', function (object $server, int $workerId) use ($application): void {
            $application->workerStopped($workerId);
        });
        $server->on('workerError', function (object $server, int $workerId, int $workerPid, int $exitCode, int $signal): void {
            if ($signal === SIGINT || $signal === SIGTERM) {
                $this->stop();
            }
        });
        $server->on('managerStop', function (): void {
            $this->application?->markStopping();
        });
        $server->on('shutdown', function () use ($application): void {
            $application->markStopping();
        });
        $server->on('managerStart', function (): void {
            if (!$this->customServerFactory) {
                $this->registerSignals($this->useSwooleSignals);
                $swooleSignalsAvailable = class_exists(\Swoole\Process::class)
                    && method_exists(\Swoole\Process::class, 'signal');
                if (!$this->useSwooleSignals || !$swooleSignalsAvailable) {
                    $this->armSignalDispatch();
                }
            }
        });

        // Register in the parent before Swoole takes over the process group.
        // This is what makes Ctrl+C reach the running server instead of only
        // terminating the current worker.
        if (!$this->customServerFactory) {
            $swooleSignalsAvailable = class_exists(\Swoole\Process::class)
                && method_exists(\Swoole\Process::class, 'signal');
            if (!$swooleSignalsAvailable) {
                // Register only PCNTL handlers before start(). Calling
                // Swoole\Process::signal() here creates an event loop in
                // recent Swoole versions and prevents Http\Server::start()
                // from owning the loop. The manager callback registers
                // Swoole signals after the server has started.
                $this->registerSignals(false);
            }
        }

        $this->running = true;
        try {
            $started = $server->start();
        } finally {
            $this->running = false;
            $this->server = null;
            $this->application = null;
        }
        if ($started !== true && !$application->isStopping()) {
            throw new RuntimeException('Unable to start the Swoole HTTP server.');
        }
    }

    public function stop(): void
    {
        $this->application?->markStopping();
        if ($this->server !== null && method_exists($this->server, 'shutdown')) {
            try {
                $this->server->shutdown();
            } catch (Throwable $exception) {
                $this->application?->logger()->error('Swoole server shutdown failed.', [
                    'exception' => $exception,
                ]);
                throw $exception;
            }
        }

    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function handle(Application $application, object $request, object $response): void
    {
        $application->boot();
        $rawRequestId = $this->rawRequestId($request);

        try {
            $result = $application->handleSafely(SwooleRequestFactory::from(
                $request,
                $this->httpInt($application, 'max_body_bytes', 1_048_576),
                $this->httpInt($application, 'max_uri_bytes', 8_192),
                $this->httpInt($application, 'max_query_bytes', 4_096),
                $this->httpInt($application, 'max_query_params', 100),
                $this->httpInt($application, 'max_header_bytes', 65_536),
            ));
            $this->sendResponse($response, $result);
        } catch (RequestLimitException $exception) {
            $this->sendResponse(
                $response,
                ErrorResponse::json($exception->errorCode(), $exception->getMessage(), $exception->statusCode())
                    ->withHeader('X-Request-Id', RequestId::resolve($rawRequestId)),
            );
        } catch (Throwable $exception) {
            $application->logger()->error('Swoole request handling failed.', [
                'request_id' => RequestId::resolve($rawRequestId),
                'exception' => $exception,
            ]);
            $this->sendResponse(
                $response,
                ErrorResponse::json('internal_error', 'Internal Server Error', 500)
                    ->withHeader('X-Request-Id', RequestId::resolve($rawRequestId)),
            );
        }
    }

    public function isAvailable(): bool
    {
        return extension_loaded('swoole') && class_exists(\Swoole\Http\Server::class);
    }

    private function assertAvailable(): void
    {
        if (!$this->customServerFactory && !$this->isAvailable()) {
            throw new RuntimeException('The Swoole extension is required for the Swoole runtime.');
        }
    }

    private function shutdownTimeout(Application $application): int
    {
        $timeout = $application->config()->get('runtime.shutdown_timeout', 10);

        return is_int($timeout) && $timeout > 0 ? $timeout : 10;
    }

    private function runtimeInt(Application $application, string $key, int $default): int
    {
        $value = $application->config()->get('runtime.' . $key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function httpInt(Application $application, string $key, int $default): int
    {
        $value = $application->config()->get('http.' . $key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function packageMaxLength(Application $application): int
    {
        return $this->httpInt($application, 'max_body_bytes', 1_048_576)
            + $this->httpInt($application, 'max_header_bytes', 65_536)
            + $this->httpInt($application, 'max_uri_bytes', 8_192)
            + 4_096;
    }

    private function registerSignals(bool $allowSwoole = true): void
    {
        if ($this->signalsRegistered) {
            return;
        }

        $swooleSignalsAvailable = class_exists(\Swoole\Process::class) && method_exists(\Swoole\Process::class, 'signal');

        // Keep a PCNTL handler in every process where it is available. The
        // master process must have a fallback handler before Swoole forks its
        // manager and workers; otherwise SIGTERM can be consumed by a child
        // without ever reaching the process that owns the server instance.
        if ((!$allowSwoole || !$swooleSignalsAvailable)
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->handleSignal(SIGTERM);
            });
            pcntl_signal(SIGINT, function (): void {
                $this->handleSignal(SIGINT);
            });
        }

        // Swoole's HTTP server owns SIGINT/SIGTERM in the manager process.
        // Do not register them through Swoole\Process::signal(): doing so
        // conflicts with the server's handlers and can make workers report an
        // abnormal signal exit during Ctrl-C. The managerStop callback marks
        // the application as stopping after Swoole begins its own shutdown.

        $this->signalsRegistered = true;
    }

    private function rawRequestId(object $request): ?string
    {
        $headers = $request->header ?? null;
        if (!is_array($headers)) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'x-request-id' && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private function armSignalDispatch(): void
    {
        if (!function_exists('pcntl_signal_dispatch') || !class_exists(Timer::class)) {
            return;
        }

        Timer::tick(100, static function (): void {
            pcntl_signal_dispatch();
        });
    }

    private function handleSignal(int $signal): void
    {
        if ($this->signalStopping) {
            return;
        }
        $this->signalStopping = true;

        if ($this->masterPid > 0 && getmypid() !== $this->masterPid && function_exists('posix_kill')) {
            @posix_kill($this->masterPid, SIGTERM);

            return;
        }

        if ($this->running && class_exists(Timer::class)) {
            Timer::after(1, function (): void {
                $this->stop();
            });

            return;
        }

        $this->stop();
    }

    private function sendResponse(object $response, Response $result): void
    {
        /** @var \Swoole\Http\Response $response */
        $response->status($result->status());
        foreach ($result->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $response->header($name, $value);
            }
        }

        $response->end($result->body());
    }
}
