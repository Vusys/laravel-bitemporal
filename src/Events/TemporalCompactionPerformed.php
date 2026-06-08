<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired when adjacent equivalent segments were merged during a write.
 */
final readonly class TemporalCompactionPerformed
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     */
    public function __construct(
        public string $model,
        public Model $entity,
        public array $dimensions,
        public int $segmentsBefore,
        public int $segmentsAfter,
    ) {}
}
