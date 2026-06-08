<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Fired after forceDeleteHistory() permanently removes rows. Doubles as the
 * return value, carrying the deleted row ids.
 */
final readonly class TemporalHardDeleteCommitted
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

    public function deletedCount(): int
    {
        return count($this->ids);
    }
}
