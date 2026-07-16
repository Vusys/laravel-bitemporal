<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Vusys\Bitemporal\Boot\AppGuard;

/**
 * writes.lock_strategy must name a strategy the package understands. A typo
 * (e.g. 'advisory_lock') would otherwise silently fall through to the default
 * parent_row binding.
 */
final class AppGuardLockStrategy implements AppGuard
{
    private const array KNOWN = ['parent_row', 'advisory', 'custom'];

    public function check(): ?string
    {
        $strategy = config('bitemporal.writes.lock_strategy', 'parent_row');

        if (is_string($strategy) && in_array($strategy, self::KNOWN, true)) {
            return null;
        }

        $given = is_string($strategy) ? $strategy : get_debug_type($strategy);

        return "unknown writes.lock_strategy '{$given}'; expected one of ".implode(', ', self::KNOWN);
    }
}
