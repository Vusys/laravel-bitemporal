<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Relations\Mutation2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vusys\Bitemporal\Bitemporal;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * resolveTemporalForeignKey() must reject a temporalEntity() that is not a
 * BelongsTo (MorphTo is a BelongsTo subclass, so it passes; a HasMany must not).
 *
 * Kills HasBitemporalRelations InstanceOf_ (!$relation instanceof BelongsTo ->
 * !true, which would let the HasMany through) and the uncovered Throw_ (which
 * constructs but never throws the configuration exception).
 */
final class BitemporalManyRequiresBelongsToTest extends IntegrationTestCase
{
    public function test_bitemporal_many_rejects_a_non_belongs_to_temporal_entity(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);

        $this->expectException(TemporalConfigurationException::class);

        $customer->bitemporalMany(HasManyTemporalFixture::class);
    }
}

class HasManyTemporalFixture extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    /**
     * @return HasMany<Product, $this>
     */
    public function temporalEntity(): HasMany
    {
        return $this->hasMany(Product::class, 'id');
    }
}
