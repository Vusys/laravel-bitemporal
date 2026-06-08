<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Spell;

/**
 * Fired inside the write transaction, before any rows change, so an atomic
 * audit log can record the intent alongside the data.
 */
abstract class TemporalWriteStarting
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     */
    public function __construct(
        public readonly string $model,
        public readonly Model $entity,
        public readonly array $dimensions,
        public readonly Spell $window,
    ) {}
}
