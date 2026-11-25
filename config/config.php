<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => 'production',
        'debug' => false,
        'service' => 'magic-framework',
    ],
    'http' => [
        'max_body_bytes' => 1_048_576,
        'max_header_bytes' => 65_536,
        'max_uri_bytes' => 8_192,
        'max_query_bytes' => 4_096,
        'max_query_params' => 100,
    ],
    'server' => [
        'host' => '127.0.0.1',
        'port' => 9502,
    ],
    'runtime' => [
        'shutdown_timeout' => 10,
        'max_request' => 10_000,
        'max_coroutine' => 10_000,
    ],
];
