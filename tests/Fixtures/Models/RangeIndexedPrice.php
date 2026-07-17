<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * PostgreSQL range-mode temporal model. Its table carries only the EXCLUDE USING
 * gist constraint (no plain package index), so withoutIndexes() drops nothing and
 * the constraint stays enforced. Used only by the PostgreSQL-gated test.
 */
class RangeIndexedPrice extends Model
{
    use Bitemporal;

    protected $table = 'range_indexed_prices';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
