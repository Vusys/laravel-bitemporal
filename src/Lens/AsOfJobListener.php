<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

/**
 * Resets the lens stack before each queued job runs and asserts it is empty
 * after, so a leaked asOf() frame cannot bleed between jobs on a long-lived
 * worker. Registered against the queue's JobProcessing / JobProcessed events.
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
}
