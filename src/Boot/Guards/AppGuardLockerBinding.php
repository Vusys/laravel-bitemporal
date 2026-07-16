<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Contracts\Container\Container;
use Vusys\Bitemporal\Boot\AppGuard;
use Vusys\Bitemporal\Locking\WriteLocker;

/**
 * Unless the strategy is 'custom' (the application binds its own), a WriteLocker
 * must be bound so the writer can resolve one. The 'custom' strategy is exempt:
 * the package deliberately leaves WriteLocker unbound for the app to provide.
 */
final readonly class AppGuardLockerBinding implements AppGuard
{
    public function __construct(private Container $app) {}

    public function check(): ?string
    {
        $strategy = config('bitemporal.writes.lock_strategy', 'parent_row');

        if ($strategy === 'custom') {
            return null;
        }

        if ($this->app->bound(WriteLocker::class)) {
            return null;
        }

        $given = is_string($strategy) ? $strategy : get_debug_type($strategy);

        return "writes.lock_strategy '{$given}' needs a bound ".WriteLocker::class.', but none is bound.';
    }
}
