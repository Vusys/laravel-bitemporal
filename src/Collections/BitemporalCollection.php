<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Vusys\Bitemporal\Collections\Concerns\HasPolymorphicGrouping;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;

/**
 * Collection returned by temporal queries. Adds entity-keying and grouping
 * helpers that work for both BelongsTo and MorphTo temporal entities.
 *
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
class BitemporalCollection extends Collection
{
    use HasPolymorphicGrouping;

    /**
     * Key rows by their bare temporal-entity id. Throws on polymorphic entities
     * (ids are not unique across types — use keyByTemporalEntityReference()).
     *
     * @return SupportCollection<int|string, TModel>
     */
    public function keyByTemporalEntityId(): SupportCollection
    {
        return $this->keyBy(function (Model $model): int|string {
            if ($this->temporalEntityIsPolymorphic($model)) {
                throw new TemporalConfigurationException(
                    'keyByTemporalEntityId() is unavailable for polymorphic temporal entities; use keyByTemporalEntityReference()',
                );
            }

            return $this->temporalEntityId($model);
        });
    }

    /**
     * Key rows by a `type:id` reference string. Works for every temporal model.
     *
     * @return SupportCollection<string, TModel>
     */
    public function keyByTemporalEntityReference(): SupportCollection
    {
        return $this->keyBy(fn (Model $model): string => $this->temporalEntityReference($model));
    }

    /**
     * Group rows by their `type:id` reference string.
     *
     * @return SupportCollection<string, static>
     */
    public function groupByTemporalEntity(): SupportCollection
    {
        return $this->groupBy(fn (Model $model): string => $this->temporalEntityReference($model));
    }
}
