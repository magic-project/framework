<?php

declare(strict_types=1);

namespace Magic\Runtime;

use Magic\Application;

interface RuntimeInterface
{
    public function name(): string;

    public function run(Application $application, string $host, int $port): void;

    public function stop(): void;
}
