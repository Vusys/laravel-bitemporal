<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Deliberately broken: pinned to a different connection than its
 * temporalEntityRelation() (Product, on the default connection). Used to exercise
 * BootGuardConnection (booted via TemporalLens::withoutBootGuards so the guard
 * can be invoked in isolation).
 */
class CrossConnectionPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    // The test registers a real 'secondary' connection; Product stays on the
    // default connection, so the two sides disagree.
    protected $connection = 'secondary';

    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        // The related Product resolves on the default connection, so the two
        // sides disagree.
        return $this->belongsTo(Product::class);
    }
}
