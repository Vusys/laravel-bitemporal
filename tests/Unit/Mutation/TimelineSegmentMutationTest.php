<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Mutation;

use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;
use Vusys\Bitemporal\TimelineSegment;

/**
 * Pins the surviving mutants listed in build/mutants/src__TimelineSegment.txt:
 * both live in recordedSpellsEqual() and are reached through equals().
 */
final class TimelineSegmentMutationTest extends TestCase
{
    public function test_segments_differing_only_in_recorded_spell_nullness_are_unequal(): void
    {
        $withoutRecorded = new TimelineSegment(Spell::between('2026-01-01', '2026-06-01'), null, ['amount' => 1000]);
        $withRecorded = new TimelineSegment(Spell::between('2026-01-01', '2026-06-01'), Spell::between('2026-01-01', null), ['amount' => 1000]);

        // recordedSpellsEqual(null, Spell) must be false. The `!true || !$b` mutant
        // dereferences null->equals() (fatal); the `!$a && !false` mutant returns
        // true and makes equals() wrongly report equality.
        $this->assertFalse($withoutRecorded->equals($withRecorded));
        $this->assertFalse($withRecorded->equals($withoutRecorded));
    }
}
