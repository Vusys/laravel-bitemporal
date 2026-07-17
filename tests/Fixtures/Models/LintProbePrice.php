<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * A well-configured temporal model used by exactly one mutation test so that its
 * per-class boot cache is guaranteed cold the first time it is constructed. This
 * lets the test observe initializeBitemporal() invoking the boot lints without
 * resetting any shared static state.
 */
class LintProbePrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'integer',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
