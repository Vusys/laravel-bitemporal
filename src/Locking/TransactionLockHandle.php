<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

/**
 * Handle for a lock that is released implicitly when the surrounding
 * transaction ends (parent-row and PostgreSQL transaction-scoped advisory
 * locks). release() is a no-op kept for interface symmetry.
 */
final class TransactionLockHandle implements WriteLockHandle
{
    private bool $held = true;

    public function __construct(private readonly string $strategy) {}

    public function release(): void
    {
        $this->held = false;
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
