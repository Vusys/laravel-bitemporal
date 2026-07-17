<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Collections\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection as SupportCollection;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;

/**
 * Resolves a row's temporal-entity reference (type + id) for both BelongsTo and
 * MorphTo entities, and groups by morph type.
 *
 * @phpstan-require-extends Collection
 */
trait HasPolymorphicGrouping
{
    /**
     * Group rows by their temporal-entity morph type (e.g. 'customer').
     *
     * @return SupportCollection<string, static>
     */
    public function groupByTemporalEntityType(): SupportCollection
    {
        return $this->groupBy(fn (Model $model): string => $this->temporalEntityType($model));
    }

    protected function temporalEntityReference(Model $model): string
    {
        return $this->temporalEntityType($model).':'.$this->temporalEntityId($model);
    }

    protected function temporalEntityType(Model $model): string
    {
        $relation = $this->entityRelationOf($model);

        if ($relation instanceof MorphTo) {
            $type = $model->getAttribute($relation->getMorphType());

            if (! is_string($type)) {
                throw new TemporalConfigurationException('temporal entity morph type must be a string');
            }

            return $type;
        }

        return $relation->getRelated()->getMorphClass();
    }

    protected function temporalEntityId(Model $model): int|string
    {
        $value = $model->getAttribute($this->entityRelationOf($model)->getForeignKeyName());

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new TemporalConfigurationException('temporal entity key must be an int or string; got '.get_debug_type($value));
    }

    protected function temporalEntityIsPolymorphic(Model $model): bool
    {
        return $this->entityRelationOf($model) instanceof MorphTo;
    }

    /**
     * @return BelongsTo<Model, Model>
     */
    protected function entityRelationOf(Model $model): BelongsTo
    {
        if (! method_exists($model, 'temporalEntityRelation')) {
            throw TemporalConfigurationException::missingTemporalEntity($model::class);
        }

        $relation = $model->temporalEntityRelation();

        if (! $relation instanceof BelongsTo) {
            throw new TemporalConfigurationException('temporalEntityRelation() must return a BelongsTo or MorphTo relation');
        }

        return $relation;
    }
}
