<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads;

use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class WhereTemporalEntityTest extends IntegrationTestCase
{
    public function test_where_temporal_entity_scopes_to_one_entity(): void
    {
        $a = $this->makeProduct('A');
        $b = $this->makeProduct('B');
        $this->insertPrice($a, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($b, ['amount' => 2000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $rows = ProductPrice::query()->whereTemporalEntity($a)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(1000, $rows->first()?->amount);
    }

    public function test_where_temporal_entity_in_accepts_a_collection(): void
    {
        $a = $this->makeProduct('A');
        $b = $this->makeProduct('B');
        $c = $this->makeProduct('C');
        $this->insertPrice($a, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($b, ['amount' => 2000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($c, ['amount' => 3000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $rows = ProductPrice::query()->whereTemporalEntityIn(collect([$a, $b]))->get();

        $this->assertCount(2, $rows);
    }

    public function test_where_temporal_entity_in_accepts_an_array_of_models(): void
    {
        $a = $this->makeProduct('A');
        $b = $this->makeProduct('B');
        $this->insertPrice($a, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($b, ['amount' => 2000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertCount(2, ProductPrice::query()->whereTemporalEntityIn([$a, $b])->get());
    }

    public function test_where_temporal_entity_in_accepts_bare_ids(): void
    {
        $a = $this->makeProduct('A');
        $b = $this->makeProduct('B');
        $this->insertPrice($a, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($b, ['amount' => 2000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertCount(1, ProductPrice::query()->whereTemporalEntityIn([$a->getKey()])->get());
    }

    public function test_where_temporal_entity_in_with_an_empty_set_matches_nothing(): void
    {
        $a = $this->makeProduct('A');
        $this->insertPrice($a, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertCount(0, ProductPrice::query()->whereTemporalEntityIn([])->get());
    }

    public function test_where_temporal_entity_in_rejects_unexpected_values(): void
    {
        $this->expectException(TemporalConfigurationException::class);

        ProductPrice::query()->whereTemporalEntityIn([3.14])->get();
    }
}
