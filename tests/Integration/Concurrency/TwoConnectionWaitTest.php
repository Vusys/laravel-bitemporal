<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Locking\ParentRowLocker;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;

/**
 * The "second writer waits" guarantee: a concurrent writer blocks until the
 * first commits. This can only be expressed against a real engine (SQLite's
 * lockForUpdate()/advisory locks are no-ops), so it is gated to non-SQLite and
 * skipped-with-reason on SQLite.
 *
 * Both lock strategies are covered — parent_row (SELECT ... FOR UPDATE) and
 * advisory (GET_LOCK / pg_advisory_xact_lock). Each test proves the second
 * session genuinely blocks (it fails if locking is removed, because then the
 * second session would acquire immediately) and that the lock releases once the
 * holder commits.
 */
final class TwoConnectionWaitTest extends ConcurrencyTestCase
{
    // Lower bound proving the waiter blocked rather than failing instantly. The
    // engine waits are ~1s (MySQL innodb_lock_wait_timeout / GET_LOCK second
    // granularity / PG lock_timeout), so 700ms leaves generous slack.
    private const MIN_WAIT_MS = 700;

    public function test_parent_row_lock_serialises_a_second_writer(): void
    {
        $this->skipUnlessContentionCapable();

        $product = $this->makeProduct();
        $dimensions = [];
        $locker = new ParentRowLocker;

        $second = $this->secondConnection();
        $holderEntity = Product::on(self::SECOND_CONNECTION)->whereKey($product->getKey())->firstOrFail();

        // Holder takes the FOR UPDATE row lock and keeps its transaction open.
        $second->beginTransaction();
        $locker->lockFor($holderEntity, $dimensions, 5000);

        try {
            [$blocked, $elapsedMs] = $this->timeParentRowWaiter($locker, $product, $dimensions);

            $this->assertTrue($blocked, 'the second writer should have blocked on the held FOR UPDATE row lock');
            $this->assertGreaterThanOrEqual(self::MIN_WAIT_MS, $elapsedMs, 'the waiter should have blocked, not failed instantly');
        } finally {
            $second->commit();
            $this->resetLockWait();
        }

        // The holder has committed: the row lock is gone and the waiter acquires.
        [$blockedAfter] = $this->timeParentRowWaiter($locker, $product, $dimensions);
        $this->assertFalse($blockedAfter, 'the lock must release on commit so the next writer proceeds');
        $this->resetLockWait();
    }

    public function test_advisory_lock_serialises_a_second_writer(): void
    {
        $this->skipUnlessContentionCapable();

        $product = $this->makeProduct();
        $dimensions = ['region' => 'eu'];
        $locker = new AdvisoryLocker;

        $second = $this->secondConnection();
        $holderEntity = Product::on(self::SECOND_CONNECTION)->whereKey($product->getKey())->firstOrFail();

        $second->beginTransaction();
        $holderHandle = $locker->lockFor($holderEntity, $dimensions, 5000);

        try {
            $start = microtime(true);
            $conflict = null;

            try {
                DB::transaction(function () use ($locker, $product, $dimensions): void {
                    $locker->lockFor($product, $dimensions, 1000);
                });
            } catch (TemporalWriteConflictException $exception) {
                $conflict = $exception;
            }

            $elapsedMs = (microtime(true) - $start) * 1000;

            $this->assertNotNull($conflict, 'the second writer should have blocked on the held advisory lock');
            $this->assertGreaterThanOrEqual(self::MIN_WAIT_MS - 400, $elapsedMs, 'the waiter should have blocked, not failed instantly');
        } finally {
            $holderHandle->release();
            $second->rollBack();
        }

        // Holder released: the same acquisition now succeeds and releases cleanly.
        DB::transaction(function () use ($locker, $product, $dimensions): void {
            $handle = $locker->lockFor($product, $dimensions, 2000);
            $this->assertTrue($handle->isHeld());
            $handle->release();
        });
    }

    public function test_the_advisory_write_lock_is_bindable(): void
    {
        // Guards against the WriteLocker binding regressing (pairs with #13's
        // AppGuardLockerBinding); runs on every engine.
        config(['bitemporal.writes.lock_strategy' => 'advisory']);

        $this->assertInstanceOf(WriteLocker::class, app(WriteLocker::class));
    }

    /**
     * Attempt the parent-row lock on the default connection under a bounded
     * engine lock-wait, returning [blocked, elapsedMs].
     *
     * @param  array<string, mixed>  $dimensions
     * @return array{0: bool, 1: float}
     */
    private function timeParentRowWaiter(ParentRowLocker $locker, Product $product, array $dimensions): array
    {
        $this->boundLockWait();

        $start = microtime(true);
        $blocked = false;

        try {
            DB::transaction(function () use ($locker, $product, $dimensions): void {
                $locker->lockFor($product, $dimensions, 1000);
            });
        } catch (QueryException) {
            $blocked = true;
        }

        return [$blocked, (microtime(true) - $start) * 1000];
    }

    private function boundLockWait(): void
    {
        // MySQL/MariaDB honour innodb_lock_wait_timeout (seconds, min 1); PG uses
        // lock_timeout. Applied to the default (waiter) connection.
        match ($this->driver()) {
            'mysql', 'mariadb' => DB::statement('SET SESSION innodb_lock_wait_timeout = 1'),
            'pgsql' => DB::statement("SET lock_timeout = '1000ms'"),
            default => null,
        };
    }

    private function resetLockWait(): void
    {
        match ($this->driver()) {
            'mysql', 'mariadb' => DB::statement('SET SESSION innodb_lock_wait_timeout = 50'),
            'pgsql' => DB::statement('SET lock_timeout = 0'),
            default => null,
        };
    }

    protected function tearDown(): void
    {
        $this->resetLockWait();

        parent::tearDown();
    }
}
