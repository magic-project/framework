<?php

declare(strict_types=1);

namespace Magic\Console;

use Magic\Container\Container;
use RuntimeException;

final class CommandRegistry
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    private bool $frozen = false;

    public function __construct(private readonly Container $container)
    {
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /** @param class-string<CommandInterface>|CommandInterface $command */
    public function register(string|CommandInterface $command): self
    {
        if ($this->frozen) {
            throw new RuntimeException('Cannot register commands after application boot.');
        }

        $instance = is_string($command) ? $this->container->get($command) : $command;
        if (!$instance instanceof CommandInterface) {
            throw new RuntimeException(sprintf(
                'Command [%s] must implement %s.',
                is_string($command) ? $command : $command::class,
                CommandInterface::class,
            ));
        }

        $name = trim($instance->name());
        if ($name === '') {
            throw new RuntimeException('Command name cannot be empty.');
        }
        if (isset($this->commands[$name])) {
            throw new RuntimeException(sprintf('Command [%s] is already registered.', $name));
        }

        $this->commands[$name] = $instance;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function get(string $name): CommandInterface
    {
        if (!isset($this->commands[$name])) {
            throw new RuntimeException(sprintf('Command [%s] is not registered.', $name));
        }

        return $this->commands[$name];
    }

    /** @return array<string, CommandInterface> */
    public function all(): array
    {
        $commands = $this->commands;
        uasort($commands, static fn (CommandInterface $left, CommandInterface $right): int =>
            $left->name() <=> $right->name(),
        );

        return $commands;
    }
}
