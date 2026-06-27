<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Mutation;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;
use Vusys\Bitemporal\Timeline;
use Vusys\Bitemporal\TimelineSegment;
use Vusys\Bitemporal\Writers\TimelineSplitter;

/**
 * Pins the uncovered Continue_ mutant in build/mutants/src__Writers__TimelineSplitter.txt:
 * matchingIndex() must SKIP an already-matched candidate (continue) rather than
 * stop scanning (break).
 */
final class TimelineSplitterMutationTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function segment(?string $from, ?string $to, array $attributes): TimelineSegment
    {
        return new TimelineSegment(new Spell(
            $from === null ? null : CarbonImmutable::parse($from),
            $to === null ? null : CarbonImmutable::parse($to),
        ), null, $attributes);
    }

    public function test_second_segment_matches_past_an_already_matched_candidate(): void
    {
        // Two identical timelines. The first current segment consumes next[0]
        // (matched[0] = true). When matching the second current segment, the loop
        // must `continue` past the matched candidate index 0 to find its match at
        // index 1. The `break` mutant stops at index 0 and reports a phantom
        // close + insert.
        $current = new Timeline([
            $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000]),
            $this->segment('2026-06-01', null, ['amount' => 1000]),
        ]);
        $next = new Timeline([
            $this->segment('2026-01-01', '2026-06-01', ['amount' => 1000]),
            $this->segment('2026-06-01', null, ['amount' => 1000]),
        ]);

        $plan = (new TimelineSplitter)->plan($current, $next);

        $this->assertSame([], $plan['closeIndexes']);
        $this->assertSame([], $plan['insert']);
    }
}
