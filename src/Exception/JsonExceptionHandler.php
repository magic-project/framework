<?php

declare(strict_types=1);

namespace Magic\Exception;

use Magic\Http\Request;
use Magic\Http\Response;
use Magic\Http\ErrorResponse;
use Magic\Context\ContextManager;
use Psr\Log\LoggerInterface;
use Throwable;

final class JsonExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ContextManager $context = null,
    )
    {
    }

    public function render(Throwable $exception, Request $request): Response
    {
        $context = [
            'exception' => $exception,
            'method' => $request->method(),
            'path' => $request->path(),
        ];
        if ($this->context?->isActive() && $this->context->has('request_id')) {
            $requestId = $this->context->get('request_id');
            if (is_string($requestId) && $requestId !== '') {
                $context['request_id'] = $requestId;
            }
        }

        $this->logger?->error('Unhandled request exception.', $context);

        if ($this->logger === null) {
            error_log(sprintf('%s: %s at %s:%d', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }

        return ErrorResponse::json('internal_error', 'Internal Server Error', 500);
    }
}
