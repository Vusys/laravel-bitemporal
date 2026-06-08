<?php

declare(strict_types=1);

namespace Bitemporal\Casts;

use Bitemporal\Period;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads and writes a Period against a native PostgreSQL tstzrange column.
 * Fully implemented in Phase 12 (PostgreSQL range columns); this stub fixes the
 * public class name and cast contract from Phase 2.
 *
 * @implements CastsAttributes<Period, Period>
 */
final class PostgresPeriodCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Period
    {
        return $value instanceof Period ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => $value];
    }
}
