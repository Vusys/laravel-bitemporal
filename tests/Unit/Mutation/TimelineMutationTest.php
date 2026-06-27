<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Mutation;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;
use Vusys\Bitemporal\Timeline;
use Vusys\Bitemporal\TimelineSegment;

/**
 * Pins the surviving mutants listed in build/mutants/src__Timeline.txt that are
 * not equivalent. The compact()/recordedSpellsEqual mutants are killed here; the
 * spans() Break_/LogicalAnd and compareFrom()/dateValue InstanceOf mutants are
 * equivalent (see report in the task notes).
 */
final class TimelineMutationTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function segment(?string $from, ?string $to, array $attributes = ['amount' => 1000]): TimelineSegment
    {
        return new TimelineSegment(
            new Spell($from === null ? null : CarbonImmutable::parse($from), $to === null ? null : CarbonImmutable::parse($to)),
            null,
            $attributes,
        );
    }

    // --- compact(): the `continue` after a merge (Continue_ -> break) ---

    public function test_compact_continues_processing_segments_after_a_merge(): void
    {
        // A and B merge; C (after a gap) must still be processed and kept. The
        // mutant `break` stops the loop right after the merge and drops C.
        $timeline = new Timeline([
            $this->segment('2026-01-01', '2026-03-01', ['amount' => 1000]),
            $this->segment('2026-03-01', '2026-06-01', ['amount' => 1000]),
            $this->segment('2026-07-01', '2026-09-01', ['amount' => 2000]),
        ]);

        $compacted = $timeline->compact([]);

        $this->assertCount(2, $compacted);
        $this->assertTrue($compacted->head()?->validSpell->equals(Spell::between('2026-01-01', '2026-06-01')));
        $this->assertSame(2000, $compacted->tail()?->attributes['amount']);
    }

    // --- recordedSpellsEqual(): null-on-left combinations ---
    // Kills: `!true || !$b` (InstanceOf_), `&&` instead of `||` (LogicalOr),
    // and `!$a && !false` (InstanceOf_) on the return line.

    public function test_compact_does_not_merge_when_only_first_recorded_spell_is_null(): void
    {
        $timeline = new Timeline([
            new TimelineSegment(Spell::between('2026-01-01', '2026-06-01'), null, ['amount' => 1000]),
            new TimelineSegment(Spell::between('2026-06-01', '2026-09-01'), Spell::between('2026-01-01', null), ['amount' => 1000]),
        ]);

        // recordedSpellsEqual(null, Spell) must be false (no merge). Mutants either
        // call ->equals() on null (fatal) or wrongly report equality and merge.
        $this->assertCount(2, $timeline->compact([]));
    }

    // --- recordedSpellsEqual(): null-on-right combinations ---
    // Kills: `!$a || !true` (InstanceOf_), `!false && !$b` (InstanceOf_),
    // and `||` instead of `&&` (LogicalAnd) on the return line.

    public function test_compact_does_not_merge_when_only_second_recorded_spell_is_null(): void
    {
        $timeline = new Timeline([
            new TimelineSegment(Spell::between('2026-01-01', '2026-06-01'), Spell::between('2026-01-01', null), ['amount' => 1000]),
            new TimelineSegment(Spell::between('2026-06-01', '2026-09-01'), null, ['amount' => 1000]),
        ]);

        // recordedSpellsEqual(Spell, null) must be false (no merge). Mutants either
        // call ->equals(null) (TypeError) or wrongly report equality and merge.
        $this->assertCount(2, $timeline->compact([]));
    }
}
