<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Boot\BootGuard;

/**
 * The primary key must not collide with a temporal column or a dimension —
 * the writer stamps those columns on new rows and cannot also own the key.
 */
final class BootGuardPrimaryKey implements BootGuard
{
    public function check(Model $model): ?string
    {
        if (! method_exists($model, 'temporalColumnMap') || ! method_exists($model, 'temporalDimensions')) {
            return null;
        }

        $key = $model->getKeyName();
        $reserved = [...array_values($model->temporalColumnMap()), ...$model->temporalDimensions()];

        if (in_array($key, $reserved, true)) {
            return "primary key '{$key}' collides with a temporal column or dimension";
        }

        return null;
    }
}
