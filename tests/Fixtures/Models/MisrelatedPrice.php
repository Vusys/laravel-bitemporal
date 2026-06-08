<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vusys\Bitemporal\Bitemporal;

/**
 * Intentionally misconfigured: temporalEntity() returns a HasMany instead of a
 * BelongsTo/MorphTo. Used to exercise BootGuardRelationType.
 */
class MisrelatedPrice extends Model
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
