<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads;

use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class KnownAtTest extends IntegrationTestCase
{
    /**
     * Seeds a value (1000) believed open-endedly from 2026-01-01, then a
     * correction recorded on 2026-03-01 splitting it and raising the later
     * value to 1200.
     */
    private function seedCorrectedTimeline(): Product
    {
        $product = $this->makeProduct();

        // Original belief, since superseded.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
        ]);

        // Current belief after the 2026-03-01 correction.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01',
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);
        $this->insertPrice($product, [
            'amount' => 1200,
            'valid_from' => '2026-06-01', 'valid_to' => null,
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);

        return $product;
    }

    public function test_known_at_returns_the_belief_held_then(): void
    {
        $product = $this->seedCorrectedTimeline();

        $price = $product->prices()->validAt('2026-09-01')->knownAt('2026-02-01')->sole();

        $this->assertSame(1000, $price->amount);
    }

    public function test_known_at_after_correction_returns_new_belief(): void
    {
        $product = $this->seedCorrectedTimeline();

        $price = $product->prices()->validAt('2026-09-01')->knownAt('2026-04-01')->sole();

        $this->assertSame(1200, $price->amount);
    }

    public function test_known_at_is_half_open_at_recorded_upper_bound(): void
    {
        $product = $this->seedCorrectedTimeline();

        // The old belief's recorded period ends at 2026-03-01 (exclusive).
        $price = $product->prices()->validAt('2026-09-01')->knownAt('2026-03-01')->sole();

        $this->assertSame(1200, $price->amount);
    }
}
