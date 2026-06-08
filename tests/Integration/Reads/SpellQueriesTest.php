<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads;

use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class SpellQueriesTest extends IntegrationTestCase
{
    private function seedThreeSegments(): Product
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1, 'valid_from' => '2026-01-01', 'valid_to' => '2026-04-01']);
        $this->insertPrice($product, ['amount' => 2, 'valid_from' => '2026-04-01', 'valid_to' => '2026-07-01']);
        $this->insertPrice($product, ['amount' => 3, 'valid_from' => '2026-07-01', 'valid_to' => null]);

        return $product;
    }

    public function test_valid_intersects(): void
    {
        $product = $this->seedThreeSegments();

        $amounts = $product->prices()->validIntersects('2026-03-01', '2026-05-01')->pluck('amount')->sort()->values()->all();
        $this->assertSame([1, 2], $amounts);

        $open = $product->prices()->validIntersects('2026-08-01')->pluck('amount')->all();
        $this->assertSame([3], $open);
    }

    public function test_valid_contains(): void
    {
        $product = $this->seedThreeSegments();

        $this->assertSame([2], $product->prices()->validContains('2026-05-01', '2026-06-01')->pluck('amount')->all());
        $this->assertSame([3], $product->prices()->validContains('2026-08-01')->pluck('amount')->all());
    }

    public function test_valid_contained_by(): void
    {
        $product = $this->seedThreeSegments();

        $bounded = $product->prices()->validContainedBy('2026-01-01', '2026-07-01')->pluck('amount')->sort()->values()->all();
        $this->assertSame([1, 2], $bounded);

        $openWindow = $product->prices()->validContainedBy('2026-01-01')->pluck('amount')->sort()->values()->all();
        $this->assertSame([1, 2, 3], $openWindow);
    }

    public function test_valid_starting_from_and_ending_by(): void
    {
        $product = $this->seedThreeSegments();

        $this->assertSame([2, 3], $product->prices()->validStartingFrom('2026-04-01')->pluck('amount')->sort()->values()->all());
        $this->assertSame([1, 2], $product->prices()->validEndingBy('2026-07-01')->pluck('amount')->sort()->values()->all());
    }

    public function test_recorded_range_queries(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
        ]);
        $this->insertPrice($product, [
            'amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);

        $this->assertSame([1000], $product->prices()->recordedIntersects('2026-02-01', '2026-02-15')->pluck('amount')->all());
        $this->assertSame([1200], $product->prices()->recordedStartingFrom('2026-03-01')->pluck('amount')->all());
        $this->assertSame([1000], $product->prices()->recordedEndingBy('2026-03-01')->pluck('amount')->all());
    }

    public function test_exclude_retractions(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => null, 'valid_from' => '2026-06-01', 'valid_to' => '2026-09-01', 'is_retraction' => true]);

        $this->assertCount(2, $product->prices()->get());
        $this->assertCount(1, $product->prices()->excludeRetractions()->get());
    }

    public function test_where_temporal_entity_of(): void
    {
        $a = $this->makeProduct('A');
        $b = $this->makeProduct('B');
        $this->insertPrice($a, ['amount' => 1, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($b, ['amount' => 2, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $rows = ProductPrice::query()->whereTemporalEntityOf(Product::class, [$a->id])->get();

        $this->assertSame([1], $rows->pluck('amount')->all());
    }
}
