<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins table(): the configured audit table name is honoured. The migration and
 * the subscriber both read the config, so a mutant that returns the literal
 * default instead writes to a non-existent table.
 */
final class AuditLogTableMutationTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('bitemporal.audit_log.enabled', true);
        $app['config']->set('bitemporal.audit_log.table', 'custom_audit_log');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_audit_rows_land_in_the_configured_table(): void
    {
        // Kills the table() Ternary swap (is_string ? 'temporal_audit_log' : $table).
        $this->assertTrue(Schema::hasTable('custom_audit_log'));
        $this->assertFalse(Schema::hasTable('temporal_audit_log'));

        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1500], '2026-01-01');

        $this->assertSame(1, DB::table('custom_audit_log')->count());
    }
}
