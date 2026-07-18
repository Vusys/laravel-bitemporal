<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Locking\WriteLockHandle;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins issue #49: a lock handle whose release() throws (the hazard the
 * AdvisoryLocker connection-changed guard represents) must not mask the write's
 * real outcome from the finally block. releaseQuietly() swallows and logs it.
 */
final class LockReleaseSafetyTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_throwing_lock_release_does_not_fail_a_committed_write(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        app()->instance(WriteLocker::class, new class implements WriteLocker
        {
            public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000): WriteLockHandle
            {
                return new class implements WriteLockHandle
                {
                    private bool $held = true;

                    public function release(): void
                    {
                        $this->held = false;

                        throw new RuntimeException('release boom');
                    }

                    public function isHeld(): bool
                    {
                        return $this->held;
                    }

                    public function strategy(): string
                    {
                        return 'fake';
                    }
                };
            }
        });

        $product = $this->makeProduct();

        // Under the old `$handle?->release()` in finally, the release throw would
        // propagate and this call would raise RuntimeException even though the
        // transaction committed. It must now return the committed result.
        $committed = $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01');

        $this->assertSame(1, $committed->insertedCount());
        $this->assertSame(1, ProductPrice::query()->where('product_id', $product->getKey())->count());
    }
}
