<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Deliberately broken: its primary key is the valid_to temporal column (not the
 * first column in the map). Exercises BootGuardPrimaryKey across the *whole*
 * spread of temporal columns, not just the first one.
 */
class CollidingValidToKeyModel extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $primaryKey = 'valid_to';

    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
