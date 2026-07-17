<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Factories;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;

/**
 * Base factory for temporal models. Adds states named after the temporal
 * columns (`validFrom`, `validTo`, `recordedFrom`, `recordedTo`) plus the
 * semantic shortcuts (`currentKnowledge`, `openEnded`, `superseded`,
 * `retracted`) documented in 10-testing.md.
 *
 * Inserts go straight through Eloquent — they do NOT route through the
 * BitemporalWriter — so fixtures may set up arbitrary (even intentionally
 * broken) historical state. Use the assertion helpers to catch corruption.
 *
 * @template TModel of Model
 *
 * @extends Factory<TModel>
 */
abstract class BitemporalFactory extends Factory
{
    public function validFrom(CarbonInterface|string $date): static
    {
        return $this->state([$this->meta()->validFrom => $this->instant($date)]);
    }

    public function validTo(CarbonInterface|string|null $date): static
    {
        return $this->state([$this->meta()->validTo => $date === null ? null : $this->instant($date)]);
    }

    public function recordedFrom(CarbonInterface|string $date): static
    {
        return $this->state([$this->meta()->recordedFrom => $this->instant($date)]);
    }

    public function recordedTo(CarbonInterface|string|null $date): static
    {
        return $this->state([$this->meta()->recordedTo => $date === null ? null : $this->instant($date)]);
    }

    /**
     * Part of current knowledge: recorded_to is null.
     */
    public function currentKnowledge(): static
    {
        return $this->state([$this->meta()->recordedTo => null]);
    }

    /**
     * Open-ended on the valid axis: valid_to is null.
     */
    public function openEnded(): static
    {
        return $this->state([$this->meta()->validTo => null]);
    }

    /**
     * The row was the system's belief until $at: recorded_to = $at.
     */
    public function superseded(CarbonInterface|string $at): static
    {
        return $this->state([$this->meta()->recordedTo => $this->instant($at)]);
    }

    /**
     * An anti-row: is_retraction = true and every value attribute nulled.
     */
    public function retracted(): static
    {
        return $this->state(function (array $attributes): array {
            $model = $this->newModel();
            $meta = $this->meta();
            $preserved = [
                $model->getKeyName(),
                $meta->validFrom,
                $meta->validTo,
                $meta->recordedFrom,
                $meta->recordedTo,
                $meta->isRetraction,
                ...$meta->dimensions,
                ...$this->entityColumns($model),
            ];

            $nulled = [];
            foreach (array_keys($attributes) as $column) {
                if (! in_array($column, $preserved, true)) {
                    $nulled[$column] = null;
                }
            }

            return [...$nulled, $meta->isRetraction => true];
        });
    }

    /**
     * @return array<int, string>
     */
    private function entityColumns(Model $model): array
    {
        if (! method_exists($model, 'temporalEntityRelation')) {
            return [];
        }

        $relation = $model->temporalEntityRelation();

        if ($relation instanceof MorphTo) {
            return [$relation->getMorphType(), $relation->getForeignKeyName()];
        }

        if ($relation instanceof BelongsTo) {
            return [$relation->getForeignKeyName()];
        }

        return [];
    }

    private function instant(CarbonInterface|string $date): CarbonImmutable
    {
        $timezone = config('bitemporal.spells.timezone', 'UTC');

        return CarbonImmutable::parse($date)->setTimezone(is_string($timezone) ? $timezone : 'UTC');
    }

    private function meta(): TemporalEntityMetadata
    {
        $model = $this->newModel();

        if (! method_exists($model, 'temporalMetadata')) {
            throw new \LogicException($model::class.' is not a temporal model; BitemporalFactory requires the Bitemporal trait');
        }

        return $model->temporalMetadata();
    }
}
