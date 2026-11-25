<?php

declare(strict_types=1);

namespace Magic\Tests\Fixtures\Routing;

use Magic\Http\Request;

final class UnsafeController
{
    public function __construct(Request $request)
    {
    }

    public function show(): string
    {
        return 'never';
    }
}
