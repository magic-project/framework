<?php

declare(strict_types=1);

namespace Magic\Context;

use Magic\Http\Request;
use RuntimeException;

final class ContextManager
{
    public function __construct(private ContextStorageInterface $storage)
    {
    }

    public function setStorage(ContextStorageInterface $storage): void
    {
        if ($this->storage->isActive()) {
            throw new RuntimeException('Cannot change context storage while a request is active.');
        }

        $this->storage = $storage;
    }

    public function storage(): ContextStorageInterface
    {
        return $this->storage;
    }

    public function begin(Request $request): RequestContext
    {
        return $this->storage->begin($request);
    }

    public function current(): RequestContext
    {
        return $this->storage->current();
    }

    public function end(): void
    {
        $this->storage->end();
    }

    public function isActive(): bool
    {
        return $this->storage->isActive();
    }

    public function set(string $id, mixed $value): void
    {
        $this->current()->set($id, $value);
    }

    public function has(string $id): bool
    {
        return $this->current()->has($id);
    }

    public function get(string $id, mixed $default = null): mixed
    {
        return $this->current()->get($id, $default);
    }

    public function defer(callable $cleanup): void
    {
        $this->current()->defer($cleanup);
    }
}
