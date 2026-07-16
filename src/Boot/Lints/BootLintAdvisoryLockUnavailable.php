<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Lints;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use Vusys\Bitemporal\Boot\BootLint;

/**
 * The advisory lock strategy is configured, but the model's connection runs on
 * an engine without advisory locks (SQLite). Writes silently fall back to the
 * parent_row strategy — correct, but worth surfacing so the operator knows the
 * chosen strategy is not actually in effect on this connection.
 */
final class BootLintAdvisoryLockUnavailable implements BootLint
{
    public function check(Model $model): ?string
    {
        if (config('bitemporal.writes.lock_strategy', 'parent_row') !== 'advisory') {
            return null;
        }

        try {
            $driver = $model->getConnection()->getDriverName();
        } catch (Throwable) {
            return null;
        }

        if ($driver !== 'sqlite') {
            return null;
        }

        return "lock_strategy is 'advisory' but the {$driver} connection has no advisory locks; "
            .'temporal writes fall back to the parent_row strategy on this connection.';
    }
}
