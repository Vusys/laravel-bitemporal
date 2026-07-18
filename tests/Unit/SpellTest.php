<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Date;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\SpellBounds;
use Vusys\Bitemporal\Tests\TestCase;

final class SpellTest extends TestCase
{
    private function at(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }

    private function spell(?string $from, ?string $to): Spell
    {
        return new Spell(
            $from === null ? null : $this->at($from),
            $to === null ? null : $this->at($to),
        );
    }

    public function test_construction_normal(): void
    {
        $spell = $this->spell('2026-01-01', '2026-06-01');

        $this->assertTrue($spell->from?->equalTo($this->at('2026-01-01')));
        $this->assertTrue($spell->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_rejects_inverted_spell(): void
    {
        $this->expectException(TemporalInvalidSpellException::class);
        $this->expectExceptionMessage('valid_from must be before valid_to');

        $this->spell('2026-06-01', '2026-01-01');
    }

    public function test_rejects_zero_length_by_default(): void
    {
        $this->expectException(TemporalInvalidSpellException::class);

        $this->spell('2026-01-01', '2026-01-01');
    }

    public function test_allows_zero_length_when_configured(): void
    {
        config(['bitemporal.spells.allow_zero_length' => true]);

        $spell = $this->spell('2026-01-01', '2026-01-01');

        $this->assertTrue($spell->isEmpty());
    }

    public function test_named_constructors(): void
    {
        $this->assertTrue(Spell::unbounded()->isUnbounded());
        $this->assertTrue(Spell::startingAt('2026-01-01')->isOpenEnded());
        $this->assertTrue(Spell::startingAt('2026-01-01')->from?->equalTo($this->at('2026-01-01')));
        $this->assertTrue(Spell::endingAt('2026-06-01')->isOpenStart());
        $this->assertTrue(Spell::endingAt('2026-06-01')->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_from_array_round_trips(): void
    {
        $spell = $this->spell('2026-01-01 09:30:00', '2026-06-01');
        $rebuilt = Spell::fromArray($spell->toArray());

        $this->assertTrue($spell->equals($rebuilt));
    }

    public function test_from_array_handles_nulls(): void
    {
        $this->assertTrue(Spell::fromArray([])->isUnbounded());
        $this->assertTrue(Spell::fromArray(['from' => '2026-01-01'])->isOpenEnded());
    }

    public function test_between_closed_open_is_identity(): void
    {
        $spell = Spell::between('2026-01-01', '2026-06-01');

        $this->assertTrue($spell->from?->equalTo($this->at('2026-01-01')));
        $this->assertTrue($spell->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_between_closed_bounds_shifts_upper(): void
    {
        $spell = Spell::between('2026-01-01', '2026-06-01', SpellBounds::Closed);

        $this->assertTrue($spell->from?->equalTo($this->at('2026-01-01')));
        $this->assertTrue($spell->to?->equalTo($this->at('2026-06-01')->addMicrosecond()));
        $this->assertTrue($spell->containsInstant($this->at('2026-06-01')));
    }

    public function test_between_open_closed_shifts_both(): void
    {
        $spell = Spell::between('2026-01-01', '2026-06-01', SpellBounds::OpenClosed);

        $this->assertTrue($spell->from?->equalTo($this->at('2026-01-01')->addMicrosecond()));
        $this->assertTrue($spell->to?->equalTo($this->at('2026-06-01')->addMicrosecond()));
        $this->assertFalse($spell->containsInstant($this->at('2026-01-01')));
    }

    public function test_between_open_bounds_shifts_lower(): void
    {
        $spell = Spell::between('2026-01-01', '2026-06-01', SpellBounds::Open);

        $this->assertTrue($spell->from?->equalTo($this->at('2026-01-01')->addMicrosecond()));
        $this->assertTrue($spell->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_between_open_ended_with_inclusive_upper_leaves_null(): void
    {
        $spell = Spell::between('2026-01-01', null, SpellBounds::Closed);

        $this->assertTrue($spell->isOpenEnded());
    }

    public function test_open_state_predicates(): void
    {
        $this->assertTrue($this->spell('2026-01-01', null)->isOpenEnded());
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->isOpenEnded());
        $this->assertFalse($this->spell(null, '2026-06-01')->isOpenEnded());

        $this->assertFalse($this->spell('2026-01-01', null)->isOpenStart());
        $this->assertTrue($this->spell(null, '2026-06-01')->isOpenStart());
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->isOpenStart());

        $this->assertTrue(Spell::unbounded()->isUnbounded());
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->isUnbounded());
        $this->assertFalse($this->spell('2026-01-01', null)->isUnbounded());
        $this->assertFalse($this->spell(null, '2026-06-01')->isUnbounded());
    }

    public function test_is_empty(): void
    {
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->isEmpty());
        $this->assertFalse($this->spell('2026-01-01', null)->isEmpty());
        $this->assertFalse($this->spell(null, '2026-06-01')->isEmpty());
        $this->assertFalse(Spell::unbounded()->isEmpty());

        config(['bitemporal.spells.allow_zero_length' => true]);
        $this->assertTrue($this->spell('2026-01-01', '2026-01-01')->isEmpty());
    }

    public function test_length(): void
    {
        $this->assertNull($this->spell('2026-01-01', null)->length());
        $this->assertNull($this->spell(null, '2026-06-01')->length());

        $length = $this->spell('2026-01-01', '2026-01-08')->length();
        $this->assertInstanceOf(CarbonInterval::class, $length);
        $this->assertSame(7, (int) $this->at('2026-01-01')->diffInDays($this->at('2026-01-08')));
    }

    public function test_contains_instant_half_open(): void
    {
        $spell = $this->spell('2026-01-01', '2026-06-01');

        $this->assertTrue($spell->containsInstant($this->at('2026-01-01')));
        $this->assertTrue($spell->containsInstant($this->at('2026-03-15')));
        $this->assertFalse($spell->containsInstant($this->at('2026-06-01')));
        $this->assertFalse($spell->containsInstant($this->at('2025-12-31')));
    }

    public function test_contains_instant_with_open_bounds(): void
    {
        $this->assertTrue($this->spell('2026-01-01', null)->containsInstant($this->at('2030-01-01')));
        $this->assertFalse($this->spell('2026-01-01', null)->containsInstant($this->at('2025-01-01')));
        $this->assertTrue($this->spell(null, '2026-06-01')->containsInstant($this->at('1900-01-01')));
        $this->assertTrue(Spell::unbounded()->containsInstant($this->at('2026-03-01')));
    }

    public function test_contains_spell(): void
    {
        $outer = $this->spell('2026-01-01', '2026-12-01');

        $this->assertTrue($outer->containsSpell($this->spell('2026-03-01', '2026-09-01')));
        $this->assertTrue($outer->containsSpell($this->spell('2026-01-01', '2026-12-01')));
        $this->assertFalse($outer->containsSpell($this->spell('2025-12-01', '2026-09-01')));
        $this->assertFalse($outer->containsSpell($this->spell('2026-03-01', '2027-01-01')));
        $this->assertFalse($outer->containsSpell($this->spell('2026-03-01', null)));
        $this->assertFalse($outer->containsSpell($this->spell(null, '2026-09-01')));
    }

    public function test_contains_spell_with_unbounded_container(): void
    {
        $this->assertTrue(Spell::unbounded()->containsSpell($this->spell('2026-01-01', '2026-06-01')));
        $this->assertTrue(Spell::unbounded()->containsSpell(Spell::unbounded()));
        $this->assertTrue($this->spell('2026-01-01', null)->containsSpell($this->spell('2026-03-01', null)));
    }

    public function test_intersects(): void
    {
        $a = $this->spell('2026-01-01', '2026-06-01');

        $this->assertTrue($a->intersects($this->spell('2026-03-01', '2026-09-01')));
        $this->assertTrue($a->intersects($this->spell('2025-01-01', '2026-02-01')));
        $this->assertFalse($a->intersects($this->spell('2026-06-01', '2026-09-01')));
        $this->assertFalse($a->intersects($this->spell('2026-07-01', '2026-09-01')));
        $this->assertFalse($a->intersects($this->spell('2025-01-01', '2026-01-01')));
    }

    public function test_intersects_with_open_bounds(): void
    {
        $this->assertTrue(Spell::unbounded()->intersects($this->spell('2026-01-01', '2026-06-01')));
        $this->assertTrue($this->spell('2026-01-01', null)->intersects($this->spell('2030-01-01', null)));
        $this->assertTrue($this->spell(null, '2026-06-01')->intersects($this->spell('2026-01-01', '2026-03-01')));
        $this->assertTrue($this->spell('2026-01-01', null)->intersects($this->spell(null, '2026-06-01')));
        $this->assertFalse($this->spell(null, '2026-01-01')->intersects($this->spell('2026-01-01', null)));
    }

    public function test_factories_accept_mutable_carbon(): void
    {
        $from = Date::parse('2026-01-01 09:30:00');
        $to = Date::parse('2026-06-01 09:30:00');

        $spell = Spell::between($from, $to);

        $this->assertInstanceOf(CarbonImmutable::class, $spell->from);
        $this->assertTrue($spell->from->equalTo($this->at('2026-01-01 09:30:00')));
        $this->assertTrue(Spell::startingAt($from)->from?->equalTo($this->at('2026-01-01 09:30:00')));
    }

    public function test_contained_by(): void
    {
        $this->assertTrue($this->spell('2026-03-01', '2026-09-01')->containedBy($this->spell('2026-01-01', '2026-12-01')));
        $this->assertFalse($this->spell('2026-01-01', '2026-12-01')->containedBy($this->spell('2026-03-01', '2026-09-01')));
    }

    public function test_intersect_returns_overlap(): void
    {
        $overlap = $this->spell('2026-01-01', '2026-06-01')->intersect($this->spell('2026-03-01', '2026-09-01'));

        $this->assertNotNull($overlap);
        $this->assertTrue($overlap->from?->equalTo($this->at('2026-03-01')));
        $this->assertTrue($overlap->to?->equalTo($this->at('2026-06-01')));
    }

    public function test_intersect_with_open_bounds(): void
    {
        $this->assertTrue(
            $this->spell('2026-01-01', null)->intersect($this->spell(null, '2026-06-01'))
                ?->equals($this->spell('2026-01-01', '2026-06-01')),
        );
        $this->assertTrue(
            $this->spell(null, '2026-06-01')->intersect($this->spell('2026-03-01', null))
                ?->equals($this->spell('2026-03-01', '2026-06-01')),
        );
        $this->assertTrue(
            $this->spell('2026-01-01', '2026-12-01')->intersect($this->spell(null, '2026-06-01'))
                ?->equals($this->spell('2026-01-01', '2026-06-01')),
        );
        $this->assertTrue(
            $this->spell('2026-01-01', '2026-12-01')->intersect($this->spell('2026-03-01', null))
                ?->equals($this->spell('2026-03-01', '2026-12-01')),
        );
        $this->assertTrue(
            Spell::unbounded()->intersect($this->spell('2026-03-01', '2026-06-01'))
                ?->equals($this->spell('2026-03-01', '2026-06-01')),
        );
    }

    public function test_intersect_disjoint_is_null(): void
    {
        $this->assertNull($this->spell('2026-01-01', '2026-03-01')->intersect($this->spell('2026-06-01', '2026-09-01')));
        $this->assertNull($this->spell('2026-01-01', '2026-03-01')->intersect($this->spell('2026-03-01', '2026-09-01')));
    }

    public function test_subtract(): void
    {
        $base = $this->spell('2026-01-01', '2026-12-01');

        $middle = $base->subtract($this->spell('2026-04-01', '2026-08-01'));
        $this->assertCount(2, $middle);
        $this->assertTrue($middle[0]->equals($this->spell('2026-01-01', '2026-04-01')));
        $this->assertTrue($middle[1]->equals($this->spell('2026-08-01', '2026-12-01')));

        $disjoint = $base->subtract($this->spell('2027-01-01', '2027-06-01'));
        $this->assertCount(1, $disjoint);
        $this->assertTrue($disjoint[0]->equals($base));

        $whole = $base->subtract($this->spell('2025-01-01', '2027-01-01'));
        $this->assertCount(0, $whole);

        $leftOnly = $base->subtract($this->spell('2026-06-01', '2027-01-01'));
        $this->assertCount(1, $leftOnly);
        $this->assertTrue($leftOnly[0]->equals($this->spell('2026-01-01', '2026-06-01')));

        $rightOnly = $base->subtract($this->spell('2025-01-01', '2026-06-01'));
        $this->assertCount(1, $rightOnly);
        $this->assertTrue($rightOnly[0]->equals($this->spell('2026-06-01', '2026-12-01')));
    }

    public function test_subtract_open_ended(): void
    {
        $openEnded = $this->spell('2026-01-01', null);

        $result = $openEnded->subtract($this->spell('2026-06-01', '2026-09-01'));
        $this->assertCount(2, $result);
        $this->assertTrue($result[0]->equals($this->spell('2026-01-01', '2026-06-01')));
        $this->assertTrue($result[1]->equals($this->spell('2026-09-01', null)));
    }

    public function test_merge(): void
    {
        $merged = $this->spell('2026-01-01', '2026-06-01')->merge($this->spell('2026-04-01', '2026-09-01'));
        $this->assertTrue($merged->equals($this->spell('2026-01-01', '2026-09-01')));

        $adjacent = $this->spell('2026-01-01', '2026-06-01')->merge($this->spell('2026-06-01', '2026-09-01'));
        $this->assertTrue($adjacent->equals($this->spell('2026-01-01', '2026-09-01')));
    }

    public function test_merge_with_open_bounds(): void
    {
        $merged = $this->spell(null, '2026-06-01')->merge($this->spell('2026-04-01', null));
        $this->assertTrue($merged->isUnbounded());

        $openEndedRight = $this->spell('2026-01-01', '2026-06-01')->merge($this->spell('2026-04-01', null));
        $this->assertTrue($openEndedRight->equals($this->spell('2026-01-01', null)));

        $openStartLeft = $this->spell(null, '2026-06-01')->merge($this->spell('2026-04-01', '2026-09-01'));
        $this->assertTrue($openStartLeft->equals($this->spell(null, '2026-09-01')));

        $bothBounded = $this->spell('2026-01-01', '2026-06-01')->merge($this->spell('2026-03-01', '2026-09-01'));
        $this->assertTrue($bothBounded->equals($this->spell('2026-01-01', '2026-09-01')));

        $openStartOther = $this->spell('2026-01-01', '2026-06-01')->merge($this->spell(null, '2026-09-01'));
        $this->assertTrue($openStartOther->equals($this->spell(null, '2026-09-01')));

        $openEndedThis = $this->spell('2026-01-01', null)->merge($this->spell('2026-03-01', '2026-09-01'));
        $this->assertTrue($openEndedThis->equals($this->spell('2026-01-01', null)));
    }

    public function test_merge_disjoint_throws(): void
    {
        $this->expectException(TemporalInvalidSpellException::class);

        $this->spell('2026-01-01', '2026-03-01')->merge($this->spell('2026-06-01', '2026-09-01'));
    }

    public function test_meets(): void
    {
        $this->assertTrue($this->spell('2026-01-01', '2026-06-01')->meets($this->spell('2026-06-01', '2026-09-01')));
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->meets($this->spell('2026-07-01', '2026-09-01')));
        $this->assertFalse($this->spell('2026-01-01', null)->meets($this->spell('2026-06-01', '2026-09-01')));
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->meets($this->spell(null, '2026-09-01')));
    }

    public function test_precedes_and_follows(): void
    {
        $early = $this->spell('2026-01-01', '2026-06-01');
        $late = $this->spell('2026-06-01', '2026-09-01');

        $this->assertTrue($early->precedes($late));
        $this->assertTrue($late->follows($early));
        $this->assertFalse($late->precedes($early));
        $this->assertFalse($this->spell('2026-01-01', null)->precedes($late));
        $this->assertFalse($early->precedes($this->spell(null, '2026-09-01')));

        // Inclusive contract (issue #50): adjacent half-open spells both meet AND
        // precede — precedes() uses `<=`, so it is true at the touching boundary.
        $this->assertTrue($early->meets($late));
        $this->assertTrue($early->precedes($late));
    }

    public function test_is_adjacent(): void
    {
        $this->assertTrue($this->spell('2026-01-01', '2026-06-01')->isAdjacent($this->spell('2026-06-01', '2026-09-01')));
        $this->assertTrue($this->spell('2026-06-01', '2026-09-01')->isAdjacent($this->spell('2026-01-01', '2026-06-01')));
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->isAdjacent($this->spell('2026-07-01', '2026-09-01')));
    }

    public function test_with_from_and_with_to(): void
    {
        $base = $this->spell('2026-01-01', '2026-06-01');

        $this->assertTrue($base->withFrom($this->at('2026-02-01'))->equals($this->spell('2026-02-01', '2026-06-01')));
        $this->assertTrue($base->withTo($this->at('2026-09-01'))->equals($this->spell('2026-01-01', '2026-09-01')));
        $this->assertTrue($base->withFrom(null)->isOpenStart());
        $this->assertTrue($base->withTo(null)->isOpenEnded());
    }

    public function test_equals(): void
    {
        $this->assertTrue($this->spell('2026-01-01', '2026-06-01')->equals($this->spell('2026-01-01', '2026-06-01')));
        $this->assertTrue(Spell::unbounded()->equals(Spell::unbounded()));
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->equals($this->spell('2026-01-01', '2026-07-01')));
        $this->assertFalse($this->spell('2026-01-01', null)->equals($this->spell('2026-01-01', '2026-06-01')));
        $this->assertFalse($this->spell(null, '2026-06-01')->equals($this->spell('2026-01-01', '2026-06-01')));
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->equals($this->spell('2026-01-01', null)));
        $this->assertFalse($this->spell('2026-01-01', '2026-06-01')->equals($this->spell(null, '2026-06-01')));
    }

    public function test_to_string(): void
    {
        $this->assertSame('[2026-01-01, 2026-06-01)', (string) $this->spell('2026-01-01', '2026-06-01'));
        $this->assertSame('[2026-01-01, ∞)', (string) $this->spell('2026-01-01', null));
        $this->assertSame('[-∞, 2026-06-01)', (string) $this->spell(null, '2026-06-01'));
        $this->assertSame('[-∞, ∞)', (string) Spell::unbounded());
        $this->assertSame('[2026-01-01 09:30:00.000000, ∞)', (string) $this->spell('2026-01-01 09:30:00', null));
    }
}
