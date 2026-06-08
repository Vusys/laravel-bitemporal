<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Writers;

use Vusys\Bitemporal\Timeline;
use Vusys\Bitemporal\TimelineSegment;

/**
 * Computes the minimal close/insert plan between the current-known timeline and
 * the recomputed next timeline. A current segment survives (its row stays open)
 * only when an identical segment — same valid spell, attributes, and retraction
 * flag — exists in the next timeline; otherwise its row is closed. Next
 * segments without an identical current counterpart are inserted.
 */
final class TimelineSplitter
{
    /**
     * @return array{closeIndexes: array<int, int>, insert: array<int, TimelineSegment>}
     */
    public function plan(Timeline $current, Timeline $next): array
    {
        $currentSegments = $current->segments();
        $nextSegments = $next->segments();

        $matched = array_fill(0, count($nextSegments), false);
        $closeIndexes = [];

        foreach ($currentSegments as $index => $segment) {
            $matchedIndex = $this->matchingIndex($segment, $nextSegments, $matched);

            if ($matchedIndex === null) {
                $closeIndexes[] = $index;

                continue;
            }

            $matched[$matchedIndex] = true;
        }

        $insert = [];
        foreach ($nextSegments as $index => $segment) {
            if (! $matched[$index]) {
                $insert[] = $segment;
            }
        }

        return ['closeIndexes' => $closeIndexes, 'insert' => $insert];
    }

    /**
     * @param  array<int, TimelineSegment>  $candidates
     * @param  array<int, bool>  $matched
     */
    private function matchingIndex(TimelineSegment $segment, array $candidates, array $matched): ?int
    {
        foreach ($candidates as $index => $candidate) {
            if ($matched[$index]) {
                continue;
            }

            if ($segment->validSpell->equals($candidate->validSpell) && $segment->hasSameAttributesAs($candidate)) {
                return $index;
            }
        }

        return null;
    }
}
