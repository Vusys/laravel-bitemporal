<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\AuditLog;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vusys\Bitemporal\Events\TemporalAuditLogWriteFailed;
use Vusys\Bitemporal\Events\TemporalBackfillCommitted;
use Vusys\Bitemporal\Events\TemporalChangeCommitted;
use Vusys\Bitemporal\Events\TemporalCorrectionCommitted;
use Vusys\Bitemporal\Events\TemporalHardDeleteCommitted;
use Vusys\Bitemporal\Events\TemporalRetractionCommitted;
use Vusys\Bitemporal\Events\TemporalTimelineSuperseded;
use Vusys\Bitemporal\Events\TemporalWriteCommitted;

/**
 * Opt-in append-only audit log. Listens for every Temporal*Committed event and
 * inserts a row into the audit-log table. Because the committed events fire
 * after the write transaction commits, the audit INSERT is a separate
 * transaction: a failure logs + emits TemporalAuditLogWriteFailed but never
 * rolls back the temporal write.
 */
final class TemporalAuditLogSubscriber
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            TemporalChangeCommitted::class => 'handleWrite',
            TemporalCorrectionCommitted::class => 'handleWrite',
            TemporalRetractionCommitted::class => 'handleWrite',
            TemporalTimelineSuperseded::class => 'handleWrite',
            TemporalBackfillCommitted::class => 'handleBackfill',
            TemporalHardDeleteCommitted::class => 'handleHardDelete',
        ];
    }

    public function handleWrite(TemporalWriteCommitted $event): void
    {
        $this->record($event, $event->model, $event->entity, $event->dimensions, [
            'closed_ids' => array_map($this->key(...), $event->rowsClosed),
            'inserted_ids' => array_map($this->key(...), $event->rowsInserted),
            'compacted' => $event->compacted,
        ], $event->recordedAt);
    }

    public function handleBackfill(TemporalBackfillCommitted $event): void
    {
        $this->record($event, $event->model, $event->entity, $event->dimensions, [
            'inserted_ids' => array_map($this->key(...), $event->rowsInserted),
        ], CarbonImmutable::now());
    }

    public function handleHardDelete(TemporalHardDeleteCommitted $event): void
    {
        $this->record($event, $event->model, $event->entity, $event->dimensions, [
            'deleted_ids' => array_values(array_map($this->scalar(...), $event->ids)),
        ], CarbonImmutable::now());
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     * @param  array<string, mixed>  $payload
     */
    private function record(object $event, string $model, Model $entity, array $dimensions, array $payload, CarbonImmutable $recordedAt): void
    {
        try {
            DB::connection($this->connection())->table($this->table())->insert([
                'event_class' => class_basename($event),
                'model' => $model,
                'entity_type' => $this->entityType($model, $entity),
                'entity_id' => $this->scalar($entity->getKey()),
                'dimensions' => (string) json_encode($dimensions),
                'payload' => (string) json_encode($payload),
                'recorded_at' => $recordedAt->format('Y-m-d H:i:s.u'),
                'observed_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
            ]);
        } catch (Throwable $exception) {
            Log::error('TemporalAuditLogSubscriber failed', [
                'event' => $event::class,
                'model' => $model,
                'exception' => $exception->getMessage(),
            ]);

            event(new TemporalAuditLogWriteFailed($event, $exception));
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function entityType(string $model, Model $entity): ?string
    {
        $instance = new $model;

        if (! method_exists($instance, 'temporalEntityRelation')) {
            return null;
        }

        return $instance->temporalEntityRelation() instanceof MorphTo ? $entity->getMorphClass() : null;
    }

    private function connection(): ?string
    {
        $connection = config('bitemporal.audit_log.connection');

        return is_string($connection) ? $connection : null;
    }

    private function table(): string
    {
        $table = config('bitemporal.audit_log.table', 'temporal_audit_log');

        return is_string($table) ? $table : 'temporal_audit_log';
    }

    private function key(Model $model): int|string
    {
        return $this->scalar($model->getKey());
    }

    private function scalar(mixed $value): int|string
    {
        return is_int($value) || is_string($value) ? $value : get_debug_type($value);
    }
}
