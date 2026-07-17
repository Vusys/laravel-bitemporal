<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey\Journeys;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;

/**
 * Shared helpers for timeline journeys: tolerating legitimately-rejected draws,
 * and taking a stable, comparable snapshot of a set of temporal rows.
 */
trait ExploresTimelines
{
    /**
     * Run a write that may legitimately reject the *drawn window* — a
     * past-dated forward change, an empty or inverted span — and report whether
     * it was rejected so the caller can assert conditionally.
     *
     * Only `TemporalInvalidSpellException` is tolerated: it means "you asked for
     * a nonsensical window", which the shuffler legitimately does. Any other
     * temporal exception (an overlap-guard trip, a write conflict) signals
     * corruption and is deliberately left to propagate and fail the trail.
     */
    private function attempt(callable $write): bool
    {
        try {
            $write();

            return false;
        } catch (TemporalInvalidSpellException) {
            return true;
        }
    }

    /**
     * A stable, comparable view of a set of temporal rows: the given dimension
     * columns plus the valid window and amount, ordered deterministically so two
     * snapshots of the same belief compare equal.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $rows
     * @param  list<string>  $dimensions
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(Collection $rows, array $dimensions = []): array
    {
        return $rows
            ->sortBy(fn (Model $row): string => implode('|', [
                ...array_map(fn (string $column): string => (string) ($this->scalar($row->getAttribute($column)) ?? ''), $dimensions),
                (string) $this->instant($row->getAttribute('valid_from')),
            ]))
            ->map(fn (Model $row): array => [
                ...array_combine($dimensions, array_map(fn (string $column): int|string|null => $this->scalar($row->getAttribute($column)), $dimensions)),
                'valid_from' => $this->instant($row->getAttribute('valid_from')),
                'valid_to' => $this->instant($row->getAttribute('valid_to')),
                'amount' => $this->scalar($row->getAttribute('amount')),
            ])
            ->values()
            ->all();
    }

    private function instant(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->format('Y-m-d H:i:s.u') : null;
    }

    private function scalar(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}
