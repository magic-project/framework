<?php

declare(strict_types=1);

namespace Magic\Context;

use Magic\Http\Request;
use RuntimeException;

/**
 * Stores one request context for synchronous application handling.
 *
 * Swoole uses SwooleContextStorage for coroutine isolation in production.
 * This storage keeps direct Application::handle() calls usable for CLI tools
 * and unit tests without coupling the core to a PHP SAPI.
 */
final class SynchronousContextStorage implements ContextStorageInterface
{
    private ?RequestContext $context = null;

    public function begin(Request $request): RequestContext
    {
        if ($this->context !== null) {
            throw new RuntimeException('Nested request contexts are not supported.');
        }

        $this->context = new RequestContext($request);

        return $this->context;
    }

    public function current(): RequestContext
    {
        if ($this->context === null) {
            throw new RuntimeException('No active request context.');
        }

        return $this->context;
    }

    public function end(): void
    {
        $context = $this->context;
        $this->context = null;
        $context?->close();
    }

    public function isActive(): bool
    {
        return $this->context !== null;
    }
}
