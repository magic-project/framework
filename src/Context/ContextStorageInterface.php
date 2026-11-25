<?php

declare(strict_types=1);

namespace Magic\Context;

use Magic\Http\Request;

interface ContextStorageInterface
{
    public function begin(Request $request): RequestContext;

    public function current(): RequestContext;

    public function end(): void;

    public function isActive(): bool;
}
