<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Vusys\Bitemporal\Collections\BitemporalCollection;
use Vusys\Bitemporal\Concerns\HasTemporalCasts;
use Vusys\Bitemporal\Concerns\HasTemporalEntity;

/**
 * Marks an Eloquent model as temporal. The model must define a
 * `temporalEntity()` relation (BelongsTo or, from Phase 8, MorphTo).
 *
 * @phpstan-require-extends Model
 */
trait Bitemporal
{
    use HasTemporalCasts;
    use HasTemporalEntity;

    /**
     * @param  Builder  $query
     * @return BitemporalBuilder<Model>
     */
    public function newEloquentBuilder($query)
    {
        return new BitemporalBuilder($query);
    }

    /**
     * @param  array<int, static>  $models
     * @return BitemporalCollection<int, static>
     */
    public function newCollection(array $models = [])
    {
        return new BitemporalCollection($models);
    }
}
