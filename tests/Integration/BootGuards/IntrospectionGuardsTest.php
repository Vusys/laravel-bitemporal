<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\BootGuards;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vusys\Bitemporal\Boot\BootLints;
use Vusys\Bitemporal\Boot\Guards\BootGuardColumnsExist;
use Vusys\Bitemporal\Boot\Guards\BootGuardConnection;
use Vusys\Bitemporal\Boot\Lints\BootLintAdvisoryLockUnavailable;
use Vusys\Bitemporal\Events\TemporalBootLintRaised;
use Vusys\Bitemporal\Tests\Fixtures\Models\CrossConnectionPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\MissingTemporalColumnPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class IntrospectionGuardsTest extends IntegrationTestCase
{
    public function test_columns_exist_guard_passes_for_a_well_configured_model(): void
    {
        $this->assertNull((new BootGuardColumnsExist)->check(new ProductPrice));
    }

    public function test_columns_exist_guard_flags_a_missing_overridden_column(): void
    {
        config(['bitemporal.guards.enabled' => false]);
        $model = new MissingTemporalColumnPrice;

        $message = (new BootGuardColumnsExist)->check($model);

        $this->assertNotNull($message);
        $this->assertStringContainsString('ghost_valid_from', $message);
    }

    public function test_connection_guard_passes_when_both_sides_share_a_connection(): void
    {
        $this->assertNull((new BootGuardConnection)->check(new ProductPrice));
    }

    public function test_connection_guard_flags_a_cross_connection_relation(): void
    {
        // A real secondary connection so the relation resolves; Product stays on
        // the default connection, so the CrossConnectionPrice disagrees with it.
        config(['database.connections.secondary' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]]);
        config(['bitemporal.guards.enabled' => false]);

        $model = new CrossConnectionPrice;

        $message = (new BootGuardConnection)->check($model);

        $this->assertNotNull($message);
        $this->assertStringContainsString('secondary', $message);
    }

    public function test_advisory_lint_is_silent_under_the_default_strategy(): void
    {
        config(['bitemporal.writes.lock_strategy' => 'parent_row']);

        $this->assertNull((new BootLintAdvisoryLockUnavailable)->check(new ProductPrice));
    }

    public function test_advisory_lint_fires_for_advisory_on_sqlite(): void
    {
        if ($this->driver() !== 'sqlite') {
            $this->markTestSkipped('The advisory-unavailable lint targets the SQLite fallback.');
        }

        config(['bitemporal.writes.lock_strategy' => 'advisory']);

        $message = (new BootLintAdvisoryLockUnavailable)->check(new ProductPrice);

        $this->assertNotNull($message);
        $this->assertStringContainsString('advisory', $message);
    }

    public function test_advisory_lint_dispatches_through_the_lint_runner(): void
    {
        if ($this->driver() !== 'sqlite') {
            $this->markTestSkipped('The advisory-unavailable lint targets the SQLite fallback.');
        }

        Event::fake([TemporalBootLintRaised::class]);
        config(['bitemporal.writes.lock_strategy' => 'advisory']);

        BootLints::default()->runAgainst(new ProductPrice);

        Event::assertDispatched(
            TemporalBootLintRaised::class,
            fn (TemporalBootLintRaised $event): bool => $event->lint === BootLintAdvisoryLockUnavailable::class,
        );
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
