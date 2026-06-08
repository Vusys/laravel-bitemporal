<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Vusys\Bitemporal\Database\TemporalBlueprintMacros;
use Vusys\Bitemporal\Lens\AsOfJobListener;
use Vusys\Bitemporal\Lens\LensStack;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Locking\ParentRowLocker;
use Vusys\Bitemporal\Locking\WriteLocker;

final class BitemporalServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bitemporal.php', 'bitemporal');

        // 'custom' leaves WriteLocker unbound for the application to provide.
        $strategy = config('bitemporal.writes.lock_strategy', 'parent_row');

        if ($strategy === 'advisory') {
            $this->app->bindIf(WriteLocker::class, AdvisoryLocker::class);
        } elseif ($strategy !== 'custom') {
            $this->app->bindIf(WriteLocker::class, ParentRowLocker::class);
        }

        $this->app->singleton(LensStack::class);
    }

    public function boot(): void
    {
        TemporalBlueprintMacros::register();

        $events = $this->app->make(Dispatcher::class);
        $events->listen(JobProcessing::class, [AsOfJobListener::class, 'handleProcessing']);
        $events->listen(JobProcessed::class, [AsOfJobListener::class, 'handleProcessed']);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bitemporal.php' => $this->app->configPath('bitemporal.php'),
            ], 'bitemporal-config');
        }
    }
}
