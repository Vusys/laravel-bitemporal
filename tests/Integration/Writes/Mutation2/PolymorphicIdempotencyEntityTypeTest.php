<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\Mutation2;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\Supplier;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins BitemporalWriter::idempotencyEntityType() for a polymorphic temporal
 * model. A Customer and a Supplier are created so they share the same numeric
 * primary key (1) across their two tables. Both write to Address with the same
 * idempotency key, so the ONLY thing keeping their idempotency records apart is
 * the entity_type (their distinct morph classes).
 *
 * Kills: LogicalNot (early null return), InstanceOf_ false (`false ? ... : null`),
 * and Ternary swap — each makes entity_type null for the morph model, which
 * collapses the two records into one and replays the customer's write for the
 * supplier.
 */
final class PolymorphicIdempotencyEntityTypeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap([
            'customer' => Customer::class,
            'supplier' => Supplier::class,
        ]);
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_morph_entity_type_keeps_same_key_writes_separate_per_type(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $customer = Customer::query()->create(['name' => 'Acme']);
        $supplier = Supplier::query()->create(['name' => 'Globex']);

        // Same numeric primary key across both morph types.
        $this->assertSame($customer->getKey(), $supplier->getKey());

        $customer->addresses()->changeEffectiveFrom(['label' => 'cust-hq'], '2026-06-01', idempotencyKey: 'shared-key');
        $supplier->addresses()->changeEffectiveFrom(['label' => 'supp-hq'], '2026-06-01', idempotencyKey: 'shared-key');

        // With a correct (non-null) entity_type the supplier's write is its own
        // record and lands its own row. If entity_type collapses to null, the
        // supplier call replays the customer's stored snapshot and writes nothing
        // for the supplier.
        $this->assertSame('supp-hq', $supplier->addresses()->validAt('2026-09-01')->currentKnowledge()->sole()->label);
        $this->assertSame('cust-hq', $customer->addresses()->validAt('2026-09-01')->currentKnowledge()->sole()->label);

        $this->assertCount(1, $supplier->addresses()->currentKnowledge()->get());
        $this->assertCount(1, $customer->addresses()->currentKnowledge()->get());
    }
}
