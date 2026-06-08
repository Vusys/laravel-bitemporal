<?php

declare(strict_types=1);

namespace Bitemporal\Concerns;

use Bitemporal\BitemporalBuilder;
use Carbon\CarbonInterface;

/**
 * Period range predicates for the temporal builder. Each `valid*` method
 * operates on the valid period; each `recorded*` method on the recorded period
 * (and requires a bitemporal model). All bounds are half-open `[from, to)`;
 * a null upper bound means "open ended" (+infinity).
 *
 * @phpstan-require-extends BitemporalBuilder
 */
trait HasPeriodQueries
{
    public function validIntersects(CarbonInterface|string $from, CarbonInterface|string|null $to = null): static
    {
        $meta = $this->temporalMetadata();

        return $this->applyIntersects($meta->validFrom, $meta->validTo, $from, $to);
    }

    public function validContains(CarbonInterface|string $from, CarbonInterface|string|null $to = null): static
    {
        $meta = $this->temporalMetadata();

        return $this->applyContains($meta->validFrom, $meta->validTo, $from, $to);
    }

    public function validContainedBy(CarbonInterface|string $from, CarbonInterface|string|null $to = null): static
    {
        $meta = $this->temporalMetadata();

        return $this->applyContainedBy($meta->validFrom, $meta->validTo, $from, $to);
    }

    public function validStartingFrom(CarbonInterface|string $date): static
    {
        $meta = $this->temporalMetadata();
        $this->where($this->qualify($meta->validFrom), '>=', $this->instant($date));

        return $this;
    }

    public function validEndingBy(CarbonInterface|string $date): static
    {
        $meta = $this->temporalMetadata();
        $this->whereNotNull($this->qualify($meta->validTo))
            ->where($this->qualify($meta->validTo), '<=', $this->instant($date));

        return $this;
    }

    public function recordedIntersects(CarbonInterface|string $from, CarbonInterface|string|null $to = null): static
    {
        $meta = $this->requireRecordedTime('recordedIntersects');

        return $this->applyIntersects($meta->recordedFrom, $meta->recordedTo, $from, $to);
    }

    public function recordedContains(CarbonInterface|string $from, CarbonInterface|string|null $to = null): static
    {
        $meta = $this->requireRecordedTime('recordedContains');

        return $this->applyContains($meta->recordedFrom, $meta->recordedTo, $from, $to);
    }

    public function recordedContainedBy(CarbonInterface|string $from, CarbonInterface|string|null $to = null): static
    {
        $meta = $this->requireRecordedTime('recordedContainedBy');

        return $this->applyContainedBy($meta->recordedFrom, $meta->recordedTo, $from, $to);
    }

    public function recordedStartingFrom(CarbonInterface|string $date): static
    {
        $meta = $this->requireRecordedTime('recordedStartingFrom');
        $this->where($this->qualify($meta->recordedFrom), '>=', $this->instant($date));

        return $this;
    }

    public function recordedEndingBy(CarbonInterface|string $date): static
    {
        $meta = $this->requireRecordedTime('recordedEndingBy');
        $this->whereNotNull($this->qualify($meta->recordedTo))
            ->where($this->qualify($meta->recordedTo), '<=', $this->instant($date));

        return $this;
    }

    public function excludeRetractions(): static
    {
        $meta = $this->temporalMetadata();
        $this->where($this->qualify($meta->isRetraction), '=', false);

        return $this;
    }

    private function applyIntersects(string $fromColumn, string $toColumn, CarbonInterface|string $from, CarbonInterface|string|null $to): static
    {
        $fromQualified = $this->qualify($fromColumn);
        $toQualified = $this->qualify($toColumn);
        $fromInstant = $this->instant($from);
        $toInstant = $to === null ? null : $this->instant($to);

        $this->where(function (BitemporalBuilder $query) use ($toQualified, $fromInstant): void {
            $query->whereNull($toQualified)->orWhere($toQualified, '>', $fromInstant);
        });

        if ($toInstant !== null) {
            $this->where($fromQualified, '<', $toInstant);
        }

        return $this;
    }

    private function applyContains(string $fromColumn, string $toColumn, CarbonInterface|string $from, CarbonInterface|string|null $to): static
    {
        $fromQualified = $this->qualify($fromColumn);
        $toQualified = $this->qualify($toColumn);
        $fromInstant = $this->instant($from);
        $toInstant = $to === null ? null : $this->instant($to);

        $this->where($fromQualified, '<=', $fromInstant);

        if ($toInstant === null) {
            $this->whereNull($toQualified);

            return $this;
        }

        $this->where(function (BitemporalBuilder $query) use ($toQualified, $toInstant): void {
            $query->whereNull($toQualified)->orWhere($toQualified, '>=', $toInstant);
        });

        return $this;
    }

    private function applyContainedBy(string $fromColumn, string $toColumn, CarbonInterface|string $from, CarbonInterface|string|null $to): static
    {
        $fromQualified = $this->qualify($fromColumn);
        $toQualified = $this->qualify($toColumn);
        $fromInstant = $this->instant($from);
        $toInstant = $to === null ? null : $this->instant($to);

        $this->where($fromQualified, '>=', $fromInstant);

        if ($toInstant !== null) {
            $this->whereNotNull($toQualified)->where($toQualified, '<=', $toInstant);
        }

        return $this;
    }
}
