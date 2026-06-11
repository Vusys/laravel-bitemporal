<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

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

    public static function noAssignmentToCorrect(string $tuple): self
    {
        return new self("correctAssignment requires an existing assignment to correct; none found for tuple {$tuple}. Use attachFor to create the assignment.");
    }

    public static function noAssignmentToDetach(string $tuple): self
    {
        return new self("detachAt requires an open-ended current assignment to end; none found for tuple {$tuple}.");
    }

    public function wasNoneFound(): bool
    {
        return $this->noneFound;
    }
}
