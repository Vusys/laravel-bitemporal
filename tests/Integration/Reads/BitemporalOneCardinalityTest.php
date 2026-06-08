<?php

declare(strict_types=1);

namespace Bitemporal\Tests\Integration\Reads;

use Bitemporal\Exceptions\TemporalCardinalityException;
use Bitemporal\Tests\Integration\IntegrationTestCase;

final class BitemporalOneCardinalityTest extends IntegrationTestCase
{
    public function test_one_returns_null_when_absent(): void
    {
        $product = $this->makeProduct();

        $this->assertNull($product->price()->sole());
    }

    public function test_one_returns_the_single_match(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertSame(1000, $product->price()->sole()?->amount);
    }

    public function test_one_throws_on_multiple_matches(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->expectException(TemporalCardinalityException::class);

        $product->price()->sole();
    }

    public function test_one_or_fail_throws_when_absent(): void
    {
        $product = $this->makeProduct();

        $this->expectException(TemporalCardinalityException::class);

        $product->currentPrice()->sole();
    }

    public function test_builder_sole_throws_on_multiple(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->expectException(TemporalCardinalityException::class);

        $product->prices()->validAt('2026-03-01')->orWhere('amount', 1200)->sole();
    }

    public function test_builder_sole_throws_when_none(): void
    {
        $product = $this->makeProduct();

        $this->expectException(TemporalCardinalityException::class);

        $product->prices()->validAt('2026-03-01')->sole();
    }
}
