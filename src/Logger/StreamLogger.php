<?php

declare(strict_types=1);

namespace Magic\Logger;

use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

final class StreamLogger extends AbstractLogger
{
    public function __construct(
        private readonly int $maxStringBytes = 2_048,
        private readonly int $maxDepth = 4,
        private readonly int $maxItems = 100,
    ) {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $record = [
            'time' => gmdate('c'),
            'level' => (string) $level,
            'message' => $this->truncate($this->interpolate($this->safeString($message), $context)),
        ];

        if ($context !== []) {
            $record['context'] = $this->normalizeContext($context);
        }

        error_log((string) json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replace['{' . $key . '}'] = $this->safeString($value);
            }
        }

        return strtr($message, $replace);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $normalized[$key] = [
                    'type' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
                continue;
            }

            $normalized[(string) $key] = $this->normalizeValue($value, 0);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return strlen($value) > $this->maxStringBytes
                ? substr($value, 0, $this->maxStringBytes) . '…'
                : $value;
        }

        if ($value instanceof Stringable) {
            return $this->normalizeValue($this->safeString($value), $depth + 1);
        }

        if ($value instanceof Throwable) {
            return [
                'type' => $value::class,
                'message' => $this->normalizeValue($value->getMessage(), $depth + 1),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }

        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : '[' . get_debug_type($value) . ']';
        }

        if ($depth >= $this->maxDepth) {
            return '[max-depth]';
        }

        $normalized = [];
        $count = 0;
        foreach ($value as $key => $child) {
            if ($count++ >= $this->maxItems) {
                $normalized['…'] = '[truncated]';
                break;
            }
            $normalized[(string) $key] = $this->normalizeValue($child, $depth + 1);
        }

        return $normalized;
    }

    private function safeString(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        try {
            return (string) $value;
        } catch (Throwable $exception) {
            return sprintf('[unstringable:%s]', $exception::class);
        }
    }

    private function truncate(string $value): string
    {
        return strlen($value) > $this->maxStringBytes
            ? substr($value, 0, $this->maxStringBytes) . '…'
            : $value;
    }
}
