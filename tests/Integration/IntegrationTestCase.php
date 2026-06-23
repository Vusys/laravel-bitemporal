<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration;

use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../Fixtures/Migrations');
    }

    protected function makeProduct(string $name = 'Widget'): Product
    {
        return Product::query()->create(['name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function insertPrice(Product $product, array $attributes): ProductPrice
    {
        return ProductPrice::query()->create([
            'product_id' => $product->getKey(),
            'recorded_from' => '2026-01-01 00:00:00',
            'recorded_to' => null,
            'is_retraction' => false,
            ...$attributes,
        ]);
    }
}
