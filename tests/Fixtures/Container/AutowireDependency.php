<?php

declare(strict_types=1);

namespace Magic\Tests\Fixtures\Container;

final class AutowireDependency
{
    public function value(): string
    {
        return 'autowired';
    }
}
