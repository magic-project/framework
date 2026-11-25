<?php

declare(strict_types=1);

namespace Magic\Http;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

final class Request implements ServerRequestInterface
{
    /** @var array<string, list<string>> */
    private array $headers;
    /** @var array<string, mixed> */
    private array $serverParams;
    /** @var array<string, mixed> */
    private array $cookieParams;
    /** @var array<string, mixed> */
    private array $queryParams;
    /** @var array<string, mixed> */
    private array $uploadedFiles;
    /** @var array<string, mixed>|object|null */
    private mixed $parsedBody;
    /** @var array<string, mixed> */
    private array $attributes;

    private string $method;
    private string $path;
    private string $uri;
    private string $queryString;
    private string $protocolVersion = '1.1';
    private ?string $requestTarget = null;
    private StreamInterface $bodyStream;
    private UriInterface $uriObject;

    public static function fromPsr(ServerRequestInterface $request): self
    {
        if ($request instanceof self) {
            return $request;
        }

        $uri = $request->getUri();
        $converted = new self(
            $request->getMethod(),
            $uri->getPath() !== '' ? $uri->getPath() : '/',
            $request->getHeaders(),
            $request->getQueryParams(),
            (string) $request->getBody(),
            (string) $uri,
            $uri->getQuery(),
            $request->getServerParams(),
            $request->getCookieParams(),
            $request->getUploadedFiles(),
            $request->getParsedBody(),
            $request->getAttributes(),
        );
        $converted->protocolVersion = $request->getProtocolVersion();
        $converted->requestTarget = $request->getRequestTarget();

        return $converted;
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $serverParams
     * @param array<string, mixed> $cookieParams
     * @param array<string, mixed> $uploadedFiles
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        string $method,
        string $path,
        array $headers = [],
        private readonly array $query = [],
        private readonly string $body = '',
        ?string $uri = null,
        ?string $queryString = null,
        array $serverParams = [],
        array $cookieParams = [],
        array $uploadedFiles = [],
        mixed $parsedBody = null,
        array $attributes = [],
    ) {
        $this->method = strtoupper($method);
        $this->headers = self::normalizeHeaders($headers);
        $this->queryParams = $query;
        $this->queryString = $queryString ?? self::resolveQueryString($uri ?? $path, $this->query);
        $this->uri = $uri ?? ($this->queryString === '' ? $path : $path . '?' . $this->queryString);
        $this->path = $path;
        $this->uriObject = new Uri($this->uri);
        $this->bodyStream = Stream::fromString($body);
        $this->serverParams = $serverParams;
        $this->cookieParams = $cookieParams;
        $this->uploadedFiles = self::validateUploadedFiles($uploadedFiles);
        $this->parsedBody = self::validateParsedBody($parsedBody);
        $this->attributes = $attributes;
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function uri(): string { return $this->uri; }
    public function queryString(): string { return $this->queryString; }
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->hasHeader($name) ? $this->getHeaderLine($name) : $default;
    }
    /** @return array<string, string> */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }
    /**
     * Returns all query parameters or one query parameter by name.
     *
     * The no-argument form remains compatible with the original API.
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }

        return self::value($this->queryParams, $key, $default);
    }

    /** Returns a query parameter. Alias for query($key, $default). */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query($key, $default);
    }

    /**
     * Returns all parsed body data or one body field by name.
     * Form and JSON bodies are parsed lazily and cached on this request only.
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        $body = $this->parsedBodyData();
        if ($key === null) {
            return $body;
        }

        return is_array($body) ? self::value($body, $key, $default) : $default;
    }

    /** Returns a value from query parameters or parsed body data. */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $body = $this->parsedBodyData();
        $input = $this->queryParams;
        if (is_array($body)) {
            $input = array_replace($input, $body);
        }

        if ($key === null) {
            return $input;
        }

        return self::value($input, $key, $default);
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->uploadedFiles;
    }

    /** Returns one uploaded file, or all files when no key is supplied. */
    public function file(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->uploadedFiles;
        }

        return self::value($this->uploadedFiles, $key, $default);
    }

    public function hasFile(string $key): bool
    {
        return self::containsUploadedFile($this->file($key));
    }

    /** @return array<string, mixed> */
    public function routeParams(): array
    {
        $params = $this->getAttribute('route_params', []);

        return is_array($params) ? $params : [];
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return self::value($this->routeParams(), $key, $default);
    }

    /** Alias for body(), kept explicit for business code. */
    public function rawBody(): string
    {
        return $this->body();
    }
    public function body(): string { return (string) $this->bodyStream; }
    public function contentType(): ?string
    {
        $contentType = $this->header('content-type');

        return $contentType === null ? null : strtolower(trim(explode(';', $contentType, 2)[0]));
    }
    public function isJson(): bool
    {
        return $this->contentType() === 'application/json' || str_ends_with((string) $this->contentType(), '+json');
    }
    public function json(bool $associative = true): mixed
    {
        if ($this->body() === '') {
            return null;
        }

        return BodyParser::parseJson($this->body(), $associative);
    }

    private function parsedBodyData(): mixed
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        if ($this->body() === '') {
            return null;
        }

        if ($this->isJson()) {
            try {
                $this->parsedBody = $this->json();
            } catch (JsonException) {
                throw new RequestLimitException('Invalid JSON body.', 'invalid_json', 400);
            }

            return $this->parsedBody;
        }

        if ($this->contentType() === 'application/x-www-form-urlencoded') {
            $this->parsedBody = BodyParser::parseForm($this->body());

            return $this->parsedBody;
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private static function value(array $data, string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private static function containsUploadedFile(mixed $value): bool
    {
        if ($value instanceof UploadedFileInterface) {
            return $value->getError() === UPLOAD_ERR_OK;
        }
        if (is_array($value)) {
            foreach ($value as $nested) {
                if (self::containsUploadedFile($nested)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getProtocolVersion(): string { return $this->protocolVersion; }
    public function withProtocolVersion(string $version): RequestInterface
    {
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $version) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP protocol version [%s].', $version));
        }
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }
    public function getHeaders(): array { return $this->headers; }
    public function hasHeader(string $name): bool { return $this->findHeaderName($name) !== null; }
    public function getHeader(string $name): array
    {
        $actual = $this->findHeaderName($name);

        return $actual === null ? [] : $this->headers[$actual];
    }
    public function getHeaderLine(string $name): string { return implode(', ', $this->getHeader($name)); }
    public function withHeader(string $name, $value): RequestInterface
    {
        $values = self::normalizeHeaderValues($name, $value, true);
        $clone = clone $this;
        $actual = $clone->findHeaderName($name);
        if ($actual !== null) {
            unset($clone->headers[$actual]);
        }
        $clone->headers[$name] = $values;

        return $clone;
    }
    public function withAddedHeader(string $name, $value): RequestInterface
    {
        $values = self::normalizeHeaderValues($name, $value, true);
        $clone = clone $this;
        $actual = $clone->findHeaderName($name);
        if ($actual === null) {
            $clone->headers[$name] = $values;
        } else {
            $clone->headers[$actual] = [...$clone->headers[$actual], ...$values];
        }

        return $clone;
    }
    public function withoutHeader(string $name): RequestInterface
    {
        $clone = clone $this;
        $actual = $clone->findHeaderName($name);
        if ($actual !== null) {
            unset($clone->headers[$actual]);
        }

        return $clone;
    }
    public function getBody(): StreamInterface { return $this->bodyStream; }
    public function withBody(StreamInterface $body): RequestInterface
    {
        $clone = clone $this;
        $clone->bodyStream = $body;

        return $clone;
    }
    public function getRequestTarget(): string
    {
        $path = $this->uriObject->getPath();
        $path = $path === '' ? '/' : $path;

        return $this->requestTarget ?? ($path . ($this->uriObject->getQuery() === '' ? '' : '?' . $this->uriObject->getQuery()));
    }
    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        if (preg_match('/[\x00-\x20\x7F]/', $requestTarget) === 1) {
            throw new InvalidArgumentException('Request target contains invalid characters.');
        }
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;

        return $clone;
    }
    public function getMethod(): string { return $this->method; }
    public function withMethod(string $method): RequestInterface
    {
        if ($method === '' || preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $method) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP method [%s].', $method));
        }
        $clone = clone $this;
        $clone->method = $method;

        return $clone;
    }
    public function getUri(): UriInterface { return $this->uriObject; }
    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $clone = clone $this;
        $clone->uriObject = $uri;
        $clone->uri = (string) $uri;
        $clone->path = $uri->getPath() === '' ? '/' : $uri->getPath();
        $clone->queryString = $uri->getQuery();
        if (!$preserveHost || !$clone->hasHeader('Host')) {
            if ($uri->getHost() !== '') {
                $host = $uri->getHost();
                if ($uri->getPort() !== null) {
                    $host .= ':' . $uri->getPort();
                }
                $clone = $clone->withHeader('Host', $host);
            }
        }

        return $clone;
    }
    /** @return array<string, mixed> */
    public function getServerParams(): array { return $this->serverParams; }
    /** @return array<string, mixed> */
    public function getCookieParams(): array { return $this->cookieParams; }
    /**
     * @param array<string, mixed> $cookies
     * @return ServerRequestInterface
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;

        return $clone;
    }
    /** @return array<string, mixed> */
    public function getQueryParams(): array { return $this->queryParams; }
    /**
     * @param array<string, mixed> $query
     * @return ServerRequestInterface
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;

        return $clone;
    }
    /** @return array<string, mixed> */
    public function getUploadedFiles(): array { return $this->uploadedFiles; }
    /**
     * @param array<string, mixed> $uploadedFiles
     * @return ServerRequestInterface
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->uploadedFiles = self::validateUploadedFiles($uploadedFiles);

        return $clone;
    }
    /** @return array<string, mixed>|object|null */
    public function getParsedBody() { return $this->parsedBody; }
    /**
     * @param array<string, mixed>|object|null $data
     * @return ServerRequestInterface
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->parsedBody = self::validateParsedBody($data);

        return $clone;
    }
    /** @return array<string, mixed> */
    public function getAttributes(): array { return $this->attributes; }
    public function getAttribute(string $name, $default = null) { return $this->attributes[$name] ?? $default; }
    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }
    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            try {
            $normalized[$name] = self::normalizeHeaderValues($name, $value);
            } catch (InvalidArgumentException) {
                // Server-provided headers are untrusted input. Invalid fields
                // are discarded at the boundary; PSR-7 mutators remain strict.
            }
        }

        return $normalized;
    }
    /** @return list<string> */
    private static function normalizeHeaderValues(string $name, mixed $value, bool $strict = false): array
    {
        if (preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid header name [%s].', $name));
        }
        if ($strict && !is_string($value) && !is_array($value)) {
            throw new InvalidArgumentException(sprintf('Header [%s] must be a string or an array of strings.', $name));
        }
        $values = is_array($value) ? $value : [$value];
        $normalized = [];
        foreach ($values as $item) {
            if ($strict && !is_string($item)) {
                throw new InvalidArgumentException(sprintf('Header [%s] must contain string values.', $name));
            }
            if (!$strict && !is_scalar($item) && !$item instanceof \Stringable) {
                throw new InvalidArgumentException(sprintf('Header [%s] must contain scalar string values.', $name));
            }
            $item = (string) $item;
            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $item) === 1) {
                throw new InvalidArgumentException(sprintf('Header [%s] contains invalid control characters.', $name));
            }
            $normalized[] = $item;
        }

        return $normalized;
    }
    private function findHeaderName(string $name): ?string
    {
        foreach (array_keys($this->headers) as $actual) {
            if (strcasecmp($actual, $name) === 0) {
                return $actual;
            }
        }

        return null;
    }
    /** @param array<string, mixed> $query */
    private static function resolveQueryString(string $uri, array $query): string
    {
        $queryString = parse_url($uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            return $queryString;
        }

        return http_build_query($query);
    }
    /**
     * @param array<string, mixed> $uploadedFiles
     * @return array<string, mixed>
     */
    private static function validateUploadedFiles(array $uploadedFiles): array
    {
        $validate = static function (mixed $value) use (&$validate): mixed {
            if ($value instanceof UploadedFileInterface) {
                return $value;
            }
            if (is_array($value)) {
                return array_map($validate, $value);
            }

            throw new InvalidArgumentException('Uploaded files must contain UploadedFileInterface instances.');
        };

        return array_map($validate, $uploadedFiles);
    }
    private static function validateParsedBody(mixed $data): mixed
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('Parsed body must be null, an array, or an object.');
        }

        return $data;
    }
}
