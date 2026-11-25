<?php

declare(strict_types=1);

namespace Magic\Container;

use Psr\Container\ContainerExceptionInterface;

final class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
}
