<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Exceptions;

/**
 * Assertion-style invariant violation during algorithm execution — "this should
 * never happen" in correctly-configured production. If it fires, it indicates a
 * package bug or a host-environment fault (e.g. a regressed clock), not a caller
 * error.
 */
final class TemporalDomainException extends TemporalException
{
    public static function invariant(string $assertion, string $algorithm): self
    {
        return new self("Internal: {$assertion} failed at {$algorithm}. Report this with reproduction.");
    }

    public static function clockSkew(string $entity, string $persisted, string $now, int $driftMs, int $toleranceMs): self
    {
        return new self(
            "Clock skew at {$entity}: max(recorded_from) = {$persisted}, now() = {$now}, drift {$driftMs}ms exceeds writes.clock_skew_tolerance_ms ({$toleranceMs}ms).",
        );
    }
}
