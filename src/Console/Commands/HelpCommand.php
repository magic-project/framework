<?php

declare(strict_types=1);

namespace Magic\Console\Commands;

use Magic\Console\CommandInput;
use Magic\Console\CommandInterface;
use Magic\Console\CommandOutput;
use Magic\Console\CommandRegistry;

final class HelpCommand implements CommandInterface
{
    public function __construct(private readonly CommandRegistry $registry)
    {
    }

    public function name(): string
    {
        return 'help';
    }

    public function description(): string
    {
        return 'Show available commands or command help.';
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $name = $input->argument(0);
        if ($name !== null && $name !== '' && $this->registry->has($name)) {
            $command = $this->registry->get($name);
            $output->writeln($command->name() . ' - ' . $command->description());
            $output->writeln('Usage: magic ' . $command->name() . ' [arguments] [options]');

            return 0;
        }

        $output->writeln('Available commands:');
        foreach ($this->registry->all() as $command) {
            $output->writeln(sprintf('  %-24s %s', $command->name(), $command->description()));
        }

        return 0;
    }
}
