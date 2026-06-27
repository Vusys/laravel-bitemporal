<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency\Mutation;

use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Locking\ParentRowLocker;
use Vusys\Bitemporal\Locking\TransactionLockHandle;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins the surviving mutants in build/mutants/src__Locking__ParentRowLocker.txt.
 * The IncrementInteger/DecrementInteger mutants on the unused $timeoutMs default
 * are equivalent (the parameter is never read on the parent-row path).
 */
final class ParentRowLockerMutationTest extends IntegrationTestCase
{
    public function test_lock_succeeds_for_an_existing_entity_row(): void
    {
        $product = $this->makeProduct();

        $handle = (new ParentRowLocker)->lockFor($product, []);

        $this->assertInstanceOf(TransactionLockHandle::class, $handle);
        $this->assertTrue($handle->isHeld());
    }

    public function test_lock_throws_when_the_entity_row_is_gone(): void
    {
        $product = $this->makeProduct();
        Product::query()->whereKey($product->getKey())->delete();

        // SELECT ... FOR UPDATE finds no row => the locker must throw. The uncovered
        // Throw_ mutant drops the throw and returns a handle instead.
        $this->expectException(TemporalWriteConflictException::class);
        (new ParentRowLocker)->lockFor($product, []);
    }
}
