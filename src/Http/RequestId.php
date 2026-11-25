<?php

declare(strict_types=1);

namespace Magic\Http;

final class RequestId
{
    private const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    public static function resolve(?string $incoming): string
    {
        return is_string($incoming) && preg_match(self::PATTERN, $incoming) === 1
            ? $incoming
            : bin2hex(random_bytes(16));
    }

    private function __construct()
    {
    }
}
