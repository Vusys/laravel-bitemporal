<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Deliberately broken: $temporalDimensions contains a non-string element, which
 * BootGuardDimensions must reject. The non-string sits *after* a valid string so
 * the guard has to actually iterate the list (not just look at the first item).
 */
class BadDimensionsPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    /**
     * @var array<int, mixed>
     */
    protected array $temporalDimensions = ['currency', 123];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
