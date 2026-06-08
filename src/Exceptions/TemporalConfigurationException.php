<?php

declare(strict_types=1);

namespace Bitemporal\Exceptions;

final class TemporalConfigurationException extends TemporalException
{
    public static function missingTemporalEntity(string $model): self
    {
        return new self("temporal model {$model} must define a temporalEntity() relation");
    }

    public static function unexpectedEntityArgument(string $given): self
    {
        return new self("temporal entity scope expects a Model, MorphContext, Collection, or array; got {$given}");
    }
}
