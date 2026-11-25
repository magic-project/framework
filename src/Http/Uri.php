<?php

declare(strict_types=1);

namespace Magic\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final class Uri implements UriInterface
{
    private string $scheme;
    private string $userInfo;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;
    private string $fragment;

    public function __construct(string $uri = '')
    {
        $parts = parse_url($uri);
        if ($uri !== '' && $parts === false) {
            throw new InvalidArgumentException(sprintf('Invalid URI [%s].', $uri));
        }

        $this->scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $this->userInfo = isset($parts['user'])
            ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '')
            : '';
        $this->host = strtolower((string) ($parts['host'] ?? ''));
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path = (string) ($parts['path'] ?? '');
        $this->query = (string) ($parts['query'] ?? '');
        $this->fragment = (string) ($parts['fragment'] ?? '');
        $this->assertValid();
    }

    public function getScheme(): string { return $this->scheme; }

    public function getAuthority(): string
    {
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string { return $this->userInfo; }
    public function getHost(): string { return $this->host; }
    public function getPort(): ?int { return $this->port; }
    public function getPath(): string { return $this->path; }
    public function getQuery(): string { return $this->query; }
    public function getFragment(): string { return $this->fragment; }

    public function withScheme(string $scheme): UriInterface
    {
        $clone = clone $this;
        $clone->scheme = strtolower($scheme);
        $clone->assertValid();

        return $clone;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $clone = clone $this;
        $clone->userInfo = $user === '' ? '' : $user . ($password === null ? '' : ':' . $password);

        return $clone;
    }

    public function withHost(string $host): UriInterface
    {
        $clone = clone $this;
        $clone->host = strtolower($host);
        $clone->assertValid();

        return $clone;
    }

    public function withPort(?int $port): UriInterface
    {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('URI port must be between 1 and 65535.');
        }
        $clone = clone $this;
        $clone->port = $port;

        return $clone;
    }

    public function withPath(string $path): UriInterface
    {
        $clone = clone $this;
        $clone->path = $path;
        $clone->assertValid();

        return $clone;
    }

    public function withQuery(string $query): UriInterface
    {
        $clone = clone $this;
        $clone->query = ltrim($query, '?');
        $clone->assertValid();

        return $clone;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $clone = clone $this;
        $clone->fragment = ltrim($fragment, '#');
        $clone->assertValid();

        return $clone;
    }

    public function __toString(): string
    {
        $uri = $this->scheme === '' ? '' : $this->scheme . ':';
        if ($this->getAuthority() !== '') {
            $uri .= '//' . $this->getAuthority();
        }
        $uri .= $this->path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    private function assertValid(): void
    {
        if (preg_match('/[\x00-\x20\x7F]/', $this->scheme . $this->userInfo . $this->host . $this->path . $this->query . $this->fragment) === 1) {
            throw new InvalidArgumentException('URI contains invalid control characters.');
        }
        if ($this->scheme !== '' && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/D', $this->scheme) !== 1) {
            throw new InvalidArgumentException('URI scheme is invalid.');
        }
    }
}
