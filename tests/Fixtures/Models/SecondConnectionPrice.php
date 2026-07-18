<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * A temporal-rows model pinned to the 'temporal_second' connection while its
 * parent entity (Product) resolves on the default connection. Both connection
 * names point at the same database, so this is the exact cross-connection shape
 * issue #67 is about: the write transaction runs on this model's connection and
 * the advisory write lock must be taken there, not on the entity's connection.
 *
 * The boot guard rejects this shape, so tests using it disable guards to reach
 * the write path directly (defence-in-depth / guard-bypass coverage).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $amount
 * @property string|null $currency
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 */
class SecondConnectionPrice extends Model
{
    use Bitemporal;

    protected $connection = 'temporal_second';

    protected $table = 'product_price_versions';

    protected $guarded = [];

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
