<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Vusys\Bitemporal\Facades\TemporalLens;

/**
 * Records the ambient lens depth at the moment it runs. Dispatched synchronously
 * inside an asOf() callback to prove the caller's open frame survives the job
 * turn (issue #72).
 */
final class CapturesLensStateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?int $depthDuringHandle = null;

    public function handle(): void
    {
        self::$depthDuringHandle = TemporalLens::depth();
    }
}
