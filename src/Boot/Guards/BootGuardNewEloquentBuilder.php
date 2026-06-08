<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Boot\BootGuard;

/**
 * The model's query builder must be a BitemporalBuilder — otherwise the
 * temporal read predicates are unavailable. Catches a model that overrode
 * newEloquentBuilder() incompatibly.
 */
final class BootGuardNewEloquentBuilder implements BootGuard
{
    public function check(Model $model): ?string
    {
        if (! $model->newQuery() instanceof BitemporalBuilder) {
            return 'newEloquentBuilder() must return a Vusys\Bitemporal\BitemporalBuilder';
        }

        return null;
    }
}
