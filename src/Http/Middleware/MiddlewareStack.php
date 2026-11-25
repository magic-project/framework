<?php

declare(strict_types=1);

namespace Magic\Http\Middleware;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as PsrRequestHandlerInterface;

final class MiddlewareStack implements PsrRequestHandlerInterface
{
    private int $index = 0;

    /** @param list<PsrMiddlewareInterface> $middleware */
    public function __construct(
        private readonly array $middleware,
        private readonly Closure $last,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) {
            return ($this->last)($request);
        }

        $middleware = $this->middleware[$this->index++];

        try {
            $response = $middleware->process($request, $this);
            if (!$response instanceof ResponseInterface) {
                throw new \RuntimeException('Middleware must return a PSR-7 response.');
            }

            return $response;
        } finally {
            $this->index--;
        }
    }
}
