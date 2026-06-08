<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired inside the transaction before backfilled historical rows are inserted.
 */
final readonly class TemporalBackfillStarting
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     */
    public function __construct(
        public string $model,
        public Model $entity,
        public array $dimensions,
        public int $rowCount,
    ) {}
}
