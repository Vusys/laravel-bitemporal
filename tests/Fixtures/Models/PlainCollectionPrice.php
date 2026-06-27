<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * Deliberately broken: overrides newCollection() to return a plain Eloquent
 * collection instead of a BitemporalCollection, which BootGuardNewCollection
 * must reject.
 */
class PlainCollectionPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    /**
     * @param  array<int, static>  $models
     * @return Collection<int, static>
     */
    #[\Override]
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
