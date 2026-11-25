<?php

declare(strict_types=1);

namespace Magic\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class Stream implements StreamInterface
{
    /** @param resource $resource */
    public function __construct(private $resource)
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Stream resource must be a valid PHP resource.');
        }
    }

    public static function fromString(string $contents): self
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new RuntimeException('Unable to open temporary stream.');
        }

        $stream = new self($resource);
        $stream->write($contents);
        $stream->rewind();

        return $stream;
    }

    public function __toString(): string
    {
        if ($this->resource === null) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            $contents = stream_get_contents($this->resource);

            return $contents === false ? '' : $contents;
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }

        $this->resource = null;
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;

        return $resource;
    }

    public function getSize(): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }

        $stats = fstat($this->resource);

        return is_array($stats) && isset($stats['size']) ? (int) $stats['size'] : null;
    }

    public function tell(): int
    {
        $this->assertResource();
        $position = ftell($this->resource);
        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position.');
        }

        return $position;
    }

    public function eof(): bool
    {
        return !is_resource($this->resource) || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return is_resource($this->resource)
            && (($this->getMetadata('seekable') ?? false) === true);
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertResource();
        if (fseek($this->resource, $offset, $whence) !== 0) {
            throw new RuntimeException('Unable to seek stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        $mode = (string) $this->getMetadata('mode');

        return $mode !== '' && (str_contains($mode, '+') || str_contains($mode, 'w') || str_contains($mode, 'a') || str_contains($mode, 'x'));
    }

    public function write(string $string): int
    {
        $this->assertResource();
        $written = fwrite($this->resource, $string);
        if ($written === false) {
            throw new RuntimeException('Unable to write to stream.');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        $mode = (string) $this->getMetadata('mode');

        return $mode !== '' && (str_contains($mode, '+') || str_contains($mode, 'r'));
    }

    public function read(int $length): string
    {
        $this->assertResource();
        $contents = fread($this->resource, $length);
        if ($contents === false) {
            throw new RuntimeException('Unable to read from stream.');
        }

        return $contents;
    }

    public function getContents(): string
    {
        $this->assertResource();
        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents.');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if (!is_resource($this->resource)) {
            return $key === null ? [] : null;
        }

        $metadata = stream_get_meta_data($this->resource);

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    private function assertResource(): void
    {
        if (!is_resource($this->resource)) {
            throw new RuntimeException('Stream is detached.');
        }
    }
}
