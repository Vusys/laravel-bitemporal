<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

final class TemporalConfigurationException extends TemporalException
{
    public static function missingTemporalEntity(string $model): self
    {
        return new self("temporal model {$model} must declare a \$temporalEntity model class or a temporalEntityRelation() method");
    }

    public static function unexpectedEntityArgument(string $given): self
    {
        return new self("temporal entity scope expects a Model, MorphContext, Collection, or array; got {$given}");
    }

    public static function disabledPivotMethod(string $method, string $useInstead): self
    {
        return new self("{$method}() is disabled on a temporal pivot relation because it would destroy history; use {$useInstead} instead");
    }

    public static function nativeRangesUnsupported(): self
    {
        return new self(
            'bitemporal.database.prefer_native_ranges is enabled, but native PostgreSQL tstzrange columns are not yet supported by the read path: '
            .'the temporal predicates (validAt, knownAt, currentKnowledge, …) query the scalar valid_from/valid_to/recorded_from/recorded_to columns, '
            .'which a range-backed table does not have. Keep prefer_native_ranges = false and use the default composite-index layout until range reads land.'
        );
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

    /**
     * @param  array<string, string>  $failures  guard short-name => message
     */
    public static function appGuardFailures(array $failures): self
    {
        $lines = [];
        foreach ($failures as $guard => $message) {
            $lines[] = "  [{$guard}] {$message}";
        }

        return new self("bitemporal application configuration is invalid:\n".implode("\n", $lines));
    }
}
