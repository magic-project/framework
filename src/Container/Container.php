<?php

declare(strict_types=1);

namespace Magic\Container;

use Closure;
use Magic\Http\Request;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class Container implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, string> */
    private array $definitions = [];

    /** @var array<string, Closure> */
    private array $requestScopedFactories = [];

    /** @var array<string, Closure> */
    private array $prototypeFactories = [];

    /** @var null|\Closure(): ?object */
    private ?Closure $scopeResolver = null;

    private bool $frozen = false;

    /** @var list<string> */
    private array $resolving = [];

    /** @var array<string, true> */
    private array $resolvingFactories = [];

    /** @var array<string, \ReflectionClass<object>> */
    private array $reflectionClasses = [];

    /** @var array<string, list<\ReflectionParameter>|null> */
    private array $constructorParameters = [];

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function set(string $id, mixed $value): void
    {
        $this->assertMutable();
        unset($this->factories[$id]);
        unset($this->definitions[$id]);
        unset($this->requestScopedFactories[$id], $this->prototypeFactories[$id]);
        $this->entries[$id] = $value;
    }

    public function factory(string $id, Closure $factory): void
    {
        $this->assertMutable();
        unset($this->entries[$id]);
        unset($this->definitions[$id]);
        unset($this->requestScopedFactories[$id], $this->prototypeFactories[$id]);
        $this->factories[$id] = $factory;
    }

    public function singleton(string $id, mixed $definition = null): void
    {
        $this->register($id, $definition);
    }

    public function requestScoped(string $id, ?Closure $factory = null): void
    {
        $this->assertMutable();
        $this->forget($id);
        $this->requestScopedFactories[$id] = $factory ?? static function (Container $container) use ($id): object {
            return $container->autowire($id);
        };
    }

    public function prototype(string $id, ?Closure $factory = null): void
    {
        $this->assertMutable();
        $this->forget($id);
        $this->prototypeFactories[$id] = $factory ?? static function (Container $container) use ($id): object {
            return $container->autowire($id);
        };
    }

    public function registerSingleton(string $id, mixed $definition = null): void
    {
        $this->singleton($id, $definition);
    }

    public function registerRequestScoped(string $id, ?Closure $factory = null): void
    {
        $this->requestScoped($id, $factory);
    }

    public function registerPrototype(string $id, ?Closure $factory = null): void
    {
        $this->prototype($id, $factory);
    }

    public function setScopeResolver(Closure $resolver): void
    {
        $this->assertMutable();
        $this->scopeResolver = $resolver;
    }

    /**
     * Registers a service relation, instance, or factory.
     *
     * String definitions are lazily autowired and cached as singletons.
     */
    public function register(string $id, mixed $definition = null): void
    {
        $this->assertMutable();
        $definition ??= $id;

        $this->forget($id);

        if ($definition instanceof Closure) {
            $this->factory($id, $definition);

            return;
        }

        if (is_object($definition)) {
            $this->set($id, $definition);

            return;
        }

        if (!is_string($definition)) {
            throw new RuntimeException(sprintf(
                'Container registration [%s] must be a class name, object, or factory.',
                $id,
            ));
        }

        $this->definitions[$id] = $definition;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries)
            || array_key_exists($id, $this->factories)
            || array_key_exists($id, $this->definitions)
            || array_key_exists($id, $this->requestScopedFactories)
            || array_key_exists($id, $this->prototypeFactories);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->requestScopedFactories)) {
            $scope = $this->scopeResolver === null ? null : ($this->scopeResolver)();
            if ($scope === null || !method_exists($scope, 'has') || !method_exists($scope, 'get') || !method_exists($scope, 'set')) {
                throw new ContainerException(sprintf('Scoped container entry [%s] requires an active request context.', $id));
            }
            if ($scope->has($id)) {
                return $scope->get($id);
            }
            try {
                $value = ($this->requestScopedFactories[$id])($this);
            } catch (NotFoundException|ContainerException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new ContainerException(sprintf('Unable to resolve scoped container entry [%s].', $id), 0, $exception);
            }
            $scope->set($id, $value);

            return $value;
        }

        if (array_key_exists($id, $this->prototypeFactories)) {
            try {
                return ($this->prototypeFactories[$id])($this);
            } catch (NotFoundException|ContainerException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new ContainerException(sprintf('Unable to resolve prototype container entry [%s].', $id), 0, $exception);
            }
        }

        if (array_key_exists($id, $this->entries)) {
            return $this->entries[$id];
        }

        if (array_key_exists($id, $this->factories)) {
            if (isset($this->resolvingFactories[$id])) {
                throw new ContainerException(sprintf('Circular factory dependency detected for [%s].', $id));
            }
            $factory = $this->factories[$id];

            $this->resolvingFactories[$id] = true;
            try {
                $this->entries[$id] = $factory($this);
                unset($this->factories[$id]);
            } catch (NotFoundException|ContainerException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new ContainerException(sprintf('Unable to resolve container factory [%s].', $id), 0, $exception);
            } finally {
                unset($this->resolvingFactories[$id]);
            }

            return $this->entries[$id];
        }

        if (array_key_exists($id, $this->definitions)) {
            $concrete = $this->definitions[$id];
            $this->entries[$id] = $this->autowire($concrete);

            return $this->entries[$id];
        }

        if (class_exists($id)) {
            $this->entries[$id] = $this->autowire($id);

            return $this->entries[$id];
        }

        throw new NotFoundException(sprintf(
            'Container entry [%s] was not found and cannot be autowired.',
            $id,
        ));
    }

    /**
     * Creates a fresh instance without storing it in the container.
     *
     * This is intended for request-scoped handlers in long-running runtimes.
     */
    public function make(string $class): object
    {
        return $this->autowire($class);
    }

    /**
     * Resolves a controller using its configured lifecycle.
     *
     * Controllers that are not explicitly registered are created fresh for
     * every request so long-running workers cannot leak controller state.
     */
    public function resolveController(string $class): object
    {
        $this->assertControllerConstructorSafe($class);

        $isRegistered = array_key_exists($class, $this->entries)
            || array_key_exists($class, $this->factories)
            || array_key_exists($class, $this->definitions)
            || array_key_exists($class, $this->requestScopedFactories)
            || array_key_exists($class, $this->prototypeFactories);

        $controller = $isRegistered ? $this->get($class) : $this->make($class);
        if (!is_object($controller)) {
            throw new ContainerException(sprintf(
                'Controller [%s] must resolve to an object.',
                $class,
            ));
        }

        return $controller;
    }

    private function assertControllerConstructorSafe(string $class): void
    {
        if (!class_exists($class)) {
            return;
        }

        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $typeName = $type->getName();
            if ($typeName === Request::class || is_a($typeName, ServerRequestInterface::class, true)) {
                throw new ContainerException(sprintf(
                    'Controller [%s] cannot inject the current request in its constructor. '
                    . 'Receive Request in the action method or inject CurrentRequest.',
                    $class,
                ));
            }
        }
    }

    /**
     * Creates a concrete class by resolving its constructor dependencies.
     */
    public function autowire(string $class): object
    {
        if (in_array($class, $this->resolving, true)) {
            $chain = [...$this->resolving, $class];

            throw new ContainerException(sprintf(
                'Circular dependency detected: %s.',
                implode(' -> ', $chain),
            ));
        }

        $reflection = $this->reflectionClasses[$class] ??= new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf(
                'Class [%s] is not instantiable.',
                $class,
            ));
        }

        if (!array_key_exists($class, $this->constructorParameters)) {
            $constructor = $reflection->getConstructor();
            $this->constructorParameters[$class] = $constructor?->getParameters();
        }

        $parameters = $this->constructorParameters[$class];
        if ($parameters === null) {
            return $reflection->newInstance();
        }

        $this->resolving[] = $class;

        try {
            $arguments = [];
            foreach ($parameters as $parameter) {
                $type = $parameter->getType();

                if ($parameter->isDefaultValueAvailable() && $type instanceof \ReflectionNamedType && $type->allowsNull()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $arguments[] = $this->get($type->getName());
                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new ContainerException(sprintf(
                    'Cannot autowire [%s::$%s]. Bind the dependency explicitly.',
                    $class,
                    $parameter->getName(),
                ));
            }

            return $reflection->newInstanceArgs($arguments);
        } finally {
            array_pop($this->resolving);
        }
    }

    /**
     * Eagerly resolves all discovered class definitions.
     */
    public function warmup(): void
    {
        foreach (array_keys($this->definitions) as $id) {
            $this->get($id);
        }
    }

    private function forget(string $id): void
    {
        unset($this->entries[$id], $this->factories[$id], $this->definitions[$id], $this->requestScopedFactories[$id], $this->prototypeFactories[$id]);
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new RuntimeException('Container is frozen after application boot.');
        }
    }
}
