<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Collection as SupportCollection;
use Vusys\Bitemporal\Concerns\HasSpellQueries;
use Vusys\Bitemporal\Concerns\HasTemporalDimensions;
use Vusys\Bitemporal\Concerns\InteractsWithLens;
use Vusys\Bitemporal\Exceptions\TemporalCardinalityException;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Support\MorphContext;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;

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
    use HasSpellQueries;
    use HasTemporalDimensions;
    use InteractsWithLens;

    private ?TemporalEntityMetadata $temporalMeta = null;

    /**
     * Rows whose valid period contains the instant: valid_from <= t < valid_to.
     */
    public function validAt(CarbonInterface|string $date): static
    {
        $this->markValidAtPinned();
        $meta = $this->temporalMetadata();

        return $this->containsInstant($meta->validFrom, $meta->validTo, $date);
    }

    /**
     * Rows whose recorded period contains the instant.
     */
    public function knownAt(CarbonInterface|string $date): static
    {
        $this->markKnownAtPinned();
        $meta = $this->requireRecordedTime('knownAt');

        return $this->containsInstant($meta->recordedFrom, $meta->recordedTo, $date);
    }

    /**
     * The current belief: rows whose recorded period is still open.
     */
    public function currentKnowledge(): static
    {
        $this->markKnownAtPinned();
        $meta = $this->requireRecordedTime('currentKnowledge');

        $this->whereNull($this->qualify($meta->recordedTo));

        return $this;
    }

    public function whereTemporalEntity(Model|MorphContext $entity): static
    {
        $columns = $this->temporalEntityColumns();
        $context = $entity instanceof MorphContext ? $entity : MorphContext::fromModel($entity);

        if (isset($columns['type'])) {
            $this->where($this->qualify($columns['type']), '=', $context->type)
                ->where($this->qualify($columns['id']), '=', $context->id);

            return $this;
        }

        $this->where($this->qualify($columns['id']), '=', $context->id);

        return $this;
    }

    /**
     * @param  iterable<int, mixed>  $entities
     */
    public function whereTemporalEntityIn(iterable $entities): static
    {
        $columns = $this->temporalEntityColumns();

        if (! isset($columns['type'])) {
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

            $this->whereIn($this->qualify($columns['id']), $keys);

            return $this;
        }

        return $this->wherePolymorphicEntityIn($columns, $entities);
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<int, int|string>  $ids
     */
    public function whereTemporalEntityOf(string $class, array $ids): static
    {
        $columns = $this->temporalEntityColumns();

        if (isset($columns['type'])) {
            $this->where($this->qualify($columns['type']), '=', (new $class)->getMorphClass())
                ->whereIn($this->qualify($columns['id']), $ids);

            return $this;
        }

        $this->whereIn($this->qualify($columns['id']), $ids);

        return $this;
    }

    /**
     * @param  array{type: string, id: string}  $columns
     * @param  iterable<int, mixed>  $entities
     */
    private function wherePolymorphicEntityIn(array $columns, iterable $entities): static
    {
        $byType = [];
        foreach ($entities as $entity) {
            $context = match (true) {
                $entity instanceof Model => MorphContext::fromModel($entity),
                $entity instanceof MorphContext => $entity,
                default => throw TemporalConfigurationException::unexpectedEntityArgument(get_debug_type($entity)),
            };

            $byType[$context->type][] = $context->id;
        }

        $typeColumn = $this->qualify($columns['type']);
        $idColumn = $this->qualify($columns['id']);

        $this->where(function (self $query) use ($byType, $typeColumn, $idColumn): void {
            foreach ($byType as $type => $ids) {
                $query->orWhere(function (self $group) use ($type, $ids, $typeColumn, $idColumn): void {
                    $group->where($typeColumn, '=', $type)->whereIn($idColumn, $ids);
                });
            }
        });

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

    protected function instant(CarbonInterface|string $date): string
    {
        $timezone = config('bitemporal.spells.timezone', 'UTC');

        return CarbonImmutable::parse($date)
            ->setTimezone(is_string($timezone) ? $timezone : 'UTC')
            ->format('Y-m-d H:i:s.u');
    }

    protected function qualify(string $column): string
    {
        return $this->getModel()->getTable().'.'.$column;
    }

    protected function temporalMetadata(): TemporalEntityMetadata
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

    protected function requireRecordedTime(string $method): TemporalEntityMetadata
    {
        $meta = $this->temporalMetadata();

        if (! $meta->tracksRecordedTime) {
            throw new TemporalConfigurationException(
                "{$method}() requires a bitemporal model that tracks recorded time",
            );
        }

        return $meta;
    }

    /**
     * @return array{type?: string, id: string}
     */
    private function temporalEntityColumns(): array
    {
        $model = $this->getModel();

        if (! method_exists($model, 'temporalEntity')) {
            throw TemporalConfigurationException::missingTemporalEntity($model::class);
        }

        $relation = $model->temporalEntity();

        if ($relation instanceof MorphTo) {
            return ['type' => $relation->getMorphType(), 'id' => $relation->getForeignKeyName()];
        }

        if ($relation instanceof BelongsTo) {
            return ['id' => $relation->getForeignKeyName()];
        }

        throw new TemporalConfigurationException(
            'temporalEntity() must return a BelongsTo or MorphTo relation',
        );
    }
}
