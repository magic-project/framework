<?php

declare(strict_types=1);

namespace Magic\Container;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
}
