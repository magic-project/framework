<?php

declare(strict_types=1);

namespace Magic\Runtime;

/**
 * Small in-process metrics registry for long-running runtimes.
 *
 * The registry exposes snapshots instead of choosing a metrics backend.
 */
final class RuntimeMetrics
{
    /** @var array<string, int|\Swoole\Atomic> */
    private array $counters = [];
    private ?int $workerId = null;

    public function __construct()
    {
        $names = [
            'active_requests',
            'requests_total',
            'request_errors_total',
            'request_duration_nanoseconds_total',
            'request_duration_nanoseconds_max',
            'workers_started_total',
            'workers_stopped_total',
        ];

        foreach ($names as $name) {
            $this->counters[$name] = class_exists('Swoole\\Atomic')
                ? new \Swoole\Atomic(0)
                : 0;
        }
    }

    public function requestStarted(): void
    {
        $this->add('active_requests');
        $this->add('requests_total');
    }

    public function requestFinished(int $durationNanoseconds, bool $failed = false): void
    {
        $durationNanoseconds = max(0, $durationNanoseconds);
        $this->decrementActiveRequests();
        $this->add('request_duration_nanoseconds_total', $durationNanoseconds);
        $this->max('request_duration_nanoseconds_max', $durationNanoseconds);

        if ($failed) {
            $this->add('request_errors_total');
        }
    }

    public function workerStarted(int $workerId): void
    {
        $this->workerId = $workerId;
        $this->add('workers_started_total');
    }

    public function workerStopped(int $workerId): void
    {
        $this->workerId = $workerId;
        $this->add('workers_stopped_total');
    }

    /** @return array<string, int|float|null> */
    public function snapshot(): array
    {
        return [
            'active_requests' => $this->value('active_requests'),
            'requests_total' => $this->value('requests_total'),
            'request_errors_total' => $this->value('request_errors_total'),
            'request_duration_seconds_total' => $this->value('request_duration_nanoseconds_total') / 1_000_000_000,
            'request_duration_seconds_max' => $this->value('request_duration_nanoseconds_max') / 1_000_000_000,
            'workers_started_total' => $this->value('workers_started_total'),
            'workers_stopped_total' => $this->value('workers_stopped_total'),
            'worker_id' => $this->workerId,
        ];
    }

    private function add(string $name, int $delta = 1): void
    {
        $counter = $this->counters[$name];
        if (is_object($counter)) {
            $counter->add($delta);

            return;
        }

        $this->counters[$name] = $counter + $delta;
    }

    private function value(string $name): int
    {
        $counter = $this->counters[$name];

        return is_object($counter) ? $counter->get() : $counter;
    }

    private function decrementActiveRequests(): void
    {
        $counter = $this->counters['active_requests'];
        if (!is_object($counter)) {
            $this->counters['active_requests'] = max(0, $counter - 1);

            return;
        }

        do {
            $current = $counter->get();
            if ($current <= 0) {
                return;
            }
        } while (!$counter->cmpset($current, $current - 1));
    }

    private function max(string $name, int $candidate): void
    {
        $counter = $this->counters[$name];
        if (!is_object($counter)) {
            $this->counters[$name] = max($counter, $candidate);

            return;
        }

        do {
            $current = $counter->get();
            if ($candidate <= $current) {
                return;
            }
        } while (!$counter->cmpset($current, $candidate));
    }
}
