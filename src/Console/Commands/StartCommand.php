<?php

declare(strict_types=1);

namespace Magic\Console\Commands;

use Magic\Application;
use Magic\Config\Config;
use Magic\Console\CommandInput;
use Magic\Console\CommandInterface;
use Magic\Console\CommandOutput;
use Magic\Runtime\Swoole\SwooleRuntime;

final class StartCommand implements CommandInterface
{
    public function __construct(
        private readonly Application $application,
        private readonly Config $config,
    ) {
    }

    public function name(): string
    {
        return 'start';
    }

    public function description(): string
    {
        return 'Start the application with the Swoole HTTP runtime.';
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $host = $this->stringOption(
            $input->option('host'),
            (string) $this->config->get('server.host', '127.0.0.1'),
        );
        $port = $this->positiveIntOption(
            $input->option('port'),
            (int) $this->config->get('server.port', 9501),
        );

        $output->writeln(sprintf('Starting Magic Framework with Swoole at http://%s:%d', $host, $port));

        new SwooleRuntime()->run($this->application, $host, $port);

        return 0;
    }

    private function stringOption(bool|string|null $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    private function positiveIntOption(bool|string|null $value, int $default): int
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        $port = (int) $value;

        return $port > 0 && $port <= 65535 ? $port : $default;
    }
}
