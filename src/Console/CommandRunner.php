<?php

declare(strict_types=1);

namespace Magic\Console;

use Psr\Log\LoggerInterface;
use Throwable;

final class CommandRunner
{
    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array $argv
     * @param CommandOutput|null $output
     * @return int
     */
    public function run(array $argv, ?CommandOutput $output = null): int
    {
        $output ??= new CommandOutput();
        $input = CommandInput::fromArgv($argv);

        if ($input->command() === '' || $input->command() === 'list') {
            $this->writeList($output);

            return 0;
        }

        if (in_array($input->command(), ['-h', '--help'], true)
            || $input->option('help') === true
            || $input->option('h') === true) {
            if (($input->command() === '-h' || $input->command() === '--help')
                && $this->registry->has('help')) {
                $this->registry->get('help')->execute(new CommandInput('help'), $output);
            } else {
                $this->writeHelp($input, $output);
            }

            return 0;
        }

        if (in_array($input->command(), ['-V', '--version'], true)
            || $input->option('version') === true
            || $input->option('V') === true) {
            if ($this->registry->has('version')) {
                $this->registry->get('version')->execute(new CommandInput('version'), $output);
            } else {
                $output->writeln('Magic Framework ' . \Magic\Version::VALUE);
            }

            return 0;
        }

        if (!$this->registry->has($input->command())) {
            $output->errorln(sprintf('Unknown command: %s', $input->command()));
            $this->writeList($output, true);

            return 2;
        }

        try {
            $exitCode = $this->registry->get($input->command())->execute($input, $output);

            if (!is_int($exitCode) || $exitCode < 0 || $exitCode > 255) {
                $this->logger->warning('Console command returned an invalid exit code.', [
                    'command' => $input->command(),
                    'exit_code' => $exitCode,
                ]);

                return 1;
            }

            return $exitCode;
        } catch (Throwable $exception) {
            $this->logger->error('Console command failed.', [
                'command' => $input->command(),
                'exception' => $exception,
            ]);
            $output->errorln('Command failed: ' . $exception->getMessage());

            return 1;
        }
    }

    private function writeHelp(CommandInput $input, CommandOutput $output): void
    {
        if ($this->registry->has('help')) {
            $this->registry->get('help')->execute(
                new CommandInput('help', [$input->command()]),
                $output,
            );

            return;
        }

        if ($this->registry->has($input->command())) {
            $command = $this->registry->get($input->command());
            $output->writeln($command->name() . ' - ' . $command->description());
            $output->writeln('Usage: magic ' . $command->name() . ' [arguments] [options]');

            return;
        }

        $this->writeList($output);
    }

    private function writeList(CommandOutput $output, bool $error = false): void
    {
        $writer = $error ? $output->errorln(...) : $output->writeln(...);
        $writer('Available commands:');
        foreach ($this->registry->all() as $command) {
            $writer(sprintf('  %-24s %s', $command->name(), $command->description()));
        }
    }
}
