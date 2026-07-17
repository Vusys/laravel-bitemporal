<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Intentionally misconfigured: disables the automatic immutable_datetime casts
 * and declares a mutable `datetime` cast on the valid_from period column, so
 * BootLintMutableDatetimeCast raises (naming valid_from only). Otherwise a
 * well-configured temporal model that passes every boot guard.
 */
class MutableDatetimeCastPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    protected bool $autoApplyTemporalCasts = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'valid_from' => 'datetime',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
