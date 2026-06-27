<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins entityType(): null for a belongsTo entity, the morph class for a MorphTo
 * entity.
 */
final class AuditLogEntityTypeMutationTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('bitemporal.audit_log.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['customer' => Customer::class]);
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_belongs_to_entity_records_null_entity_type(): void
    {
        // Kills entityType InstanceOf_ (true) and the Ternary swap on the
        // belongsTo side: ProductPrice's entity is a belongsTo, so entity_type
        // must be null.
        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1500], '2026-01-01');

        $row = DB::table('temporal_audit_log')->where('event_class', 'TemporalCorrectionCommitted')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->entity_type);
    }

    public function test_morph_entity_records_the_morph_class(): void
    {
        // Kills entityType LogicalNot, InstanceOf_ (false), and the Ternary swap
        // on the morph side: Address's entity is a MorphTo, so entity_type must
        // be the owner's morph alias.
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $customer = Customer::query()->create(['name' => 'Acme']);
        $customer->addresses()->changeEffectiveFrom(['label' => 'HQ'], '2026-06-01');

        $row = DB::table('temporal_audit_log')->where('event_class', 'TemporalChangeCommitted')->first();
        $this->assertNotNull($row);
        $this->assertSame('customer', $row->entity_type);
        $this->assertEquals($customer->getKey(), $row->entity_id);
    }
}
