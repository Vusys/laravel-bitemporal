<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Smoke\Mutation;

use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Mockery;
use Vusys\Bitemporal\BitemporalServiceProvider;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Lens\LensStack;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Locking\ParentRowLocker;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Kills the surviving mutants in BitemporalServiceProvider by asserting each
 * registered artifact (locker binding, singleton, migrations, publish groups,
 * scheduled prune, queue listeners, commands) is wired exactly as written.
 *
 * Equivalent mutants (not targeted):
 *  - boot(): `if ($this->app->runningUnitTests())` IfNegation and the
 *    `PestExpectations::register()` MethodCallRemoval. Pest is not installed
 *    (function_exists('expect') === false), so register() is a no-op and never
 *    sets its $registered flag — there is no observable difference either way.
 *  - boot(): audit_log FalseValue (`config('bitemporal.audit_log.enabled',
 *    false)` -> `..., true)`). The package config always merges an explicit
 *    `audit_log.enabled => false`, so the default argument is dead code.
 */
final class BitemporalServiceProviderMutationTest extends TestCase
{
    /**
     * Run register() in a throwaway container with a chosen lock strategy, so
     * the strategy-dependent branch is exercised in isolation (the real
     * provider has already bound the locker by the time any test config hook
     * runs).
     */
    private function registerWithStrategy(string $strategy): Application
    {
        $previous = Container::getInstance();

        $container = new Application;
        $container->instance('config', new Repository([
            'bitemporal' => ['writes' => ['lock_strategy' => $strategy]],
        ]));

        Container::setInstance($container);

        try {
            new BitemporalServiceProvider($container)->register();
        } finally {
            Container::setInstance($previous);
        }

        return $container;
    }

    public function test_advisory_strategy_binds_the_advisory_locker(): void
    {
        $container = $this->registerWithStrategy('advisory');

        $this->assertInstanceOf(AdvisoryLocker::class, $container->make(WriteLocker::class));
    }

    public function test_parent_row_strategy_binds_the_parent_row_locker(): void
    {
        $container = $this->registerWithStrategy('parent_row');

        $this->assertInstanceOf(ParentRowLocker::class, $container->make(WriteLocker::class));
    }

    public function test_custom_strategy_leaves_the_locker_unbound(): void
    {
        $container = $this->registerWithStrategy('custom');

        $this->assertFalse($container->bound(WriteLocker::class));
    }

    public function test_lens_stack_is_registered_as_a_singleton(): void
    {
        $container = $this->registerWithStrategy('parent_row');

        $this->assertTrue($container->bound(LensStack::class));
        $this->assertSame($container->make(LensStack::class), $container->make(LensStack::class));
    }

    public function test_package_migrations_path_is_loaded(): void
    {
        $expected = realpath(__DIR__.'/../../../database/migrations');
        $this->assertNotFalse($expected);

        $app = $this->app;
        $this->assertNotNull($app);

        $paths = array_map(
            realpath(...),
            $app->make('migrator')->paths(),
        );

        $this->assertContains($expected, $paths);
    }

    public function test_migrations_are_published(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);

        $this->assertPublishes('bitemporal-migrations', __DIR__.'/../../../database/migrations', $app->databasePath('migrations'));
    }

    public function test_stubs_are_published(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);

        $this->assertPublishes('bitemporal-stubs', __DIR__.'/../../../stubs', $app->basePath('stubs/vendor/bitemporal'));
    }

    public function test_config_is_published(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);

        $this->assertPublishes('bitemporal-config', __DIR__.'/../../../config/bitemporal.php', $app->configPath('bitemporal.php'));
    }

    private function assertPublishes(string $group, string $expectedSource, string $expectedTarget): void
    {
        $paths = ServiceProvider::pathsToPublish(BitemporalServiceProvider::class, $group);

        $this->assertCount(1, $paths, "exactly one path should be published for {$group}");

        $source = array_key_first($paths);
        $this->assertIsString($source);
        $this->assertSame(realpath($expectedSource), realpath($source));
        $this->assertSame($expectedTarget, reset($paths));
    }

    public function test_idempotency_prune_command_is_scheduled_daily(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);

        $schedule = $app->make(Schedule::class);

        $matches = array_filter(
            $schedule->events(),
            static fn (object $event): bool => str_contains((string) $event->command, 'bitemporal:prune-idempotency-keys')
                && $event->expression === '0 0 * * *',
        );

        $this->assertCount(1, $matches, 'the prune-idempotency-keys command should be scheduled daily');
    }

    public function test_job_processing_listener_resets_the_lens_stack(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldIgnoreMissing();
        $this->assertInstanceOf(Job::class, $job);

        TemporalLens::asOf('2026-01-01', null, function () use ($job): void {
            $this->assertSame(1, TemporalLens::depth());

            event(new JobProcessing('sync', $job));

            $this->assertSame(0, TemporalLens::depth());
        });
    }

    public function test_job_processed_listener_asserts_the_lens_stack_is_empty(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldIgnoreMissing();
        $this->assertInstanceOf(Job::class, $job);

        $this->expectException(TemporalConfigurationException::class);

        try {
            TemporalLens::asOf('2026-01-01', null, function () use ($job): void {
                // A frame is still open here, so the JobProcessed listener's
                // assertEmpty() must throw.
                event(new JobProcessed('sync', $job));
            });
        } finally {
            TemporalLens::reset();
        }
    }

    public function test_job_processed_listener_is_silent_when_the_stack_is_empty(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldIgnoreMissing();
        $this->assertInstanceOf(Job::class, $job);

        event(new JobProcessed('sync', $job));

        $this->assertSame(0, TemporalLens::depth());
    }

    public function test_all_console_commands_are_registered(): void
    {
        $registered = array_keys(Artisan::all());

        foreach ([
            'bitemporal:audit-overlaps',
            'bitemporal:audit-table',
            'bitemporal:diff-timelines',
            'bitemporal:prune-idempotency-keys',
            'bitemporal:warm-guards',
            'make:bitemporal-factory',
            'make:bitemporal-migration',
            'make:bitemporal-model',
        ] as $command) {
            $this->assertContains($command, $registered);
        }
    }
}
