<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * A second indexed temporal model on its own table, so withoutIndexes() can be
 * shown to compose across different models.
 */
class IndexedPriceTwo extends Model
{
    use Bitemporal;

    protected $table = 'indexed_prices_two';

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
