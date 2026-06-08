<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Writers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Events\TemporalChangeCommitted;
use Vusys\Bitemporal\Events\TemporalChangeStarting;
use Vusys\Bitemporal\Events\TemporalCompactionPerformed;
use Vusys\Bitemporal\Events\TemporalCorrectionCommitted;
use Vusys\Bitemporal\Events\TemporalCorrectionStarting;
use Vusys\Bitemporal\Events\TemporalFutureRowEncountered;
use Vusys\Bitemporal\Events\TemporalHardDeleteCommitted;
use Vusys\Bitemporal\Events\TemporalHardDeleteStarting;
use Vusys\Bitemporal\Events\TemporalOverlapPrevented;
use Vusys\Bitemporal\Events\TemporalRetractionCommitted;
use Vusys\Bitemporal\Events\TemporalRetractionStarting;
use Vusys\Bitemporal\Events\TemporalTimelineSuperseded;
use Vusys\Bitemporal\Events\TemporalTimelineSupersedingStarting;
use Vusys\Bitemporal\Events\TemporalWriteCommitted;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Support\DimensionValidator;
use Vusys\Bitemporal\Support\EntityScope;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;
use Vusys\Bitemporal\Timeline;
use Vusys\Bitemporal\TimelineSegment;

final readonly class BitemporalWriter
{
    private TemporalEntityMetadata $meta;

    /**
     * Columns that pin a row to the temporal entity, mapped to the values to
     * filter and stamp: ['owner_id' => 42] for a BelongsTo, or
     * ['owner_type' => 'customer', 'owner_id' => 42] for a MorphTo.
     *
     * @var array<string, mixed>
     */
    private array $entityScope;

    /**
     * @param  array<string, mixed>  $dimensions
     */
    public function __construct(
        private Model $related,
        private Model $entity,
        private array $dimensions,
        private WriteLocker $locker,
        private TimelineSplitter $splitter,
        private Dispatcher $events,
    ) {
        if (! method_exists($related, 'temporalMetadata')) {
            throw new TemporalInvalidSpellException($related::class.' is not a temporal model');
        }

        $this->meta = $related->temporalMetadata();
        $this->entityScope = $this->resolveEntityScope();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveEntityScope(): array
    {
        return EntityScope::resolve($this->related, $this->entity);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function changeEffectiveFrom(array $attributes, CarbonInterface|string $validFrom, ?bool $compact = null): TemporalChangeCommitted
    {
        $from = $this->instant($validFrom);
        $this->assertForwardDated($from);
        $attributes = DimensionValidator::reconcileAttributes($this->meta->dimensions, $this->dimensions, $attributes);

        $committed = $this->run(
            fn (Timeline $current): Spell => $this->forwardWindow($current, $from),
            fn (Timeline $current, Spell $window, array $columns): Timeline => $current->applyCorrection(
                new TimelineSegment($window, null, $this->normalise($attributes, $columns), false),
            ),
            $attributes,
            $compact,
            fn (Spell $window): object => new TemporalChangeStarting($this->related::class, $this->entity, $this->dimensions, $window),
            fn (CarbonImmutable $at, array $closed, array $inserted, bool $compacted): TemporalWriteCommitted => new TemporalChangeCommitted($this->related::class, $this->entity, $this->dimensions, $at, $closed, $inserted, $compacted),
        );

        return $committed instanceof TemporalChangeCommitted
            ? $committed
            : throw new TemporalInvalidSpellException('unexpected write result');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function correct(array $attributes, CarbonInterface|string|null $validFrom = null, CarbonInterface|string|null $validTo = null, ?bool $compact = null): TemporalCorrectionCommitted
    {
        $window = new Spell(
            $validFrom === null ? null : $this->instant($validFrom),
            $validTo === null ? null : $this->instant($validTo),
        );
        $attributes = DimensionValidator::reconcileAttributes($this->meta->dimensions, $this->dimensions, $attributes);

        $committed = $this->run(
            static fn (): Spell => $window,
            fn (Timeline $current, Spell $w, array $columns): Timeline => $current->applyCorrection(
                new TimelineSegment($w, null, $this->normalise($attributes, $columns), false),
            ),
            $attributes,
            $compact,
            fn (Spell $w): object => new TemporalCorrectionStarting($this->related::class, $this->entity, $this->dimensions, $w),
            fn (CarbonImmutable $at, array $closed, array $inserted, bool $compacted): TemporalWriteCommitted => new TemporalCorrectionCommitted($this->related::class, $this->entity, $this->dimensions, $at, $closed, $inserted, $compacted),
        );

        return $committed instanceof TemporalCorrectionCommitted
            ? $committed
            : throw new TemporalInvalidSpellException('unexpected write result');
    }

    public function retract(CarbonInterface|string $validFrom, CarbonInterface|string|null $validTo = null, ?bool $compact = null): TemporalRetractionCommitted
    {
        $window = new Spell($this->instant($validFrom), $validTo === null ? null : $this->instant($validTo));

        $committed = $this->run(
            static fn (): Spell => $window,
            static fn (Timeline $current, Spell $w): Timeline => $current->applyRetraction($w),
            [],
            $compact,
            fn (Spell $w): object => new TemporalRetractionStarting($this->related::class, $this->entity, $this->dimensions, $w),
            fn (CarbonImmutable $at, array $closed, array $inserted, bool $compacted): TemporalWriteCommitted => new TemporalRetractionCommitted($this->related::class, $this->entity, $this->dimensions, $at, $closed, $inserted, $compacted),
        );

        return $committed instanceof TemporalRetractionCommitted
            ? $committed
            : throw new TemporalInvalidSpellException('unexpected write result');
    }

    public function endAt(CarbonInterface|string $validTo, ?bool $compact = null): TemporalChangeCommitted
    {
        $boundary = $this->instant($validTo);
        $window = new Spell($boundary, null);

        $committed = $this->run(
            static fn (): Spell => $window,
            static fn (Timeline $current, Spell $w): Timeline => $current->subtract($w),
            [],
            $compact,
            fn (Spell $w): object => new TemporalChangeStarting($this->related::class, $this->entity, $this->dimensions, $w),
            fn (CarbonImmutable $at, array $closed, array $inserted, bool $compacted): TemporalWriteCommitted => new TemporalChangeCommitted($this->related::class, $this->entity, $this->dimensions, $at, $closed, $inserted, $compacted),
        );

        return $committed instanceof TemporalChangeCommitted
            ? $committed
            : throw new TemporalInvalidSpellException('unexpected write result');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  each row: value attributes + valid_from + valid_to (+ optional is_retraction)
     */
    public function supersedeTimeline(array $rows, ?bool $compact = null): TemporalTimelineSuperseded
    {
        $merged = [];
        foreach ($rows as $row) {
            foreach ($this->rowValueAttributes($row) as $key => $value) {
                $merged[$key] = $value;
            }
        }

        $committed = $this->run(
            static fn (): Spell => Spell::unbounded(),
            fn (Timeline $current, Spell $w, array $columns): Timeline => $this->timelineFromRows($rows, $columns),
            $merged,
            $compact,
            fn (Spell $w): object => new TemporalTimelineSupersedingStarting($this->related::class, $this->entity, $this->dimensions, $w),
            fn (CarbonImmutable $at, array $closed, array $inserted, bool $compacted): TemporalWriteCommitted => new TemporalTimelineSuperseded($this->related::class, $this->entity, $this->dimensions, $at, $closed, $inserted, $compacted),
        );

        return $committed instanceof TemporalTimelineSuperseded
            ? $committed
            : throw new TemporalInvalidSpellException('unexpected write result');
    }

    public function forceDeleteHistory(): TemporalHardDeleteCommitted
    {
        $committed = $this->connection()->transaction(function (): TemporalHardDeleteCommitted {
            $this->locker->lockFor($this->entity, $this->dimensions, $this->parentLockTimeout());

            $ids = array_map(
                static fn (Model $model): mixed => $model->getKey(),
                $this->newQuery()->get()->all(),
            );

            $this->events->dispatch(new TemporalHardDeleteStarting($this->related::class, $this->entity, $this->dimensions, $ids));

            $this->newQuery()->delete();

            return new TemporalHardDeleteCommitted($this->related::class, $this->entity, $this->dimensions, $ids);
        });

        if (! $committed instanceof TemporalHardDeleteCommitted) {
            throw new TemporalInvalidSpellException('unexpected write result');
        }

        $this->events->dispatch($committed);

        return $committed;
    }

    /**
     * @param  \Closure(Timeline): Spell  $windowResolver
     * @param  \Closure(Timeline, Spell, array<int, string>): Timeline  $transform
     * @param  array<string, mixed>  $attributes
     * @param  \Closure(Spell): object  $starting
     * @param  \Closure(CarbonImmutable, array<int, Model>, array<int, Model>, bool): TemporalWriteCommitted  $committed
     */
    private function run(\Closure $windowResolver, \Closure $transform, array $attributes, ?bool $compact, \Closure $starting, \Closure $committed): TemporalWriteCommitted
    {
        $this->assertNoForbiddenAttributes($attributes);
        DimensionValidator::assertComplete($this->meta->dimensions, $this->dimensions);

        $result = $this->connection()->transaction(function () use ($windowResolver, $transform, $attributes, $compact, $starting, $committed): TemporalWriteCommitted {
            $this->locker->lockFor($this->entity, $this->dimensions, $this->parentLockTimeout());

            $recordedAt = $this->captureRecordedAt();
            $currentModels = $this->loadCurrentKnown();
            $valueColumns = $this->resolveValueColumns($currentModels, $attributes);
            $current = $this->toTimeline($currentModels, $valueColumns);

            $window = $windowResolver($current);
            $next = $transform($current, $window, $valueColumns);

            $before = $next->count();
            if ($compact ?? $this->compactByDefault()) {
                $next = $next->compact(array_keys($this->dimensions));
            }
            $compacted = $next->count() !== $before;

            $this->events->dispatch($starting($window));

            $plan = $this->splitter->plan($current, $next);

            $rowsClosed = [];
            foreach ($plan['closeIndexes'] as $index) {
                $model = $currentModels[$index];
                $model->setAttribute($this->meta->recordedTo, $recordedAt);
                $this->persist($model);
                $rowsClosed[] = $model;
            }

            $rowsInserted = [];
            foreach ($plan['insert'] as $segment) {
                $row = $this->buildRow($segment, $recordedAt);
                $this->persist($row);
                $rowsInserted[] = $row;
            }

            if ($compacted) {
                $this->events->dispatch(new TemporalCompactionPerformed(
                    $this->related::class, $this->entity, $this->dimensions, $before, $next->count(),
                ));
            }

            $this->assertNoCurrentOverlaps();

            return $committed($recordedAt, $rowsClosed, $rowsInserted, $compacted);
        });

        if (! $result instanceof TemporalWriteCommitted) {
            throw new TemporalInvalidSpellException('unexpected write result');
        }

        // The transaction has committed by the time control returns here; firing
        // the committed event now keeps audit-log subscribers outside the write
        // transaction (matching DB::afterCommit semantics for top-level writes).
        $this->events->dispatch($result);

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $valueColumns
     */
    private function timelineFromRows(array $rows, array $valueColumns): Timeline
    {
        $segments = [];
        foreach ($rows as $row) {
            $segments[] = new TimelineSegment(
                new Spell(
                    $this->rowInstant($row, $this->meta->validFrom),
                    $this->rowInstant($row, $this->meta->validTo),
                ),
                null,
                $this->normalise($this->rowValueAttributes($row), $valueColumns),
                (bool) ($row[$this->meta->isRetraction] ?? false),
            );
        }

        return new Timeline($segments);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowValueAttributes(array $row): array
    {
        $reserved = $this->reservedColumns();

        return array_filter(
            $row,
            fn (string $key): bool => ! in_array($key, $reserved, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowInstant(array $row, string $column): ?CarbonImmutable
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value)) {
            return $this->instant($value);
        }

        throw new TemporalInvalidSpellException("superseded row has an invalid {$column} value");
    }

    private function forwardWindow(Timeline $current, CarbonImmutable $from): Spell
    {
        $boundary = null;
        foreach ($current->segments() as $segment) {
            $segmentFrom = $segment->validSpell->from;
            if ($segmentFrom !== null && $segmentFrom->greaterThan($from) && ($boundary === null || $segmentFrom->lessThan($boundary))) {
                $boundary = $segmentFrom;
            }
        }

        if ($boundary !== null) {
            $this->events->dispatch(new TemporalFutureRowEncountered(
                $this->related::class, $this->entity, $this->dimensions, $boundary,
            ));
        }

        return new Spell($from, $boundary);
    }

    /**
     * @return array<int, Model>
     */
    private function loadCurrentKnown(): array
    {
        $models = $this->newQuery()->currentKnowledge()->get()->all();

        usort($models, fn (Model $a, Model $b): int => [$this->validFrom($a) instanceof CarbonImmutable, $this->validFrom($a)] <=> [$this->validFrom($b) instanceof CarbonImmutable, $this->validFrom($b)]);

        return $models;
    }

    private function validFrom(Model $model): ?CarbonImmutable
    {
        $value = $model->getAttribute($this->meta->validFrom);

        return $value instanceof CarbonImmutable ? $value : null;
    }

    /**
     * @param  array<int, Model>  $models
     * @param  array<int, string>  $valueColumns
     */
    private function toTimeline(array $models, array $valueColumns): Timeline
    {
        $segments = [];
        foreach ($models as $model) {
            $segments[] = new TimelineSegment(
                new Spell($this->validFrom($model), $this->validTo($model)),
                null,
                $this->valueAttributes($model, $valueColumns),
                (bool) $model->getAttribute($this->meta->isRetraction),
            );
        }

        return new Timeline($segments);
    }

    private function validTo(Model $model): ?CarbonImmutable
    {
        $value = $model->getAttribute($this->meta->validTo);

        return $value instanceof CarbonImmutable ? $value : null;
    }

    /**
     * @param  array<int, Model>  $models
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    private function resolveValueColumns(array $models, array $attributes): array
    {
        $columns = array_keys($attributes);
        foreach ($models as $model) {
            foreach (array_keys($model->getAttributes()) as $column) {
                $columns[] = $column;
            }
        }

        $excluded = $this->reservedColumns();

        return array_values(array_unique(array_filter(
            $columns,
            fn (string $column): bool => ! in_array($column, $excluded, true),
        )));
    }

    /**
     * @param  array<int, string>  $valueColumns
     * @return array<string, mixed>
     */
    private function valueAttributes(Model $model, array $valueColumns): array
    {
        $attributes = [];
        foreach ($valueColumns as $column) {
            $attributes[$column] = $model->getAttribute($column);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $valueColumns
     * @return array<string, mixed>
     */
    private function normalise(array $attributes, array $valueColumns): array
    {
        $normalised = [];
        foreach ($valueColumns as $column) {
            $normalised[$column] = $attributes[$column] ?? null;
        }

        return $normalised;
    }

    private function buildRow(TimelineSegment $segment, CarbonImmutable $recordedAt): Model
    {
        $row = $this->related->newInstance();
        $row->forceFill($segment->attributes);

        foreach ($this->entityScope as $column => $value) {
            $row->setAttribute($column, $value);
        }

        $row->setAttribute($this->meta->validFrom, $segment->validSpell->from);
        $row->setAttribute($this->meta->validTo, $segment->validSpell->to);
        $row->setAttribute($this->meta->isRetraction, $segment->isRetraction);

        foreach ($this->dimensions as $column => $value) {
            $row->setAttribute($column, $value);
        }

        if ($this->meta->tracksRecordedTime) {
            $row->setAttribute($this->meta->recordedFrom, $recordedAt);
            $row->setAttribute($this->meta->recordedTo, null);
        }

        return $row;
    }

    private function persist(Model $model): void
    {
        if ($this->fireEloquentEvents()) {
            $model->save();

            return;
        }

        $model->saveQuietly();
    }

    private function captureRecordedAt(): CarbonImmutable
    {
        $now = CarbonImmutable::now($this->timezone());

        $max = $this->newQuery()->max($this->meta->recordedFrom);

        if (is_string($max)) {
            $maxInstant = CarbonImmutable::parse($max, $this->timezone());

            if (! $now->greaterThan($maxInstant)) {
                return $maxInstant->addMicrosecond();
            }
        }

        return $now;
    }

    private function assertNoCurrentOverlaps(): void
    {
        try {
            $this->toTimeline($this->loadCurrentKnown(), []);
        } catch (TemporalOverlapException $exception) {
            $this->events->dispatch(new TemporalOverlapPrevented($this->related::class, $this->entity, $this->dimensions));

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoForbiddenAttributes(array $attributes): void
    {
        foreach ($this->reservedColumns() as $column) {
            if (array_key_exists($column, $attributes)) {
                throw TemporalMissingDimensionException::forbiddenAttribute($column);
            }
        }
    }

    private function assertForwardDated(CarbonImmutable $from): void
    {
        $tolerance = $this->intConfig('bitemporal.writes.future_validity_tolerance_ms', 1000);
        $threshold = CarbonImmutable::now($this->timezone())->subMilliseconds($tolerance);

        if ($from->lessThan($threshold)) {
            throw new TemporalInvalidSpellException('changeEffectiveFrom requires a forward-dated validFrom; use correctPeriod for retroactive changes');
        }
    }

    /**
     * @return array<int, string>
     */
    private function reservedColumns(): array
    {
        $excluded = config('bitemporal.writes.compaction_excluded_columns', []);

        return [
            $this->related->getKeyName(),
            ...array_keys($this->entityScope),
            $this->meta->validFrom,
            $this->meta->validTo,
            $this->meta->recordedFrom,
            $this->meta->recordedTo,
            $this->meta->isRetraction,
            ...$this->meta->dimensions,
            ...(is_array($excluded) ? array_values(array_filter($excluded, is_string(...))) : []),
        ];
    }

    /**
     * @return BitemporalBuilder<Model>
     */
    private function newQuery(): BitemporalBuilder
    {
        $query = $this->related->newQuery();

        if (! $query instanceof BitemporalBuilder) {
            throw new TemporalInvalidSpellException($this->related::class.' must use the Bitemporal trait');
        }

        // Writes read the true stored state, never an ambient point-in-time lens.
        $query->withoutLens();

        foreach ($this->entityScope as $column => $value) {
            $query->where($column, '=', $value);
        }

        foreach ($this->dimensions as $column => $value) {
            if ($value === null) {
                $query->whereNull($column);

                continue;
            }

            $query->where($column, '=', $value);
        }

        return $query;
    }

    private function instant(CarbonInterface|string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone($this->timezone());
    }

    private function timezone(): string
    {
        $timezone = config('bitemporal.spells.timezone', 'UTC');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    private function compactByDefault(): bool
    {
        return (bool) config('bitemporal.writes.compact_adjacent_segments', true);
    }

    private function parentLockTimeout(): int
    {
        return $this->intConfig('bitemporal.writes.parent_lock_timeout_ms', 5000);
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    private function fireEloquentEvents(): bool
    {
        if (property_exists($this->related, 'fireEloquentEventsOnTemporalWrites')) {
            return (bool) $this->related->fireEloquentEventsOnTemporalWrites;
        }

        return (bool) config('bitemporal.writes.fire_eloquent_events', false);
    }

    private function connection(): ConnectionInterface
    {
        return $this->related->getConnection();
    }
}
