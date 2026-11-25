<?php

declare(strict_types=1);

namespace Magic\Console;

interface CommandInterface
{
    public function name(): string;

    public function description(): string;

    public function execute(CommandInput $input, CommandOutput $output): int;
}
