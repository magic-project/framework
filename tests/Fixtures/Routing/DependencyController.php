<?php

declare(strict_types=1);

namespace Magic\Tests\Fixtures\Routing;

use Magic\Http\CurrentRequest;
use Magic\Http\Request;

final class DependencyController
{
    public function show(Request $request, array $params, CurrentRequest $current): array
    {
        return [
            'path' => $request->path(),
            'id' => $params['id'] ?? null,
            'same' => $current->get() === $request,
        ];
    }
}
