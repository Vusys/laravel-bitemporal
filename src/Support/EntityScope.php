<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;

/**
 * Resolves the columns that pin a temporal row to its entity, mapped to the
 * values to filter and stamp: ['owner_id' => 42] for a BelongsTo entity, or
 * ['owner_type' => 'customer', 'owner_id' => 42] for a MorphTo entity.
 */
final class EntityScope
{
    /**
     * @return array<string, mixed>
     */
    public static function resolve(Model $related, Model $entity): array
    {
        if (! method_exists($related, 'temporalEntity')) {
            throw new TemporalInvalidSpellException($related::class.' must define a temporalEntity() relation');
        }

        $relation = $related->temporalEntity();

        if ($relation instanceof MorphTo) {
            return [
                $relation->getMorphType() => $entity->getMorphClass(),
                $relation->getForeignKeyName() => $entity->getKey(),
            ];
        }

        if ($relation instanceof BelongsTo) {
            return [$relation->getForeignKeyName() => $entity->getKey()];
        }

        throw new TemporalInvalidSpellException('temporalEntity() must return a BelongsTo or MorphTo relation');
    }
}
