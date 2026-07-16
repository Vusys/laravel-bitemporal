<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Observability;

use Vusys\Bitemporal\Observability\TemporalMetrics;

/**
 * Records every metric call so tests can assert on them.
 */
final class SpyMetrics implements TemporalMetrics
{
    /** @var list<array{operation: string, ms: float, tags: array<string, string|int|float>}> */
    public array $latencies = [];

    /** @var list<array{count: int, tags: array<string, string|int|float>}> */
    public array $rowsClosed = [];

    /** @var list<array{count: int, tags: array<string, string|int|float>}> */
    public array $rowsInserted = [];

    /** @var list<array{ms: float, tags: array<string, string|int|float>}> */
    public array $lockWaits = [];

    /** @var list<array{attempt: int, tags: array<string, string|int|float>}> */
    public array $deadlockRetries = [];

    /** @var list<array<string, string|int|float>> */
    public array $overlaps = [];

    /** @var list<array{before: int, after: int, tags: array<string, string|int|float>}> */
    public array $compaction = [];

    public function writeLatency(string $operation, float $ms, array $tags): void
    {
        $this->latencies[] = ['operation' => $operation, 'ms' => $ms, 'tags' => $tags];
    }

    public function rowsClosed(int $count, array $tags): void
    {
        $this->rowsClosed[] = ['count' => $count, 'tags' => $tags];
    }

    public function rowsInserted(int $count, array $tags): void
    {
        $this->rowsInserted[] = ['count' => $count, 'tags' => $tags];
    }

    public function lockWaitMs(float $ms, array $tags): void
    {
        $this->lockWaits[] = ['ms' => $ms, 'tags' => $tags];
    }

    public function deadlockRetry(int $attempt, array $tags): void
    {
        $this->deadlockRetries[] = ['attempt' => $attempt, 'tags' => $tags];
    }

    public function overlapPrevented(array $tags): void
    {
        $this->overlaps[] = $tags;
    }

    public function compactionPerformed(int $segmentsBefore, int $segmentsAfter, array $tags): void
    {
        $this->compaction[] = ['before' => $segmentsBefore, 'after' => $segmentsAfter, 'tags' => $tags];
    }
}
