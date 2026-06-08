<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

use Vusys\Bitemporal\BitemporalBuilder;

/**
 * Dimension scoping for the temporal builder. forDimensions() both filters
 * reads to a dimension tuple (NULL treated as a distinct value) and records the
 * tuple so the write API can scope and stamp rows.
 *
 * @phpstan-require-extends BitemporalBuilder
 */
trait HasTemporalDimensions
{
    /**
     * @var array<string, mixed>
     */
    private array $temporalDimensionTuple = [];

    /**
     * @param  array<string, mixed>  $dimensions
     */
    public function forDimensions(array $dimensions): static
    {
        $this->temporalDimensionTuple = $dimensions;

        foreach ($dimensions as $column => $value) {
            $qualified = $this->qualify($column);

            if ($value === null) {
                $this->whereNull($qualified);

                continue;
            }

            $this->where($qualified, '=', $value);
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function temporalDimensionTuple(): array
    {
        return $this->temporalDimensionTuple;
    }

    /**
     * True when the query carries a where clause on a column outside the given
     * allow-list (used to reject raw where() scoping before a temporal write).
     *
     * @param  array<int, string>  $allowedColumns
     */
    public function hasWheresOutside(array $allowedColumns): bool
    {
        $wheres = $this->getQuery()->wheres;

        foreach ($wheres as $where) {
            $column = $where['column'] ?? null;

            if (! is_string($column)) {
                return true;
            }

            $unqualified = str_contains($column, '.') ? substr((string) strrchr($column, '.'), 1) : $column;

            if (! in_array($unqualified, $allowedColumns, true)) {
                return true;
            }
        }

        return false;
    }
}
