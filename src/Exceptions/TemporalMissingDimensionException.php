<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

final class TemporalMissingDimensionException extends TemporalException
{
    public static function pendingWhere(): self
    {
        return new self('temporal writes cannot run with pending where() clauses; use forDimensions() or attributes');
    }

    public static function forbiddenAttribute(string $column): self
    {
        return new self("attribute '{$column}' is writer-managed and must not appear in the attributes payload");
    }
}
