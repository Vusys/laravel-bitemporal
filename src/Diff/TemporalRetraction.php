<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Diff;

use Illuminate\Database\Eloquent\Model;

/**
 * A valid window that was withdrawn (became an anti-row) between the two
 * knowledge dates. `to` is the anti-row believed on the later side; `from` is
 * the value row believed on the earlier side, or null when the window was both
 * created and retracted between the two dates (so nothing was believed for it at
 * the earlier knowledge date). Carrying both sides keeps a diff a complete
 * reconciliation of the two beliefs.
 */
final readonly class TemporalRetraction
{
    public function __construct(
        public ?Model $from,
        public Model $to,
    ) {}
}
