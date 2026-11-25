<?php

declare(strict_types=1);

namespace Magic\Runtime\Swoole;

use Magic\Http\HttpFactory;
use Magic\Http\Stream;
use RuntimeException;

/** Converts Swoole's $_FILES-shaped arrays into PSR-7 uploaded files. */
final class UploadedFileMapper
{
    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public static function fromSwoole(array $files): array
    {
        $factory = new HttpFactory();
        $map = static function (mixed $value) use (&$map, $factory): mixed {
            if (!is_array($value)) {
                throw new RuntimeException('Swoole uploaded file data must be an array.');
            }

            if (self::isFileRecord($value)) {
                $error = (int) ($value['error'] ?? UPLOAD_ERR_NO_FILE);
                $path = (string) ($value['tmp_name'] ?? '');
                $stream = null;
                if ($path !== '') {
                    $resource = @fopen($path, 'rb');
                    if ($resource !== false) {
                        $stream = new Stream($resource);
                    } elseif ($error === UPLOAD_ERR_OK) {
                        throw new RuntimeException(sprintf('Unable to open uploaded file [%s].', $path));
                    }
                }
                $stream ??= Stream::fromString('');

                return $factory->createUploadedFile(
                    $stream,
                    isset($value['size']) ? (int) $value['size'] : null,
                    $error,
                    isset($value['name']) ? (string) $value['name'] : null,
                    isset($value['type']) ? (string) $value['type'] : null,
                );
            }

            return array_map($map, $value);
        };

        return array_map($map, $files);
    }

    /** @param array<string, mixed> $value */
    private static function isFileRecord(array $value): bool
    {
        return array_key_exists('tmp_name', $value)
            || array_key_exists('error', $value)
            || (array_key_exists('name', $value) && array_key_exists('size', $value));
    }
}
