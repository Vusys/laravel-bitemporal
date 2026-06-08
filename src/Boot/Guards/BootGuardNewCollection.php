<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Boot\BootGuard;
use Vusys\Bitemporal\Collections\BitemporalCollection;

/**
 * The model's collection must be a BitemporalCollection so the entity-keying
 * and grouping helpers are available on query results.
 */
final class BootGuardNewCollection implements BootGuard
{
    public function check(Model $model): ?string
    {
        if (! $model->newCollection() instanceof BitemporalCollection) {
            return 'newCollection() must return a Vusys\Bitemporal\Collections\BitemporalCollection';
        }

        return null;
    }
}
