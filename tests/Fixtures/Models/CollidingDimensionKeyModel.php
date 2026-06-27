<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Deliberately broken: its primary key collides with a temporal *dimension*
 * (currency). Exercises that BootGuardPrimaryKey spreads the dimensions into
 * the reserved set rather than nesting them as a single array element.
 */
class CollidingDimensionKeyModel extends Model
{
    use Bitemporal;

    protected $table = 'dimensioned_prices';

    protected $primaryKey = 'currency';

    protected $guarded = [];

    /**
     * @var array<int, string>
     */
    protected array $temporalDimensions = ['currency'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
