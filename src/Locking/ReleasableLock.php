<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

use Closure;

/**
 * A lock that must be released explicitly (MySQL GET_LOCK is session-scoped, not
 * transaction-scoped). release() is idempotent.
 */
final class ReleasableLock implements WriteLockHandle
{
    private bool $held = true;

    /**
     * @param  Closure(): void  $releaser
     */
    public function __construct(
        private readonly string $strategy,
        private readonly Closure $releaser,
    ) {}

    public function release(): void
    {
        if (! $this->held) {
            return;
        }

        $this->held = false;
        ($this->releaser)();
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    public function strategy(): string
    {
        return $this->strategy;
    }
}
