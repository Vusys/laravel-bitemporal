<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Support;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Support\AttributeEquality;
use Vusys\Bitemporal\Tests\TestCase;

final class AttributeEqualityTest extends TestCase
{
    public function test_scalars_use_strict_equality(): void
    {
        $this->assertTrue(AttributeEquality::equals(1000, 1000));
        $this->assertTrue(AttributeEquality::equals('GBP', 'GBP'));
        $this->assertTrue(AttributeEquality::equals(null, null));
        $this->assertTrue(AttributeEquality::equals(true, true));

        // Non-numeric strings stay strict; a numeric value never equals null/bool.
        $this->assertFalse(AttributeEquality::equals('GBP', 'USD'));
        $this->assertFalse(AttributeEquality::equals(1, true));
        $this->assertFalse(AttributeEquality::equals(0, null));
    }

    public function test_numeric_values_compare_by_value_across_types(): void
    {
        // Issue #50: driver type drift ("10.00" un-cast decimal vs 10 cast int,
        // or "0" vs 0) must not register as a change and churn the timeline.
        $this->assertTrue(AttributeEquality::equals(1000, '1000'));
        $this->assertTrue(AttributeEquality::equals('10.00', 10));
        $this->assertTrue(AttributeEquality::equals('0', 0));
        $this->assertTrue(AttributeEquality::equals(2.5, '2.50'));

        // Genuinely different numbers still differ.
        $this->assertFalse(AttributeEquality::equals('10.00', 10.01));
        $this->assertFalse(AttributeEquality::equals(1, 2));

        // A numeric string vs a non-numeric string is not numeric-numeric.
        $this->assertFalse(AttributeEquality::equals('10', 'ten'));
    }

    public function test_datetimes_compared_by_instant(): void
    {
        $newYork = CarbonImmutable::parse('2026-01-01 12:00:00', 'America/New_York');
        $utc = CarbonImmutable::parse('2026-01-01 12:00:00', 'UTC');
        $sameInstantUtc = $newYork->utc();

        $this->assertTrue(AttributeEquality::equals($newYork, $sameInstantUtc));
        $this->assertFalse(AttributeEquality::equals($newYork, $utc));
    }

    public function test_microsecond_resolution_for_datetimes(): void
    {
        $a = CarbonImmutable::parse('2026-01-01 00:00:00.000001');
        $b = CarbonImmutable::parse('2026-01-01 00:00:00.000002');

        $this->assertFalse(AttributeEquality::equals($a, $a->addMicrosecond()));
        $this->assertTrue(AttributeEquality::equals($a, $b->subMicrosecond()));
    }

    public function test_datetime_versus_scalar_is_unequal(): void
    {
        $this->assertFalse(AttributeEquality::equals(CarbonImmutable::parse('2026-01-01'), '2026-01-01'));
        $this->assertFalse(AttributeEquality::equals('2026-01-01', CarbonImmutable::parse('2026-01-01')));
    }

    public function test_list_arrays_compared_in_order(): void
    {
        $this->assertTrue(AttributeEquality::equals([1, 2, 3], [1, 2, 3]));
        $this->assertFalse(AttributeEquality::equals([1, 2, 3], [3, 2, 1]));
        $this->assertFalse(AttributeEquality::equals([1, 2], [1, 2, 3]));
    }

    public function test_assoc_arrays_are_key_order_independent(): void
    {
        $this->assertTrue(AttributeEquality::equals(
            ['amount' => 1000, 'currency' => 'GBP'],
            ['currency' => 'GBP', 'amount' => 1000],
        ));

        $this->assertFalse(AttributeEquality::equals(
            ['amount' => 1000, 'currency' => 'GBP'],
            ['amount' => 1000, 'currency' => 'USD'],
        ));
    }

    public function test_list_versus_assoc_is_unequal(): void
    {
        $this->assertFalse(AttributeEquality::equals([0 => 'a', 1 => 'b'], ['x' => 'a', 'y' => 'b']));
    }

    public function test_different_key_sets_unequal(): void
    {
        $this->assertFalse(AttributeEquality::equals(['a' => 1], ['b' => 1]));
    }

    public function test_nested_arrays_recurse(): void
    {
        $this->assertTrue(AttributeEquality::equals(
            ['meta' => ['tags' => ['x', 'y'], 'n' => 1]],
            ['meta' => ['n' => 1, 'tags' => ['x', 'y']]],
        ));

        $this->assertFalse(AttributeEquality::equals(
            ['meta' => ['tags' => ['x', 'y']]],
            ['meta' => ['tags' => ['y', 'x']]],
        ));
    }

    public function test_array_versus_scalar_is_unequal(): void
    {
        $this->assertFalse(AttributeEquality::equals(['a' => 1], 1));
        $this->assertFalse(AttributeEquality::equals(1, ['a' => 1]));
    }

    public function test_datetime_versus_array_is_unequal(): void
    {
        $date = CarbonImmutable::parse('2026-01-01');

        $this->assertFalse(AttributeEquality::equals($date, ['a' => 1]));
        $this->assertFalse(AttributeEquality::equals(['a' => 1], $date));
    }

    public function test_array_versus_null_is_unequal(): void
    {
        $this->assertFalse(AttributeEquality::equals(['a' => 1], null));
        $this->assertFalse(AttributeEquality::equals(null, ['a' => 1]));
    }

    public function test_attributes_match_helper(): void
    {
        $this->assertTrue(AttributeEquality::attributesMatch(
            ['amount' => 1000, 'currency' => 'GBP'],
            ['currency' => 'GBP', 'amount' => 1000],
        ));
        $this->assertTrue(AttributeEquality::attributesMatch([], []));
        $this->assertFalse(AttributeEquality::attributesMatch(['amount' => 1000], ['amount' => 1200]));
    }
}
