<?php

declare(strict_types=1);

namespace Bitemporal\Tests\Property;

use Bitemporal\Period;
use Bitemporal\Tests\TestCase;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('property')]
final class PeriodPropertyTest extends TestCase
{
    private const int ITERATIONS = 300;

    private const string EPOCH = '2026-01-01 00:00:00';

    private function randomPeriod(bool $allowOpen = true): Period
    {
        $base = CarbonImmutable::parse(self::EPOCH);

        $from = $allowOpen && random_int(0, 5) === 0
            ? null
            : $base->addDays(random_int(0, 200));

        $to = $allowOpen && random_int(0, 5) === 0
            ? null
            : ($from === null
                ? $base->addDays(random_int(0, 200))
                : $from->addDays(random_int(1, 200)));

        return new Period($from, $to);
    }

    public function test_intersects_is_symmetric(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = $this->randomPeriod();
            $b = $this->randomPeriod();

            $this->assertSame(
                $a->intersects($b),
                $b->intersects($a),
                "intersects asymmetric for {$a} and {$b}",
            );
        }
    }

    public function test_intersect_result_is_contained_by_both(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = $this->randomPeriod();
            $b = $this->randomPeriod();

            $overlap = $a->intersect($b);

            if (! $overlap instanceof Period) {
                $this->assertFalse($a->intersects($b));

                continue;
            }

            $this->assertTrue($a->containsPeriod($overlap), "overlap not in a: {$a} ∩ {$b} = {$overlap}");
            $this->assertTrue($b->containsPeriod($overlap), "overlap not in b: {$a} ∩ {$b} = {$overlap}");
        }
    }

    public function test_subtract_pieces_are_disjoint_from_other(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = $this->randomPeriod();
            $b = $this->randomPeriod();

            foreach ($a->subtract($b) as $piece) {
                $this->assertTrue($a->containsPeriod($piece), "piece {$piece} not within {$a}");
                $this->assertFalse($piece->intersects($b), "piece {$piece} intersects subtracted {$b}");
            }
        }
    }

    public function test_contains_instant_consistent_with_contains_zero_width_logic(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $period = $this->randomPeriod(allowOpen: false);
            $from = $period->from;
            $this->assertNotNull($from);

            $this->assertTrue($period->containsInstant($from));
            $this->assertTrue($period->containsInstant($from->addMicrosecond()));

            $to = $period->to;
            $this->assertNotNull($to);
            $this->assertFalse($period->containsInstant($to));
        }
    }

    public function test_merge_of_adjacent_or_overlapping_contains_both(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = $this->randomPeriod(allowOpen: false);
            $b = $this->randomPeriod(allowOpen: false);

            if (! $a->intersects($b) && ! $a->isAdjacent($b)) {
                continue;
            }

            $merged = $a->merge($b);

            $this->assertTrue($merged->containsPeriod($a), "merge {$merged} lost {$a}");
            $this->assertTrue($merged->containsPeriod($b), "merge {$merged} lost {$b}");
        }
    }

    public function test_equals_is_reflexive(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $period = $this->randomPeriod();
            $this->assertTrue($period->equals($period));
            $this->assertTrue($period->equals(Period::fromArray($period->toArray())));
        }
    }
}
