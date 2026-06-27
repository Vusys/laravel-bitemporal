<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads\Mutation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vusys\Bitemporal\Collections\BitemporalCollection;
use Vusys\Bitemporal\Collections\Concerns\HasPolymorphicGrouping;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\MisrelatedPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\Role;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Mutation coverage for {@see HasPolymorphicGrouping}.
 * Uses in-memory models so foreign-key / morph-type values can be set precisely.
 */
final class PolymorphicGroupingMutationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        parent::tearDown();
    }

    /**
     * @param  iterable<int, Model>  $models
     * @return BitemporalCollection<int, Model>
     */
    private function collect(iterable $models): BitemporalCollection
    {
        return new BitemporalCollection($models);
    }

    // Kills the temporalEntityId LogicalOrSingleSubExprNegation
    // (`is_int || !is_string`): a string foreign key must be returned as-is.
    public function test_string_foreign_key_is_accepted(): void
    {
        $price = new ProductPrice(['product_id' => 'abc']);

        $keyed = $this->collect([$price])->keyByTemporalEntityReference();

        $reference = (new Product)->getMorphClass().':abc';
        $this->assertTrue($keyed->has($reference));
    }

    // Kills temporalEntityId LogicalOrAllSubExprNegation + the message
    // Concat / ConcatOperandRemoval + Throw_ mutants: a non-scalar key throws
    // with the type-tagged message.
    public function test_non_scalar_foreign_key_throws(): void
    {
        $price = new ProductPrice(['product_id' => 1.5]);

        try {
            $this->collect([$price])->keyByTemporalEntityReference();
            $this->fail('Expected a TemporalConfigurationException.');
        } catch (TemporalConfigurationException $exception) {
            $this->assertStringStartsWith('temporal entity key must be an int or string; got ', $exception->getMessage());
            $this->assertStringContainsString('float', $exception->getMessage());
        }
    }

    // Kills temporalEntityType InstanceOf_ (`instanceof MorphTo` -> false): the
    // stored morph type must be used verbatim, not the resolved class's morph
    // alias. Here owner_type holds the FQCN while the morph map aliases it.
    public function test_morph_type_uses_the_stored_value(): void
    {
        Relation::morphMap(['customer' => Customer::class]);

        $address = new Address(['owner_type' => Customer::class, 'owner_id' => 1]);

        $grouped = $this->collect([$address])->groupByTemporalEntityType();

        $this->assertTrue($grouped->has(Customer::class));
        $this->assertFalse($grouped->has('customer'));
    }

    // Kills temporalEntityType Throw_ (the morph-type-must-be-a-string throw):
    // a non-string morph type must raise, not fall through.
    public function test_non_string_morph_type_throws(): void
    {
        Relation::morphMap([7 => Customer::class]);

        $address = new Address(['owner_type' => 7, 'owner_id' => 1]);

        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessage('temporal entity morph type must be a string');

        $this->collect([$address])->groupByTemporalEntityType();
    }

    // Kills temporalEntityRelation Throw_ #1: a model lacking temporalEntity()
    // must raise the missing-relation error.
    public function test_missing_temporal_entity_relation_throws(): void
    {
        $this->expectException(TemporalConfigurationException::class);

        $this->collect([new Role(['id' => 1])])->keyByTemporalEntityReference();
    }

    // Kills temporalEntityRelation InstanceOf_ (`! instanceof BelongsTo`) +
    // Throw_ #2: a HasMany temporalEntity() must be rejected.
    public function test_non_belongs_to_relation_throws(): void
    {
        $related = TemporalLens::withoutBootGuards(fn (): MisrelatedPrice => new MisrelatedPrice(['id' => 1]));
        $this->assertInstanceOf(MisrelatedPrice::class, $related);

        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessage('BelongsTo or MorphTo relation');

        $this->collect([$related])->keyByTemporalEntityReference();
    }
}
