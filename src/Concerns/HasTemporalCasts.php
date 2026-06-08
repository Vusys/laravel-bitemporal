<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

/**
 * Auto-applies immutable_datetime casts to the period columns and a boolean
 * cast to the retraction column. Disable per-model with
 * `protected bool $autoApplyTemporalCasts = false;`.
 */
trait HasTemporalCasts
{
    public function initializeHasTemporalCasts(): void
    {
        if (property_exists($this, 'autoApplyTemporalCasts') && ! $this->autoApplyTemporalCasts) {
            return;
        }

        $casts = [
            $this->validFromColumn() => 'immutable_datetime',
            $this->validToColumn() => 'immutable_datetime',
            $this->isRetractionColumn() => 'boolean',
        ];

        if ($this->tracksRecordedTime()) {
            $casts[$this->recordedFromColumn()] = 'immutable_datetime';
            $casts[$this->recordedToColumn()] = 'immutable_datetime';
        }

        $this->mergeCasts($casts);
    }
}
