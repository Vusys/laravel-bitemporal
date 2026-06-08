<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection as SupportCollection;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;

/**
 * Collection returned by temporal queries. Adds entity-keying and grouping
 * helpers. Polymorphic temporal entities are supported from Phase 8.
 *
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
class BitemporalCollection extends Collection
{
    /**
     * Key rows by their bare temporal-entity id. Throws on polymorphic entities
     * (ids are not unique across types — use keyByTemporalEntityReference()).
     *
     * @return SupportCollection<int|string, TModel>
     */
    public function keyByTemporalEntityId(): SupportCollection
    {
        return $this->keyBy(fn (Model $model): int|string => $this->temporalEntityId($model));
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

    private function temporalEntityId(Model $model): int|string
    {
        $value = $model->getAttribute($this->temporalBelongsTo($model)->getForeignKeyName());

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new TemporalConfigurationException(
            'temporal entity key must be an int or string; got '.get_debug_type($value),
        );
    }

    private function temporalEntityReference(Model $model): string
    {
        $relation = $this->temporalBelongsTo($model);
        $type = $relation->getRelated()->getMorphClass();

        return $type.':'.$this->temporalEntityId($model);
    }

    /**
     * @return BelongsTo<Model, TModel>
     */
    private function temporalBelongsTo(Model $model): BelongsTo
    {
        if (! method_exists($model, 'temporalEntity')) {
            throw TemporalConfigurationException::missingTemporalEntity($model::class);
        }

        $relation = $model->temporalEntity();

        if (! $relation instanceof BelongsTo) {
            throw new TemporalConfigurationException(
                'polymorphic temporal entities are supported from Phase 8',
            );
        }

        return $relation;
    }
}
