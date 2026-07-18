<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

/**
 * Auto-applies immutable_datetime casts to the period columns and a boolean
 * cast to the retraction column. Disable per-model with
 * `protected bool $autoApplyTemporalCasts = false;`.
 *
 * Also defaults the model's $dateFormat to microsecond precision: the casts
 * preserve sub-second precision in PHP, but Eloquent serialises datetimes to
 * storage with $dateFormat, and the connection default (Y-m-d H:i:s) would
 * truncate the writer's microsecond instants on save.
 */
trait HasTemporalCasts
{
    /**
     * The storage date format temporal spells require. Microsecond precision so
     * two writes in the same second produce distinct, ordered recorded instants.
     */
    public const string TEMPORAL_DATE_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * Serialise datetimes at microsecond precision unless the model declares its
     * own $dateFormat — an explicit format is treated as a deliberate override
     * (and flagged by BootLintTruncatedDateFormat if it drops sub-second parts).
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat ?: static::TEMPORAL_DATE_FORMAT;
    }

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
