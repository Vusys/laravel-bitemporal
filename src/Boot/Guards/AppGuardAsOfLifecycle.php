<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Vusys\Bitemporal\Boot\AppGuard;

/**
 * The queue lens-lifecycle listeners must be registered, otherwise a queued job
 * could leak an asOf() frame into the next job on a long-lived worker. The
 * package registers them in its service provider; this guard catches a boot
 * order or override that dropped them.
 */
final readonly class AppGuardAsOfLifecycle implements AppGuard
{
    public function __construct(private Dispatcher $events) {}

    public function check(): ?string
    {
        if ($this->events->hasListeners(JobProcessing::class)) {
            return null;
        }

        return 'the JobProcessing lens-reset listener is not registered; queued jobs may leak asOf() frames across a worker.';
    }
}
