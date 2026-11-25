<?php

declare(strict_types=1);

namespace Magic\Container;

use RuntimeException;

final class ProviderRegistry
{
    /** @var list<ServiceProviderInterface> */
    private array $providers = [];

    /** @var array<class-string<ServiceProviderInterface>, true> */
    private array $registered = [];

    private bool $booted = false;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Registers a provider immediately. Its boot method is called later by
     * boot(), which mirrors Laravel's register/boot lifecycle.
     *
     * @param class-string<ServiceProviderInterface>|ServiceProviderInterface $provider
     */
    public function register(string|ServiceProviderInterface $provider): void
    {
        if ($this->booted) {
            throw new RuntimeException('Cannot register a service provider after providers have booted.');
        }

        if (is_string($provider)) {
            if (!class_exists($provider)) {
                throw new RuntimeException(sprintf('Service provider [%s] does not exist.', $provider));
            }

            $provider = $this->container->get($provider);
        }

        if (!$provider instanceof ServiceProviderInterface) {
            throw new RuntimeException(sprintf(
                'Service provider [%s] must implement %s.',
                $provider::class,
                ServiceProviderInterface::class,
            ));
        }

        $class = $provider::class;
        if (isset($this->registered[$class])) {
            return;
        }

        $provider->register($this->container);
        $this->providers[] = $provider;
        $this->registered[$class] = true;
    }

    /** @param list<class-string<ServiceProviderInterface>|ServiceProviderInterface> $providers */
    public function registerMany(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /** @return list<ServiceProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
