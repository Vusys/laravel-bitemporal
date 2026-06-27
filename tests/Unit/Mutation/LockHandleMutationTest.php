<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Mutation;

use Vusys\Bitemporal\Locking\ReleasableLock;
use Vusys\Bitemporal\Locking\TransactionLockHandle;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Pins surviving mutants in build/mutants/src__Locking__ReleasableLock.txt and
 * src__Locking__TransactionLockHandle.txt.
 */
final class LockHandleMutationTest extends TestCase
{
    public function test_releasable_lock_releases_once_then_is_idempotent(): void
    {
        $calls = 0;
        $lock = new ReleasableLock('advisory', function () use (&$calls): void {
            $calls++;
        });

        $this->assertTrue($lock->isHeld());

        $lock->release();

        // FunctionCallRemoval drops the releaser call (calls stays 0); LogicalNot
        // (`if ($this->held)`) returns early and also never releases; FalseValue
        // (`held = true`) leaves the lock held.
        $this->assertSame(1, $calls);
        $this->assertFalse($lock->isHeld());

        // Idempotent: a second release must NOT fire the releaser again. If
        // FalseValue kept held=true, this would bump calls to 2.
        $lock->release();
        $this->assertSame(1, $calls);
    }

    public function test_transaction_lock_handle_is_no_longer_held_after_release(): void
    {
        $handle = new TransactionLockHandle('parent_row');

        $this->assertTrue($handle->isHeld());

        $handle->release();

        // FalseValue flips `held = false` to `held = true`.
        $this->assertFalse($handle->isHeld());
        $this->assertSame('parent_row', $handle->strategy());
    }
}
