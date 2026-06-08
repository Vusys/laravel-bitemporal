<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Boot\BootGuard;

/**
 * $temporalDimensions must be a list of column-name strings.
 */
final class BootGuardDimensions implements BootGuard
{
    public function check(Model $model): ?string
    {
        if (! method_exists($model, 'temporalDimensions')) {
            return null;
        }

        foreach ($model->temporalDimensions() as $dimension) {
            if (! is_string($dimension)) {
                return '$temporalDimensions must be an array of column-name strings';
            }
        }

        return null;
    }
}
