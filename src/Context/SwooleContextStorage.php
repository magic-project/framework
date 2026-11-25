<?php

declare(strict_types=1);

namespace Magic\Context;

use Magic\Http\Request;
use RuntimeException;

final class SwooleContextStorage implements ContextStorageInterface
{
    private const string CONTEXT_KEY = '__magic_request_context';

    public function begin(Request $request): RequestContext
    {
        $context = $this->coroutineContext();

        if (isset($context[self::CONTEXT_KEY])) {
            throw new RuntimeException('Nested request contexts are not supported.');
        }

        $requestContext = new RequestContext($request);
        $context[self::CONTEXT_KEY] = $requestContext;

        return $requestContext;
    }

    public function current(): RequestContext
    {
        $context = $this->coroutineContext();
        $requestContext = $context[self::CONTEXT_KEY] ?? null;

        if (!$requestContext instanceof RequestContext) {
            throw new RuntimeException('No active Swoole request context.');
        }

        return $requestContext;
    }

    public function end(): void
    {
        $context = $this->coroutineContext();
        $requestContext = $context[self::CONTEXT_KEY] ?? null;
        unset($context[self::CONTEXT_KEY]);

        if ($requestContext instanceof RequestContext) {
            $requestContext->close();
        }
    }

    public function isActive(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $context = \Swoole\Coroutine::getContext();

        return isset($context[self::CONTEXT_KEY]) && $context[self::CONTEXT_KEY] instanceof RequestContext;
    }

    public function isAvailable(): bool
    {
        return extension_loaded('swoole') && class_exists(\Swoole\Coroutine::class);
    }

    /** @return \Swoole\Coroutine\Context */
    private function coroutineContext(): object
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('The Swoole extension is required for Swoole context storage.');
        }

        $context = \Swoole\Coroutine::getContext();
        if (!is_object($context)) {
            throw new RuntimeException('Swoole request context is only available inside a coroutine.');
        }

        /** @var \Swoole\Coroutine\Context $context */
        return $context;
    }
}
