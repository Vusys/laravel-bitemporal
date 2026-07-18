<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Backfill;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\BitemporalBuilder;
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
 * Imports historical knowledge (ETL) without running the correction algorithm.
 * Domain columns may be supplied flat on each row or nested under an
 * 'attributes' key. `timeline()` stamps the recorded axis as "now" for any row
 * that omits recorded_from — seeding a clean current-knowledge history —
 * whereas `importHistoricalKnowledge()` is used with explicit recorded spells to
 * reconstruct past beliefs.
 */
final readonly class BitemporalBackfill
{
    /**
     * Row keys the DSL reserves for period/control values; every other key is
     * treated as a domain value column when a row supplies them flat.
     *
     * @var array<int, string>
     */
    private const array RESERVED_ROW_KEYS = ['attributes', 'valid_from', 'valid_to', 'recorded_from', 'recorded_to', 'is_retraction'];

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

        $now = CarbonImmutable::now($this->timezone());

        $segments = [];
        foreach ($rows as $row) {
            $segments[] = $this->toSegment($row, $now);
        }

        return $this->insert($segments, $now);
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
    private function insert(array $segments, CarbonImmutable $now): TemporalBackfillCommitted
    {
        (new BackfillValidator)->validate($segments, $now);

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

            // BackfillValidator only checks the in-memory batch for internal
            // overlap; backfilling into a scope that already holds rows could
            // otherwise insert bitemporally-overlapping rows undetected (issue
            // #71). Run the same scoped DB audit the streaming path runs — here,
            // still inside the transaction, so a conflict rolls the batch back.
            if ($this->postAuditEnabled()) {
                $this->auditScopedOverlaps($rowsInserted);
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
            $buffer[] = $this->toSegment($row, $now);

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
     * Scoped overlap audit over the entity/dimension tuple — the same guarantee
     * bitemporal:audit-overlaps enforces, at a cost proportional to the import
     * rather than the whole table. On failure the inserted primary keys ride
     * along so the caller can recover.
     *
     * The audit is bitemporal: importHistoricalKnowledge() inserts explicit
     * recorded spells (both open and closed), so two chunks can each insert
     * *closed* rows that collide only when both the valid and recorded periods
     * intersect. It therefore loads ALL rows for the tuple (not just the
     * current-known ones) and tests intersection on both axes via the exact
     * predicate the per-chunk BackfillValidator uses. It reads withoutLens() so
     * an active as-of/knownAt frame cannot hide a conflicting row.
     *
     * @param  array<int, Model>  $inserted
     */
    private function auditScopedOverlaps(array $inserted): void
    {
        $query = $this->related->newQuery();

        if ($query instanceof BitemporalBuilder) {
            $query->withoutLens();
        }

        foreach ([...$this->entityScope, ...$this->dimensions] as $column => $value) {
            $query->where($column, $value);
        }

        $tracksRecorded = $this->meta->tracksRecordedTime;

        $segments = [];
        foreach ($query->get()->all() as $row) {
            $segments[] = new TimelineSegment(
                new Spell(
                    $this->date($row->getAttribute($this->meta->validFrom)),
                    $this->date($row->getAttribute($this->meta->validTo)),
                ),
                $tracksRecorded ? new Spell(
                    $this->date($row->getAttribute($this->meta->recordedFrom)),
                    $this->date($row->getAttribute($this->meta->recordedTo)),
                ) : null,
                [],
                (bool) $row->getAttribute($this->meta->isRetraction),
            );
        }

        $validator = new BackfillValidator;
        $count = count($segments);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                // Effective-dated-only tuples have no recorded axis, so a valid
                // intersection alone is the conflict; bitemporal tuples require
                // both axes to collide.
                $conflict = $tracksRecorded
                    ? $validator->overlaps($segments[$i], $segments[$j])
                    : $segments[$i]->validSpell->intersects($segments[$j]->validSpell);

                if ($conflict) {
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
    private function toSegment(array $row, CarbonImmutable $now): TimelineSegment
    {
        // Domain columns may be nested under 'attributes' or supplied flat
        // alongside the period columns (as supersedeTimeline() accepts them).
        $attributes = $row['attributes'] ?? array_diff_key($row, array_flip(self::RESERVED_ROW_KEYS));

        // timeline() stamps the recorded axis as "now" when no recorded_from is
        // given; importHistoricalKnowledge() supplies explicit recorded spells.
        $recordedFrom = $this->date($row['recorded_from'] ?? null) ?? $now;

        return new TimelineSegment(
            new Spell($this->date($row['valid_from'] ?? null), $this->date($row['valid_to'] ?? null)),
            new Spell($recordedFrom, $this->date($row['recorded_to'] ?? null)),
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
