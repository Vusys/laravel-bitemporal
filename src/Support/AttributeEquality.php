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

        return $a === $b;
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
