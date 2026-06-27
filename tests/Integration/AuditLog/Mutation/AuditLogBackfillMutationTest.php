<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins handleBackfill: that it is invoked at all (public listener) and that the
 * inserted_ids payload is populated with scalar ids.
 */
final class AuditLogBackfillMutationTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('bitemporal.audit_log.enabled', true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_backfill_writes_an_audit_row_with_inserted_ids(): void
    {
        // Kills handleBackfill PublicVisibility, MethodCallRemoval,
        // ArrayItemRemoval, ArrayItem ('>'), and UnwrapArrayMap.
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->backfill()->timeline([
            [
                'attributes' => ['amount' => 1000],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
            ],
            [
                'attributes' => ['amount' => 1200],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-03-01', 'recorded_to' => null,
            ],
        ]);

        $rows = DB::table('temporal_audit_log')->where('event_class', 'TemporalBackfillCommitted')->get();
        $this->assertCount(1, $rows);

        $payload = json_decode((string) $rows->first()->payload, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('inserted_ids', $payload);
        $this->assertCount(2, $payload['inserted_ids']);

        foreach ($payload['inserted_ids'] as $id) {
            $this->assertIsInt($id);
        }
    }
}
