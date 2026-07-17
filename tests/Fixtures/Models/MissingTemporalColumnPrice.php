<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Deliberately broken: overrides valid_from to a column that does not exist on
 * the product_price_versions table. Used to exercise BootGuardColumnsExist
 * (booted via TemporalLens::withoutBootGuards so the guard can be invoked in
 * isolation).
 */
class MissingTemporalColumnPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    protected string $validFromColumn = 'ghost_valid_from';

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
