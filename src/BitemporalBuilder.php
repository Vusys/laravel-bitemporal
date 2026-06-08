<?php

declare(strict_types=1);

namespace Bitemporal;

use Bitemporal\Exceptions\TemporalCardinalityException;
use Bitemporal\Exceptions\TemporalConfigurationException;
use Bitemporal\Support\TemporalEntityMetadata;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Eloquent builder for temporal models. Adds point-in-time and entity-scoping
 * read predicates. Writes arrive in Phase 4.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class BitemporalBuilder extends Builder
{
    private ?TemporalEntityMetadata $temporalMeta = null;

    /**
     * Rows whose valid period contains the instant: valid_from <= t < valid_to.
     */
    public function validAt(CarbonInterface|string $date): static
    {
        $meta = $this->temporalMetadata();

        return $this->containsInstant($meta->validFrom, $meta->validTo, $date);
    }

    /**
     * Rows whose recorded period contains the instant.
     */
    public function knownAt(CarbonInterface|string $date): static
    {
        $meta = $this->requireRecordedTime('knownAt');

        return $this->containsInstant($meta->recordedFrom, $meta->recordedTo, $date);
    }

    /**
     * The current belief: rows whose recorded period is still open.
     */
    public function currentKnowledge(): static
    {
        $meta = $this->requireRecordedTime('currentKnowledge');

        $this->whereNull($this->qualify($meta->recordedTo));

        return $this;
    }

    public function whereTemporalEntity(Model $entity): static
    {
        $this->where($this->qualify($this->temporalForeignKey()), '=', $entity->getKey());

        return $this;
    }

    /**
     * @param  iterable<int, mixed>  $entities
     */
    public function whereTemporalEntityIn(iterable $entities): static
    {
        $keys = new SupportCollection($entities)
            ->map(static function (mixed $entity): mixed {
                if ($entity instanceof Model) {
                    return $entity->getKey();
                }

                if (is_int($entity) || is_string($entity)) {
                    return $entity;
                }

                throw TemporalConfigurationException::unexpectedEntityArgument(get_debug_type($entity));
            })
            ->all();

        $this->whereIn($this->qualify($this->temporalForeignKey()), $keys);

        return $this;
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return TModel
     */
    #[\Override]
    public function sole($columns = ['*']): Model
    {
        try {
            return parent::sole(is_string($columns) ? [$columns] : $columns);
        } catch (MultipleRecordsFoundException $exception) {
            throw TemporalCardinalityException::expectedOneFoundMany($this->getModel()::class, $exception->getCount());
        } catch (ModelNotFoundException) {
            throw TemporalCardinalityException::expectedOneFoundNone($this->getModel()::class);
        }
    }

    private function containsInstant(string $fromColumn, string $toColumn, CarbonInterface|string $date): static
    {
        $instant = $this->instant($date);
        $to = $this->qualify($toColumn);

        $this
            ->where($this->qualify($fromColumn), '<=', $instant)
            ->where(function (self $query) use ($to, $instant): void {
                $query->whereNull($to)->orWhere($to, '>', $instant);
            });

        return $this;
    }

    private function instant(CarbonInterface|string $date): string
    {
        $timezone = config('bitemporal.periods.timezone', 'UTC');

        return CarbonImmutable::parse($date)
            ->setTimezone(is_string($timezone) ? $timezone : 'UTC')
            ->format('Y-m-d H:i:s.u');
    }

    private function qualify(string $column): string
    {
        return $this->getModel()->getTable().'.'.$column;
    }

    private function temporalMetadata(): TemporalEntityMetadata
    {
        if ($this->temporalMeta instanceof TemporalEntityMetadata) {
            return $this->temporalMeta;
        }

        $model = $this->getModel();

        if (! method_exists($model, 'temporalMetadata')) {
            throw TemporalConfigurationException::missingTemporalEntity($model::class);
        }

        return $this->temporalMeta = $model->temporalMetadata();
    }

    private function requireRecordedTime(string $method): TemporalEntityMetadata
    {
        $meta = $this->temporalMetadata();

        if (! $meta->tracksRecordedTime) {
            throw new TemporalConfigurationException(
                "{$method}() requires a bitemporal model that tracks recorded time",
            );
        }

        return $meta;
    }

    private function temporalForeignKey(): string
    {
        $model = $this->getModel();

        if (! method_exists($model, 'temporalEntity')) {
            throw TemporalConfigurationException::missingTemporalEntity($model::class);
        }

        $relation = $model->temporalEntity();

        if (! $relation instanceof BelongsTo) {
            throw new TemporalConfigurationException(
                'temporalEntity() must return a BelongsTo relation (MorphTo support arrives in Phase 8)',
            );
        }

        return $relation->getForeignKeyName();
    }
}
