<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads;

use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins issue #44: an unpinned single-result relation must not return a
 * storage-order-dependent (arbitrary) row. BitemporalOne forces a total order
 * — latest valid period, then latest belief, then key — so both lazy property
 * access and eager loading resolve to the same, reproducible row.
 */
final class BitemporalOneDeterminismTest extends IntegrationTestCase
{
    public function test_unpinned_lazy_read_returns_the_latest_valid_row_deterministically(): void
    {
        $product = $this->makeProduct();
        // Insert out of valid order so a naive "first physical row" would win.
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);

        // No validAt/currentKnowledge pin: several rows match. The result must be
        // the latest valid period every time, not whichever row the storage
        // engine happens to return first.
        $this->assertSame(1200, $product->price()->getResults()?->amount);
        $this->assertSame(1200, $product->fresh()?->price?->amount);
    }

    public function test_unpinned_eager_read_matches_the_latest_valid_row_deterministically(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);

        $loaded = Product::query()->with('price')->whereKey($product->getKey())->first();

        $this->assertSame(1200, $loaded?->price?->amount);
    }
}
