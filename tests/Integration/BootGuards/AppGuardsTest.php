<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\BootGuards;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Vusys\Bitemporal\Boot\AppGuards;
use Vusys\Bitemporal\Boot\Guards\AppGuardAsOfLifecycle;
use Vusys\Bitemporal\Boot\Guards\AppGuardLockerBinding;
use Vusys\Bitemporal\Boot\Guards\AppGuardLockStrategy;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Lens\AsOfJobListener;
use Vusys\Bitemporal\Locking\ParentRowLocker;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Tests\TestCase;

final class AppGuardsTest extends TestCase
{
    public function test_the_default_app_guards_pass_for_the_booted_provider(): void
    {
        // The service provider has already registered the locker binding and the
        // lifecycle listeners, so the full runner passes.
        AppGuards::default(app())->run();

        $this->expectNotToPerformAssertions();
    }

    public function test_lock_strategy_guard_accepts_known_strategies(): void
    {
        foreach (['parent_row', 'advisory', 'custom'] as $strategy) {
            config(['bitemporal.writes.lock_strategy' => $strategy]);
            $this->assertNull((new AppGuardLockStrategy)->check(), $strategy);
        }
    }

    public function test_lock_strategy_guard_rejects_an_unknown_strategy(): void
    {
        config(['bitemporal.writes.lock_strategy' => 'advisory_lock']);

        $message = (new AppGuardLockStrategy)->check();

        $this->assertNotNull($message);
        $this->assertStringContainsString('advisory_lock', $message);
    }

    public function test_locker_binding_guard_flags_a_missing_binding(): void
    {
        config(['bitemporal.writes.lock_strategy' => 'parent_row']);

        $empty = new Container;
        $message = new AppGuardLockerBinding($empty)->check();

        $this->assertNotNull($message);
        $this->assertStringContainsString('WriteLocker', $message);
    }

    public function test_locker_binding_guard_exempts_the_custom_strategy(): void
    {
        config(['bitemporal.writes.lock_strategy' => 'custom']);

        $empty = new Container;
        $this->assertNull(new AppGuardLockerBinding($empty)->check());
    }

    public function test_locker_binding_guard_passes_when_bound(): void
    {
        config(['bitemporal.writes.lock_strategy' => 'parent_row']);

        $container = new Container;
        $container->bind(WriteLocker::class, ParentRowLocker::class);

        $this->assertNull(new AppGuardLockerBinding($container)->check());
    }

    public function test_as_of_lifecycle_guard_flags_a_missing_listener(): void
    {
        $message = new AppGuardAsOfLifecycle(new Dispatcher)->check();

        $this->assertNotNull($message);
        $this->assertStringContainsString('JobProcessing', $message);
    }

    public function test_as_of_lifecycle_guard_passes_when_the_listener_is_registered(): void
    {
        $events = new Dispatcher;
        $events->listen(JobProcessing::class, [AsOfJobListener::class, 'handleProcessing']);

        $this->assertNull(new AppGuardAsOfLifecycle($events)->check());
    }

    public function test_the_runner_collects_failures_into_one_exception(): void
    {
        config(['bitemporal.writes.lock_strategy' => 'not_a_strategy']);

        $guards = new AppGuards([
            new AppGuardLockStrategy,
            new AppGuardAsOfLifecycle(new Dispatcher),
        ]);

        try {
            $guards->run();
            $this->fail('the runner should have thrown');
        } catch (TemporalConfigurationException $exception) {
            $this->assertStringContainsString('AppGuardLockStrategy', $exception->getMessage());
            $this->assertStringContainsString('AppGuardAsOfLifecycle', $exception->getMessage());
        }
    }
}
