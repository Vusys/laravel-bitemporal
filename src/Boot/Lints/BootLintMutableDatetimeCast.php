<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Lints;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Boot\BootLint;

/**
 * A temporal column is cast to mutable `datetime`. The trait substitutes
 * `immutable_datetime`, but the declared mutable cast is usually a mistake —
 * mutating a returned Carbon would not write back, surprising callers.
 */
final class BootLintMutableDatetimeCast implements BootLint
{
    public function check(Model $model): ?string
    {
        if (! method_exists($model, 'temporalColumnMap')) {
            return null;
        }

        $casts = $model->getCasts();
        $mutable = [];

        foreach ($model->temporalColumnMap() as $column) {
            $cast = $casts[$column] ?? null;

            if ($cast === 'datetime' || $cast === 'date') {
                $mutable[] = $column;
            }
        }

        if ($mutable === []) {
            return null;
        }

        return 'temporal column(s) declared with a mutable datetime cast: '
            .implode(', ', $mutable).'. Use immutable_datetime (the trait applies it automatically).';
    }
}
