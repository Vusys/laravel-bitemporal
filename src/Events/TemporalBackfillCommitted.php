<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired after backfilled historical rows are committed. Doubles as the return
 * value of the backfill API.
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
    ) {}

    public function insertedCount(): int
    {
        return count($this->rowsInserted);
    }
}
