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
use Vusys\Bitemporal\Events\TemporalOverlapPrevented;
use Vusys\Bitemporal\Events\TemporalWriteCommitted;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;
use Vusys\Bitemporal\Timeline;
use Vusys\Bitemporal\TimelineSegment;

final readonly class BitemporalWriter
{
    private TemporalEntityMetadata $meta;

    /**
     * @param  array<string, mixed>  $dimensions
     */
    public function __construct(
        private Model $related,
        private Model $entity,
        private string $foreignKey,
        private array $dimensions,
        private WriteLocker $locker,
        private TimelineSplitter $splitter,
        private Dispatcher $events,
    ) {
        if (! method_exists($related, 'temporalMetadata')) {
            throw new TemporalInvalidSpellException($related::class.' is not a temporal model');
        }

        $this->meta = $related->temporalMetadata();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function changeEffectiveFrom(array $attributes, CarbonInterface|string $validFrom, ?bool $compact = null): TemporalChangeCommitted
    {
        $from = $this->instant($validFrom);
        $this->assertForwardDated($from);

        $committed = $this->run(
            fn (Timeline $current): Spell => $this->forwardWindow($current, $from),
            $attributes,
            $compact,
            change: true,
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
        $from = $validFrom === null ? null : $this->instant($validFrom);
        $to = $validTo === null ? null : $this->instant($validTo);

        $committed = $this->run(
            static fn (): Spell => new Spell($from, $to),
            $attributes,
            $compact,
            change: false,
        );

        return $committed instanceof TemporalCorrectionCommitted
            ? $committed
            : throw new TemporalInvalidSpellException('unexpected write result');
    }

    /**
     * @param  \Closure(Timeline): Spell  $windowResolver
     * @param  array<string, mixed>  $attributes
     */
    private function run(\Closure $windowResolver, array $attributes, ?bool $compact, bool $change): TemporalWriteCommitted
    {
        $this->assertNoForbiddenAttributes($attributes);

        $committed = $this->connection()->transaction(function () use ($windowResolver, $attributes, $compact, $change): TemporalWriteCommitted {
            $this->locker->lockFor($this->entity, $this->dimensions, $this->parentLockTimeout());

            $recordedAt = $this->captureRecordedAt();
            $currentModels = $this->loadCurrentKnown();
            $valueColumns = $this->resolveValueColumns($currentModels, $attributes);
            $current = $this->toTimeline($currentModels, $valueColumns);

            $window = $windowResolver($current);

            $correction = new TimelineSegment($window, null, $this->normalise($attributes, $valueColumns), false);
            $next = $current->applyCorrection($correction);

            $before = $next->count();
            if ($compact ?? $this->compactByDefault()) {
                $next = $next->compact(array_keys($this->dimensions));
            }
            $compacted = $next->count() !== $before;

            $this->events->dispatch($change
                ? new TemporalChangeStarting($this->related::class, $this->entity, $this->dimensions, $window)
                : new TemporalCorrectionStarting($this->related::class, $this->entity, $this->dimensions, $window));

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

            return $change
                ? new TemporalChangeCommitted($this->related::class, $this->entity, $this->dimensions, $recordedAt, $rowsClosed, $rowsInserted, $compacted)
                : new TemporalCorrectionCommitted($this->related::class, $this->entity, $this->dimensions, $recordedAt, $rowsClosed, $rowsInserted, $compacted);
        });

        if (! $committed instanceof TemporalWriteCommitted) {
            throw new TemporalInvalidSpellException('unexpected write result');
        }

        // The transaction has committed by the time control returns here; firing
        // the committed event now keeps audit-log subscribers outside the write
        // transaction (matching DB::afterCommit semantics for top-level writes).
        $this->events->dispatch($committed);

        return $committed;
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
        $row->setAttribute($this->foreignKey, $this->entity->getKey());
        $row->setAttribute($this->meta->validFrom, $segment->validSpell->from);
        $row->setAttribute($this->meta->validTo, $segment->validSpell->to);
        $row->setAttribute($this->meta->isRetraction, $segment->isRetraction);

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
            $this->foreignKey,
            $this->meta->validFrom,
            $this->meta->validTo,
            $this->meta->recordedFrom,
            $this->meta->recordedTo,
            $this->meta->isRetraction,
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

        $query->where($this->foreignKey, $this->entity->getKey());

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
