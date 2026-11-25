<?php

declare(strict_types=1);

namespace App\Middleware;

use Magic\Context\ContextManager;
use Magic\Http\Request;
use Magic\Http\RequestId;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContextManager $context)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = Request::fromPsr($request);
        $existing = $this->context->get('request_id');
        $requestId = is_string($existing) && $existing !== ''
            ? RequestId::resolve($existing)
            : RequestId::resolve($request->header('x-request-id'));
        $this->context->set('request_id', $requestId);
        $response = $handler->handle($request);

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
