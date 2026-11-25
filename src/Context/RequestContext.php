<?php

declare(strict_types=1);

namespace Magic\Context;

use Magic\Http\Request;
use RuntimeException;
use Throwable;

final class RequestContext
{
    /** @var array<string, mixed> */
    private array $values;

    /** @var list<callable(): void> */
    private array $cleanupCallbacks = [];

    private bool $closed = false;

    public function __construct(Request $request)
    {
        $this->values = [
            Request::class => $request,
        ];
    }

    public function request(): Request
    {
        return $this->values[Request::class];
    }

    public function replaceRequest(Request $request): void
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot replace the request after the context is closed.');
        }

        $this->values[Request::class] = $request;
    }

    public function set(string $id, mixed $value): void
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot write to a request context after it is closed.');
        }

        $this->values[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->values);
    }

    public function get(string $id, mixed $default = null): mixed
    {
        return array_key_exists($id, $this->values)
            ? $this->values[$id]
            : $default;
    }

    public function defer(callable $cleanup): void
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot register cleanup after the request context is closed.');
        }

        $this->cleanupCallbacks[] = $cleanup;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $firstException = null;

        while ($cleanup = array_pop($this->cleanupCallbacks)) {
            try {
                $cleanup();
            } catch (Throwable $exception) {
                $firstException ??= $exception;
            }
        }

        $this->values = [];

        if ($firstException !== null) {
            throw $firstException;
        }
    }
}
