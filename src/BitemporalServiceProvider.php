<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Vusys\Bitemporal\AuditLog\TemporalAuditLogSubscriber;
use Vusys\Bitemporal\Console\Commands\AuditOverlapsCommand;
use Vusys\Bitemporal\Console\Commands\AuditTableCommand;
use Vusys\Bitemporal\Console\Commands\DiffTimelinesCommand;
use Vusys\Bitemporal\Console\Commands\MakeBitemporalFactoryCommand;
use Vusys\Bitemporal\Console\Commands\MakeBitemporalMigrationCommand;
use Vusys\Bitemporal\Console\Commands\MakeBitemporalModelCommand;
use Vusys\Bitemporal\Console\Commands\PruneIdempotencyKeysCommand;
use Vusys\Bitemporal\Console\Commands\WarmGuardsCommand;
use Vusys\Bitemporal\Database\TemporalBlueprintMacros;
use Vusys\Bitemporal\Lens\AsOfJobListener;
use Vusys\Bitemporal\Lens\AsOfOctaneListener;
use Vusys\Bitemporal\Lens\LensStack;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Locking\ParentRowLocker;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Testing\PestExpectations;

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

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningUnitTests()) {
            PestExpectations::register();
        }

        if (config('bitemporal.writes.idempotency_auto_prune', true) === true) {
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command(PruneIdempotencyKeysCommand::class)->daily();
            });
        }

        $events = $this->app->make(Dispatcher::class);
        $events->listen(JobProcessing::class, [AsOfJobListener::class, 'handleProcessing']);
        $events->listen(JobProcessed::class, [AsOfJobListener::class, 'handleProcessed']);
        $events->listen(JobFailed::class, [AsOfJobListener::class, 'handleFailed']);

        // Octane/FrankenPHP/Swoole request lifecycle. Listened by class-string
        // name so there is no hard dependency on laravel/octane: the listeners
        // only ever fire when Octane is installed and dispatches these events.
        $events->listen('Laravel\Octane\Events\RequestReceived', [AsOfOctaneListener::class, 'handleRequestReceived']);
        $events->listen('Laravel\Octane\Events\RequestTerminated', [AsOfOctaneListener::class, 'handleRequestTerminated']);
        $events->listen('Laravel\Octane\Events\WorkerStarting', [AsOfOctaneListener::class, 'handleWorkerStarting']);

        if (config('bitemporal.audit_log.enabled', false) === true) {
            $events->subscribe(TemporalAuditLogSubscriber::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditOverlapsCommand::class,
                AuditTableCommand::class,
                DiffTimelinesCommand::class,
                MakeBitemporalFactoryCommand::class,
                MakeBitemporalMigrationCommand::class,
                MakeBitemporalModelCommand::class,
                PruneIdempotencyKeysCommand::class,
                WarmGuardsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'bitemporal-migrations');

            $this->publishes([
                __DIR__.'/../stubs' => $this->app->basePath('stubs/vendor/bitemporal'),
            ], 'bitemporal-stubs');

            $this->publishes([
                __DIR__.'/../config/bitemporal.php' => $this->app->configPath('bitemporal.php'),
            ], 'bitemporal-config');
        }
    }
}
