<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency;

use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;

/**
 * Proves the advisory-lock timeout is genuinely honoured on real engines
 * (MySQL/MariaDB via GET_LOCK's wait argument, PostgreSQL via SET LOCAL
 * lock_timeout), and that writes.advisory_lock_timeout_ms is threaded through
 * the writer. Gated to non-SQLite; skipped with a reason otherwise.
 */
final class AdvisoryLockTimeoutTest extends ConcurrencyTestCase
{
    // GET_LOCK is second-granular, so the shortest honest MySQL wait is 1s.
    private const WAITER_TIMEOUT_MS = 1000;

    // Lower bound proving the waiter actually blocked rather than failing fast.
    private const MIN_WAIT_MS = 400;

    public function test_second_writer_times_out_while_first_holds_the_advisory_lock(): void
    {
        $this->skipUnlessContentionCapable();

        $product = $this->makeProduct();
        $dimensions = ['region' => 'eu'];
        $locker = new AdvisoryLocker;

        $second = $this->secondConnection();

        // Hold the lock on a genuinely separate session.
        $holderEntity = Product::on(self::SECOND_CONNECTION)->whereKey($product->getKey())->firstOrFail();
        $second->beginTransaction();
        $holderHandle = $locker->lockFor($holderEntity, $dimensions, 5000);

        try {
            $start = microtime(true);
            $conflict = null;

            try {
                // The PG branch requires an open transaction for SET LOCAL /
                // pg_advisory_xact_lock; MySQL GET_LOCK is happy inside one too.
                DB::transaction(function () use ($locker, $product, $dimensions): void {
                    $locker->lockFor($product, $dimensions, self::WAITER_TIMEOUT_MS);
                });
            } catch (TemporalWriteConflictException $exception) {
                $conflict = $exception;
            }

            $elapsedMs = (microtime(true) - $start) * 1000;

            $this->assertNotNull($conflict, 'the second writer should have failed to acquire the held lock');
            $this->assertStringContainsStringIgnoringCase('lock', $conflict->getMessage());
            $this->assertGreaterThanOrEqual(
                self::MIN_WAIT_MS,
                $elapsedMs,
                'the waiter should have blocked for roughly the timeout, proving real contention',
            );
        } finally {
            $holderHandle->release();
            $second->rollBack();
        }

        // With the holder gone the same acquisition now succeeds immediately.
        DB::transaction(function () use ($locker, $product, $dimensions): void {
            $handle = $locker->lockFor($product, $dimensions, 2000);
            $this->assertTrue($handle->isHeld());
            $handle->release(); // never leak a session-scoped GET_LOCK across tests
        });
    }

    public function test_writer_threads_advisory_lock_timeout_config_into_get_lock(): void
    {
        // GET_LOCK's wait argument is only observable on MySQL/MariaDB.
        $this->requireDriver('mysql', 'mariadb');

        config([
            'bitemporal.writes.lock_strategy' => 'advisory',
            'bitemporal.writes.advisory_lock_timeout_ms' => 3000,
        ]);
        app()->bind(WriteLocker::class, AdvisoryLocker::class);

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $captured = [];
        DB::connection()->listen(function ($query) use (&$captured): void {
            if (str_contains($query->sql, 'GET_LOCK')) {
                $captured[] = $query->bindings;
            }
        });

        $product->prices()->correct(['amount' => 1200], validFrom: '2026-02-01');

        $this->assertNotEmpty($captured, 'a write under the advisory strategy must acquire GET_LOCK');
        // ceil(3000 / 1000) = 3 seconds — the config value, not the 5000 default.
        $this->assertSame(3, $captured[0][1]);
    }
}
