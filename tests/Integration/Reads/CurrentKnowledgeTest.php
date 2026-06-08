<?php

declare(strict_types=1);

namespace Bitemporal\Tests\Integration\Reads;

use Bitemporal\Tests\Integration\IntegrationTestCase;

final class CurrentKnowledgeTest extends IntegrationTestCase
{
    public function test_current_knowledge_excludes_superseded_rows(): void
    {
        $product = $this->makeProduct();

        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
        ]);
        $this->insertPrice($product, [
            'amount' => 1200,
            'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);

        $current = $product->prices()->currentKnowledge()->get();

        $this->assertCount(1, $current);
        $this->assertSame(1200, $current->first()?->amount);
    }

    public function test_current_knowledge_composes_with_valid_at(): void
    {
        $product = $this->makeProduct();

        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01',
            'recorded_from' => '2026-01-01', 'recorded_to' => null,
        ]);
        $this->insertPrice($product, [
            'amount' => 1200,
            'valid_from' => '2026-06-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => null,
        ]);

        $price = $product->prices()->validAt('2026-03-01')->currentKnowledge()->sole();

        $this->assertSame(1000, $price->amount);
    }
}
