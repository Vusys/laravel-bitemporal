<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Reads\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Support\MorphContext;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\MisrelatedPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Fixtures\Models\UserRoleAssignment;
use Vusys\Bitemporal\Tests\Fixtures\Models\ValidTimeOnlyPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Mutation-killing coverage for BitemporalBuilder read predicates plus the
 * HasSpellQueries / HasTemporalDimensions / HasTemporalDiff concerns.
 */
final class BuilderPredicateMutationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function seedThreeSegments(): Product
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1, 'valid_from' => '2026-01-01', 'valid_to' => '2026-04-01']);
        $this->insertPrice($product, ['amount' => 2, 'valid_from' => '2026-04-01', 'valid_to' => '2026-07-01']);
        $this->insertPrice($product, ['amount' => 3, 'valid_from' => '2026-07-01', 'valid_to' => null]);

        return $product;
    }

    // --- validAt / knownAt lens pinning (markValidAtPinned / markKnownAtPinned) ---

    public function test_explicit_valid_at_pins_the_axis_against_the_ambient_lens(): void
    {
        $product = $this->seedThreeSegments();

        // Lens pins valid-time to a date matching amount 1; the explicit
        // validAt() below pins amount 2. markValidAtPinned() must stop the lens
        // re-applying its own validAt (which would yield an empty intersection).
        $amounts = TemporalLens::validAt('2026-02-01', fn (): array => $product->prices()
            ->validAt('2026-05-01')
            ->pluck('amount')
            ->all());

        $this->assertSame([2], $amounts);
    }

    public function test_explicit_known_at_pins_the_axis_against_the_ambient_lens(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
        ]);
        $this->insertPrice($product, [
            'amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);

        // Lens pins known-time to the first belief; the explicit knownAt() pins
        // the second. Without markKnownAtPinned() both apply and intersect empty.
        $amounts = TemporalLens::knownAt('2026-02-01', fn (): array => $product->prices()
            ->knownAt('2026-09-01')
            ->pluck('amount')
            ->all());

        $this->assertSame([1200], $amounts);
    }

    // --- instant() timezone ternary ---

    public function test_instant_applies_the_configured_timezone(): void
    {
        config(['bitemporal.spells.timezone' => 'Asia/Kolkata']);

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-06-01 04:00:00', 'valid_to' => null]);

        // Asia/Kolkata is UTC+5:30, so the query instant shifts to 05:30 and the
        // 04:00 row is covered. Under UTC it would be 00:00 and excluded.
        $this->assertCount(1, $product->prices()->validAt('2026-06-01 00:00:00')->get());
    }

    // --- sole(string $column) column wrapping ---

    public function test_builder_sole_selects_the_named_string_column(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $model = ProductPrice::query()->sole('amount');

        $this->assertSame(['amount'], array_keys($model->getAttributes()));
    }

    // --- whereTemporalEntity with a MorphContext (InstanceOf) ---

    public function test_where_temporal_entity_accepts_a_morph_context_directly(): void
    {
        Relation::morphMap(['customer' => Customer::class]);

        $customer = Customer::query()->create(['name' => 'Acme']);
        $this->seedAddress($customer, 'hq');

        $customerKey = $customer->getKey();
        $this->assertIsInt($customerKey);
        $context = new MorphContext($customer->getMorphClass(), $customerKey);

        $rows = Address::query()->whereTemporalEntity($context)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('hq', $rows->first()?->label);
    }

    // --- wherePolymorphicEntityIn match arms ---

    public function test_where_temporal_entity_in_accepts_morph_contexts(): void
    {
        Relation::morphMap(['customer' => Customer::class]);

        $customer = Customer::query()->create(['name' => 'Acme']);
        $other = Customer::query()->create(['name' => 'Globex']);
        $this->seedAddress($customer, 'a');
        $this->seedAddress($other, 'b');

        $customerKey = $customer->getKey();
        $this->assertIsInt($customerKey);
        $context = new MorphContext($customer->getMorphClass(), $customerKey);

        $rows = Address::query()->whereTemporalEntityIn([$context])->get();

        $this->assertSame(['a'], $rows->pluck('label')->all());
    }

    public function test_where_temporal_entity_in_accepts_models_on_the_polymorphic_path(): void
    {
        Relation::morphMap(['customer' => Customer::class]);

        $customer = Customer::query()->create(['name' => 'Acme']);
        $other = Customer::query()->create(['name' => 'Globex']);
        $this->seedAddress($customer, 'a');
        $this->seedAddress($other, 'b');

        $rows = Address::query()->whereTemporalEntityIn([$customer])->get();

        $this->assertSame(['a'], $rows->pluck('label')->all());
    }

    public function test_where_temporal_entity_in_rejects_unexpected_polymorphic_values(): void
    {
        Relation::morphMap(['customer' => Customer::class]);

        Customer::query()->create(['name' => 'Acme']);

        $this->expectException(TemporalConfigurationException::class);

        Address::query()->whereTemporalEntityIn([3.14])->get();
    }

    // --- temporalEntityColumns relation-type guard ---

    public function test_where_temporal_entity_rejects_a_non_belongs_to_morph_to_relation(): void
    {
        $product = $this->makeProduct();

        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/BelongsTo or MorphTo/');

        MisrelatedPrice::query()->whereTemporalEntity($product)->get();
    }

    public function test_where_temporal_entity_rejects_a_model_without_temporal_entity(): void
    {
        $product = $this->makeProduct();

        $this->expectException(TemporalConfigurationException::class);

        UserRoleAssignment::query()->whereTemporalEntity($product)->get();
    }

    // --- requireRecordedTime guard ---

    public function test_known_at_requires_a_recorded_time_model(): void
    {
        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/recorded time/');

        ValidTimeOnlyPrice::query()->knownAt('2026-01-01')->get();
    }

    // --- HasSpellQueries: applyContainedBy lower bound + public visibility ---

    public function test_valid_contained_by_enforces_the_lower_bound(): void
    {
        $product = $this->seedThreeSegments();

        // Only the segment fully inside [2026-04-01, 2026-07-01) qualifies; the
        // earlier segment starts before the lower bound and must be excluded.
        $this->assertSame([2], $product->prices()
            ->validContainedBy('2026-04-01', '2026-07-01')
            ->pluck('amount')
            ->all());
    }

    public function test_recorded_contains_is_publicly_callable(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
        ]);
        $this->insertPrice($product, [
            'amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);

        $this->assertSame([1000], $product->prices()
            ->recordedContains('2026-02-01', '2026-02-15')
            ->pluck('amount')
            ->all());
    }

    public function test_recorded_contained_by_is_publicly_callable(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-02-01', 'recorded_to' => '2026-03-01',
        ]);
        $this->insertPrice($product, [
            'amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => null,
        ]);

        $this->assertSame([1000], $product->prices()
            ->recordedContainedBy('2026-01-01', '2026-04-01')
            ->pluck('amount')
            ->all());
    }

    // --- HasTemporalDimensions: forDimensions null handling + hasWheresOutside ---

    public function test_for_dimensions_applies_every_clause_after_a_null_value(): void
    {
        $product = $this->makeProduct();
        $this->makeDimensionedPrice($product, 1000, null);
        $this->makeDimensionedPrice($product, 2000, null);

        // currency is NULL for both; the amount clause must still apply even
        // though it comes after the null dimension in the tuple.
        $rows = ProductPriceWithDimensions::query()
            ->forDimensions(['currency' => null, 'amount' => 1000])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(1000, $rows->first()?->amount);
    }

    public function test_has_wheres_outside_flags_a_non_string_column_clause(): void
    {
        $query = ProductPrice::query()->where(function ($inner): void {
            $inner->where('amount', '=', 1);
        });

        $this->assertTrue($query->hasWheresOutside(['amount']));
    }

    // --- HasTemporalDiff: fullHistory ordering ---

    public function test_full_history_orders_by_valid_from_then_recorded_from(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, [
            'amount' => 10, 'valid_from' => '2026-02-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => null,
        ]);
        $this->insertPrice($product, [
            'amount' => 20, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);
        $this->insertPrice($product, [
            'amount' => 30, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-02-01', 'recorded_to' => null,
        ]);

        $ordered = $product->prices()->fullHistory()->pluck('amount')->all();

        $this->assertSame([30, 20, 10], $ordered);
    }

    private function seedAddress(Customer $owner, string $label): void
    {
        Address::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'label' => $label,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-01-01',
            'recorded_to' => null,
            'is_retraction' => false,
        ]);
    }

    private function makeDimensionedPrice(Product $product, int $amount, ?string $currency): void
    {
        ProductPriceWithDimensions::query()->create([
            'product_id' => $product->getKey(),
            'amount' => $amount,
            'currency' => $currency,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-01-01',
            'recorded_to' => null,
            'is_retraction' => false,
        ]);
    }
}
