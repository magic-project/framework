<?php

declare(strict_types=1);

namespace Magic\Container;

/**
 * A provider is the extension point for registering application services.
 *
 * Registration happens while the application is being constructed. Booting is
 * deliberately deferred until Application::boot(), after every provider has
 * registered its services.
 */
interface ServiceProviderInterface
{
    public function register(Container $container): void;

    public function boot(Container $container): void;
}
