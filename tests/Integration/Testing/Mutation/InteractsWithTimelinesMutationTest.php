<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Testing\Mutation;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use Vusys\Bitemporal\Boot\Guards\BootGuardRelationType;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Testing\InteractsWithTimelines;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Fixtures\Models\Supplier;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Meta-tests that pin the assertion helpers in InteractsWithTimelines. Each
 * helper is exercised with data that must PASS (calling it directly so any
 * unexpected throw fails the test) and data that must FAIL (wrapped so we can
 * assert it throws and that the failure message is exactly right).
 */
final class InteractsWithTimelinesMutationTest extends IntegrationTestCase
{
    use InteractsWithTimelines;

    // ----- assertTemporalAttributes ----------------------------------------

    public function test_attributes_pass_for_matching_row(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertTemporalAttributes($product->prices(), validAt: '2026-03-01', attributes: ['amount' => 1000]);
    }

    public function test_attributes_missing_row_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        // No row valid at this instant; with empty attributes only the
        // assertNotNull guard can fail (kills its removal).
        $this->assertFailsFirstLine(
            fn () => $this->assertTemporalAttributes($product->prices(), validAt: '2025-01-01', attributes: []),
            'Expected a temporal row valid at the given instant, found none.',
        );
    }

    public function test_attributes_wrong_value_throws_with_exact_message(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertFailsExact(
            fn () => $this->assertTemporalAttributes($product->prices(), validAt: '2026-03-01', attributes: ['amount' => 999]),
            'Temporal attribute [amount] did not match. Expected 999, got 1000.',
        );
    }

    // ----- assertTemporalTimeline ------------------------------------------

    public function test_timeline_pass_positional(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertTemporalTimeline($product->prices(), [
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
            ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null],
        ]);
    }

    public function test_timeline_default_excludes_superseded(): void
    {
        $product = $this->supersededAndCurrent();

        // Default includeSuperseded=false -> current-known only (one row).
        $this->assertTemporalTimeline($product->prices(), [
            ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null],
        ]);
    }

    public function test_timeline_include_superseded_returns_full_history(): void
    {
        $product = $this->supersededAndCurrent();

        $this->assertTemporalTimeline($product->prices(), [
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_to' => '2026-02-01'],
            ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_to' => null],
        ], includeSuperseded: true);
    }

    public function test_timeline_count_mismatch_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertFailsFirstLine(
            fn () => $this->assertTemporalTimeline($product->prices(), [
                ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
            ]),
            'Timeline row count did not match the expected timeline.',
        );
    }

    public function test_timeline_content_mismatch_throws_with_exact_message(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $expected = ['amount' => 999, 'valid_from' => '2026-01-01', 'valid_to' => null];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected]),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    // ----- rowMatches near-misses (via assertTemporalTimeline) --------------

    public function test_valid_from_off_by_one_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $expected = ['amount' => 1000, 'valid_from' => '2026-01-02', 'valid_to' => null];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected]),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    public function test_valid_to_off_by_one_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);

        $expected = ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-02'];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected]),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    public function test_recorded_from_off_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01']);

        $expected = ['amount' => 1000, 'valid_from' => '2026-01-01', 'recorded_from' => '2026-05-05'];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected], includeSuperseded: true),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    public function test_recorded_to_null_vs_nonnull_throws(): void
    {
        // actual recorded_to is null, expectation supplies a value: instantsEqual
        // must report inequality (and the LogicalOr mutant errors on null->equalTo,
        // which escapes the AssertionFailedError catch -> killed).
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_to' => null]);

        $expected = ['amount' => 1000, 'recorded_to' => '2026-05-05'];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected], includeSuperseded: true),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    public function test_valid_to_null_vs_nonnull_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $expected = ['amount' => 1000, 'valid_to' => '2026-06-01'];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected]),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    public function test_omitting_valid_from_still_checks_later_columns(): void
    {
        // valid_from absent from the expectation (continue), valid_to present and
        // wrong: a `break` instead of `continue` would skip the valid_to check.
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);

        $expected = ['amount' => 1000, 'valid_to' => '2026-09-09'];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected]),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    public function test_attribute_mismatch_with_correct_instants_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'currency' => 'GBP', 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $expected = ['amount' => 1000, 'currency' => 'USD', 'valid_from' => '2026-01-01', 'valid_to' => null];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected]),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    public function test_timeline_row_with_only_temporal_keys_passes(): void
    {
        // No value keys at all: the retraction guard must not fire for a normal row.
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertTemporalTimeline($product->prices(), [
            ['valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
            ['valid_from' => '2026-06-01', 'valid_to' => null],
        ]);
    }

    public function test_retraction_expectation_with_values_is_rejected(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => null, 'currency' => null, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01', 'is_retraction' => true]);

        $this->assertLogicFails(
            fn () => $this->assertTemporalTimeline($product->prices(), [
                ['is_retraction' => true, 'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
            ]),
            'A retracted expectation row may not also assert attribute values.',
        );
    }

    public function test_retraction_flag_truthy_int_matches(): void
    {
        // is_retraction provided as int 1 (not bool) must still match a retraction
        // row; this pins the (bool) cast on the expectation side.
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => null, 'currency' => null, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01', 'is_retraction' => true]);

        $this->assertTemporalTimeline($product->prices(), [
            ['is_retraction' => 1, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
        ]);
    }

    public function test_non_retraction_row_against_retraction_expectation_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $expected = ['is_retraction' => true, 'valid_from' => '2026-01-01', 'valid_to' => null];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimeline($product->prices(), [$expected]),
            'Temporal row at timeline position 0 did not match expected '.var_export($expected, true).'.',
        );
    }

    // ----- assertTemporalTimelineUnordered ---------------------------------

    public function test_unordered_single_row_passes(): void
    {
        // A single row: the rowMatches branch must be taken positively (an
        // IfNegation would leave matchIndex null and throw).
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertTemporalTimelineUnordered($product->prices(), [
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null],
        ]);
    }

    public function test_unordered_pass_reversed_order(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertTemporalTimelineUnordered($product->prices(), [
            ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null],
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
        ]);
    }

    public function test_unordered_default_excludes_superseded(): void
    {
        $product = $this->supersededAndCurrent();

        $this->assertTemporalTimelineUnordered($product->prices(), [
            ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null],
        ]);
    }

    public function test_unordered_count_mismatch_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertFailsFirstLine(
            fn () => $this->assertTemporalTimelineUnordered($product->prices(), [
                ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
            ]),
            'Timeline row count did not match the expected timeline.',
        );
    }

    public function test_unordered_no_match_throws(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $expected = ['amount' => 9999, 'valid_from' => '2026-01-01', 'valid_to' => null];
        $this->assertFailsExact(
            fn () => $this->assertTemporalTimelineUnordered($product->prices(), [$expected]),
            'No current-known row matched expected '.var_export($expected, true).'.',
        );
    }

    // ----- assertNoTemporalOverlaps / assertNoBitemporalOverlaps -----------

    public function test_no_overlap_passes_for_clean_timeline(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertNoTemporalOverlaps(ProductPrice::class);
    }

    public function test_overlap_three_rows_message_is_deduplicated(): void
    {
        $product = $this->makeProduct();
        // Three mutually overlapping current-known rows -> three overlapping pairs,
        // collapsed by array_unique to a single tuple in the message.
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($product, ['amount' => 1100, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $key = 'product_id='.var_export($product->getKey(), true);
        $this->assertFailsFirstLine(
            fn () => $this->assertNoTemporalOverlaps(ProductPrice::class),
            'Temporal overlap detected in '.ProductPrice::class.' tuple(s): '.$key.'.',
        );
    }

    public function test_temporal_overlaps_only_consider_current_known_rows(): void
    {
        // A superseded row overlaps the current one on the valid axis, but it is
        // not current-known, so assertNoTemporalOverlaps must ignore it.
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01', 'recorded_from' => '2026-01-01', 'recorded_to' => '2026-02-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-03-01', 'valid_to' => '2026-09-01', 'recorded_from' => '2026-02-01', 'recorded_to' => null]);

        $this->assertNoTemporalOverlaps(ProductPrice::class);
    }

    public function test_bitemporal_overlaps_detect_superseded_rows(): void
    {
        // Two rows share the same valid window AND overlapping recorded windows.
        // assertNoBitemporalOverlaps must flag it even though only one row is
        // current-known.
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => null]);

        $key = 'product_id='.var_export($product->getKey(), true);
        $this->assertFailsFirstLine(
            fn () => $this->assertNoBitemporalOverlaps(ProductPrice::class),
            'Temporal overlap detected in '.ProductPrice::class.' tuple(s): '.$key.'.',
        );
    }

    public function test_entity_columns_separate_distinct_products(): void
    {
        // Same overlapping valid window on two different products: the entity
        // (product_id) column must keep the tuples apart.
        $a = $this->makeProduct('A');
        $b = $this->makeProduct('B');
        $this->insertPrice($a, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($b, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertNoTemporalOverlaps(ProductPrice::class);
    }

    // ----- dimensions -------------------------------------------------------

    public function test_dimensions_separate_distinct_currencies(): void
    {
        $product = $this->makeProduct();
        $this->insertDimensioned($product, ['currency' => 'GBP', 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertDimensioned($product, ['currency' => 'USD', 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertNoTemporalOverlaps(ProductPriceWithDimensions::class);
    }

    public function test_dimensioned_overlap_message_includes_dimension(): void
    {
        $product = $this->makeProduct();
        $this->insertDimensioned($product, ['currency' => 'GBP', 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertDimensioned($product, ['currency' => 'GBP', 'valid_from' => '2026-03-01', 'valid_to' => null]);

        $key = 'product_id='.var_export($product->getKey(), true).'|currency='.var_export('GBP', true);
        $this->assertFailsFirstLine(
            fn () => $this->assertNoTemporalOverlaps(ProductPriceWithDimensions::class),
            'Temporal overlap detected in '.ProductPriceWithDimensions::class.' tuple(s): '.$key.'.',
        );
    }

    // ----- MorphTo entity columns ------------------------------------------

    public function test_morphto_separates_by_owner_id(): void
    {
        $c1 = Customer::query()->create(['name' => 'c1']);
        $c2 = Customer::query()->create(['name' => 'c2']);
        $c1Key = $c1->getKey();
        $c2Key = $c2->getKey();
        $this->assertIsInt($c1Key);
        $this->assertIsInt($c2Key);
        $this->insertAddress(Customer::class, $c1Key, ['valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertAddress(Customer::class, $c2Key, ['valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertNoTemporalOverlaps(Address::class);
    }

    public function test_morphto_separates_by_owner_type(): void
    {
        $customer = Customer::query()->create(['name' => 'c']);
        $supplier = Supplier::query()->create(['name' => 's']);
        $customerKey = $customer->getKey();
        $supplierKey = $supplier->getKey();
        $this->assertIsInt($customerKey);
        $this->assertIsInt($supplierKey);
        // Force the same owner_id but different owner_type.
        $this->insertAddress(Customer::class, $customerKey, ['owner_id' => 1, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertAddress(Supplier::class, $supplierKey, ['owner_id' => 1, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertNoTemporalOverlaps(Address::class);
    }

    // ----- assertExactlyOneOpenEndedCurrentKnownPerDimensionTuple ----------

    public function test_one_open_ended_row_passes(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertExactlyOneOpenEndedCurrentKnownPerDimensionTuple($product);
    }

    public function test_two_open_ended_rows_throws(): void
    {
        $product = $this->makeProduct();
        // Closed row inserted first (so a break instead of continue would stop
        // before the open rows are counted), then two open-ended rows.
        $this->insertPrice($product, ['amount' => 900, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-06-01', 'valid_to' => null]);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-07-01', 'valid_to' => null]);

        $key = 'product_id='.var_export($product->getKey(), true);
        $this->assertFailsFirstLine(
            fn () => $this->assertExactlyOneOpenEndedCurrentKnownPerDimensionTuple($product),
            "Tuple [{$key}] has 2 open-ended current-known rows; expected at most one.",
        );
    }

    public function test_entity_without_temporal_relations_throws(): void
    {
        // A ProductPrice has no temporal *relations* of its own, so the helper
        // must report that and not silently pass.
        $price = new ProductPrice;

        $this->assertFailsFirstLine(
            fn () => $this->assertExactlyOneOpenEndedCurrentKnownPerDimensionTuple($price),
            ProductPrice::class.' declares no temporal relations.',
        );
    }

    public function test_non_temporal_model_is_rejected(): void
    {
        // Product is an entity, not a temporal model, so metaFor() must reject it.
        $this->assertLogicFails(
            fn () => $this->assertNoTemporalOverlaps(Product::class),
            Product::class.' is not a temporal model.',
        );
    }

    // ----- temporalQuery ----------------------------------------------------

    public function test_accepts_bitemporal_builder_directly(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $builder = ProductPrice::query()->where('product_id', $product->getKey());

        $this->assertTemporalTimeline($builder, [
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null],
        ]);
    }

    public function test_non_temporal_relation_is_rejected(): void
    {
        $product = $this->makeProduct();
        $price = $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertLogicFails(
            fn () => $this->assertTemporalTimeline($price->product(), []),
            'The given relation is not backed by a BitemporalBuilder.',
        );
    }

    // ----- instant() --------------------------------------------------------

    public function test_instant_rejects_non_date_value(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->assertLogicFails(
            fn () => $this->assertTemporalTimeline($product->prices(), [
                ['amount' => 1000, 'valid_from' => 999],
            ]),
            'Expected a date/datetime value; got int.',
        );
    }

    public function test_instant_accepts_datetime_interface(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        // A plain DateTimeImmutable must be accepted (not just Carbon/string).
        $this->assertTemporalTimeline($product->prices(), [
            ['amount' => 1000, 'valid_from' => new DateTimeImmutable('2026-01-01')],
        ]);
    }

    // ----- expectTemporalException -----------------------------------------

    public function test_expect_temporal_exception_quotes_regex_metacharacters(): void
    {
        // The substring contains a '(' which must be quoted; an unquoted pattern
        // would be an invalid regex and error at teardown.
        $this->expectTemporalException(TemporalInvalidSpellException::class, 'boom(');

        throw new TemporalInvalidSpellException('it went boom( hard');
    }

    // ----- expectGuardFailure ----------------------------------------------

    public function test_expect_guard_failure_when_nothing_thrown(): void
    {
        $this->assertFailsFirstLine(
            fn () => $this->expectGuardFailure(BootGuardRelationType::class, fn (): null => null),
            'Expected a TemporalConfigurationException including the [BootGuardRelationType] guard failure; none was thrown.',
        );
    }

    // ----- fixtures & helpers ----------------------------------------------

    private function supersededAndCurrent(): Product
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => '2026-02-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => null]);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertDimensioned(Product $product, array $attributes): ProductPriceWithDimensions
    {
        return ProductPriceWithDimensions::query()->create([
            'product_id' => $product->getKey(),
            'amount' => 1000,
            'currency' => 'GBP',
            'recorded_from' => '2026-01-01 00:00:00',
            'recorded_to' => null,
            'is_retraction' => false,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertAddress(string $ownerType, int $ownerId, array $attributes): Address
    {
        return Address::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'label' => 'home',
            'recorded_from' => '2026-01-01 00:00:00',
            'recorded_to' => null,
            'is_retraction' => false,
            ...$attributes,
        ]);
    }

    /**
     * The custom failure message is the prefix of the thrown message (PHPUnit
     * appends its own description after a newline), so matching the prefix
     * exactly still distinguishes every reordered/truncated message mutant.
     */
    private function assertFailsExact(callable $fn, string $exactMessage): void
    {
        $this->assertFailsFirstLine($fn, $exactMessage);
    }

    private function assertFailsFirstLine(callable $fn, string $expectedPrefix): void
    {
        if ($expectedPrefix === '') {
            $this->fail('Expected prefix must not be empty.');
        }

        try {
            $fn();
        } catch (AssertionFailedError $e) {
            $this->assertStringStartsWith($expectedPrefix, $e->getMessage());

            return;
        }

        $this->fail('Expected an AssertionFailedError to be thrown.');
    }

    private function assertLogicFails(callable $fn, string $exactMessage): void
    {
        try {
            $fn();
        } catch (LogicException $e) {
            $this->assertSame($exactMessage, $e->getMessage());

            return;
        }

        $this->fail('Expected a LogicException to be thrown.');
    }
}
