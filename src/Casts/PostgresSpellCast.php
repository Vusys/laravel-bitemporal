<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Spell;

/**
 * Reads and writes a {@see Spell} against a native PostgreSQL `tstzrange`
 * column. Ranges are always half-open `[)` — matching the package's Spell
 * semantics — so the two map onto each other directly: an unbounded Spell side
 * becomes an open (empty) range bound and vice versa.
 *
 * Read shape (PG text form): `["2024-01-01 00:00:00+00","2024-06-01 00:00:00+00")`,
 * open-ended upper `["2024-01-01 00:00:00+00",)`, open lower `(,"…")`.
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
        if ($value instanceof Spell) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '' || strtolower(trim($value)) === 'empty') {
            return null;
        }

        return $this->parse($value);
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

        return [$key => $this->format($value)];
    }

    private function parse(string $range): Spell
    {
        $range = trim($range);
        // Strip the bound characters ([ or (, ] or )); we always persist [).
        $inner = substr($range, 1, -1);

        $comma = $this->topLevelComma($inner);
        $lower = $comma === null ? $inner : substr($inner, 0, $comma);
        $upper = $comma === null ? '' : substr($inner, $comma + 1);

        return new Spell($this->bound($lower), $this->bound($upper));
    }

    private function bound(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
            // Postgres doubles an interior double-quote inside a quoted range
            // element ("" -> "). Timestamp literals never contain quotes, so this
            // is a no-op today, but un-doubling keeps the parser correct for any
            // quoted content rather than silently mangling it.
            $value = str_replace('""', '"', $value);
        }

        // A tstzrange bound written with the literal infinity / -infinity — legal
        // Postgres, common when the table is populated by hand-written SQL,
        // migrations, or another application — is the same "unbounded" concept
        // the package already represents as null. Map it there rather than
        // letting CarbonImmutable::parse() throw an InvalidFormatException
        // (issue #70). Case-insensitive; any surrounding quotes are stripped above.
        if ($value === '' || in_array(strtolower($value), ['infinity', '-infinity', '+infinity'], true)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private function format(Spell $spell): string
    {
        $lower = $spell->from instanceof CarbonImmutable ? '"'.$spell->from->format('Y-m-d H:i:s.uP').'"' : '';
        $upper = $spell->to instanceof CarbonImmutable ? '"'.$spell->to->format('Y-m-d H:i:s.uP').'"' : '';

        return "[{$lower},{$upper})";
    }

    /**
     * Index of the comma separating the two bounds, ignoring any inside the
     * double-quoted timestamp literals.
     */
    private function topLevelComma(string $inner): ?int
    {
        $inQuotes = false;
        $length = strlen($inner);

        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];

            if ($char === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if ($char === ',' && ! $inQuotes) {
                return $i;
            }
        }

        return null;
    }
}
