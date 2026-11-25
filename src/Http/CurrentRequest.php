<?php

declare(strict_types=1);

namespace Magic\Http;

use Magic\Context\ContextManager;
use RuntimeException;
use Throwable;

/** Resolves the request belonging to the currently active request context. */
final class CurrentRequest
{
    public function __construct(private readonly ContextManager $context)
    {
    }

    public function get(): Request
    {
        try {
            return $this->context->current()->request();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'CurrentRequest can only be accessed while handling an active request.',
                0,
                $exception,
            );
        }
    }
}
