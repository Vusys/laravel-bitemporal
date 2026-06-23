<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

use Carbon\CarbonInterface;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Diff\DiffEngine;
use Vusys\Bitemporal\Diff\TemporalDiff;
use Vusys\Bitemporal\Timeline;

/**
 * Read-side timeline and diff helpers for the builder.
 *
 * @phpstan-require-extends BitemporalBuilder
 */
trait HasTemporalDiff
{
    /**
     * Terminal materialiser: run the query and return a Timeline value object
     * (segments ordered valid_from ASC) rather than a Collection.
     */
    public function asTimeline(): Timeline
    {
        $meta = $this->temporalMetadata();

        $rows = $this->get()->map(static fn ($model): array => $model->getAttributes())->all();

        return Timeline::fromRows($rows, $meta->columnMap());
    }

    /**
     * Explicit, self-documenting form of "every physical row for the entity",
     * ordered valid_from ASC, recorded_from ASC. Chainable.
     */
    public function fullHistory(): static
    {
        $meta = $this->temporalMetadata();

        $this->orderBy($this->qualify($meta->validFrom));
        $this->orderBy($this->qualify($meta->recordedFrom));

        return $this;
    }

    /**
     * Compare what was believed about a single valid date at two recorded dates.
     */
    public function diffKnowledge(CarbonInterface|string $validAt, CarbonInterface|string $fromKnownAt, CarbonInterface|string $toKnownAt): TemporalDiff
    {
        $meta = $this->temporalMetadata();

        $from = $this->clone()->validAt($validAt)->knownAt($fromKnownAt)->get()->all();
        $to = $this->clone()->validAt($validAt)->knownAt($toKnownAt)->get()->all();

        return DiffEngine::compare($from, $to, $meta);
    }

    /**
     * Compare the entire believed valid-time timeline at two recorded dates.
     */
    public function diffTimelines(CarbonInterface|string $fromKnownAt, CarbonInterface|string $toKnownAt): TemporalDiff
    {
        $meta = $this->temporalMetadata();

        $from = $this->clone()->knownAt($fromKnownAt)->get()->all();
        $to = $this->clone()->knownAt($toKnownAt)->get()->all();

        return DiffEngine::compare($from, $to, $meta);
    }
}
