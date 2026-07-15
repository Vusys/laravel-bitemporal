<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

/**
 * Resets the lens stack before each queued job runs and asserts it is empty
 * after, so a leaked asOf() frame cannot bleed between jobs on a long-lived
 * worker. Registered against the queue's JobProcessing / JobProcessed /
 * JobFailed events.
 */
final readonly class AsOfJobListener
{
    public function __construct(private LensStack $stack) {}

    public function handleProcessing(): void
    {
        $this->stack->reset();
    }

    public function handleProcessed(): void
    {
        $this->stack->assertEmpty();
    }

    /**
     * A failed job still ends a worker turn, so assert the stack came back clean
     * (asOf()'s finally pops on ordinary exceptions; a surviving frame means the
     * worker was interrupted mid-callback). The next job's handleProcessing()
     * resets regardless, so a leak never cascades.
     */
    public function handleFailed(): void
    {
        $this->stack->assertEmpty();
    }
}
