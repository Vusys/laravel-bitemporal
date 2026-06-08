<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class ChangeEffectiveFromTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_forward_change_splits_the_open_ended_row(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $result = $product->prices()->changeEffectiveFrom(['amount' => 1200], '2026-06-01');

        $current = $product->prices()->currentKnowledge()->get();
        $this->assertCount(2, $current);
        $this->assertSame(1000, $product->prices()->validAt('2026-03-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(1200, $product->prices()->validAt('2026-09-01')->currentKnowledge()->sole()->amount);

        // Original open-ended belief was closed, not deleted.
        $this->assertSame(3, ProductPrice::query()->count());
        $this->assertSame(1, $result->closedCount());
        $this->assertSame(2, $result->insertedCount());
    }

    public function test_forward_change_on_empty_timeline_inserts_one_row(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->changeEffectiveFrom(['amount' => 500], '2026-01-01');

        $current = $product->prices()->currentKnowledge()->get();
        $this->assertCount(1, $current);

        $row = $current->first();
        $this->assertNotNull($row);
        $this->assertSame(500, $row->amount);
        $this->assertNull($row->valid_to);
    }

    public function test_past_dated_change_is_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->expectException(TemporalInvalidSpellException::class);

        $product->prices()->changeEffectiveFrom(['amount' => 1200], '2026-02-01');
    }
}
