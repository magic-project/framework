<?php

declare(strict_types=1);

namespace Magic\Console;

use InvalidArgumentException;

final class CommandInput
{
    /**
     * @param list<string> $arguments
     * @param array<string, bool|string> $options
     */
    public function __construct(
        private readonly string $command,
        private readonly array $arguments = [],
        private readonly array $options = [],
    ) {
    }

    /**
     * @param array $argv
     * @return self
     */
    public static function fromArgv(array $argv): self
    {
        $tokens = array_values(array_slice($argv, 1));
        $command = (string) array_shift($tokens);
        $arguments = [];
        $options = [];
        $parseOptions = true;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($parseOptions && $token === '--') {
                $parseOptions = false;
                continue;
            }

            if ($parseOptions && str_starts_with($token, '--') && strlen($token) > 2) {
                [$name, $value, $hasValue] = self::longOption($token);
                if (!$hasValue
                    && !self::isBooleanOption($name)
                    && isset($tokens[$index + 1])
                    && $tokens[$index + 1] !== '--'
                    && !str_starts_with($tokens[$index + 1], '-')
                ) {
                    $value = $tokens[++$index];
                    $hasValue = true;
                }
                $options[$name] = $hasValue ? $value : true;
                continue;
            }

            if ($parseOptions && preg_match('/^-[^-].*$/D', $token) === 1) {
                $short = substr($token, 1);
                if (str_contains($short, '=')) {
                    [$name, $value] = explode('=', $short, 2);
                    if ($name === '') {
                        throw new InvalidArgumentException('Command option name cannot be empty.');
                    }
                    $options[$name] = $value;
                    continue;
                }

                foreach (str_split($short) as $name) {
                    $options[$name] = true;
                }
                continue;
            }

            $arguments[] = $token;
        }

        return new self($command, $arguments, $options);
    }

    public function command(): string
    {
        return $this->command;
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function option(string $name, bool|string|null $default = null): bool|string|null
    {
        return $this->options[$name] ?? $default;
    }

    /** @return array<string, bool|string> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return array{0: string, 1: string|null, 2: bool} */
    private static function longOption(string $token): array
    {
        $value = null;
        $hasValue = false;
        $name = substr($token, 2);

        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
            $hasValue = true;
        }

        if ($name === '') {
            throw new InvalidArgumentException('Command option name cannot be empty.');
        }

        return [$name, $value, $hasValue];
    }

    private static function isBooleanOption(string $name): bool
    {
        return in_array($name, ['help', 'h', 'version', 'V'], true);
    }

}
