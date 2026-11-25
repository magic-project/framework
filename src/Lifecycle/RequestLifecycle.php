<?php

declare(strict_types=1);

namespace Magic\Lifecycle;

use Magic\Context\RequestContext;
use Throwable;

final class RequestLifecycle
{
    /** @var list<callable(RequestContext): void> */
    private array $startHooks = [];

    /** @var list<callable(RequestContext): void> */
    private array $endHooks = [];

    private bool $frozen = false;

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function onStart(callable $hook): void
    {
        if ($this->frozen) {
            throw new \RuntimeException('Cannot register request hooks after application boot.');
        }
        $this->startHooks[] = $hook;
    }

    public function onEnd(callable $hook): void
    {
        if ($this->frozen) {
            throw new \RuntimeException('Cannot register request hooks after application boot.');
        }
        $this->endHooks[] = $hook;
    }

    public function start(RequestContext $context): void
    {
        foreach ($this->startHooks as $hook) {
            $hook($context);
        }
    }

    public function end(RequestContext $context): void
    {
        $firstException = null;

        foreach (array_reverse($this->endHooks) as $hook) {
            try {
                $hook($context);
            } catch (Throwable $exception) {
                $firstException ??= $exception;
            }
        }

        if ($firstException !== null) {
            throw $firstException;
        }
    }
}
