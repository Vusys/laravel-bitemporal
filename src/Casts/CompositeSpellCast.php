<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Spell;

/**
 * Synthetic Spell cast over two datetime columns, e.g.
 * `'valid_spell' => CompositeSpellCast::class.':valid_from,valid_to'`.
 *
 * @implements CastsAttributes<Spell, Spell>
 */
final readonly class CompositeSpellCast implements CastsAttributes
{
    public function __construct(
        private string $fromColumn = 'valid_from',
        private string $toColumn = 'valid_to',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Spell
    {
        return new Spell(
            $this->bound($attributes[$this->fromColumn] ?? null),
            $this->bound($attributes[$this->toColumn] ?? null),
        );
    }

    private function bound(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) || is_int($value)) {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof Spell) {
            return [];
        }

        return [
            $this->fromColumn => $value->from,
            $this->toColumn => $value->to,
        ];
    }
}
