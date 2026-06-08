<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads;

use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class CollectionKeyingTest extends IntegrationTestCase
{
    /**
     * @return array{Product, Product}
     */
    private function seedTwoProducts(): array
    {
        $a = $this->makeProduct('A');
        $b = $this->makeProduct('B');
        $this->insertPrice($a, ['amount' => 10, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($b, ['amount' => 20, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        return [$a, $b];
    }

    public function test_key_by_temporal_entity_id(): void
    {
        [$a, $b] = $this->seedTwoProducts();

        $keyed = ProductPrice::query()->get()->keyByTemporalEntityId();

        $this->assertSame(10, $keyed->get($a->id)?->amount);
        $this->assertSame(20, $keyed->get($b->id)?->amount);
    }

    public function test_key_by_temporal_entity_reference(): void
    {
        [$a] = $this->seedTwoProducts();

        $keyed = ProductPrice::query()->get()->keyByTemporalEntityReference();

        $reference = (new Product)->getMorphClass().':'.$a->id;
        $this->assertSame(10, $keyed->get($reference)?->amount);
    }

    public function test_group_by_temporal_entity(): void
    {
        $a = $this->makeProduct('A');
        $this->insertPrice($a, ['amount' => 10, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($a, ['amount' => 12, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $grouped = ProductPrice::query()->get()->groupByTemporalEntity();

        $reference = (new Product)->getMorphClass().':'.$a->id;
        $this->assertCount(1, $grouped);
        $this->assertCount(2, $grouped->get($reference) ?? collect());
    }
}
