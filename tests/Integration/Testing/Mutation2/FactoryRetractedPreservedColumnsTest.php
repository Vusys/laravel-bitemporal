<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Testing\Mutation2;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Factories\BitemporalFactory;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins BitemporalFactory::retracted()'s "preserved columns" set: an anti-row
 * nulls every value attribute but must keep the key, period, dimension and
 * entity-scope columns. Each test forces a specific preserved entry to matter.
 */
final class FactoryRetractedPreservedColumnsTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_retracted_preserves_primary_key_column(): void
    {
        // Kills ArrayItemRemoval (drop $model->getKeyName() from preserved):
        // an explicitly-set primary key must survive the nulling pass.
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->state(['id' => 4242, 'amount' => 1000])
            ->retracted()
            ->create();

        $this->assertSame(4242, $price->id);
        $this->assertNull($price->amount);
        $this->assertTrue((bool) $price->is_retraction);
    }

    public function test_retracted_preserves_dimension_columns(): void
    {
        // Kills SpreadRemoval (...$meta->dimensions -> $meta->dimensions): the
        // currency dimension must be preserved, while the amount value is nulled.
        $product = $this->makeProduct();

        $price = (new ProductPriceWithDimensionsFactory)
            ->state([
                'product_id' => $product->getKey(),
                'amount' => 1000,
                'currency' => 'GBP',
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => null,
                'recorded_from' => '2026-01-01 00:00:00',
                'recorded_to' => null,
                'is_retraction' => false,
            ])
            ->retracted()
            ->create();

        $this->assertInstanceOf(ProductPriceWithDimensions::class, $price);
        $this->assertSame('GBP', $price->currency);
        $this->assertNull($price->amount);
    }

    public function test_retracted_preserves_morph_entity_columns(): void
    {
        // Kills entityColumns SpreadOneItem ([...entityColumns][0] drops owner_id),
        // InstanceOf_ (MorphTo -> false: returns [] so both columns null) and the
        // uncovered ArrayItemRemoval on the MorphTo branch (drops getMorphType).
        $address = (new AddressFactory)
            ->state([
                'owner_type' => 'customer',
                'owner_id' => 7,
                'label' => 'HQ',
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => null,
                'recorded_from' => '2026-01-01 00:00:00',
                'recorded_to' => null,
                'is_retraction' => false,
            ])
            ->retracted()
            ->create();

        $this->assertInstanceOf(Address::class, $address);
        $this->assertSame('customer', $address->owner_type);
        $this->assertSame(7, (int) $address->owner_id);
        $this->assertNull($address->label);
        $this->assertTrue((bool) $address->is_retraction);
    }
}

/**
 * @extends BitemporalFactory<ProductPriceWithDimensions>
 */
final class ProductPriceWithDimensionsFactory extends BitemporalFactory
{
    /** @var class-string<ProductPriceWithDimensions> */
    protected $model = ProductPriceWithDimensions::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}

/**
 * @extends BitemporalFactory<Address>
 */
final class AddressFactory extends BitemporalFactory
{
    /** @var class-string<Address> */
    protected $model = Address::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}
