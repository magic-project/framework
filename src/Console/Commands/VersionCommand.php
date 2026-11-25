<?php

declare(strict_types=1);

namespace Magic\Console\Commands;

use Magic\Console\CommandInput;
use Magic\Console\CommandInterface;
use Magic\Console\CommandOutput;
use Magic\Version;

final class VersionCommand implements CommandInterface
{
    public function name(): string
    {
        return 'version';
    }

    public function description(): string
    {
        return 'Show the framework version.';
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $output->writeln('Magic Framework ' . Version::VALUE);

        return 0;
    }
}
