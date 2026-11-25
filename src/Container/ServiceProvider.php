<?php

declare(strict_types=1);

namespace Magic\Container;

/**
 * Convenience base class for providers that only need register().
 */
abstract class ServiceProvider implements ServiceProviderInterface
{
    public function boot(Container $container): void
    {
    }
}
