<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use Illuminate\Support\ServiceProvider;

final class BitemporalServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bitemporal.php', 'bitemporal');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bitemporal.php' => $this->app->configPath('bitemporal.php'),
            ], 'bitemporal-config');
        }
    }
}
