<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class AttributeEquality
{
    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function attributesMatch(array $a, array $b): bool
    {
        return self::arraysEqual($a, $b);
    }

    public static function equals(mixed $a, mixed $b): bool
    {
        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return CarbonImmutable::instance($a)->equalTo($b);
        }

        if (is_array($a) && is_array($b)) {
            return self::arraysEqual($a, $b);
        }

        if (is_array($a) || is_array($b) || $a instanceof DateTimeInterface || $b instanceof DateTimeInterface) {
            return false;
        }

        // Two integers: compare exactly. Routing them through float would fold
        // distinct values past 2^53 (9007199254740992 vs ...993) and silently
        // drop a real correction. bool is excluded here (is_int(true) is false),
        // so true/1 stay distinct.
        if (is_int($a) && is_int($b)) {
            return $a === $b;
        }

        // Two strings: textually distinct numeric strings are genuinely distinct
        // values — zero-padded codes ("007" vs "7"), exponential notation
        // ("1000" vs "1e3"). Folding them via float would treat a real
        // correction as a no-op and drop the write. Compare strictly.
        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }

        // Mixed string/int/float: compare by value. This is the driver type
        // drift the fold exists for ("10.00" from an un-cast decimal column vs
        // 10 from a cast one, or "0" vs 0), which would otherwise register as a
        // spurious change and trigger needless close+reinsert churn or a failed
        // compaction. bool is excluded (is_numeric(true) is false and a bool is
        // not int/float/string), so true/1 stay distinct.
        $aNumeric = self::asFloat($a);
        $bNumeric = self::asFloat($b);

        if ($aNumeric !== null && $bNumeric !== null) {
            return $aNumeric === $bNumeric;
        }

        return $a === $b;
    }

    private static function asFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     */
    private static function arraysEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        if (array_is_list($a) !== array_is_list($b)) {
            return false;
        }

        if (array_is_list($a)) {
            return array_all($a, fn ($value, $index): bool => self::equals($value, $b[$index]));
        }

        ksort($a);
        ksort($b);

        if (array_keys($a) !== array_keys($b)) {
            return false;
        }

        return array_all($a, fn ($value, $key): bool => self::equals($value, $b[$key]));
    }
}
