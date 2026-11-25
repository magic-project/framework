<?php

declare(strict_types=1);

namespace Magic\Config;

use Magic\Application;
use Magic\Container\Container;
use Magic\Http\Middleware\MiddlewareRegistry;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Loads the declarative middleware configuration file (config/middleware.php)
 * and resolves the global pipeline through the middleware registry.
 *
 * The file may return a flat list, a {global, groups, aliases} map, or a
 * factory. Keeping this file I/O and parsing out of the Application
 * constructor leaves Application focused on wiring services together.
 */
final class MiddlewareLoader
{
    public function __construct(
        private readonly MiddlewareRegistry $registry,
        private readonly Container $container,
    ) {
    }

    /** @return list<MiddlewareInterface> */
    public function load(Application $application, string $path): array
    {
        $definitions = require $path;
        if (is_callable($definitions)) {
            $definitions = $this->invokeFactory($definitions, $application);
        }

        if ($definitions === null) {
            return [];
        }

        if (!is_array($definitions)) {
            throw new \RuntimeException(sprintf(
                'Middleware configuration file [%s] must return a list or a factory returning a list.',
                $path,
            ));
        }

        if (array_is_list($definitions)) {
            return $this->resolve($definitions, $path);
        }

        $global = $definitions['global'] ?? [];
        if (!is_array($global) || !array_is_list($global)) {
            throw new \RuntimeException(sprintf(
                'Middleware configuration file [%s] key [global] must be a list.',
                $path,
            ));
        }

        $groups = $definitions['groups'] ?? [];
        if (!is_array($groups) || ($groups !== [] && array_is_list($groups))) {
            throw new \RuntimeException(sprintf(
                'Middleware configuration file [%s] key [groups] must be a name-to-list map.',
                $path,
            ));
        }
        foreach ($groups as $name => $group) {
            if (!is_string($name) || !is_array($group) || !array_is_list($group)) {
                throw new \RuntimeException(sprintf(
                    'Middleware configuration file [%s] contains an invalid group definition.',
                    $path,
                ));
            }
            $this->registry->defineGroup($name, $group);
        }

        $aliases = $definitions['aliases'] ?? [];
        if (!is_array($aliases) || ($aliases !== [] && array_is_list($aliases))) {
            throw new \RuntimeException(sprintf(
                'Middleware configuration file [%s] key [aliases] must be a name-to-reference map.',
                $path,
            ));
        }
        foreach ($aliases as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException(sprintf(
                    'Middleware configuration file [%s] contains an invalid alias definition.',
                    $path,
                ));
            }
            $this->registry->defineAlias($name, $definition);
        }

        if (array_key_exists('routes', $definitions)) {
            throw new \RuntimeException(sprintf(
                'Middleware configuration file [%s] cannot contain route bindings; declare them in config/routers.php.',
                $path,
            ));
        }

        return $this->resolve($global, $path);
    }

    /**
     * @param list<mixed> $definitions
     * @return list<MiddlewareInterface>
     */
    private function resolve(array $definitions, string $path): array
    {
        try {
            return $this->registry->resolve($definitions, $this->container);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf(
                'Unable to load middleware configuration file [%s].',
                $path,
            ), 0, $exception);
        }
    }

    private function invokeFactory(callable $factory, Application $application): mixed
    {
        $reflection = is_array($factory)
            ? new \ReflectionMethod($factory[0], $factory[1])
            : new \ReflectionFunction(\Closure::fromCallable($factory));
        $parameters = $reflection->getParameters();

        if ($parameters === []) {
            return $factory();
        }

        $type = $parameters[0]->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && $type->getName() === Container::class) {
            return $factory($this->container);
        }

        return $factory($application);
    }
}
