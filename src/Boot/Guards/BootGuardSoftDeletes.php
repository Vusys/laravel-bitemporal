<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\Bitemporal\Boot\BootGuard;

/**
 * Temporal models must not use SoftDeletes — deletion is modelled with
 * retractions and a soft-delete scope would silently hide rows from the
 * temporal reads.
 */
final class BootGuardSoftDeletes implements BootGuard
{
    public function check(Model $model): ?string
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return 'temporal models cannot use the SoftDeletes trait; model deletion is represented with retractPeriod()/retract()';
        }

        return null;
    }
}
