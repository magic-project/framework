<?php

declare(strict_types=1);

namespace Magic\Http;

use JsonException;

/**
 * Single source of truth for parsing request bodies.
 *
 * Request (lazy parsing for synchronous handling) and the Swoole request
 * factory (eager parsing for early limit failures) both delegate here so the
 * malformed-body semantics can never drift between the two paths.
 */
final class BodyParser
{
    /** @return array<string, mixed> */
    public static function parseForm(string $body): array
    {
        $parsed = [];
        parse_str($body, $parsed);

        return $parsed;
    }

    /** @throws JsonException */
    public static function parseJson(string $body, bool $associative = true): mixed
    {
        return json_decode($body, $associative, 512, JSON_THROW_ON_ERROR);
    }

    private function __construct()
    {
    }
}
