<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Observability;

/**
 * Default no-op {@see TemporalMetrics}. Bound unless the application binds its
 * own implementation, so metrics collection is free until opted into. The
 * writer skips its inline timing entirely when this implementation is bound.
 */
final class NullMetrics implements TemporalMetrics
{
    public function writeLatency(string $operation, float $ms, array $tags): void {}

    public function rowsClosed(int $count, array $tags): void {}

    public function rowsInserted(int $count, array $tags): void {}

    public function lockWaitMs(float $ms, array $tags): void {}

    public function deadlockRetry(int $attempt, array $tags): void {}

    public function overlapPrevented(array $tags): void {}

    public function compactionPerformed(int $segmentsBefore, int $segmentsAfter, array $tags): void {}
}
