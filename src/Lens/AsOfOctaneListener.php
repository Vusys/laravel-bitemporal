<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

/**
 * Resets the lens stack at the start of each long-lived-worker request and
 * asserts it is empty at the end, so an asOf() frame cannot bleed between
 * requests served by the same Octane/FrankenPHP/Swoole worker.
 *
 * Registered against the Octane events by class-string name in the service
 * provider, so it carries no hard dependency on laravel/octane — the listeners
 * simply never fire when Octane is absent.
 *
 *  - RequestReceived  → reset  (clear anything a killed prior request leaked)
 *  - RequestTerminated → assert (surface a frame left open this request)
 *  - WorkerStarting   → reset  (fresh worker boot, notably FrankenPHP)
 */
final readonly class AsOfOctaneListener
{
    public function __construct(private LensStack $stack) {}

    public function handleRequestReceived(): void
    {
        $this->stack->reset();
    }

    public function handleRequestTerminated(): void
    {
        $this->stack->assertEmpty();
    }

    public function handleWorkerStarting(): void
    {
        $this->stack->reset();
    }
}
