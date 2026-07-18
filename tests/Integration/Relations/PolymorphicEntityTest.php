<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Relations;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\Supplier;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class PolymorphicEntityTest extends IntegrationTestCase
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

    /**
     * @param  Customer|Supplier  $owner
     */
    private function seedAddress($owner, string $label, ?string $validTo = null): void
    {
        $owner->addresses()->getRelated()->newQuery()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'label' => $label,
            'valid_from' => '2026-01-01',
            'valid_to' => $validTo,
            'recorded_from' => '2026-01-01',
            'recorded_to' => null,
            'is_retraction' => false,
        ]);
    }

    public function test_where_temporal_entity_scopes_to_a_polymorphic_owner(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);
        $supplier = Supplier::query()->create(['name' => 'Globex']);
        $this->seedAddress($customer, 'customer address');
        $this->seedAddress($supplier, 'supplier address');

        $rows = Address::query()->whereTemporalEntity($customer)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('customer address', $rows->first()?->label);
    }

    public function test_where_temporal_entity_in_with_mixed_types(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);
        $supplier = Supplier::query()->create(['name' => 'Globex']);
        $other = Supplier::query()->create(['name' => 'Initech']);
        $this->seedAddress($customer, 'a');
        $this->seedAddress($supplier, 'b');
        $this->seedAddress($other, 'c');

        $rows = Address::query()->whereTemporalEntityIn([$customer, $supplier])->get();

        $this->assertCount(2, $rows);
    }

    public function test_where_temporal_entity_in_with_an_empty_set_matches_nothing(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);
        $supplier = Supplier::query()->create(['name' => 'Globex']);
        $this->seedAddress($customer, 'a');
        $this->seedAddress($supplier, 'b');

        // An empty polymorphic filter must scope to nothing, not leak the table.
        $rows = Address::query()->whereTemporalEntityIn([])->get();

        $this->assertCount(0, $rows);
    }

    public function test_where_temporal_entity_of(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);
        $supplier = Supplier::query()->create(['name' => 'Globex']);
        $this->seedAddress($customer, 'a');
        $this->seedAddress($supplier, 'b');

        $rows = Address::query()->whereTemporalEntityOf(Customer::class, [$customer->id])->get();

        $this->assertSame(['a'], $rows->pluck('label')->all());
    }

    public function test_relation_reads_and_writes_scope_to_the_owner(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $customer = Customer::query()->create(['name' => 'Acme']);
        $supplier = Supplier::query()->create(['name' => 'Globex']);
        $this->seedAddress($supplier, 'supplier address');

        $customer->addresses()->changeEffectiveFrom(['label' => 'HQ'], '2026-06-01');

        $this->assertSame('HQ', $customer->addresses()->validAt('2026-09-01')->currentKnowledge()->sole()->label);
        // The supplier's address is untouched.
        $this->assertCount(1, $supplier->addresses()->currentKnowledge()->get());
        $this->assertSame('supplier address', $supplier->addresses()->currentKnowledge()->sole()->label);
    }

    public function test_collection_grouping_for_polymorphic_entities(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);
        $supplier = Supplier::query()->create(['name' => 'Globex']);
        $this->seedAddress($customer, 'a');
        $this->seedAddress($supplier, 'b');

        $byReference = Address::query()->get()->keyByTemporalEntityReference();
        $this->assertSame('a', $byReference->get('customer:'.$customer->id)?->label);

        $byType = Address::query()->get()->groupByTemporalEntityType();
        $this->assertCount(1, $byType->get('customer') ?? collect());
        $this->assertCount(1, $byType->get('supplier') ?? collect());
    }

    public function test_key_by_temporal_entity_id_rejects_polymorphic(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);
        $this->seedAddress($customer, 'a');

        $this->expectException(TemporalConfigurationException::class);

        Address::query()->get()->keyByTemporalEntityId();
    }
}
