<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Diff;

use Vusys\Bitemporal\Diff\TemporalDiffPair;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class DiffTest extends IntegrationTestCase
{
    public function test_diff_timelines_reports_a_changed_row(): void
    {
        $product = $this->makeProduct();
        // Belief held 2026-02-01 .. 2026-03-01: amount 1000.
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => '2026-03-01']);
        // Superseding belief from 2026-03-01: amount 1200.
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-03-01', 'recorded_to' => null]);

        $diff = $product->prices()->diffTimelines(fromKnownAt: '2026-02-20', toKnownAt: '2026-03-10');

        $this->assertCount(0, $diff->added);
        $this->assertCount(0, $diff->removed);
        $this->assertCount(1, $diff->changed);
        $this->assertFalse($diff->isEmpty());

        $pair = $diff->changed->first();
        $this->assertInstanceOf(TemporalDiffPair::class, $pair);
        $this->assertSame(1000, $pair->from->getAttribute('amount'));
        $this->assertSame(1200, $pair->to->getAttribute('amount'));
        $this->assertContains('amount', $pair->changedAttributes);
    }

    public function test_diff_timelines_reports_added_window(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01', 'recorded_from' => '2026-02-01', 'recorded_to' => null]);
        // A second valid window only believed from 2026-03-01.
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null, 'recorded_from' => '2026-03-01', 'recorded_to' => null]);

        $diff = $product->prices()->diffTimelines(fromKnownAt: '2026-02-20', toKnownAt: '2026-03-10');

        $this->assertCount(1, $diff->added);
        $this->assertCount(0, $diff->removed);
        $this->assertCount(1, $diff->unchanged);
        $this->assertSame(1200, $diff->added->first()?->getAttribute('amount'));
    }

    public function test_diff_knowledge_for_a_single_valid_date(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => '2026-03-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-03-01', 'recorded_to' => null]);

        $diff = $product->prices()->diffKnowledge(validAt: '2026-06-01', fromKnownAt: '2026-02-20', toKnownAt: '2026-03-10');

        $this->assertCount(1, $diff->changed);
        $pair = $diff->changed->first();
        $this->assertNotNull($pair);
        $this->assertContains('amount', $pair->changedAttributes);
    }

    public function test_diff_is_empty_when_belief_did_not_change(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => null]);

        $diff = $product->prices()->diffTimelines(fromKnownAt: '2026-02-20', toKnownAt: '2026-03-10');

        $this->assertTrue($diff->isEmpty());
        $this->assertCount(1, $diff->unchanged);
    }
}
