<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-model boot guard. Returns an error message when the model is
 * misconfigured, or null when it passes. Guards run once per model class at
 * boot and any failures are collected into a single TemporalConfigurationException.
 */
interface BootGuard
{
    public function check(Model $model): ?string;
}
