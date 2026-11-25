<?php

declare(strict_types=1);

namespace Magic\Config;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable application configuration with dot-notation access.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values = [])
    {
    }

    /**
     * @param array<string, mixed> $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        return new self(self::environmentValues($environment));
    }

    /**
     * Snapshots the process environment via getenv(), which works correctly in
     * long-running runtimes (Swoole/Swow) where $_ENV may be empty and $_SERVER
     * may be stale after the worker starts.
     *
     * @return array<string, mixed>
     */
    public static function environment(): array
    {
        $environment = getenv();

        return is_array($environment) ? $environment : [];
    }

    /**
     * Loads configuration from a PHP file that returns an array (e.g. config/config.php).
     *
     * Environment variables passed via $environment override individual keys
     * when they are present; otherwise the values from the file are kept.
     *
     * @param array<string, mixed> $environment
     */
    public static function fromFile(string $path, array $environment = []): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Configuration file [%s] does not exist.', $path));
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException(sprintf('Configuration file [%s] must return an array.', $path));
        }

        if ($environment !== []) {
            foreach (self::environmentOverrides($environment) as $section => $overrides) {
                if (!is_array($overrides)) {
                    continue;
                }

                if (!isset($values[$section]) || !is_array($values[$section])) {
                    $values[$section] = [];
                }
                foreach ($overrides as $key => $value) {
                    $values[$section][$key] = $value;
                }
            }
        }

        return new self($values);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function has(string $key): bool
    {
        [$found] = $this->lookup($key);

        return $found;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->lookup($key);

        return $found ? $value : $default;
    }

    public function require(string $key): mixed
    {
        [$found, $value] = $this->lookup($key);
        if (!$found) {
            throw new RuntimeException(sprintf('Required configuration [%s] is missing.', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /** @return array{0: bool, 1: mixed} */
    private function lookup(string $key): array
    {
        if ($key === '') {
            throw new InvalidArgumentException('Configuration key cannot be empty.');
        }

        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, array<string, bool|int|string>>
     */
    private static function environmentValues(array $environment): array
    {
        $env = self::stringValue($environment, 'APP_ENV', 'production');
        $debug = filter_var(
            self::stringValue($environment, 'APP_DEBUG', '0'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );

        return [
            'app' => [
                'env' => $env,
                'debug' => $debug ?? false,
                'service' => self::stringValue($environment, 'APP_SERVICE', 'magic-framework'),
            ],
            'http' => [
                'max_body_bytes' => self::positiveInt($environment, 'HTTP_MAX_BODY_BYTES', 1_048_576),
                'max_header_bytes' => self::positiveInt($environment, 'HTTP_MAX_HEADER_BYTES', 65_536),
                'max_uri_bytes' => self::positiveInt($environment, 'HTTP_MAX_URI_BYTES', 8_192),
                'max_query_bytes' => self::positiveInt($environment, 'HTTP_MAX_QUERY_BYTES', 4_096),
                'max_query_params' => self::positiveInt($environment, 'HTTP_MAX_QUERY_PARAMS', 100),
            ],
            'server' => [
                'host' => self::stringValue($environment, 'SERVER_HOST', '127.0.0.1'),
                'port' => self::positiveInt($environment, 'SERVER_PORT', 9501),
            ],
            'runtime' => [
                'shutdown_timeout' => self::positiveInt($environment, 'RUNTIME_SHUTDOWN_TIMEOUT', 10),
                'max_request' => self::positiveInt($environment, 'RUNTIME_MAX_REQUEST', 10_000),
                'max_coroutine' => self::positiveInt($environment, 'RUNTIME_MAX_COROUTINE', 10_000),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, array<string, bool|int|string>>
     */
    private static function environmentOverrides(array $environment): array
    {
        $values = self::environmentValues($environment);

        $map = [
            'APP_ENV' => ['app', 'env'],
            'APP_DEBUG' => ['app', 'debug'],
            'APP_SERVICE' => ['app', 'service'],
            'HTTP_MAX_BODY_BYTES' => ['http', 'max_body_bytes'],
            'HTTP_MAX_HEADER_BYTES' => ['http', 'max_header_bytes'],
            'HTTP_MAX_URI_BYTES' => ['http', 'max_uri_bytes'],
            'HTTP_MAX_QUERY_BYTES' => ['http', 'max_query_bytes'],
            'HTTP_MAX_QUERY_PARAMS' => ['http', 'max_query_params'],
            'SERVER_HOST' => ['server', 'host'],
            'SERVER_PORT' => ['server', 'port'],
            'RUNTIME_SHUTDOWN_TIMEOUT' => ['runtime', 'shutdown_timeout'],
            'RUNTIME_MAX_REQUEST' => ['runtime', 'max_request'],
            'RUNTIME_MAX_COROUTINE' => ['runtime', 'max_coroutine'],
        ];

        $overrides = [];
        foreach ($map as $environmentKey => [$section, $key]) {
            if (array_key_exists($environmentKey, $environment)) {
                $overrides[$section][$key] = $values[$section][$key];
            }
        }

        return $overrides;
    }

    /** @param array<string, mixed> $environment */
    private static function stringValue(array $environment, string $key, string $default): string
    {
        $value = $environment[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<string, mixed> $environment */
    private static function positiveInt(array $environment, string $key, int $default): int
    {
        $value = filter_var($environment[$key] ?? $default, FILTER_VALIDATE_INT);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
