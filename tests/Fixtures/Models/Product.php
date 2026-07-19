<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Concerns\HasBitemporalRelations;
use Vusys\Bitemporal\Relations\BitemporalMany;
use Vusys\Bitemporal\Relations\BitemporalOne;

/**
 * @property int $id
 * @property string $name
 */
class Product extends Model
{
    use HasBitemporalRelations;

    protected $guarded = [];

    /**
     * @return BitemporalMany<ProductPrice, $this>
     */
    public function prices(): BitemporalMany
    {
        return $this->bitemporalMany(ProductPrice::class);
    }

    /**
     * @return BitemporalOne<ProductPrice, $this>
     */
    public function price(): BitemporalOne
    {
        return $this->bitemporalOne(ProductPrice::class);
    }

    /**
     * @return BitemporalOne<ProductPrice, $this>
     */
    public function currentPrice(): BitemporalOne
    {
        return $this->bitemporalOneOrFail(ProductPrice::class);
    }

    /**
     * @return BitemporalMany<ProductPriceWithDimensions, $this>
     */
    public function dimensionedPrices(): BitemporalMany
    {
        return $this->bitemporalMany(ProductPriceWithDimensions::class);
    }

    /**
     * A temporal-rows model on a different connection than this entity (issue
     * #67). Only usable with the boot guard disabled.
     *
     * @return BitemporalMany<SecondConnectionPrice, $this>
     */
    public function secondConnectionPrices(): BitemporalMany
    {
        return $this->bitemporalMany(SecondConnectionPrice::class);
    }
}
