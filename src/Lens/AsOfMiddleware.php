<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

use Closure;

/**
 * Optional HTTP middleware that asserts no TemporalLens::asOf() frame leaked
 * past the end of the request. Register it in the app's middleware stack to get
 * the guarantee; AppGuardAsOfLifecycle (Phase 10) checks it is registered.
 */
final readonly class AsOfMiddleware
{
    public function __construct(private LensStack $stack) {}

    public function handle(mixed $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(): void
    {
        $this->stack->assertEmpty();
    }
}
