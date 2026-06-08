<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Property;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;
use Vusys\Bitemporal\Timeline;
use Vusys\Bitemporal\TimelineSegment;

#[Group('property')]
final class TimelinePropertyTest extends TestCase
{
    private const int ITERATIONS = 200;

    /**
     * Build a contiguous random timeline of non-overlapping segments.
     */
    private function randomTimeline(): Timeline
    {
        $cursor = CarbonImmutable::parse('2026-01-01');
        $segments = [];
        $count = random_int(1, 6);

        for ($i = 0; $i < $count; $i++) {
            $next = $cursor->addDays(random_int(1, 90));
            $openEnded = $i === $count - 1 && random_int(0, 2) === 0;

            $segments[] = new TimelineSegment(
                new Spell($cursor, $openEnded ? null : $next),
                null,
                ['amount' => random_int(0, 3) * 100],
            );

            $cursor = $next;
        }

        return new Timeline($segments);
    }

    public function test_compaction_is_idempotent(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $timeline = $this->randomTimeline();

            $once = $timeline->compact([]);
            $twice = $once->compact([]);

            $this->assertTrue($once->equals($twice), 'compaction not idempotent');
        }
    }

    public function test_compaction_preserves_value_at_every_instant(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $timeline = $this->randomTimeline();
            $compacted = $timeline->compact([]);

            $cursor = CarbonImmutable::parse('2026-01-01');
            for ($d = 0; $d < 400; $d += 7) {
                $instant = $cursor->addDays($d);
                $before = $timeline->at($instant);
                $after = $compacted->at($instant);

                $this->assertSame(
                    $before?->attributes['amount'] ?? null,
                    $after?->attributes['amount'] ?? null,
                    "value changed at {$instant} after compaction",
                );
            }
        }
    }

    public function test_apply_correction_changes_value_only_within_window(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $timeline = $this->randomTimeline();

            $window = Spell::between('2026-02-01', '2026-04-01');
            $next = $timeline->applyCorrection(new TimelineSegment($window, null, ['amount' => 9999]));

            $cursor = CarbonImmutable::parse('2026-01-01');
            for ($d = 0; $d < 400; $d += 5) {
                $instant = $cursor->addDays($d);

                if ($window->containsInstant($instant)) {
                    $this->assertSame(9999, $next->at($instant)?->attributes['amount']);

                    continue;
                }

                $this->assertSame(
                    $timeline->at($instant)?->attributes['amount'] ?? null,
                    $next->at($instant)?->attributes['amount'] ?? null,
                    "value outside window changed at {$instant}",
                );
            }
        }
    }

    public function test_subtract_then_value_absent_in_window(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $timeline = $this->randomTimeline();
            $window = Spell::between('2026-02-15', '2026-03-15');

            $result = $timeline->subtract($window);

            $cursor = CarbonImmutable::parse('2026-02-15');
            for ($d = 0; $d < 28; $d++) {
                $instant = $cursor->addDays($d);
                if ($window->containsInstant($instant)) {
                    $this->assertNull($result->at($instant), "value present in subtracted window at {$instant}");
                }
            }
        }
    }
}
