<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Observability;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Events\TemporalBackfillCommitted;
use Vusys\Bitemporal\Events\TemporalChangeCommitted;
use Vusys\Bitemporal\Events\TemporalCompactionPerformed;
use Vusys\Bitemporal\Events\TemporalCorrectionCommitted;
use Vusys\Bitemporal\Events\TemporalOverlapPrevented;
use Vusys\Bitemporal\Events\TemporalRetractionCommitted;
use Vusys\Bitemporal\Events\TemporalTimelineSuperseded;
use Vusys\Bitemporal\Events\TemporalWriteCommitted;

/**
 * Translates the package's existing domain events into {@see TemporalMetrics}
 * calls, so the writer itself stays free of metrics concerns. Only the timing
 * metrics that no event carries (lock-wait, end-to-end latency, deadlock
 * retries) are measured inline in the writer.
 *
 * The operation tag is derived from the committed-event class, giving each
 * metric a `model`, `operation`, and `engine` tag.
 */
final readonly class TemporalMetricsSubscriber
{
    /**
     * @var array<class-string, string>
     */
    private const OPERATIONS = [
        TemporalChangeCommitted::class => 'change',
        TemporalCorrectionCommitted::class => 'correct',
        TemporalRetractionCommitted::class => 'retract',
        TemporalTimelineSuperseded::class => 'supersede',
    ];

    public function __construct(private TemporalMetrics $metrics) {}

    public function subscribe(Dispatcher $events): void
    {
        foreach (array_keys(self::OPERATIONS) as $committed) {
            $events->listen($committed, [self::class, 'handleWriteCommitted']);
        }

        $events->listen(TemporalBackfillCommitted::class, [self::class, 'handleBackfillCommitted']);
        $events->listen(TemporalCompactionPerformed::class, [self::class, 'handleCompactionPerformed']);
        $events->listen(TemporalOverlapPrevented::class, [self::class, 'handleOverlapPrevented']);
    }

    public function handleWriteCommitted(TemporalWriteCommitted $event): void
    {
        $tags = $this->tags($event->model, self::OPERATIONS[$event::class] ?? 'write', $event->entity);

        $this->metrics->rowsClosed($event->closedCount(), $tags);
        $this->metrics->rowsInserted($event->insertedCount(), $tags);
    }

    public function handleBackfillCommitted(TemporalBackfillCommitted $event): void
    {
        $this->metrics->rowsInserted(
            $event->insertedCount(),
            $this->tags($event->model, 'backfill', $event->entity),
        );
    }

    public function handleCompactionPerformed(TemporalCompactionPerformed $event): void
    {
        $this->metrics->compactionPerformed(
            $event->segmentsBefore,
            $event->segmentsAfter,
            $this->tags($event->model, 'compaction', $event->entity),
        );
    }

    public function handleOverlapPrevented(TemporalOverlapPrevented $event): void
    {
        $this->metrics->overlapPrevented($this->tags($event->model, 'overlap', $event->entity));
    }

    /**
     * @return array<string, string>
     */
    private function tags(string $model, string $operation, Model $entity): array
    {
        return [
            'model' => $model,
            'operation' => $operation,
            'engine' => $entity->getConnection()->getDriverName(),
        ];
    }
}
