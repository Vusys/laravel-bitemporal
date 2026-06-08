<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Vusys\Bitemporal\Events\TemporalHardDeleteCommitted;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class ForceDeleteHistoryTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_force_delete_history_removes_all_rows_for_the_entity(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');
        Event::fake([TemporalHardDeleteCommitted::class]);

        $product = $this->makeProduct();
        $other = $this->makeProduct('Other');
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);
        $this->insertPrice($other, ['amount' => 5000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $result = $product->prices()->forceDeleteHistory();

        $this->assertSame(2, $result->deletedCount());
        $this->assertCount(0, $product->prices()->get());

        // Other entities are untouched.
        $this->assertSame(1, ProductPrice::query()->count());

        Event::assertDispatched(TemporalHardDeleteCommitted::class);
    }
}
