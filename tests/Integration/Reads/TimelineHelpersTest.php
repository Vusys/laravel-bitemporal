<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads;

use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Bitemporal\Timeline;

final class TimelineHelpersTest extends IntegrationTestCase
{
    public function test_as_timeline_returns_a_timeline_value_object(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $timeline = $product->prices()->currentKnowledge()->asTimeline();

        $this->assertInstanceOf(Timeline::class, $timeline);
        $this->assertCount(2, $timeline);
        $this->assertSame(1000, $timeline->head()?->attributes['amount']);
    }

    public function test_full_history_orders_by_valid_then_recorded(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null, 'recorded_from' => '2026-02-01']);
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01', 'recorded_from' => '2026-01-01']);

        $rows = $product->prices()->fullHistory()->get();

        $this->assertSame(1000, $rows->first()?->amount);
        $this->assertSame(1200, $rows->last()?->amount);
    }
}
