<?php

declare(strict_types=1);

namespace Magic\Runtime\Swoole;

use JsonException;
use Magic\Http\BodyParser;
use Magic\Http\Request;
use Magic\Http\RequestLimitException;
use Magic\Http\QueryParameterCounter;

final class SwooleRequestFactory
{
    public static function from(
        object $request,
        ?int $maxBodyBytes = null,
        ?int $maxUriBytes = null,
        ?int $maxQueryBytes = null,
        ?int $maxQueryParams = null,
        ?int $maxHeaderBytes = null,
    ): Request
    {
        $server = is_array($request->server ?? null) ? $request->server : [];
        $headers = is_array($request->header ?? null) ? $request->header : [];
        $query = is_array($request->get ?? null) ? $request->get : [];
        $uri = (string) ($server['request_uri'] ?? '/');
        if ($maxUriBytes !== null && strlen($uri) > $maxUriBytes) {
            throw new RequestLimitException('Request URI is too large.', 'uri_too_long', 414);
        }
        $queryString = parse_url($uri, PHP_URL_QUERY);
        $queryString = is_string($queryString) ? $queryString : '';
        if ($maxQueryBytes !== null && strlen($queryString) > $maxQueryBytes) {
            throw new RequestLimitException('Query string is too large.', 'query_too_large', 414);
        }
        if ($maxQueryParams !== null && QueryParameterCounter::exceeds($queryString, $maxQueryParams)) {
            throw new RequestLimitException('Too many query parameters.', 'too_many_query_parameters', 400);
        }
        if ($maxHeaderBytes !== null) {
            $headerBytes = 0;
            foreach ($headers as $name => $value) {
                $headerBytes += strlen((string) $name) + strlen((string) $value) + 4;
            }
            if ($headerBytes > $maxHeaderBytes) {
                throw new RequestLimitException('Request headers are too large.', 'headers_too_large', 431);
            }
        }
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $body = '';
        if (method_exists($request, 'rawContent')) {
            $contentLength = filter_var(self::header($headers, 'content-length'), FILTER_VALIDATE_INT);
            if ($maxBodyBytes !== null && is_int($contentLength) && $contentLength > $maxBodyBytes) {
                throw new RequestLimitException('Payload Too Large', 'payload_too_large', 413);
            }
            $body = (string) $request->rawContent();
            if ($maxBodyBytes !== null && strlen($body) > $maxBodyBytes) {
                throw new RequestLimitException('Payload Too Large', 'payload_too_large', 413);
            }
        }

        $contentType = self::header($headers, 'content-type');
        $contentType = $contentType === null ? null : strtolower(trim(explode(';', $contentType, 2)[0]));
        $post = is_array($request->post ?? null) ? $request->post : [];
        $parsedBody = $post !== [] ? $post : null;
        if ($parsedBody === null && $contentType === 'application/x-www-form-urlencoded' && $body !== '') {
            $parsedBody = BodyParser::parseForm($body);
        }
        if ($parsedBody === null && ($contentType === 'application/json' || str_ends_with((string) $contentType, '+json')) && $body !== '') {
            try {
                $parsedBody = BodyParser::parseJson($body);
            } catch (JsonException) {
                throw new RequestLimitException('Invalid JSON body.', 'invalid_json', 400);
            }
        }

        $files = is_array($request->files ?? null)
            ? UploadedFileMapper::fromSwoole($request->files)
            : [];

        return new Request(
            (string) ($server['request_method'] ?? 'GET'),
            $path,
            $headers,
            $query,
            $body,
            $uri,
            $queryString,
            parsedBody: $parsedBody,
            uploadedFiles: $files,
        );
    }

    /** @param array<string, mixed> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $value;
            }
        }

        return null;
    }
}
