<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Factories;

use Vusys\Bitemporal\Factories\BitemporalFactory;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;

/**
 * @extends BitemporalFactory<ProductPrice>
 */
final class ProductPriceFactory extends BitemporalFactory
{
    protected $model = ProductPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount' => 1000,
            'currency' => 'GBP',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => null,
            'recorded_from' => '2026-01-01 00:00:00',
            'recorded_to' => null,
            'is_retraction' => false,
        ];
    }
}
