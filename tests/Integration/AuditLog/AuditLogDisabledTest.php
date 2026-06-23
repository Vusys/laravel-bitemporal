<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog;

use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AuditLogDisabledTest extends IntegrationTestCase
{
    public function test_no_audit_rows_when_disabled_by_default(): void
    {
        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1500], '2026-01-01');

        $this->assertSame(0, DB::table('temporal_audit_log')->count());
    }
}
