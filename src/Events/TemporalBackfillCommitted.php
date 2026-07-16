<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired after backfilled historical rows are committed. Doubles as the return
 * value of the backfill API.
 *
 * For a streaming import it fires once per chunk with the 0-based $chunkIndex,
 * then a final aggregate event with $chunkIndex = null once the post-import
 * overlap audit passes. Non-streaming imports always fire with $chunkIndex null.
 */
final readonly class TemporalBackfillCommitted
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     * @param  array<int, Model>  $rowsInserted
     */
    public function __construct(
        public string $model,
        public Model $entity,
        public array $dimensions,
        public array $rowsInserted,
        public ?int $chunkIndex = null,
    ) {}

    public function insertedCount(): int
    {
        return count($this->rowsInserted);
    }
}
