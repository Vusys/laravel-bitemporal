<?php

declare(strict_types=1);

namespace Bitemporal\Exceptions;

final class TemporalCardinalityException extends TemporalException
{
    private bool $noneFound = false;

    public static function expectedOneFoundMany(string $model, int $count): self
    {
        return new self("expected a single {$model} row but found {$count}");
    }

    public static function expectedOneFoundNone(string $model): self
    {
        $exception = new self("expected a single {$model} row but found none");
        $exception->noneFound = true;

        return $exception;
    }

    public function wasNoneFound(): bool
    {
        return $this->noneFound;
    }
}
