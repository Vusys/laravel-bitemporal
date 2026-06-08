<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Support;

use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;

/**
 * Validates the dimension tuple supplied to a temporal write against the
 * model's declared dimensions, and reconciles it with the attributes payload.
 */
final class DimensionValidator
{
    /**
     * Assert the tuple names exactly the declared dimensions.
     *
     * @param  array<int, string>  $declared
     * @param  array<string, mixed>  $tuple
     */
    public static function assertComplete(array $declared, array $tuple): void
    {
        foreach ($declared as $column) {
            if (! array_key_exists($column, $tuple)) {
                throw TemporalMissingDimensionException::incomplete($column);
            }
        }

        foreach (array_keys($tuple) as $column) {
            if (! in_array($column, $declared, true)) {
                throw TemporalMissingDimensionException::unknownDimension($column);
            }
        }
    }

    /**
     * Remove dimension keys from the attributes payload, throwing if any
     * conflicts with the dimension tuple.
     *
     * @param  array<int, string>  $declared
     * @param  array<string, mixed>  $tuple
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function reconcileAttributes(array $declared, array $tuple, array $attributes): array
    {
        foreach ($declared as $column) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            if (! AttributeEquality::equals($attributes[$column], $tuple[$column] ?? null)) {
                throw TemporalMissingDimensionException::conflict($column);
            }

            unset($attributes[$column]);
        }

        return $attributes;
    }
}
