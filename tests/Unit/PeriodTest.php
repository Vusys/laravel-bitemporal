<?php

declare(strict_types=1);

namespace Bitemporal\Tests\Unit;

use Bitemporal\Exceptions\TemporalInvalidPeriodException;
use Bitemporal\Period;
use Bitemporal\PeriodBounds;
use Bitemporal\Tests\TestCase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Date;

final class PeriodTest extends TestCase
{
    private function at(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }

    private function period(?string $from, ?string $to): Period
    {
        return new Period(
            $from === null ? null : $this->at($from),
            $to === null ? null : $this->at($to),
        );
    }

    public function test_construction_normal(): void
    {
        $period = $this->period('2026-01-01', '2026-06-01');

        $this->assertTrue($period->from?->equalTo($this->at('2026-01-01')));
        $this->assertTrue($period->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_rejects_inverted_period(): void
    {
        $this->expectException(TemporalInvalidPeriodException::class);
        $this->expectExceptionMessage('valid_from must be before valid_to');

        $this->period('2026-06-01', '2026-01-01');
    }

    public function test_rejects_zero_length_by_default(): void
    {
        $this->expectException(TemporalInvalidPeriodException::class);

        $this->period('2026-01-01', '2026-01-01');
    }

    public function test_allows_zero_length_when_configured(): void
    {
        config(['bitemporal.periods.allow_zero_length' => true]);

        $period = $this->period('2026-01-01', '2026-01-01');

        $this->assertTrue($period->isEmpty());
    }

    public function test_named_constructors(): void
    {
        $this->assertTrue(Period::unbounded()->isUnbounded());
        $this->assertTrue(Period::startingAt('2026-01-01')->isOpenEnded());
        $this->assertTrue(Period::startingAt('2026-01-01')->from?->equalTo($this->at('2026-01-01')));
        $this->assertTrue(Period::endingAt('2026-06-01')->isOpenStart());
        $this->assertTrue(Period::endingAt('2026-06-01')->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_from_array_round_trips(): void
    {
        $period = $this->period('2026-01-01 09:30:00', '2026-06-01');
        $rebuilt = Period::fromArray($period->toArray());

        $this->assertTrue($period->equals($rebuilt));
    }

    public function test_from_array_handles_nulls(): void
    {
        $this->assertTrue(Period::fromArray([])->isUnbounded());
        $this->assertTrue(Period::fromArray(['from' => '2026-01-01'])->isOpenEnded());
    }

    public function test_between_closed_open_is_identity(): void
    {
        $period = Period::between('2026-01-01', '2026-06-01');

        $this->assertTrue($period->from?->equalTo($this->at('2026-01-01')));
        $this->assertTrue($period->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_between_closed_bounds_shifts_upper(): void
    {
        $period = Period::between('2026-01-01', '2026-06-01', PeriodBounds::Closed);

        $this->assertTrue($period->from?->equalTo($this->at('2026-01-01')));
        $this->assertTrue($period->to?->equalTo($this->at('2026-06-01')->addMicrosecond()));
        $this->assertTrue($period->containsInstant($this->at('2026-06-01')));
    }

    public function test_between_open_closed_shifts_both(): void
    {
        $period = Period::between('2026-01-01', '2026-06-01', PeriodBounds::OpenClosed);

        $this->assertTrue($period->from?->equalTo($this->at('2026-01-01')->addMicrosecond()));
        $this->assertTrue($period->to?->equalTo($this->at('2026-06-01')->addMicrosecond()));
        $this->assertFalse($period->containsInstant($this->at('2026-01-01')));
    }

    public function test_between_open_bounds_shifts_lower(): void
    {
        $period = Period::between('2026-01-01', '2026-06-01', PeriodBounds::Open);

        $this->assertTrue($period->from?->equalTo($this->at('2026-01-01')->addMicrosecond()));
        $this->assertTrue($period->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_between_open_ended_with_inclusive_upper_leaves_null(): void
    {
        $period = Period::between('2026-01-01', null, PeriodBounds::Closed);

        $this->assertTrue($period->isOpenEnded());
    }

    public function test_open_state_predicates(): void
    {
        $this->assertTrue($this->period('2026-01-01', null)->isOpenEnded());
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->isOpenEnded());
        $this->assertFalse($this->period(null, '2026-06-01')->isOpenEnded());

        $this->assertFalse($this->period('2026-01-01', null)->isOpenStart());
        $this->assertTrue($this->period(null, '2026-06-01')->isOpenStart());
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->isOpenStart());

        $this->assertTrue(Period::unbounded()->isUnbounded());
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->isUnbounded());
        $this->assertFalse($this->period('2026-01-01', null)->isUnbounded());
        $this->assertFalse($this->period(null, '2026-06-01')->isUnbounded());
    }

    public function test_is_empty(): void
    {
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->isEmpty());
        $this->assertFalse($this->period('2026-01-01', null)->isEmpty());
        $this->assertFalse($this->period(null, '2026-06-01')->isEmpty());
        $this->assertFalse(Period::unbounded()->isEmpty());

        config(['bitemporal.periods.allow_zero_length' => true]);
        $this->assertTrue($this->period('2026-01-01', '2026-01-01')->isEmpty());
    }

    public function test_length(): void
    {
        $this->assertNull($this->period('2026-01-01', null)->length());
        $this->assertNull($this->period(null, '2026-06-01')->length());

        $length = $this->period('2026-01-01', '2026-01-08')->length();
        $this->assertInstanceOf(CarbonInterval::class, $length);
        $this->assertSame(7, (int) $this->at('2026-01-01')->diffInDays($this->at('2026-01-08')));
    }

    public function test_contains_instant_half_open(): void
    {
        $period = $this->period('2026-01-01', '2026-06-01');

        $this->assertTrue($period->containsInstant($this->at('2026-01-01')));
        $this->assertTrue($period->containsInstant($this->at('2026-03-15')));
        $this->assertFalse($period->containsInstant($this->at('2026-06-01')));
        $this->assertFalse($period->containsInstant($this->at('2025-12-31')));
    }

    public function test_contains_instant_with_open_bounds(): void
    {
        $this->assertTrue($this->period('2026-01-01', null)->containsInstant($this->at('2030-01-01')));
        $this->assertFalse($this->period('2026-01-01', null)->containsInstant($this->at('2025-01-01')));
        $this->assertTrue($this->period(null, '2026-06-01')->containsInstant($this->at('1900-01-01')));
        $this->assertTrue(Period::unbounded()->containsInstant($this->at('2026-03-01')));
    }

    public function test_contains_period(): void
    {
        $outer = $this->period('2026-01-01', '2026-12-01');

        $this->assertTrue($outer->containsPeriod($this->period('2026-03-01', '2026-09-01')));
        $this->assertTrue($outer->containsPeriod($this->period('2026-01-01', '2026-12-01')));
        $this->assertFalse($outer->containsPeriod($this->period('2025-12-01', '2026-09-01')));
        $this->assertFalse($outer->containsPeriod($this->period('2026-03-01', '2027-01-01')));
        $this->assertFalse($outer->containsPeriod($this->period('2026-03-01', null)));
        $this->assertFalse($outer->containsPeriod($this->period(null, '2026-09-01')));
    }

    public function test_contains_period_with_unbounded_container(): void
    {
        $this->assertTrue(Period::unbounded()->containsPeriod($this->period('2026-01-01', '2026-06-01')));
        $this->assertTrue(Period::unbounded()->containsPeriod(Period::unbounded()));
        $this->assertTrue($this->period('2026-01-01', null)->containsPeriod($this->period('2026-03-01', null)));
    }

    public function test_intersects(): void
    {
        $a = $this->period('2026-01-01', '2026-06-01');

        $this->assertTrue($a->intersects($this->period('2026-03-01', '2026-09-01')));
        $this->assertTrue($a->intersects($this->period('2025-01-01', '2026-02-01')));
        $this->assertFalse($a->intersects($this->period('2026-06-01', '2026-09-01')));
        $this->assertFalse($a->intersects($this->period('2026-07-01', '2026-09-01')));
        $this->assertFalse($a->intersects($this->period('2025-01-01', '2026-01-01')));
    }

    public function test_intersects_with_open_bounds(): void
    {
        $this->assertTrue(Period::unbounded()->intersects($this->period('2026-01-01', '2026-06-01')));
        $this->assertTrue($this->period('2026-01-01', null)->intersects($this->period('2030-01-01', null)));
        $this->assertTrue($this->period(null, '2026-06-01')->intersects($this->period('2026-01-01', '2026-03-01')));
        $this->assertTrue($this->period('2026-01-01', null)->intersects($this->period(null, '2026-06-01')));
        $this->assertFalse($this->period(null, '2026-01-01')->intersects($this->period('2026-01-01', null)));
    }

    public function test_factories_accept_mutable_carbon(): void
    {
        $from = Date::parse('2026-01-01 09:30:00');
        $to = Date::parse('2026-06-01 09:30:00');

        $period = Period::between($from, $to);

        $this->assertInstanceOf(CarbonImmutable::class, $period->from);
        $this->assertTrue($period->from->equalTo($this->at('2026-01-01 09:30:00')));
        $this->assertTrue(Period::startingAt($from)->from?->equalTo($this->at('2026-01-01 09:30:00')));
    }

    public function test_contained_by(): void
    {
        $this->assertTrue($this->period('2026-03-01', '2026-09-01')->containedBy($this->period('2026-01-01', '2026-12-01')));
        $this->assertFalse($this->period('2026-01-01', '2026-12-01')->containedBy($this->period('2026-03-01', '2026-09-01')));
    }

    public function test_intersect_returns_overlap(): void
    {
        $overlap = $this->period('2026-01-01', '2026-06-01')->intersect($this->period('2026-03-01', '2026-09-01'));

        $this->assertNotNull($overlap);
        $this->assertTrue($overlap->from?->equalTo($this->at('2026-03-01')));
        $this->assertTrue($overlap->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_intersect_with_open_bounds(): void
    {
        $this->assertTrue(
            $this->period('2026-01-01', null)->intersect($this->period(null, '2026-06-01'))
                ?->equals($this->period('2026-01-01', '2026-06-01')),
        );
        $this->assertTrue(
            $this->period(null, '2026-06-01')->intersect($this->period('2026-03-01', null))
                ?->equals($this->period('2026-03-01', '2026-06-01')),
        );
        $this->assertTrue(
            $this->period('2026-01-01', '2026-12-01')->intersect($this->period(null, '2026-06-01'))
                ?->equals($this->period('2026-01-01', '2026-06-01')),
        );
        $this->assertTrue(
            $this->period('2026-01-01', '2026-12-01')->intersect($this->period('2026-03-01', null))
                ?->equals($this->period('2026-03-01', '2026-12-01')),
        );
        $this->assertTrue(
            Period::unbounded()->intersect($this->period('2026-03-01', '2026-06-01'))
                ?->equals($this->period('2026-03-01', '2026-06-01')),
        );
    }

    public function test_intersect_disjoint_is_null(): void
    {
        $this->assertNull($this->period('2026-01-01', '2026-03-01')->intersect($this->period('2026-06-01', '2026-09-01')));
        $this->assertNull($this->period('2026-01-01', '2026-03-01')->intersect($this->period('2026-03-01', '2026-09-01')));
    }

    public function test_subtract(): void
    {
        $base = $this->period('2026-01-01', '2026-12-01');

        $middle = $base->subtract($this->period('2026-04-01', '2026-08-01'));
        $this->assertCount(2, $middle);
        $this->assertTrue($middle[0]->equals($this->period('2026-01-01', '2026-04-01')));
        $this->assertTrue($middle[1]->equals($this->period('2026-08-01', '2026-12-01')));

        $disjoint = $base->subtract($this->period('2027-01-01', '2027-06-01'));
        $this->assertCount(1, $disjoint);
        $this->assertTrue($disjoint[0]->equals($base));

        $whole = $base->subtract($this->period('2025-01-01', '2027-01-01'));
        $this->assertCount(0, $whole);

        $leftOnly = $base->subtract($this->period('2026-06-01', '2027-01-01'));
        $this->assertCount(1, $leftOnly);
        $this->assertTrue($leftOnly[0]->equals($this->period('2026-01-01', '2026-06-01')));

        $rightOnly = $base->subtract($this->period('2025-01-01', '2026-06-01'));
        $this->assertCount(1, $rightOnly);
        $this->assertTrue($rightOnly[0]->equals($this->period('2026-06-01', '2026-12-01')));
    }

    public function test_subtract_open_ended(): void
    {
        $openEnded = $this->period('2026-01-01', null);

        $result = $openEnded->subtract($this->period('2026-06-01', '2026-09-01'));
        $this->assertCount(2, $result);
        $this->assertTrue($result[0]->equals($this->period('2026-01-01', '2026-06-01')));
        $this->assertTrue($result[1]->equals($this->period('2026-09-01', null)));
    }

    public function test_merge(): void
    {
        $merged = $this->period('2026-01-01', '2026-06-01')->merge($this->period('2026-04-01', '2026-09-01'));
        $this->assertTrue($merged->equals($this->period('2026-01-01', '2026-09-01')));

        $adjacent = $this->period('2026-01-01', '2026-06-01')->merge($this->period('2026-06-01', '2026-09-01'));
        $this->assertTrue($adjacent->equals($this->period('2026-01-01', '2026-09-01')));
    }

    public function test_merge_with_open_bounds(): void
    {
        $merged = $this->period(null, '2026-06-01')->merge($this->period('2026-04-01', null));
        $this->assertTrue($merged->isUnbounded());

        $openEndedRight = $this->period('2026-01-01', '2026-06-01')->merge($this->period('2026-04-01', null));
        $this->assertTrue($openEndedRight->equals($this->period('2026-01-01', null)));

        $openStartLeft = $this->period(null, '2026-06-01')->merge($this->period('2026-04-01', '2026-09-01'));
        $this->assertTrue($openStartLeft->equals($this->period(null, '2026-09-01')));

        $bothBounded = $this->period('2026-01-01', '2026-06-01')->merge($this->period('2026-03-01', '2026-09-01'));
        $this->assertTrue($bothBounded->equals($this->period('2026-01-01', '2026-09-01')));

        $openStartOther = $this->period('2026-01-01', '2026-06-01')->merge($this->period(null, '2026-09-01'));
        $this->assertTrue($openStartOther->equals($this->period(null, '2026-09-01')));

        $openEndedThis = $this->period('2026-01-01', null)->merge($this->period('2026-03-01', '2026-09-01'));
        $this->assertTrue($openEndedThis->equals($this->period('2026-01-01', null)));
    }

    public function test_merge_disjoint_throws(): void
    {
        $this->expectException(TemporalInvalidPeriodException::class);

        $this->period('2026-01-01', '2026-03-01')->merge($this->period('2026-06-01', '2026-09-01'));
    }

    public function test_meets(): void
    {
        $this->assertTrue($this->period('2026-01-01', '2026-06-01')->meets($this->period('2026-06-01', '2026-09-01')));
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->meets($this->period('2026-07-01', '2026-09-01')));
        $this->assertFalse($this->period('2026-01-01', null)->meets($this->period('2026-06-01', '2026-09-01')));
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->meets($this->period(null, '2026-09-01')));
    }

    public function test_precedes_and_follows(): void
    {
        $early = $this->period('2026-01-01', '2026-06-01');
        $late = $this->period('2026-06-01', '2026-09-01');

        $this->assertTrue($early->precedes($late));
        $this->assertTrue($late->follows($early));
        $this->assertFalse($late->precedes($early));
        $this->assertFalse($this->period('2026-01-01', null)->precedes($late));
        $this->assertFalse($early->precedes($this->period(null, '2026-09-01')));
    }

    public function test_is_adjacent(): void
    {
        $this->assertTrue($this->period('2026-01-01', '2026-06-01')->isAdjacent($this->period('2026-06-01', '2026-09-01')));
        $this->assertTrue($this->period('2026-06-01', '2026-09-01')->isAdjacent($this->period('2026-01-01', '2026-06-01')));
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->isAdjacent($this->period('2026-07-01', '2026-09-01')));
    }

    public function test_with_from_and_with_to(): void
    {
        $base = $this->period('2026-01-01', '2026-06-01');

        $this->assertTrue($base->withFrom($this->at('2026-02-01'))->equals($this->period('2026-02-01', '2026-06-01')));
        $this->assertTrue($base->withTo($this->at('2026-09-01'))->equals($this->period('2026-01-01', '2026-09-01')));
        $this->assertTrue($base->withFrom(null)->isOpenStart());
        $this->assertTrue($base->withTo(null)->isOpenEnded());
    }

    public function test_equals(): void
    {
        $this->assertTrue($this->period('2026-01-01', '2026-06-01')->equals($this->period('2026-01-01', '2026-06-01')));
        $this->assertTrue(Period::unbounded()->equals(Period::unbounded()));
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->equals($this->period('2026-01-01', '2026-07-01')));
        $this->assertFalse($this->period('2026-01-01', null)->equals($this->period('2026-01-01', '2026-06-01')));
        $this->assertFalse($this->period(null, '2026-06-01')->equals($this->period('2026-01-01', '2026-06-01')));
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->equals($this->period('2026-01-01', null)));
        $this->assertFalse($this->period('2026-01-01', '2026-06-01')->equals($this->period(null, '2026-06-01')));
    }

    public function test_to_string(): void
    {
        $this->assertSame('[2026-01-01, 2026-06-01)', (string) $this->period('2026-01-01', '2026-06-01'));
        $this->assertSame('[2026-01-01, ∞)', (string) $this->period('2026-01-01', null));
        $this->assertSame('[-∞, 2026-06-01)', (string) $this->period(null, '2026-06-01'));
        $this->assertSame('[-∞, ∞)', (string) Period::unbounded());
        $this->assertSame('[2026-01-01 09:30:00.000000, ∞)', (string) $this->period('2026-01-01 09:30:00', null));
    }
}
