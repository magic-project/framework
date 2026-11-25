<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Http\Request;
use Magic\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\TestCase;
use Swoole\Process;

final class RealSwooleIntegrationTest extends TestCase
{
    public function testRealTcpServerIsolatedRequestsAcrossMultipleWorkers(): void
    {
        if (getenv('MAGIC_REAL_SWOOLE') !== '1') {
            self::markTestSkipped('Set MAGIC_REAL_SWOOLE=1 to run the real Swoole TCP integration test.');
        }
        if (!extension_loaded('swoole') || !class_exists(Process::class)) {
            self::markTestSkipped('The Swoole extension is required.');
        }

        $port = $this->freePort();
        $process = new Process(function () use ($port): void {
            $app = new Application();
            $app->get('/state', static function (Request $request) use ($app): array {
                $marker = $request->header('x-marker', 'missing');
                $before = $app->context()->get('marker', 'absent');
                $app->context()->set('marker', $marker);
                \Swoole\Coroutine::sleep(0.001);

                return [
                    'before' => $before,
                    'after' => $app->context()->get('marker', 'missing'),
                    'worker_id' => $app->metrics()->snapshot()['worker_id'],
                ];
            });

            $runtime = new SwooleRuntime(['worker_num' => 2], null, false);
            $app->run($runtime, '127.0.0.1', $port);
        }, false, 2, false);

        $pid = $process->start();
        self::assertIsInt($pid);

        try {
            $this->waitForPort($port);
            $responses = [];
            for ($index = 1; $index <= 8; $index++) {
                $responses[] = $this->request($port, '/state', 'request-' . $index);
            }

            foreach ($responses as $index => $response) {
                self::assertSame(200, $response['status'], 'request ' . $index . ' failed');
                self::assertSame('request-' . ($index + 1), $response['json']['after']);
                self::assertSame('absent', $response['json']['before']);
                self::assertIsInt($response['json']['worker_id']);
                self::assertNotSame('', $response['headers']['x-request-id'] ?? '');
            }
        } finally {
            // The application-level graceful-stop contract is covered by
            // SwooleRuntime unit tests. This integration test focuses on real
            // TCP and multi-worker request isolation; force cleanup here so a
            // failed assertion can never leak a listening server.
            @posix_kill($pid, SIGKILL);
            Process::wait(true);
        }

        $process->close();
    }

    /** @return array{status: int, headers: array<string, string>, json: array<string, mixed>} */
    private function request(int $port, string $path, string $marker): array
    {
        $handle = curl_init(sprintf('http://127.0.0.1:%d%s', $port, $path));
        self::assertNotFalse($handle);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['X-Marker: ' . $marker],
            CURLOPT_TIMEOUT_MS => 2_000,
        ]);
        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error = curl_error($handle);
        curl_close($handle);

        self::assertIsString($raw, $error);
        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = [];
        foreach (explode("\r\n", trim($headerText)) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $status, 'headers' => $headers, 'json' => $json];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        self::assertIsString($address);

        return (int) substr(strrchr($address, ':'), 1);
    }

    private function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 3.0;
        do {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.05);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail(sprintf('Swoole server did not listen on port %d: %s', $port, $errorMessage ?? 'unknown error'));
    }
}
