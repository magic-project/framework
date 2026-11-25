<?php

declare(strict_types=1);

namespace App\Middleware;

use InvalidArgumentException;
use Magic\Config\Config;
use Magic\Http\ErrorResponse;
use Magic\Http\QueryParameterCounter;
use Magic\Http\Request;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestSizeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly int $maxBodyBytes,
        private readonly int $maxHeaderBytes,
        private readonly int $maxUriBytes = 8_192,
        private readonly int $maxQueryBytes = 4_096,
        private readonly int $maxQueryParams = 100,
    )
    {
        if ($this->maxBodyBytes <= 0 || $this->maxHeaderBytes <= 0 || $this->maxUriBytes <= 0 || $this->maxQueryBytes <= 0 || $this->maxQueryParams <= 0) {
            throw new InvalidArgumentException('Request size limits must be positive integers.');
        }
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            (int) $config->get('http.max_body_bytes', 1_048_576),
            (int) $config->get('http.max_header_bytes', 65_536),
            (int) $config->get('http.max_uri_bytes', 8_192),
            (int) $config->get('http.max_query_bytes', 4_096),
            (int) $config->get('http.max_query_params', 100),
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = Request::fromPsr($request);
        if (strlen($request->body()) > $this->maxBodyBytes) {
            return ErrorResponse::json('payload_too_large', 'Payload Too Large', 413);
        }
        if (strlen($request->uri()) > $this->maxUriBytes) {
            return ErrorResponse::json('uri_too_long', 'Request URI is too large.', 414);
        }
        if (strlen($request->queryString()) > $this->maxQueryBytes) {
            return ErrorResponse::json('query_too_large', 'Query string is too large.', 414);
        }
        if (QueryParameterCounter::exceeds($request->queryString(), $this->maxQueryParams)) {
            return ErrorResponse::json('too_many_query_parameters', 'Too many query parameters.', 400);
        }

        $headerBytes = 0;
        foreach ($request->headers() as $name => $value) {
            $headerBytes += strlen($name) + strlen($value) + 4;
        }
        if ($headerBytes > $this->maxHeaderBytes) {
            return ErrorResponse::json('headers_too_large', 'Request Header Fields Too Large', 431);
        }

        return $handler->handle($request);
    }
}
