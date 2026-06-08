<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
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
        if (! method_exists($instance, 'temporalEntity')) {
            throw TemporalConfigurationException::missingTemporalEntity($instance::class);
        }

        $relation = $instance->temporalEntity();

        if (! $relation instanceof BelongsTo) {
            throw new TemporalConfigurationException(
                'temporalEntity() must return a BelongsTo relation (MorphTo support arrives in Phase 8)',
            );
        }

        return $relation->getForeignKeyName();
    }
}
