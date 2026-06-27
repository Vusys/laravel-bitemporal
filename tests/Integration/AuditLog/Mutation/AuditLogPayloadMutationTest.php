<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins the audit payload shape for write and hard-delete events.
 */
final class AuditLogPayloadMutationTest extends IntegrationTestCase
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

    public function test_change_writes_a_change_audit_row_with_id_payload(): void
    {
        // Kills subscribe() ArrayItemRemoval (TemporalChangeCommitted mapping),
        // and the handleWrite closed_ids/inserted_ids/compacted ArrayItem,
        // ArrayItemRemoval and UnwrapArrayMap mutants.
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $result = $product->prices()->changeEffectiveFrom(['amount' => 1200], '2026-06-01');

        $row = DB::table('temporal_audit_log')->where('event_class', 'TemporalChangeCommitted')->first();
        $this->assertNotNull($row);

        $payload = json_decode((string) $row->payload, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('closed_ids', $payload);
        $this->assertArrayHasKey('inserted_ids', $payload);
        $this->assertArrayHasKey('compacted', $payload);

        $expectedClosed = array_map($this->intKey(...), $result->rowsClosed);
        $expectedInserted = array_map($this->intKey(...), $result->rowsInserted);

        $this->assertSame($expectedClosed, $payload['closed_ids']);
        $this->assertSame($expectedInserted, $payload['inserted_ids']);
        $this->assertCount(1, $payload['closed_ids']);
        $this->assertCount(2, $payload['inserted_ids']);
        $this->assertSame(false, $payload['compacted']);
    }

    public function test_hard_delete_records_the_deleted_ids(): void
    {
        // Kills handleHardDelete deleted_ids ArrayItemRemoval and ArrayItem.
        $product = $this->makeProduct();
        $first = $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $second = $this->insertPrice($product, ['amount' => 1500, 'valid_from' => '2026-02-01', 'valid_to' => null]);

        $result = $product->prices()->forceDeleteHistory();

        $row = DB::table('temporal_audit_log')->where('event_class', 'TemporalHardDeleteCommitted')->first();
        $this->assertNotNull($row);

        $payload = json_decode((string) $row->payload, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('deleted_ids', $payload);

        $expected = array_map($this->toInt(...), $result->ids);
        sort($expected);

        $deletedIds = $payload['deleted_ids'];
        $this->assertIsArray($deletedIds);
        $actual = array_map($this->toInt(...), $deletedIds);
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertContains($this->intKey($first), $actual);
        $this->assertContains($this->intKey($second), $actual);
    }

    private function intKey(Model $model): int
    {
        $key = $model->getKey();
        $this->assertIsInt($key);

        return $key;
    }

    private function toInt(mixed $value): int
    {
        $this->assertIsInt($value);

        return $value;
    }
}
