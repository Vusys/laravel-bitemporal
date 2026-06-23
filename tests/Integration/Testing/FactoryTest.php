<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Testing;

use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class FactoryTest extends IntegrationTestCase
{
    public function test_factory_creates_a_temporal_row_with_defaults(): void
    {
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)->create();

        $this->assertSame(1000, $price->amount);
        $this->assertSame('GBP', $price->currency);
        $this->assertFalse($price->is_retraction);
        $this->assertNull($price->recorded_to);
    }

    public function test_valid_from_and_valid_to_states(): void
    {
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->validFrom('2026-03-01')
            ->validTo('2026-06-01')
            ->create();

        $this->assertNotNull($price->valid_to);
        $this->assertSame('2026-03-01', $price->valid_from->format('Y-m-d'));
        $this->assertSame('2026-06-01', $price->valid_to->format('Y-m-d'));
    }

    public function test_open_ended_and_current_knowledge_compose(): void
    {
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->currentKnowledge()
            ->openEnded()
            ->create();

        $this->assertNull($price->valid_to);
        $this->assertNull($price->recorded_to);
    }

    public function test_superseded_sets_recorded_to(): void
    {
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->superseded('2026-03-01')
            ->create();

        $this->assertNotNull($price->recorded_to);
        $this->assertSame('2026-03-01', $price->recorded_to->format('Y-m-d'));
    }

    public function test_retracted_nulls_attributes_and_sets_flag(): void
    {
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->validFrom('2026-03-01')
            ->validTo('2026-06-01')
            ->retracted()
            ->create();

        $this->assertTrue($price->is_retraction);
        $this->assertNull($price->amount);
        $this->assertNull($price->currency);
        $this->assertSame('2026-03-01', $price->valid_from->format('Y-m-d'));
    }
}
