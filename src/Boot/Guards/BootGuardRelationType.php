<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Boot\BootGuard;

/**
 * temporalEntity() must resolve to a BelongsTo or MorphTo relation (MorphTo
 * extends BelongsTo) so the builder and writer can scope by the entity.
 */
final class BootGuardRelationType implements BootGuard
{
    public function check(Model $model): ?string
    {
        if (! method_exists($model, 'temporalEntity')) {
            return 'temporal models must define a temporalEntity() relation';
        }

        if (! $model->temporalEntity() instanceof BelongsTo) {
            return 'temporalEntity() must return a BelongsTo or MorphTo relation';
        }

        return null;
    }
}
