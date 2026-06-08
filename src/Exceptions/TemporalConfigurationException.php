<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

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

    /**
     * @param  array<string, string>  $failures  guard short-name => message
     */
    public static function guardFailures(string $model, array $failures): self
    {
        $lines = [];
        foreach ($failures as $guard => $message) {
            $lines[] = "  [{$guard}] {$message}";
        }

        return new self("temporal model {$model} failed boot validation:\n".implode("\n", $lines));
    }
}
