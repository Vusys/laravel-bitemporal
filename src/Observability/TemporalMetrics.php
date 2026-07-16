<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Observability;

/**
 * Opt-in observability sink for temporal writes. The package binds
 * {@see NullMetrics} by default; an application opts in by binding its own
 * implementation of this interface, which then receives write counts, churn,
 * compaction ratios, lock-wait times, and deadlock retries.
 *
 * Every call carries a $tags map containing at least `model`, `operation`, and
 * `engine`, so a backend can slice the metrics without the package depending on
 * any particular metrics library.
 *
 * @phpstan-type Tags array<string, string|int|float>
 */
interface TemporalMetrics
{
    /**
     * @param  Tags  $tags
     */
    public function writeLatency(string $operation, float $ms, array $tags): void;

    /**
     * @param  Tags  $tags
     */
    public function rowsClosed(int $count, array $tags): void;

    /**
     * @param  Tags  $tags
     */
    public function rowsInserted(int $count, array $tags): void;

    /**
     * @param  Tags  $tags
     */
    public function lockWaitMs(float $ms, array $tags): void;

    /**
     * @param  Tags  $tags
     */
    public function deadlockRetry(int $attempt, array $tags): void;

    /**
     * @param  Tags  $tags
     */
    public function overlapPrevented(array $tags): void;

    /**
     * @param  Tags  $tags
     */
    public function compactionPerformed(int $segmentsBefore, int $segmentsAfter, array $tags): void;
}
