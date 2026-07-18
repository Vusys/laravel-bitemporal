<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Idempotency;

use Carbon\CarbonInterval;
use Throwable;

/**
 * Resolves the configured idempotency retention window to a CarbonInterval.
 *
 * bitemporal.writes.idempotency_window is free-form operator config. Passing an
 * unvalidated string straight to Carbon::sub() means a misconfigured-but-non-
 * empty value ('1 fortnight', 'garbage') throws — turning every idempotent write
 * into a hard failure in IdempotencyStore::find() and aborting the prune command
 * so keys accumulate unbounded. We parse the window once and fall back to the
 * documented default rather than let garbage reach the query layer.
 */
final class IdempotencyWindow
{
    /**
     * Mirrors config/bitemporal.php writes.idempotency_window.
     */
    public const string DEFAULT = '7 days';

    public static function resolve(): CarbonInterval
    {
        return self::parse(config('bitemporal.writes.idempotency_window', self::DEFAULT));
    }

    public static function parse(mixed $window): CarbonInterval
    {
        if (is_string($window) && $window !== '') {
            try {
                return CarbonInterval::make($window) ?? self::default();
            } catch (Throwable) {
                // A non-empty but unparseable window falls back to the default
                // rather than propagating an exception into the write/prune path.
            }
        }

        return self::default();
    }

    private static function default(): CarbonInterval
    {
        return CarbonInterval::days(7);
    }
}
