<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Spell;

/**
 * Reads and writes a Spell against a native PostgreSQL tstzrange column.
 * Fully implemented in Phase 12 (PostgreSQL range columns); this stub fixes the
 * public class name and cast contract from Phase 2.
 *
 * @implements CastsAttributes<Spell, Spell>
 */
final class PostgresSpellCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Spell
    {
        return $value instanceof Spell ? $value : null;
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
