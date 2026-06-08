<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

final class TemporalOverlapException extends TemporalException
{
    public static function betweenSegments(int $first, int $second): self
    {
        return new self("timeline segments at positions {$first} and {$second} overlap in valid time");
    }
}
