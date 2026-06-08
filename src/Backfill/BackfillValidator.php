<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Backfill;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\TimelineSegment;

/**
 * Validates a backfill batch: every row must carry a recorded period that is
 * already known (recorded_from <= now, recorded_to null or <= now), anti-rows
 * must have no attributes, and no two rows may overlap bitemporally (their valid
 * AND recorded periods both intersecting).
 */
final class BackfillValidator
{
    /**
     * @param  array<int, TimelineSegment>  $segments
     */
    public function validate(array $segments, CarbonImmutable $now): void
    {
        foreach ($segments as $index => $segment) {
            $this->validateRow($segment, $index, $now);
        }

        $count = count($segments);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->overlaps($segments[$i], $segments[$j])) {
                    throw TemporalOverlapException::betweenSegments($i, $j);
                }
            }
        }
    }

    private function validateRow(TimelineSegment $segment, int $index, CarbonImmutable $now): void
    {
        $recorded = $segment->recordedSpell;

        if (! $recorded instanceof Spell || ! $recorded->from instanceof CarbonImmutable) {
            throw new TemporalInvalidSpellException("backfill row {$index} must specify recorded_from");
        }

        if ($recorded->from->greaterThan($now)) {
            throw new TemporalInvalidSpellException("backfill row {$index} has a future recorded_from");
        }

        if ($recorded->to instanceof CarbonImmutable && $recorded->to->greaterThan($now)) {
            throw new TemporalInvalidSpellException("backfill row {$index} has a future recorded_to");
        }

        if ($segment->isRetraction && $segment->attributes !== []) {
            throw new TemporalInvalidSpellException("backfill anti-row {$index} must not carry attributes");
        }
    }

    private function overlaps(TimelineSegment $a, TimelineSegment $b): bool
    {
        if (! $a->recordedSpell instanceof Spell || ! $b->recordedSpell instanceof Spell) {
            return false;
        }

        return $a->validSpell->intersects($b->validSpell)
            && $a->recordedSpell->intersects($b->recordedSpell);
    }
}
