<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fired when a forward-effective change (changeEffectiveFrom) is capped by an
 * existing future-dated row instead of running open-ended.
 */
final readonly class TemporalFutureRowEncountered
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $dimensions
     */
    public function __construct(
        public string $model,
        public Model $entity,
        public array $dimensions,
        public CarbonImmutable $boundary,
    ) {}
}
