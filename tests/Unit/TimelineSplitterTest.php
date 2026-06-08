<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;
use Vusys\Bitemporal\Timeline;
use Vusys\Bitemporal\TimelineSegment;
use Vusys\Bitemporal\Writers\TimelineSplitter;

final class TimelineSplitterTest extends TestCase
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

    public function test_identical_timelines_produce_no_changes(): void
    {
        $current = new Timeline([$this->segment('2026-01-01', null, ['amount' => 1000])]);
        $next = new Timeline([$this->segment('2026-01-01', null, ['amount' => 1000])]);

        $plan = (new TimelineSplitter)->plan($current, $next);

        $this->assertSame([], $plan['closeIndexes']);
        $this->assertSame([], $plan['insert']);
    }

    public function test_changed_value_closes_old_and_inserts_new(): void
    {
        $current = new Timeline([$this->segment('2026-01-01', null, ['amount' => 1000])]);
        $next = new Timeline([$this->segment('2026-01-01', null, ['amount' => 1200])]);

        $plan = (new TimelineSplitter)->plan($current, $next);

        $this->assertSame([0], $plan['closeIndexes']);
        $this->assertCount(1, $plan['insert']);
        $this->assertSame(1200, $plan['insert'][0]->attributes['amount']);
    }

    public function test_split_preserves_unchanged_neighbours_but_reinserts_split_remainders(): void
    {
        $current = new Timeline([$this->segment('2026-01-01', null, ['amount' => 1000])]);
        $next = new Timeline([
            $this->segment('2026-01-01', '2026-04-01', ['amount' => 1000]),
            $this->segment('2026-04-01', '2026-07-01', ['amount' => 1200]),
            $this->segment('2026-07-01', null, ['amount' => 1000]),
        ]);

        $plan = (new TimelineSplitter)->plan($current, $next);

        // The original open-ended row no longer matches any next segment exactly.
        $this->assertSame([0], $plan['closeIndexes']);
        $this->assertCount(3, $plan['insert']);
    }
}
