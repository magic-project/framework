<?php

declare(strict_types=1);

namespace Magic\Tests\Fixtures\Routing;

use Magic\Http\Request;

final class StatefulController
{
    private ?string $lastValue = null;

    public function show(Request $request): array
    {
        $previous = $this->lastValue;
        $this->lastValue = (string) $request->query('value', '');

        return [
            'previous' => $previous,
            'current' => $this->lastValue,
        ];
    }
}
