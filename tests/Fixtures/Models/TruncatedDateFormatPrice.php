<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Intentionally misconfigured: declares a $dateFormat with no sub-second
 * precision, overriding the trait's microsecond default. Eloquent would then
 * truncate the writer's microsecond instants on save, so
 * BootLintTruncatedDateFormat raises. Otherwise a well-configured temporal
 * model that passes every boot guard.
 */
class TruncatedDateFormatPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
