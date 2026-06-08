<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads;

use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class ValidAtTest extends IntegrationTestCase
{
    public function test_valid_at_returns_the_row_covering_the_instant(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertSame(1000, $product->prices()->validAt('2026-03-01')->sole()->amount);
        $this->assertSame(1200, $product->prices()->validAt('2026-09-01')->sole()->amount);
    }

    public function test_valid_at_is_half_open_at_the_upper_bound(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertSame(1200, $product->prices()->validAt('2026-06-01')->sole()->amount);
    }

    public function test_valid_at_returns_nothing_before_the_first_row(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertCount(0, $product->prices()->validAt('2025-12-01')->get());
    }

    public function test_valid_at_includes_lower_bound(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);

        $this->assertSame(1000, $product->prices()->validAt('2026-01-01')->sole()->amount);
    }
}
