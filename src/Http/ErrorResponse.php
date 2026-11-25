<?php

declare(strict_types=1);

namespace Magic\Http;

final class ErrorResponse
{
    /** @param array<string, string> $headers */
    public static function json(string $code, string $message, int $status, array $headers = []): Response
    {
        return Response::json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status, $headers);
    }
}
