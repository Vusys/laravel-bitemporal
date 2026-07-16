<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

final class TemporalWriteConflictException extends TemporalException
{
    public static function entityMissing(string $class, mixed $key): self
    {
        $id = is_scalar($key) ? (string) $key : get_debug_type($key);

        return new self("temporal entity {$class}#{$id} no longer exists");
    }

    public static function clockRegressed(string $tuple): self
    {
        return new self("the host clock appears to have regressed for {$tuple}; refusing to write");
    }

    public static function lockTimeout(string $entity, int $timeoutMs): self
    {
        return new self("timed out after {$timeoutMs}ms acquiring the temporal write lock for {$entity}; another write holds it");
    }

    public static function deadlock(string $entity): self
    {
        return new self("deadlock detected acquiring the temporal write lock for {$entity}; the deadlock-retry budget is exhausted");
    }

    public static function connectionChanged(string $entity): self
    {
        return new self("the database connection was swapped mid-write for {$entity}; the advisory lock can no longer be trusted");
    }

    public static function expectationFailed(string $column): self
    {
        return new self("optimistic check failed: the current value of '{$column}' is not what was expected; another write got there first");
    }

    public static function idempotencyKeyReused(string $key): self
    {
        return new self("idempotency key '{$key}' was already used with different parameters");
    }
}
