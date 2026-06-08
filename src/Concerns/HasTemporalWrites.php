<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Vusys\Bitemporal\Events\TemporalChangeCommitted;
use Vusys\Bitemporal\Events\TemporalCorrectionCommitted;
use Vusys\Bitemporal\Events\TemporalHardDeleteCommitted;
use Vusys\Bitemporal\Events\TemporalRetractionCommitted;
use Vusys\Bitemporal\Events\TemporalTimelineSuperseded;
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
     */
    public function changeEffectiveFrom(array $attributes, CarbonInterface|string $validFrom, ?bool $compact = null): TemporalChangeCommitted
    {
        return $this->temporalWriter()->changeEffectiveFrom($attributes, $validFrom, $compact);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function correct(array $attributes, CarbonInterface|string|null $validFrom = null, CarbonInterface|string|null $validTo = null, ?bool $compact = null): TemporalCorrectionCommitted
    {
        return $this->temporalWriter()->correct($attributes, $validFrom, $validTo, $compact);
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

    private function temporalWriter(): BitemporalWriter
    {
        /** @var Application $app */
        $app = app();

        return new BitemporalWriter(
            $this->getRelated(),
            $this->getParent(),
            $this->getForeignKeyName(),
            [],
            $app->make(WriteLocker::class),
            new TimelineSplitter,
            $app->make(Dispatcher::class),
        );
    }
}
