<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Events\TemporalAuditLogWriteFailed;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AuditLogWriteFailureTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('bitemporal.audit_log.enabled', true);
    }

    public function test_audit_failure_does_not_roll_back_the_write_and_emits_event(): void
    {
        // Remove the audit table so the subscriber's INSERT fails.
        Schema::dropIfExists('temporal_audit_log');

        $failures = 0;
        Event::listen(TemporalAuditLogWriteFailed::class, function () use (&$failures): void {
            $failures++;
        });

        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1500], '2026-01-01');

        // The temporal write committed despite the audit failure.
        $this->assertSame(1500, $product->prices()->validAt('2026-06-01')->sole()->amount);
        $this->assertGreaterThanOrEqual(1, ProductPrice::query()->count());
        $this->assertSame(1, $failures);
    }
}
