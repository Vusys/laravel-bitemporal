<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * A model that reuses the product price table but opts out of recorded-time
 * tracking. Used to exercise the requireRecordedTime() guard on the builder.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $amount
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 */
class ValidTimeOnlyPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    protected bool $tracksRecordedTime = false;

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->product();
    }
}
