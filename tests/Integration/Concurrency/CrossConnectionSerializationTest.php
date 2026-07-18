<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\SecondConnectionPrice;

/**
 * Pins #67: when the temporal-rows model and its parent entity resolve to two
 * distinct connection names pointing at the same database, the advisory write
 * lock must be taken on the connection the write transaction actually runs on
 * (the rows model's), not on the entity's connection.
 *
 * The test is deterministic across engines because it exploits the exact bug:
 * a holder takes the lock on the ENTITY connection's session and keeps that
 * transaction open, then a real cross-connection write runs. Post-fix the write
 * locks on its own (second) session and blocks against the holder; pre-fix it
 * locked on the entity session, where the same-session lock is re-entrant and
 * the write would sail through — interleaving concurrent writers and leaving
 * overlapping current-known rows.
 *
 * SQLite cannot express this (a second connection is a separate in-memory
 * database and advisory locks are no-ops), so it skips there.
 */
final class CrossConnectionSerializationTest extends ConcurrencyTestCase
{
    // The holder blocks the waiter for the writer's bounded lock wait (~1s). A
    // 600ms floor proves the waiter genuinely blocked rather than failing fast.
    private const int MIN_WAIT_MS = 600;

    protected function setUp(): void
    {
        parent::setUp();

        // Reach the cross-connection write path directly: the boot guard would
        // otherwise reject the model, and this is the guard-bypass path #67
        // hardens as defence-in-depth.
        config(['bitemporal.guards.enabled' => false]);
        config(['bitemporal.writes.lock_strategy' => 'advisory']);
        // Bound the waiter's advisory wait so the blocked write fails fast.
        config(['bitemporal.writes.advisory_lock_timeout_ms' => 1000]);

        // The WriteLocker binding is resolved at boot from the default
        // ('parent_row') strategy, so the config change above is too late to
        // switch it. Rebind explicitly so the write path takes the same advisory
        // lock the holder does.
        app()->bind(WriteLocker::class, AdvisoryLocker::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_cross_connection_write_takes_the_lock_on_the_write_connection(): void
    {
        $this->skipUnlessContentionCapable();

        // 'temporal_second' points at the same database as the default; the
        // SecondConnectionPrice model is pinned to it while Product stays on the
        // default connection.
        $this->secondConnection();

        CarbonImmutable::setTestNow('2025-12-01 00:00:00');
        $product = $this->makeProduct();
        $product->secondConnectionPrices()->changeEffectiveFrom(['amount' => 1000], '2026-01-01');

        // Holder: take the advisory write lock for this entity on the ENTITY
        // connection's session (a distinct session from the second connection)
        // and keep the transaction open.
        $holder = DB::connection();
        $holder->beginTransaction();
        $handle = (new AdvisoryLocker)->lockFor($product, [], 5000, $holder);

        $conflict = null;
        $elapsedMs = 0.0;

        try {
            $start = microtime(true);

            try {
                // The write runs its transaction on 'temporal_second' and — post
                // fix — takes its lock there, colliding with the holder.
                $product->secondConnectionPrices()->changeEffectiveFrom(['amount' => 1200], '2026-06-01');
            } catch (TemporalWriteConflictException $exception) {
                $conflict = $exception;
            }

            $elapsedMs = (microtime(true) - $start) * 1000;
        } finally {
            $handle->release();
            $holder->rollBack();
        }

        $this->assertNotNull(
            $conflict,
            'the cross-connection write must block on the advisory lock held by the entity session; '
            .'if it locked on the entity connection instead, the same-session lock is re-entrant and it would not block',
        );
        $this->assertGreaterThanOrEqual(self::MIN_WAIT_MS, $elapsedMs, 'the writer should have blocked on the lock, not failed instantly');

        // The blocked write left the timeline untouched: still one open segment.
        $this->assertSame(1, SecondConnectionPrice::query()->where('product_id', $product->getKey())->currentKnowledge()->count());

        // With the holder gone the write proceeds and the timeline stays
        // overlap-free — no two current-known segments cover the same instant.
        $product->secondConnectionPrices()->changeEffectiveFrom(['amount' => 1200], '2026-06-01');
        $this->assertNoOverlappingCurrentRows($product);
    }

    private function assertNoOverlappingCurrentRows(Product $product): void
    {
        $segments = SecondConnectionPrice::query()
            ->where('product_id', $product->getKey())
            ->currentKnowledge()
            ->orderBy('valid_from')
            ->get(['valid_from', 'valid_to']);

        $previousTo = null;
        foreach ($segments as $segment) {
            if ($previousTo !== null) {
                $this->assertTrue(
                    $segment->valid_from->greaterThanOrEqualTo($previousTo),
                    'current-known segments must not overlap on the valid axis',
                );
            }

            $previousTo = $segment->valid_to;
        }
    }
}
