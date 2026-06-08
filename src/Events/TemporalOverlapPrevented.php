<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired when the post-write invariant check detects overlapping current-known
 * rows, immediately before the write is rolled back. Indicates a package bug
 * or a constraint disagreement, never normal operation.
 */
final readonly class TemporalOverlapPrevented
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     */
    public function __construct(
        public string $model,
        public Model $entity,
        public array $dimensions,
    ) {}
}
