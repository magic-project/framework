<?php

declare(strict_types=1);

namespace Magic\Exception;

use Magic\Http\Request;
use Magic\Http\Response;
use Throwable;

interface ExceptionHandlerInterface
{
    public function render(Throwable $exception, Request $request): Response;
}
