<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot;

use Illuminate\Database\Eloquent\Model;

/**
 * An advisory boot-time check. Returns a message when the model would benefit
 * from a change, or null when nothing to flag. Unlike a BootGuard, a raised
 * lint never blocks boot — it is logged and dispatched as a
 * TemporalBootLintRaised event.
 */
interface BootLint
{
    public function check(Model $model): ?string;
}
