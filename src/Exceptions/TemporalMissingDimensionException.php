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

    public static function incomplete(string $column): self
    {
        return new self("temporal write is missing the required dimension '{$column}'; supply it via forDimensions()");
    }

    public static function unknownDimension(string $column): self
    {
        return new self("'{$column}' is not a declared temporal dimension");
    }

    public static function conflict(string $column): self
    {
        return new self("dimension '{$column}' is set via forDimensions() and must not be given a different value in attributes");
    }
}
