<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Backfill;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Events\TemporalBackfillCommitted;
use Vusys\Bitemporal\Events\TemporalBackfillStarting;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Support\EntityScope;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;
use Vusys\Bitemporal\TimelineSegment;

/**
 * Imports historical knowledge with explicit recorded periods (ETL). Unlike the
 * routine write API it does not run the correction algorithm or capture a
 * recorded-at clock; the caller supplies recorded_from / recorded_to per row.
 */
final readonly class BitemporalBackfill
{
    private TemporalEntityMetadata $meta;

    /**
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
        private Dispatcher $events,
    ) {
        if (! method_exists($related, 'temporalMetadata')) {
            throw new TemporalInvalidSpellException($related::class.' is not a temporal model');
        }

        $this->meta = $related->temporalMetadata();
        $this->entityScope = EntityScope::resolve($related, $entity);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function timeline(iterable $rows): TemporalBackfillCommitted
    {
        $segments = [];
        foreach ($rows as $row) {
            $segments[] = $this->toSegment($row);
        }

        return $this->insert($segments);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function importHistoricalKnowledge(iterable $rows): TemporalBackfillCommitted
    {
        return $this->timeline($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function retraction(array $row): TemporalBackfillCommitted
    {
        $row['attributes'] = [];
        $row['is_retraction'] = true;

        return $this->timeline([$row]);
    }

    /**
     * @param  array<int, TimelineSegment>  $segments
     */
    private function insert(array $segments): TemporalBackfillCommitted
    {
        (new BackfillValidator)->validate($segments, CarbonImmutable::now($this->timezone()));

        $committed = $this->connection()->transaction(function () use ($segments): TemporalBackfillCommitted {
            $this->locker->lockFor($this->entity, $this->dimensions);

            $this->events->dispatch(new TemporalBackfillStarting(
                $this->related::class, $this->entity, $this->dimensions, count($segments),
            ));

            $rowsInserted = [];
            foreach ($segments as $segment) {
                $row = $this->buildRow($segment);
                $row->saveQuietly();
                $rowsInserted[] = $row;
            }

            return new TemporalBackfillCommitted($this->related::class, $this->entity, $this->dimensions, $rowsInserted);
        });

        if (! $committed instanceof TemporalBackfillCommitted) {
            throw new TemporalInvalidSpellException('unexpected backfill result');
        }

        $this->events->dispatch($committed);

        return $committed;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toSegment(array $row): TimelineSegment
    {
        $attributes = $row['attributes'] ?? [];

        return new TimelineSegment(
            new Spell($this->date($row['valid_from'] ?? null), $this->date($row['valid_to'] ?? null)),
            new Spell($this->date($row['recorded_from'] ?? null), $this->date($row['recorded_to'] ?? null)),
            is_array($attributes) ? $attributes : [],
            (bool) ($row['is_retraction'] ?? false),
        );
    }

    private function buildRow(TimelineSegment $segment): Model
    {
        $row = $this->related->newInstance();
        $row->forceFill($segment->attributes);

        foreach ($this->entityScope as $column => $value) {
            $row->setAttribute($column, $value);
        }

        foreach ($this->dimensions as $column => $value) {
            $row->setAttribute($column, $value);
        }

        $row->setAttribute($this->meta->validFrom, $segment->validSpell->from);
        $row->setAttribute($this->meta->validTo, $segment->validSpell->to);
        $row->setAttribute($this->meta->isRetraction, $segment->isRetraction);
        $row->setAttribute($this->meta->recordedFrom, $segment->recordedSpell?->from);
        $row->setAttribute($this->meta->recordedTo, $segment->recordedSpell?->to);

        return $row;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->setTimezone($this->timezone());
        }

        if (is_string($value)) {
            return CarbonImmutable::parse($value)->setTimezone($this->timezone());
        }

        throw new TemporalInvalidSpellException('backfill row has an invalid date value');
    }

    private function timezone(): string
    {
        $timezone = config('bitemporal.spells.timezone', 'UTC');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    private function connection(): ConnectionInterface
    {
        return $this->related->getConnection();
    }
}
