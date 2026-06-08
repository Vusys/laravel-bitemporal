<?php

declare(strict_types=1);

namespace Bitemporal\Exceptions;

final class TemporalInvalidPeriodException extends TemporalException
{
    public static function fromAfterTo(): self
    {
        return new self('valid_from must be before valid_to');
    }

    public static function zeroLength(): self
    {
        return new self('zero-length periods are not permitted; enable periods.allow_zero_length to allow');
    }

    public static function mergeDisjoint(): self
    {
        return new self('cannot merge periods that neither overlap nor are adjacent');
    }

    public static function antiRowCorrection(): self
    {
        return new self('applyCorrection does not accept anti-row segments; use applyRetraction()');
    }

    public static function emptyTimelineSpan(): self
    {
        return new self('cannot compute the span of an empty timeline');
    }

    public static function unparseableDate(): self
    {
        return new self('temporal row value cannot be interpreted as a date');
    }
}
