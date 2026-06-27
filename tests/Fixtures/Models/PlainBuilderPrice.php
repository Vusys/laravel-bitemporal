<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Deliberately broken: overrides newEloquentBuilder() to return a plain Eloquent
 * builder instead of a BitemporalBuilder, which BootGuardNewEloquentBuilder must
 * reject.
 *
 * @phpstan-ignore-next-line method return type
 */
class PlainBuilderPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builder<$this>
     */
    public function newEloquentBuilder($query): Builder
    {
        return new Builder($query);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
