<?php

declare(strict_types=1);

namespace Magic\Tests\Fixtures\Routing;

use Magic\Http\Request;

final class TestAction
{
    public function __invoke(Request $request, array $params): array
    {
        return ['path' => $request->path(), 'params' => $params];
    }
}
