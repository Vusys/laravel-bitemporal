<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog\Mutation2;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins TemporalAuditLogSubscriber::connection(): the configured connection name
 * must actually be used. Kills the Ternary swap (is_string ? null : $connection)
 * which would ignore the configured name and silently fall back to the default
 * connection.
 */
final class AuditLogConnectionConfigTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        // A second, isolated in-memory connection. The audit-log migration honours
        // bitemporal.audit_log.connection, so temporal_audit_log only exists here,
        // never on the default connection.
        $app['config']->set('database.connections.audit', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('bitemporal.audit_log.enabled', true);
        $app['config']->set('bitemporal.audit_log.connection', 'audit');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_configured_connection_name_is_honoured(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1000], validFrom: '2026-01-01');

        // Real: connection() returns 'audit', so the row lands on the audit
        // connection. Mutant (Ternary swap -> null) routes to the default
        // connection, where the table does not exist; the insert is caught and
        // nothing is recorded.
        $this->assertSame(1, DB::connection('audit')->table('temporal_audit_log')->count());
    }
}
