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
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
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
        private ?int $chunkSize = null,
    ) {
        if (! method_exists($related, 'temporalMetadata')) {
            throw new TemporalInvalidSpellException($related::class.' is not a temporal model');
        }

        $this->meta = $related->temporalMetadata();
        $this->entityScope = EntityScope::resolve($related, $entity);
    }

    /**
     * Switch to the streaming path: rows are validated and inserted a chunk at a
     * time in their own transactions, keeping wall-memory bounded for large
     * historical loads. Chunks are not cross-validated during the stream, so a
     * post-import overlap audit (scoped to this entity) guards cross-chunk
     * overlaps.
     */
    public function stream(?int $chunkSize = null): self
    {
        return new self(
            $this->related,
            $this->entity,
            $this->dimensions,
            $this->locker,
            $this->events,
            max(1, $chunkSize ?? $this->configuredChunkSize()),
        );
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function timeline(iterable $rows): TemporalBackfillCommitted
    {
        if ($this->chunkSize !== null) {
            return $this->insertStreaming($rows);
        }

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

        $this->events->dispatch($committed);

        return $committed;
    }

    /**
     * Stream rows in bounded-memory chunks. Each chunk is internally validated,
     * inserted in its own locked transaction, and announced with its chunkIndex;
     * routine writes may interleave between chunks. After the last chunk a scoped
     * overlap audit runs, and only on success does the final aggregate event
     * (chunkIndex = null) fire.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function insertStreaming(iterable $rows): TemporalBackfillCommitted
    {
        $now = CarbonImmutable::now($this->timezone());
        $chunkIndex = 0;
        $allInserted = [];
        $buffer = [];

        foreach ($rows as $row) {
            $buffer[] = $this->toSegment($row);

            if (count($buffer) >= (int) $this->chunkSize) {
                $allInserted = [...$allInserted, ...$this->flushChunk($buffer, $chunkIndex, $now)];
                $chunkIndex++;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $allInserted = [...$allInserted, ...$this->flushChunk($buffer, $chunkIndex, $now)];
        }

        if ($this->postAuditEnabled()) {
            $this->auditScopedOverlaps($allInserted);
        }

        // Final aggregate event only after the audit passes.
        $committed = new TemporalBackfillCommitted(
            $this->related::class, $this->entity, $this->dimensions, $allInserted,
        );
        $this->events->dispatch($committed);

        return $committed;
    }

    /**
     * Validate a chunk for internal overlap and insert it in its own locked
     * transaction, returning the inserted rows and firing a per-chunk event.
     *
     * @param  array<int, TimelineSegment>  $segments
     * @return array<int, Model>
     */
    private function flushChunk(array $segments, int $chunkIndex, CarbonImmutable $now): array
    {
        (new BackfillValidator)->validate($segments, $now);

        $rowsInserted = $this->connection()->transaction(function () use ($segments): array {
            $this->locker->lockFor($this->entity, $this->dimensions);

            $inserted = [];
            foreach ($segments as $segment) {
                $row = $this->buildRow($segment);
                $row->saveQuietly();
                $inserted[] = $row;
            }

            return $inserted;
        });

        $this->events->dispatch(new TemporalBackfillCommitted(
            $this->related::class, $this->entity, $this->dimensions, $rowsInserted, $chunkIndex,
        ));

        return $rowsInserted;
    }

    /**
     * Scoped overlap audit over the current-known rows of this entity/dimension
     * tuple — the same guarantee bitemporal:audit-overlaps enforces, at a cost
     * proportional to the import rather than the whole table. On failure the
     * inserted primary keys ride along so the caller can recover.
     *
     * @param  array<int, Model>  $inserted
     */
    private function auditScopedOverlaps(array $inserted): void
    {
        $query = $this->related->newQuery();

        foreach ([...$this->entityScope, ...$this->dimensions] as $column => $value) {
            $query->where($column, $value);
        }

        $rows = $query->whereNull($this->meta->recordedTo)->get()->all();

        $spells = [];
        foreach ($rows as $row) {
            $spells[] = new Spell(
                $this->date($row->getAttribute($this->meta->validFrom)),
                $this->date($row->getAttribute($this->meta->validTo)),
            );
        }

        $count = count($spells);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($spells[$i]->intersects($spells[$j])) {
                    throw TemporalOverlapException::afterBackfillAudit(
                        array_map(static fn (Model $row): mixed => $row->getKey(), $inserted),
                    );
                }
            }
        }
    }

    private function configuredChunkSize(): int
    {
        $value = config('bitemporal.backfill.default_chunk_size', 1000);

        return is_int($value) && $value > 0 ? $value : 1000;
    }

    private function postAuditEnabled(): bool
    {
        return config('bitemporal.backfill.post_audit_check', true) === true;
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
