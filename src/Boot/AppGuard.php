<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot;

/**
 * A process-wide (application) boot guard. Unlike a BootGuard, which validates
 * one model, an AppGuard validates the application wiring once per boot and
 * takes its dependencies through the constructor. Returns an error message when
 * the application is misconfigured, or null when it passes.
 */
interface AppGuard
{
    public function check(): ?string;
}
