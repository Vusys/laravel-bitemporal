<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Temporal model on a table built with the overlap macros, so the package
 * indexes (indexed_prices_temporal_overlap, indexed_prices_bitemporal_overlap)
 * actually exist for the withoutIndexes() tests. The table is created per test
 * (see WithoutIndexesTest::setUp) rather than by a fixture migration.
 */
class IndexedPrice extends Model
{
    use Bitemporal;

    protected $table = 'indexed_prices';

    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
