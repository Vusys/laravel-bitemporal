<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Throwable;

/**
 * Fired when the audit-log subscriber fails to write its row. The temporal
 * write itself has already committed — this signals only that the denormalised
 * audit row is missing, for downstream observability.
 */
final readonly class TemporalAuditLogWriteFailed
{
    public function __construct(
        public object $event,
        public Throwable $exception,
    ) {}
}
