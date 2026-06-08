<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

interface WriteLockHandle
{
    public function release(): void;

    public function isHeld(): bool;

    public function strategy(): string;
}
