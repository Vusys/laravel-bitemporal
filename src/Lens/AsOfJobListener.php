<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;

/**
 * Brackets each queued job in a lens-stack "job turn": it snapshots the frame
 * depth before the job runs and restores it after, so a leaked asOf() frame
 * cannot bleed between jobs on a long-lived worker. Unlike a blind reset(), the
 * snapshot preserves an outer frame that is legitimately open when a job is
 * dispatched synchronously inside an asOf() callback (dispatchSync() or any
 * sync-queue work), which would otherwise be silently discarded. Registered
 * against the queue's JobProcessing / JobProcessed / JobFailed events.
 */
final readonly class AsOfJobListener
{
    public function __construct(private LensStack $stack) {}

    public function handleProcessing(): void
    {
        $this->stack->beginJobTurn();
    }

    public function handleProcessed(): void
    {
        $this->assertClean();
    }

    /**
     * A failed job still ends a worker turn, so close its turn and assert no
     * frame survived (asOf()'s finally pops on ordinary exceptions; a surviving
     * frame means the worker was interrupted mid-callback). endJobTurn() trims
     * any leak regardless, so a leak never cascades into the next job.
     */
    public function handleFailed(): void
    {
        $this->assertClean();
    }

    private function assertClean(): void
    {
        if ($this->stack->endJobTurn()) {
            throw new TemporalConfigurationException(
                'a TemporalLens::asOf() frame was left open when a queued job finished; '.
                'asOf() pops its frame automatically unless the worker was killed mid-callback',
            );
        }
    }
}
