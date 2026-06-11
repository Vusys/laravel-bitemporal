<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog;

use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AuditLogSubscriberTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('bitemporal.audit_log.enabled', true);
    }

    public function test_a_correction_writes_an_audit_row(): void
    {
        $product = $this->makeProduct();

        $product->prices()->correct(['amount' => 1500], '2026-01-01');

        $rows = DB::table('temporal_audit_log')->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertNotNull($row);
        $this->assertSame('TemporalCorrectionCommitted', $row->event_class);
        $this->assertEquals($product->getKey(), $row->entity_id);
    }

    public function test_hard_delete_is_recorded(): void
    {
        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1500], '2026-01-01');
        $product->prices()->forceDeleteHistory();

        $this->assertSame(1, DB::table('temporal_audit_log')->where('event_class', 'TemporalHardDeleteCommitted')->count());
    }
}
