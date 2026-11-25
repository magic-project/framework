<?php

declare(strict_types=1);

namespace Magic\Http\Middleware;

use InvalidArgumentException;
use Magic\Container\Container;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use RuntimeException;

/**
 * Holds declarative middleware groups and aliases and resolves them through
 * the application container when a global or route pipeline is registered.
 */
final class MiddlewareRegistry
{
    /** @var array<string, list<mixed>> */
    private array $groups = [];

    /** @var array<string, mixed> */
    private array $aliases = [];

    private bool $frozen = false;

    /** @param list<mixed> $middleware */
    public function defineGroup(string $name, array $middleware): void
    {
        $this->assertMutable();
        $this->assertName($name);
        if (!array_is_list($middleware)) {
            throw new InvalidArgumentException(sprintf('Middleware group [%s] must be a list.', $name));
        }

        $this->groups[$name] = $middleware;
    }

    public function defineAlias(string $name, mixed $middleware): void
    {
        $this->assertMutable();
        $this->assertName($name);
        $this->aliases[$name] = $middleware;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * @param list<mixed> $references
     * @return list<PsrMiddlewareInterface>
     */
    public function resolve(array $references, Container $container): array
    {
        if (!array_is_list($references)) {
            throw new InvalidArgumentException('Middleware references must be a list.');
        }

        $resolved = [];
        foreach ($references as $reference) {
            foreach ($this->expand($reference, $container, []) as $middleware) {
                $resolved[] = $middleware;
            }
        }

        return $resolved;
    }

    /**
     * @param list<PsrMiddlewareInterface> $stack
     * @return iterable<PsrMiddlewareInterface>
     */
    private function expand(mixed $reference, Container $container, array $stack): iterable
    {
        if ($reference instanceof PsrMiddlewareInterface) {
            yield $reference;

            return;
        }

        if (!is_string($reference) || $reference === '') {
            throw new InvalidArgumentException('Middleware references must be class names, aliases, or groups.');
        }

        if (isset($stack[$reference])) {
            throw new RuntimeException(sprintf(
                'Circular middleware group or alias reference detected: %s.',
                implode(' -> ', [...array_keys($stack), $reference]),
            ));
        }

        $nextStack = [...$stack, $reference => true];
        if (array_key_exists($reference, $this->groups)) {
            foreach ($this->groups[$reference] as $item) {
                yield from $this->expand($item, $container, $nextStack);
            }

            return;
        }

        if (array_key_exists($reference, $this->aliases)) {
            yield from $this->expand($this->aliases[$reference], $container, $nextStack);

            return;
        }

        $middleware = $container->get($reference);
        if (!$middleware instanceof PsrMiddlewareInterface) {
            throw new RuntimeException(sprintf(
                'Configured middleware [%s] must implement %s.',
                $reference,
                PsrMiddlewareInterface::class,
            ));
        }

        yield $middleware;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new RuntimeException('Middleware registry is frozen after application boot.');
        }
    }

    private function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Middleware group and alias names cannot be empty.');
        }
    }
}
