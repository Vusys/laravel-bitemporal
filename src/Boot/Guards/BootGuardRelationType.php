<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Throwable;
use Vusys\Bitemporal\Boot\BootGuard;

/**
 * temporalEntityRelation() must resolve to a BelongsTo or MorphTo relation (MorphTo
 * extends BelongsTo) so the builder and writer can scope by the entity.
 *
 * Pivot models are exempt: their entity is the (parent, related) composite,
 * injected by BitemporalBelongsToMany at relation-resolution time rather than
 * declared via temporalEntityRelation().
 */
final class BootGuardRelationType implements BootGuard
{
    public function check(Model $model): ?string
    {
        if ($model instanceof Pivot) {
            return null;
        }

        // The Bitemporal trait always supplies temporalEntityRelation(); an
        // unconfigured entity (no $temporalEntity class and no override) surfaces
        // as a thrown TemporalConfigurationException rather than a missing method.
        if (! method_exists($model, 'temporalEntityRelation')) {
            return 'temporal models must declare a $temporalEntity model class or a temporalEntityRelation() method';
        }

        try {
            $relation = $model->temporalEntityRelation();
        } catch (Throwable) {
            return 'temporal models must declare a $temporalEntity model class or a temporalEntityRelation() method';
        }

        if (! $relation instanceof BelongsTo) {
            return 'temporalEntityRelation() must return a BelongsTo or MorphTo relation';
        }

        return null;
    }
}
