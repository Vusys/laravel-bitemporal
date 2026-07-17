<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Relations\BitemporalBelongsToMany;
use Vusys\Bitemporal\Relations\BitemporalMany;
use Vusys\Bitemporal\Relations\BitemporalOne;

/**
 * Relation factories for a parent (entity) model.
 *
 * @phpstan-require-extends Model
 */
trait HasBitemporalRelations
{
    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return BitemporalMany<TRelated, $this>
     */
    public function bitemporalMany(string $related, ?string $foreignKey = null, ?string $localKey = null)
    {
        $instance = $this->newTemporalRelatedInstance($related);
        $foreignKey ??= $this->resolveTemporalForeignKey($instance);
        $localKey ??= $this->getKeyName();

        return new BitemporalMany(
            $related::query()->setModel($instance),
            $this,
            $instance->getTable().'.'.$foreignKey,
            $localKey,
        );
    }

    /**
     * A one-to-many relation to a model whose temporalEntityRelation() is a MorphTo.
     * Scopes by both the morph id (the foreign key) and the morph type.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return BitemporalMany<TRelated, $this>
     */
    public function bitemporalMorphMany(string $related)
    {
        $instance = $this->newTemporalRelatedInstance($related);
        $relation = $this->resolveTemporalMorphTo($instance);

        return new BitemporalMany(
            $related::query()->setModel($instance)->where(
                $instance->getTable().'.'.$relation->getMorphType(),
                '=',
                $this->getMorphClass(),
            ),
            $this,
            $instance->getTable().'.'.$relation->getForeignKeyName(),
            $this->getKeyName(),
        );
    }

    /**
     * A many-to-many relation whose pivot is temporal. Chain ->using(Pivot::class)
     * to bind the pivot model that carries the timeline.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  class-string<Model>|null  $using
     * @return BitemporalBelongsToMany<$this>
     */
    public function bitemporalBelongsToMany(string $related, ?string $using = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null, ?string $parentKey = null)
    {
        $relatedInstance = new $related;

        $foreignPivotKey ??= Str::snake(class_basename($this)).'_'.$this->getKeyName();
        $relatedPivotKey ??= Str::snake(class_basename($related)).'_'.$relatedInstance->getKeyName();
        $parentKey ??= $this->getKeyName();

        /** @var Builder<Model> $standIn */
        $standIn = $related::query()->setModel($relatedInstance);

        $relation = new BitemporalBelongsToMany(
            $standIn,
            $this,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $related,
        );

        if ($using !== null) {
            $relation->using($using);
        }

        return $relation;
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return BitemporalOne<TRelated, $this>
     */
    public function bitemporalOne(string $related, ?string $foreignKey = null, ?string $localKey = null)
    {
        $instance = $this->newTemporalRelatedInstance($related);
        $foreignKey ??= $this->resolveTemporalForeignKey($instance);
        $localKey ??= $this->getKeyName();

        return new BitemporalOne(
            $related::query()->setModel($instance),
            $this,
            $instance->getTable().'.'.$foreignKey,
            $localKey,
        );
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return BitemporalOne<TRelated, $this>
     */
    public function bitemporalOneOrFail(string $related, ?string $foreignKey = null, ?string $localKey = null)
    {
        return $this->bitemporalOne($related, $foreignKey, $localKey)->requirePresence();
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return TRelated
     */
    private function newTemporalRelatedInstance(string $related)
    {
        $instance = new $related;

        if ($instance->getConnectionName() === null) {
            $instance->setConnection($this->getConnectionName());
        }

        return $instance;
    }

    private function resolveTemporalForeignKey(Model $instance): string
    {
        if (! method_exists($instance, 'temporalEntityRelation')) {
            throw TemporalConfigurationException::missingTemporalEntity($instance::class);
        }

        $relation = $instance->temporalEntityRelation();

        if (! $relation instanceof BelongsTo) {
            throw new TemporalConfigurationException(
                'temporalEntityRelation() must return a BelongsTo relation (MorphTo support arrives in Phase 8)',
            );
        }

        return $relation->getForeignKeyName();
    }

    /**
     * @return MorphTo<Model, Model>
     */
    private function resolveTemporalMorphTo(Model $instance): MorphTo
    {
        if (! method_exists($instance, 'temporalEntityRelation')) {
            throw TemporalConfigurationException::missingTemporalEntity($instance::class);
        }

        $relation = $instance->temporalEntityRelation();

        if (! $relation instanceof MorphTo) {
            throw new TemporalConfigurationException(
                'bitemporalMorphMany() requires the related model to define a MorphTo temporalEntityRelation()',
            );
        }

        return $relation;
    }
}
