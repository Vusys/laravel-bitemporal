<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\Bitemporal\Bitemporal;

/**
 * Intentionally misconfigured: a temporal model that also uses SoftDeletes.
 * Used to exercise BootGuardSoftDeletes.
 */
class SoftDeletingPrice extends Model
{
    use Bitemporal;
    use SoftDeletes;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
