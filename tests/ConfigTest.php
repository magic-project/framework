<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Config\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    public function testEnvironmentValuesAreParsedWithSafeDefaults(): void
    {
        $config = Config::fromEnvironment([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true',
            'APP_SERVICE' => 'demo',
            'HTTP_MAX_BODY_BYTES' => '2048',
            'HTTP_MAX_HEADER_BYTES' => '4096',
            'SERVER_HOST' => '0.0.0.0',
            'SERVER_PORT' => '19501',
            'RUNTIME_SHUTDOWN_TIMEOUT' => '7',
        ]);

        self::assertSame('testing', $config->get('app.env'));
        self::assertTrue($config->get('app.debug'));
        self::assertSame('demo', $config->get('app.service'));
        self::assertSame(2048, $config->get('http.max_body_bytes'));
        self::assertSame(4096, $config->get('http.max_header_bytes'));
        self::assertSame('0.0.0.0', $config->get('server.host'));
        self::assertSame(19501, $config->get('server.port'));
        self::assertSame(7, $config->get('runtime.shutdown_timeout'));
    }

    public function testInvalidEnvironmentValuesUseDefaults(): void
    {
        $config = Config::fromEnvironment([
            'APP_DEBUG' => 'not-a-bool',
            'HTTP_MAX_BODY_BYTES' => '-1',
            'HTTP_MAX_HEADER_BYTES' => '0',
        ]);

        self::assertFalse($config->get('app.debug'));
        self::assertSame(1_048_576, $config->get('http.max_body_bytes'));
        self::assertSame(65_536, $config->get('http.max_header_bytes'));
    }

    public function testEnvironmentSnapshotIsAnArray(): void
    {
        self::assertIsArray(Config::environment());
    }

    public function testConfigCanBeLoadedFromFile(): void
    {
        $file = $this->writeConfigFile('<?php return ["app" => ["env" => "testing"], "custom" => 42];');

        $config = Config::fromFile($file);

        self::assertSame('testing', $config->get('app.env'));
        self::assertSame(42, $config->get('custom'));
    }

    public function testConfigFileEnvironmentOverridesOnlyPresentKeys(): void
    {
        $file = $this->writeConfigFile('<?php return ["app" => ["env" => "production", "debug" => false], "custom" => true];');

        $config = Config::fromFile($file, ['APP_ENV' => 'staging']);

        self::assertSame('staging', $config->get('app.env'));
        self::assertFalse($config->get('app.debug'));
        self::assertTrue($config->get('custom'));
    }

    public function testMissingConfigFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Config::fromFile(sys_get_temp_dir() . '/magic-config-that-does-not-exist.php');
    }

    public function testConfigFileMustReturnArray(): void
    {
        $file = $this->writeConfigFile('<?php return "not-an-array";');

        $this->expectException(RuntimeException::class);
        Config::fromFile($file);
    }

    private function writeConfigFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'magic-config-');
        self::assertNotFalse($file);

        file_put_contents($file, $contents);

        // Clean up the temporary file once the test finishes.
        $cleanup = static function () use ($file): void {
            @unlink($file);
        };
        register_shutdown_function($cleanup);

        return $file;
    }

    public function testDotNotationAndRequiredValues(): void
    {
        $config = Config::fromArray(['database' => ['dsn' => 'sqlite::memory:']]);

        self::assertTrue($config->has('database.dsn'));
        self::assertSame('sqlite::memory:', $config->require('database.dsn'));
        self::assertSame('fallback', $config->get('missing', 'fallback'));

        $this->expectException(RuntimeException::class);
        $config->require('database.user');
    }

    public function testEmptyKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Config::fromArray([])->get('');
    }
}
