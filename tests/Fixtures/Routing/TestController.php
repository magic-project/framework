<?php

declare(strict_types=1);

namespace Magic\Tests\Fixtures\Routing;

use Magic\Http\Request;

final class TestController
{
    public function show(Request $request, array $params): array
    {
        return [
            'method' => $request->method(),
            'id' => $params['id'] ?? null,
        ];
    }
}
