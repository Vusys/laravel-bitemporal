<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

enum SpellBounds: string
{
    case ClosedOpen = '[)';
    case OpenClosed = '(]';
    case Closed = '[]';
    case Open = '()';

    public function includesLower(): bool
    {
        return $this === self::ClosedOpen || $this === self::Closed;
    }

    public function includesUpper(): bool
    {
        return $this === self::OpenClosed || $this === self::Closed;
    }
}
