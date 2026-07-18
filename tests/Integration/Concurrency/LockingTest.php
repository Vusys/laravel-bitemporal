<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Locking\TransactionLockHandle;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Locking\WriteLockHandle;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class LockingTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_configured_write_locker_is_invoked_for_each_write(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $spy = new class implements WriteLocker
        {
            public int $calls = 0;

            public ?ConnectionInterface $connection = null;

            public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000, ?ConnectionInterface $connection = null): WriteLockHandle
            {
                $this->calls++;
                $this->connection = $connection;

                return new TransactionLockHandle('spy');
            }
        };

        app()->instance(WriteLocker::class, $spy);

        $product = $this->makeProduct();
        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01');

        $this->assertSame(1, $spy->calls);

        // Issue #67: the lock must be taken on the connection the write
        // transaction runs on (the temporal-rows model's connection), not left
        // to default to the entity's connection.
        $this->assertNotNull($spy->connection);
        $this->assertSame(
            $product->prices()->getModel()->getConnection(),
            $spy->connection,
        );
    }

    public function test_advisory_strategy_falls_back_on_sqlite_and_still_writes(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        app()->instance(WriteLocker::class, new AdvisoryLocker);

        $product = $this->makeProduct();
        $product->prices()->changeEffectiveFrom(['amount' => 1500], '2026-06-01');

        $this->assertSame(1500, $product->prices()->validAt('2026-09-01')->currentKnowledge()->sole()->amount);
    }
}
