<?php

declare(strict_types=1);

namespace Magic\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

final class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private readonly StreamInterface $stream,
        private readonly ?int $size = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        if ($this->error < UPLOAD_ERR_OK || $this->error > UPLOAD_ERR_EXTENSION) {
            throw new InvalidArgumentException('Invalid uploaded file error code.');
        }
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }

        return $this->stream;
    }

    public function moveTo(string $targetPath): void
    {
        if ($targetPath === '') {
            throw new InvalidArgumentException('Uploaded file target path cannot be empty.');
        }
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }

        $expectedBytes = $this->getSize();
        $sourcePosition = null;
        try {
            $sourcePosition = $this->stream->tell();
        } catch (Throwable) {
            // Some stream implementations do not expose a position.
        }
        if ($expectedBytes !== null && $sourcePosition !== null) {
            $expectedBytes = max(0, $expectedBytes - $sourcePosition);
        }

        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            throw new RuntimeException(sprintf('Unable to open uploaded file target [%s].', $targetPath));
        }
        try {
            $source = $this->stream->detach();
            if (is_resource($source)) {
                $written = stream_copy_to_stream($source, $target);
                fclose($source);
            } else {
                $contents = (string) $this->stream;
                $written = fwrite($target, $contents);
                if ($written !== false && $written !== strlen($contents)) {
                    $written = false;
                }
            }
            if ($written === false || ($expectedBytes !== null && $written !== $expectedBytes)) {
                throw new RuntimeException(sprintf('Unable to write uploaded file target [%s] completely.', $targetPath));
            }
        } finally {
            fclose($target);
        }

        $this->moved = true;
    }

    public function getSize(): ?int { return $this->size ?? $this->stream->getSize(); }
    public function getError(): int { return $this->error; }
    public function getClientFilename(): ?string { return $this->clientFilename; }
    public function getClientMediaType(): ?string { return $this->clientMediaType; }
}
