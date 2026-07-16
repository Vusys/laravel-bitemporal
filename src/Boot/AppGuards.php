<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use ReflectionClass;
use Vusys\Bitemporal\Boot\Guards\AppGuardAsOfLifecycle;
use Vusys\Bitemporal\Boot\Guards\AppGuardLockerBinding;
use Vusys\Bitemporal\Boot\Guards\AppGuardLockStrategy;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;

/**
 * Runs the process-wide application guards once per boot and collects every
 * failure into one TemporalConfigurationException. Like the per-model
 * BootGuards, these are strict — a failure blocks boot — but they validate the
 * application wiring (lock strategy, locker binding, lifecycle listeners)
 * rather than an individual model.
 */
final readonly class AppGuards
{
    /**
     * @param  array<int, AppGuard>  $guards
     */
    public function __construct(private array $guards) {}

    public static function default(Container $app): self
    {
        return new self([
            new AppGuardLockStrategy,
            new AppGuardLockerBinding($app),
            new AppGuardAsOfLifecycle($app->make(Dispatcher::class)),
        ]);
    }

    public function run(): void
    {
        $failures = [];

        foreach ($this->guards as $guard) {
            $message = $guard->check();

            if ($message !== null) {
                $failures[(new ReflectionClass($guard))->getShortName()] = $message;
            }
        }

        if ($failures !== []) {
            throw TemporalConfigurationException::appGuardFailures($failures);
        }
    }
}
