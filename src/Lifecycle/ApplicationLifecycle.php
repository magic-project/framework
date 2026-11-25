<?php

declare(strict_types=1);

namespace Magic\Lifecycle;

use Throwable;

final class ApplicationLifecycle
{
    /** @var list<callable(): void> */
    private array $bootHooks = [];

    /** @var list<callable(): void> */
    private array $shutdownHooks = [];

    /** @var list<callable(int): void> */
    private array $workerStartHooks = [];

    /** @var list<callable(int): void> */
    private array $workerStopHooks = [];

    private bool $booted = false;
    private bool $stopped = false;

    private bool $frozen = false;

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function onBoot(callable $hook): void
    {
        $this->assertMutable();
        $this->bootHooks[] = $hook;
    }

    public function onShutdown(callable $hook): void
    {
        $this->assertMutable();
        $this->shutdownHooks[] = $hook;
    }

    public function onWorkerStart(callable $hook): void
    {
        $this->assertMutable();
        $this->workerStartHooks[] = $hook;
    }

    public function onWorkerStop(callable $hook): void
    {
        $this->assertMutable();
        $this->workerStopHooks[] = $hook;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->bootHooks as $hook) {
            $hook();
        }

        $this->booted = true;
        $this->stopped = false;
    }

    public function shutdown(): void
    {
        if (!$this->booted || $this->stopped) {
            return;
        }

        $firstException = null;
        foreach (array_reverse($this->shutdownHooks) as $hook) {
            try {
                $hook();
            } catch (Throwable $exception) {
                $firstException ??= $exception;
            }
        }

        $this->stopped = true;
        if ($firstException !== null) {
            throw $firstException;
        }
    }

    public function workerStart(int $workerId): void
    {
        $firstException = null;
        foreach ($this->workerStartHooks as $hook) {
            try {
                $hook($workerId);
            } catch (Throwable $exception) {
                $firstException ??= $exception;
            }
        }

        if ($firstException !== null) {
            throw $firstException;
        }
    }

    public function workerStop(int $workerId): void
    {
        $firstException = null;
        foreach (array_reverse($this->workerStopHooks) as $hook) {
            try {
                $hook($workerId);
            } catch (Throwable $exception) {
                $firstException ??= $exception;
            }
        }

        if ($firstException !== null) {
            throw $firstException;
        }
    }

    public function isBooted(): bool
    {
        return $this->booted && !$this->stopped;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \RuntimeException('Cannot register application hooks after application boot.');
        }
    }
}
