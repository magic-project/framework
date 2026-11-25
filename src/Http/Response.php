<?php

declare(strict_types=1);

namespace Magic\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response implements ResponseInterface
{
    /** @var array<string, list<string>> */
    private array $headers;
    private StreamInterface $bodyStream;
    private string $protocolVersion = '1.1';
    private string $reasonPhrase;

    /** @param array<string, mixed> $headers */
    public function __construct(string|StreamInterface $body = '', private int $status = 200, array $headers = [], string $reasonPhrase = '')
    {
        self::assertStatus($status);
        $this->bodyStream = $body instanceof StreamInterface ? $body : Stream::fromString(self::bodyAllowed($status) ? $body : '');
        if (!self::bodyAllowed($status)) {
            $this->bodyStream = Stream::fromString('');
        }
        $this->headers = self::normalizeHeaders($headers);
        if (preg_match('/[\x00-\x1F\x7F]/', $reasonPhrase) === 1) {
            throw new InvalidArgumentException('Response reason phrase contains invalid control characters.');
        }
        $this->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : self::reasonPhrase($status);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            (string) json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            ),
            $status,
            array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers),
        );
    }

    public function body(): string { return (string) $this->bodyStream; }
    public function status(): int { return $this->status; }
    /** @return array<string, string> */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }
    public function header(string $name): ?string
    {
        $line = $this->getHeaderLine($name);

        return $line === '' ? null : $line;
    }

    public function getProtocolVersion(): string { return $this->protocolVersion; }
    public function withProtocolVersion(string $version): ResponseInterface
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
    public function withHeader(string $name, $value): ResponseInterface
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
    public function withAddedHeader(string $name, $value): ResponseInterface
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
    public function withoutHeader(string $name): ResponseInterface
    {
        $clone = clone $this;
        $actual = $clone->findHeaderName($name);
        if ($actual !== null) {
            unset($clone->headers[$actual]);
        }

        return $clone;
    }
    public function getBody(): StreamInterface { return $this->bodyStream; }
    public function withBody(StreamInterface $body): ResponseInterface
    {
        $clone = clone $this;
        $clone->bodyStream = self::bodyAllowed($clone->status) ? $body : Stream::fromString('');

        return $clone;
    }
    public function getStatusCode(): int { return $this->status; }
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        self::assertStatus($code);
        if (preg_match('/[\x00-\x1F\x7F]/', $reasonPhrase) === 1) {
            throw new InvalidArgumentException('Response reason phrase contains invalid control characters.');
        }
        $clone = clone $this;
        $clone->status = $code;
        $clone->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : self::reasonPhrase($code);
        if (!self::bodyAllowed($code)) {
            $clone->bodyStream = Stream::fromString('');
        }

        return $clone;
    }
    public function getReasonPhrase(): string { return $this->reasonPhrase; }

    private static function assertStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP status [%d].', $status));
        }
    }
    /**
     * @param array<string, mixed> $headers
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        $actualNames = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $lower = strtolower($name);
            if (isset($actualNames[$lower])) {
                unset($normalized[$actualNames[$lower]]);
            }
            $normalized[$name] = self::normalizeHeaderValues($name, $value);
            $actualNames[$lower] = $name;
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
    private static function bodyAllowed(int $status): bool
    {
        return $status >= 200 && $status !== 204 && $status !== 205 && $status !== 304;
    }
    private static function reasonPhrase(int $status): string
    {
        return [
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 205 => 'Reset Content',
            301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified', 400 => 'Bad Request',
            401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            409 => 'Conflict', 413 => 'Payload Too Large', 414 => 'URI Too Long', 422 => 'Unprocessable Content',
            429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ][$status] ?? '';
    }
}
