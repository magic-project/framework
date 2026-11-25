<?php

declare(strict_types=1);

namespace Magic\Console;

use InvalidArgumentException;

final class CommandOutput
{
    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly mixed $stdout = null,
        private readonly mixed $stderr = null,
    ) {
        foreach ([$this->stdout, $this->stderr] as $stream) {
            if ($stream !== null && (!is_resource($stream) || get_resource_type($stream) !== 'stream')) {
                throw new InvalidArgumentException('Command output streams must be writable resources.');
            }
        }
    }

    public function write(string $message): void
    {
        $this->writeTo($this->stdout ?? STDOUT, $message);
    }

    public function writeln(string $message = ''): void
    {
        $this->write($message . PHP_EOL);
    }

    public function error(string $message): void
    {
        $this->writeTo($this->stderr ?? STDERR, $message);
    }

    public function errorln(string $message = ''): void
    {
        $this->error($message . PHP_EOL);
    }

    /** @param resource $stream */
    private function writeTo(mixed $stream, string $message): void
    {
        fwrite($stream, $message);
    }
}
