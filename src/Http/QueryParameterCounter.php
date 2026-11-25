<?php

declare(strict_types=1);

namespace Magic\Http;

final class QueryParameterCounter
{
    /**
     * Count parameters from the raw query string, before PHP/Swoole parsing.
     *
     * Empty fields created by a leading, trailing, or repeated ampersand are
     * ignored. Encoded ampersands (for example, "%26") remain part of the
     * parameter value and therefore do not create another parameter.
     */
    public static function count(string $queryString): int
    {
        if ($queryString === '') {
            return 0;
        }

        $count = 0;
        foreach (explode('&', $queryString) as $field) {
            if ($field !== '') {
                $count++;
            }
        }

        return $count;
    }

    public static function exceeds(string $queryString, int $maximum): bool
    {
        if ($queryString === '') {
            return false;
        }

        $count = 0;
        foreach (explode('&', $queryString) as $field) {
            if ($field === '') {
                continue;
            }

            if (++$count > $maximum) {
                return true;
            }
        }

        return false;
    }
}
