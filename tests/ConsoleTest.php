<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Console\CommandInput;
use Magic\Console\CommandInterface;
use Magic\Console\CommandOutput;
use Magic\Console\CommandRegistry;
use Magic\Console\CommandRunner;
use Magic\Logger\StreamLogger;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    public function testCommandRunsWithArgumentsAndOptions(): void
    {
        $registry = new CommandRegistry(new \Magic\Container\Container());
        $registry->register(new class implements CommandInterface {
            public function name(): string { return 'greet'; }
            public function description(): string { return 'Greet a user.'; }
            public function execute(CommandInput $input, CommandOutput $output): int
            {
                $output->writeln(sprintf('%s:%s', $input->argument(0), $input->option('loud')));

                return 7;
            }
        });
        $runner = new CommandRunner($registry, new StreamLogger());
        $stdout = tmpfile();
        $stderr = tmpfile();

        $exitCode = $runner->run(['magic', 'greet', 'alice', '--loud=yes'], new CommandOutput($stdout, $stderr));

        rewind($stdout);
        self::assertSame(7, $exitCode);
        self::assertSame("alice:yes\n", stream_get_contents($stdout));
        fclose($stdout);
        fclose($stderr);
    }

    public function testUnknownCommandReturnsUsageError(): void
    {
        $registry = new CommandRegistry(new \Magic\Container\Container());
        $registry->register(new class implements CommandInterface {
            public function name(): string { return 'known'; }
            public function description(): string { return 'Known command.'; }
            public function execute(CommandInput $input, CommandOutput $output): int { return 0; }
        });
        $runner = new CommandRunner($registry, new StreamLogger());
        $stdout = tmpfile();
        $stderr = tmpfile();

        $exitCode = $runner->run(['magic', 'missing'], new CommandOutput($stdout, $stderr));

        rewind($stderr);
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Unknown command: missing', stream_get_contents($stderr));
        fclose($stdout);
        fclose($stderr);
    }

    public function testGlobalHelpAndVersionFlagsWorkBeforeACommand(): void
    {
        $runner = new CommandRunner(
            new CommandRegistry(new \Magic\Container\Container()),
            new StreamLogger(),
        );
        $stdout = tmpfile();
        $stderr = tmpfile();

        self::assertSame(0, $runner->run(['magic', '--help'], new CommandOutput($stdout, $stderr)));
        self::assertSame(0, $runner->run(['magic', '-V'], new CommandOutput($stdout, $stderr)));

        rewind($stdout);
        $contents = stream_get_contents($stdout);
        self::assertStringContainsString('Available commands:', $contents);
        self::assertStringContainsString('Magic Framework', $contents);
        fclose($stdout);
        fclose($stderr);
    }

    public function testApplicationProvidesCommandServicesAndShutdownRuns(): void
    {
        $app = new Application();
        $events = [];
        $app->onShutdown(static function () use (&$events): void { $events[] = 'shutdown'; });

        self::assertInstanceOf(CommandRegistry::class, $app->container()->get(CommandRegistry::class));
        self::assertInstanceOf(CommandRunner::class, $app->container()->get(CommandRunner::class));

        $app->boot();
        $app->shutdown();

        self::assertSame(['shutdown'], $events);
    }

    public function testCommandRegistryFreezesWithApplicationBoot(): void
    {
        $app = new Application();
        $registry = $app->container()->get(CommandRegistry::class);
        $app->boot();

        self::assertTrue($registry->isFrozen());
        $this->expectException(\RuntimeException::class);
        $registry->register(new class implements CommandInterface {
            public function name(): string { return 'late'; }
            public function description(): string { return 'Late command.'; }
            public function execute(CommandInput $input, CommandOutput $output): int { return 0; }
        });
    }

    public function testStartCommandIsRegisteredByTheCoreProvider(): void
    {
        $app = new Application();
        $registry = $app->container()->get(CommandRegistry::class);

        self::assertTrue($registry->has('start'));
        self::assertSame('Start the application with the Swoole HTTP runtime.', $registry->get('start')->description());
    }

    public function testHelpAndVersionCommandsAreRegistered(): void
    {
        $app = new Application();
        $registry = $app->container()->get(CommandRegistry::class);
        $runner = $app->container()->get(CommandRunner::class);
        $stdout = tmpfile();
        $stderr = tmpfile();

        self::assertTrue($registry->has('help'));
        self::assertTrue($registry->has('version'));
        self::assertSame(0, $runner->run(['magic', 'version'], new CommandOutput($stdout, $stderr)));

        rewind($stdout);
        self::assertSame("Magic Framework 0.1.0-dev\n", stream_get_contents($stdout));
        fclose($stdout);
        fclose($stderr);
    }
}
