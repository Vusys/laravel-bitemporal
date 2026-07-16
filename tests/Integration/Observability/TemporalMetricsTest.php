<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Observability;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Events\TemporalOverlapPrevented;
use Vusys\Bitemporal\Observability\NullMetrics;
use Vusys\Bitemporal\Observability\TemporalMetrics;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class TemporalMetricsTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_null_metrics_is_bound_by_default(): void
    {
        $this->assertInstanceOf(NullMetrics::class, resolve(TemporalMetrics::class));
    }

    public function test_a_bound_spy_receives_write_metrics(): void
    {
        $spy = new SpyMetrics;
        app()->instance(TemporalMetrics::class, $spy);

        CarbonImmutable::setTestNow('2026-08-01 00:00:00');
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        // Correcting the later segment back to 1000 closes/inserts rows and
        // compacts the two adjacent equivalent segments into one.
        $product->prices()->correct(['amount' => 1000], validFrom: '2026-06-01');

        // Row churn.
        $this->assertNotEmpty($spy->rowsInserted, 'rowsInserted should have fired');
        $this->assertGreaterThanOrEqual(1, $spy->rowsInserted[0]['count']);
        $this->assertNotEmpty($spy->rowsClosed, 'rowsClosed should have fired');

        // Compaction with the real before/after segment counts (2 -> 1).
        $this->assertNotEmpty($spy->compaction, 'compactionPerformed should have fired');
        $this->assertSame(2, $spy->compaction[0]['before']);
        $this->assertSame(1, $spy->compaction[0]['after']);

        // Timing.
        $this->assertNotEmpty($spy->lockWaits, 'lockWaitMs should have fired');
        $this->assertNotEmpty($spy->latencies, 'writeLatency should have fired');

        // Tags carry model / operation / engine on every call.
        foreach ([$spy->rowsInserted[0]['tags'], $spy->compaction[0]['tags'], $spy->lockWaits[0]['tags']] as $tags) {
            $this->assertArrayHasKey('model', $tags);
            $this->assertArrayHasKey('operation', $tags);
            $this->assertArrayHasKey('engine', $tags);
        }
    }

    public function test_overlap_prevented_event_is_translated_to_a_metric(): void
    {
        $spy = new SpyMetrics;
        app()->instance(TemporalMetrics::class, $spy);

        $product = $this->makeProduct();
        event(new TemporalOverlapPrevented(ProductPrice::class, $product, []));

        $this->assertNotEmpty($spy->overlaps, 'overlapPrevented should have fired');
        $this->assertArrayHasKey('engine', $spy->overlaps[0]);
    }

    public function test_null_metrics_write_path_is_silent(): void
    {
        // With NullMetrics (the default) a write must not error and emits nothing
        // observable — the spy is never bound.
        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1200], validFrom: '2026-01-01');

        $this->assertInstanceOf(NullMetrics::class, resolve(TemporalMetrics::class));
    }
}
