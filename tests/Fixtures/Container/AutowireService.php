<?php

declare(strict_types=1);

namespace Magic\Tests\Fixtures\Container;

final class AutowireService
{
    public function __construct(private readonly AutowireDependency $dependency)
    {
    }

    public function value(): string
    {
        return $this->dependency->value();
    }
}
