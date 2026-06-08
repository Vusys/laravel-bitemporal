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
}
