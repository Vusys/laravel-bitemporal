<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired inside the transaction before rows are permanently deleted by
 * forceDeleteHistory().
 */
final readonly class TemporalHardDeleteStarting
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     * @param  array<int, mixed>  $ids
     */
    public function __construct(
        public string $model,
        public Model $entity,
        public array $dimensions,
        public array $ids,
    ) {}
}
