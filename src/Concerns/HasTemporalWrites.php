<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\Bitemporal\Backfill\BitemporalBackfill;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Events\TemporalChangeCommitted;
use Vusys\Bitemporal\Events\TemporalCorrectionCommitted;
use Vusys\Bitemporal\Events\TemporalHardDeleteCommitted;
use Vusys\Bitemporal\Events\TemporalRetractionCommitted;
use Vusys\Bitemporal\Events\TemporalTimelineSuperseded;
use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Writers\BitemporalWriter;
use Vusys\Bitemporal\Writers\TimelineSplitter;

/**
 * Temporal write API for relations. `changeEffectiveFrom` makes a forward-only
 * change; `correct` rewrites the value over an arbitrary (possibly historical)
 * window. Both run the bitemporal correction algorithm transactionally.
 *
 * @phpstan-require-extends HasOneOrMany
 */
trait HasTemporalWrites
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $expectedCurrentAttributes
     */
    public function changeEffectiveFrom(array $attributes, CarbonInterface|string $validFrom, ?bool $compact = null, ?array $expectedCurrentAttributes = null): TemporalChangeCommitted
    {
        return $this->temporalWriter()->changeEffectiveFrom($attributes, $validFrom, $compact, $expectedCurrentAttributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $expectedCurrentAttributes
     */
    public function correct(array $attributes, CarbonInterface|string|null $validFrom = null, CarbonInterface|string|null $validTo = null, ?bool $compact = null, ?array $expectedCurrentAttributes = null): TemporalCorrectionCommitted
    {
        return $this->temporalWriter()->correct($attributes, $validFrom, $validTo, $compact, $expectedCurrentAttributes);
    }

    public function retract(CarbonInterface|string $validFrom, CarbonInterface|string|null $validTo = null, ?bool $compact = null): TemporalRetractionCommitted
    {
        return $this->temporalWriter()->retract($validFrom, $validTo, $compact);
    }

    public function endAt(CarbonInterface|string $validTo, ?bool $compact = null): TemporalChangeCommitted
    {
        return $this->temporalWriter()->endAt($validTo, $compact);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function supersedeTimeline(array $rows, ?bool $compact = null): TemporalTimelineSuperseded
    {
        return $this->temporalWriter()->supersedeTimeline($rows, $compact);
    }

    public function forceDeleteHistory(): TemporalHardDeleteCommitted
    {
        return $this->temporalWriter()->forceDeleteHistory();
    }

    public function backfill(): BitemporalBackfill
    {
        /** @var Application $app */
        $app = app();

        $query = $this->getQuery();
        $dimensions = $query instanceof BitemporalBuilder ? $query->temporalDimensionTuple() : [];

        return new BitemporalBackfill(
            $this->getRelated(),
            $this->getParent(),
            $dimensions,
            $app->make(WriteLocker::class),
            $app->make(Dispatcher::class),
        );
    }

    private function temporalWriter(): BitemporalWriter
    {
        /** @var Application $app */
        $app = app();

        $related = $this->getRelated();
        $declared = method_exists($related, 'temporalDimensions') ? $related->temporalDimensions() : [];

        $query = $this->getQuery();
        $dimensions = [];

        if ($query instanceof BitemporalBuilder) {
            if ($query->hasWheresOutside([...$this->temporalEntityColumns($related), ...$declared])) {
                throw TemporalMissingDimensionException::pendingWhere();
            }

            $dimensions = $query->temporalDimensionTuple();
        }

        return new BitemporalWriter(
            $related,
            $this->getParent(),
            $dimensions,
            $app->make(WriteLocker::class),
            new TimelineSplitter,
            $app->make(Dispatcher::class),
        );
    }

    /**
     * @return array<int, string>
     */
    private function temporalEntityColumns(Model $related): array
    {
        if (! method_exists($related, 'temporalEntity')) {
            return [];
        }

        $relation = $related->temporalEntity();

        if ($relation instanceof MorphTo) {
            return [$relation->getMorphType(), $relation->getForeignKeyName()];
        }

        if ($relation instanceof BelongsTo) {
            return [$relation->getForeignKeyName()];
        }

        return [];
    }
}
