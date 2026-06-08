<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fired after the write transaction commits (via DB::afterCommit). Doubles as
 * the return value of the write API.
 */
abstract class TemporalWriteCommitted
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     * @param  array<int, Model>  $rowsClosed
     * @param  array<int, Model>  $rowsInserted
     */
    public function __construct(
        public readonly string $model,
        public readonly Model $entity,
        public readonly array $dimensions,
        public readonly CarbonImmutable $recordedAt,
        public readonly array $rowsClosed,
        public readonly array $rowsInserted,
        public readonly bool $compacted = false,
    ) {}

    public function closedCount(): int
    {
        return count($this->rowsClosed);
    }

    public function insertedCount(): int
    {
        return count($this->rowsInserted);
    }
}
