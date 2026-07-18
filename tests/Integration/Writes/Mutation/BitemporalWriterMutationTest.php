<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;
use Vusys\Bitemporal\Events\TemporalCompactionPerformed;
use Vusys\Bitemporal\Events\TemporalHardDeleteCommitted;
use Vusys\Bitemporal\Events\TemporalHardDeleteStarting;
use Vusys\Bitemporal\Exceptions\TemporalDomainException;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Targeted tests that pin specific mutated lines in BitemporalWriter so the
 * mutants in build/mutants/src__Writers__BitemporalWriter.txt are killed.
 */
final class BitemporalWriterMutationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedDimensioned(Product $product, array $attributes): void
    {
        ProductPriceWithDimensions::query()->create([
            'product_id' => $product->getKey(),
            'recorded_from' => '2026-01-01 00:00:00',
            'recorded_to' => null,
            'is_retraction' => false,
            ...$attributes,
        ]);
    }

    // --- changeEffectiveFrom idempotency input array (ArrayItem 'validFrom', ArrayItemRemoval 'attributes') ---

    public function test_change_idempotency_hash_distinguishes_valid_from(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01', idempotencyKey: 'cef-key-a');

        // Same key + same attributes but a DIFFERENT validFrom must be treated as a
        // parameter conflict — proving validFrom is part of the idempotency hash.
        $this->expectException(TemporalWriteConflictException::class);
        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-07-01', idempotencyKey: 'cef-key-a');
    }

    public function test_change_idempotency_hash_distinguishes_attributes(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01', idempotencyKey: 'cef-key-b');

        // Same key + same validFrom but DIFFERENT attributes must conflict —
        // proving the attributes array is part of the idempotency hash.
        $this->expectException(TemporalWriteConflictException::class);
        $product->prices()->changeEffectiveFrom(['amount' => 2000], '2026-06-01', idempotencyKey: 'cef-key-b');
    }

    // --- correct() null-safe format on the open-from window (NullSafeMethodCall) ---

    public function test_correct_open_from_builds_inputs_without_calling_format_on_null(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();

        // validFrom is null => $window->from is null. The original uses ?->format
        // (safe). The mutant calls ->format() on null and dies with an "on null"
        // Error *before* the write reaches the DB. The real code instead fails at
        // the NOT NULL valid_from insert, whose message never mentions format().
        $message = null;
        try {
            $product->prices()->correct(['amount' => 500], validTo: '2026-06-01');
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }

        $this->assertNotNull($message, 'an open-from correction over a NOT NULL column should throw');
        $this->assertStringNotContainsString('format()', $message);
    }

    // --- supersedeTimeline row merge loops (Foreach over $rows / rowValueAttributes) ---

    public function test_supersede_collects_value_columns_from_rows(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();

        // On an empty timeline the only source of value columns is the supplied
        // rows. If either merge loop is skipped, valueColumns is empty and the
        // inserted row loses its amount.
        $product->prices()->supersedeTimeline([
            ['amount' => 1500, 'valid_from' => '2026-01-01', 'valid_to' => null],
        ]);

        $this->assertSame(1500, $product->prices()->validAt('2026-06-01')->currentKnowledge()->sole()->amount);
    }

    // --- rowInstant instanceof CarbonInterface (InstanceOf_) ---

    public function test_supersede_accepts_a_carbon_instant_in_rows(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->supersedeTimeline([
            ['amount' => 1000, 'valid_from' => CarbonImmutable::parse('2026-01-01'), 'valid_to' => null],
        ]);

        $row = $product->prices()->currentKnowledge()->sole();
        $this->assertSame(1000, $row->amount);
        $this->assertTrue($row->valid_from->equalTo(CarbonImmutable::parse('2026-01-01')));
    }

    // --- rowInstant throw on invalid type (uncovered Throw_) ---

    public function test_supersede_rejects_an_invalid_instant_type(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();

        $this->expectException(TemporalInvalidSpellException::class);
        $product->prices()->supersedeTimeline([
            ['amount' => 1000, 'valid_from' => 12345, 'valid_to' => null],
        ]);
    }

    // --- timelineFromRows is_retraction (Coalesce, CastBool, FalseValue) ---

    public function test_supersede_honours_per_row_retraction_flag(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->supersedeTimeline([
            // No is_retraction key => must default to false (FalseValue).
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
            // is_retraction provided as a non-bool truthy => must cast to true
            // (Coalesce keeps the row value, CastBool forces a real bool).
            ['valid_from' => '2026-06-01', 'valid_to' => '2026-09-01', 'is_retraction' => 1],
        ]);

        $value = $product->prices()->validAt('2026-03-01')->currentKnowledge()->sole();
        $this->assertFalse((bool) $value->is_retraction);
        $this->assertSame(1000, $value->amount);

        $anti = $product->prices()->validAt('2026-07-01')->currentKnowledge()->sole();
        $this->assertTrue((bool) $anti->is_retraction);
        $this->assertNull($anti->amount);
    }

    // --- forceDeleteHistory: array_map(getKey) (UnwrapArrayMap) + starting dispatch (MethodCallRemoval) ---

    public function test_force_delete_returns_scalar_ids_and_dispatches_starting(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');
        Event::fake([TemporalHardDeleteStarting::class, TemporalHardDeleteCommitted::class]);

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $result = $product->prices()->forceDeleteHistory();

        $this->assertSame(2, $result->deletedCount());
        $this->assertCount(2, $result->ids);
        foreach ($result->ids as $id) {
            $this->assertIsInt($id);
        }

        Event::assertDispatched(TemporalHardDeleteStarting::class);
        Event::assertDispatched(TemporalHardDeleteCommitted::class);
    }

    // --- run(): compact flag plumbing (Coalesce $compact ?? default) ---

    public function test_compact_false_disables_default_compaction(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        // Correcting the later segment back to 1000 would compact to a single row
        // by default. Explicit compact:false must keep two physical segments.
        $committed = $product->prices()->correct(['amount' => 1000], validFrom: '2026-06-01', compact: false);

        $this->assertFalse($committed->compacted);
        $this->assertCount(2, $product->prices()->currentKnowledge()->get());
    }

    // --- run(): compacted flag + compaction event (NotIdentical, IfNegation, MethodCallRemoval) ---

    public function test_compaction_sets_flag_and_dispatches_event(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');
        Event::fake([TemporalCompactionPerformed::class]);

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $committed = $product->prices()->correct(['amount' => 1000], validFrom: '2026-06-01');

        $this->assertTrue($committed->compacted);
        $this->assertCount(1, $product->prices()->currentKnowledge()->get());

        Event::assertDispatched(
            TemporalCompactionPerformed::class,
            fn (TemporalCompactionPerformed $event): bool => $event->segmentsBefore === 2 && $event->segmentsAfter === 1,
        );
    }

    public function test_compaction_event_fires_after_the_transaction_commits(): void
    {
        // Issue #46: TemporalCompactionPerformed used to dispatch inside the write
        // transaction (before assertNoCurrentOverlaps could roll it back), so a
        // rolled-back write recorded a compaction metric for work that never
        // committed. It must now fire post-commit — outside any open transaction.
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $observedLevel = null;
        Event::listen(TemporalCompactionPerformed::class, function () use (&$observedLevel): void {
            $observedLevel = DB::transactionLevel();
        });

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $product->prices()->correct(['amount' => 1000], validFrom: '2026-06-01');

        // 0 = the listener ran after commit. Under the old code it would be 1.
        $this->assertSame(0, $observedLevel);
    }

    public function test_non_compacting_write_leaves_flag_false_and_dispatches_no_event(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        Event::fake([TemporalCompactionPerformed::class]);

        $product = $this->makeProduct();

        $committed = $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01');

        $this->assertFalse($committed->compacted);
        Event::assertNotDispatched(TemporalCompactionPerformed::class);
    }

    // --- recordIdempotent/replayIdempotent round-trip (UnwrapArrayMap, ArrayItem, reloadById Identical) ---

    public function test_replay_reconstructs_closed_inserted_and_compacted(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $first = $product->prices()->correct(['amount' => 1000], validFrom: '2026-06-01', idempotencyKey: 'replay-1');

        $this->assertSame(2, $first->closedCount());
        $this->assertSame(1, $first->insertedCount());
        $this->assertTrue($first->compacted);

        // The replay must rebuild the exact same snapshot from the stored row ids
        // and compacted flag.
        $second = $product->prices()->correct(['amount' => 1000], validFrom: '2026-06-01', idempotencyKey: 'replay-1');

        $this->assertSame(2, $second->closedCount());
        $this->assertSame(1, $second->insertedCount());
        $this->assertTrue($second->compacted);
    }

    // --- modelKey() entity id derivation (LogicalOr/Negation/Ternary variants) ---

    public function test_idempotency_is_isolated_per_entity_key(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $productA = $this->makeProduct('A');
        $productB = $this->makeProduct('B');

        // Identical operation/params/key on two DISTINCT entities. If modelKey
        // collapses the entity id (e.g. returns get_debug_type), B replays A's
        // record and writes nothing.
        $productA->prices()->correct(['amount' => 1000], validFrom: '2026-01-01', idempotencyKey: 'shared');
        $productB->prices()->correct(['amount' => 1000], validFrom: '2026-01-01', idempotencyKey: 'shared');

        $this->assertSame(1, $productB->prices()->currentKnowledge()->count());
    }

    // --- captureRecordedAt drift boundary (GreaterThan > vs >=) ---

    public function test_clock_skew_exactly_at_tolerance_is_tolerated(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00.000000');

        $product = $this->makeProduct();
        // recorded_from is exactly 60_000 ms ahead of now() => drift == tolerance.
        // With `>` this is allowed (bumped); `>=` would reject it.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-06-01 12:01:00.000000',
        ]);

        $committed = $product->prices()->correct(['amount' => 1200], validFrom: '2026-02-01');

        $this->assertGreaterThan(0, $committed->insertedCount());
    }

    // --- entityLabel() concatenation (Concat / ConcatOperandRemoval) ---

    public function test_clock_skew_message_contains_full_entity_label(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2099-01-01 00:00:00',
        ]);

        $message = null;
        try {
            $product->prices()->correct(['amount' => 1200], '2026-02-01');
        } catch (TemporalDomainException $e) {
            $message = $e->getMessage();
        }

        $this->assertNotNull($message);
        // Pin the "<RelatedClass>#<entityKey>" order and both operands.
        $productKey = $product->getKey();
        $this->assertIsInt($productKey);
        $this->assertStringContainsString(ProductPrice::class.'#'.$productKey, $message);
    }

    // --- intConfig numeric-string handling (CastInt + Ternary, uncovered) ---

    public function test_numeric_string_tolerance_config_is_cast_to_int(): void
    {
        config(['bitemporal.writes.clock_skew_tolerance_ms' => '30000']);

        CarbonImmutable::setTestNow('2026-06-01 12:00:00.000000');

        $product = $this->makeProduct();
        // Drift of 45_000 ms exceeds a (string) "30000" tolerance once cast to int,
        // so the real code throws a domain exception. Dropping the cast yields a
        // TypeError; swapping the ternary branch returns the 60000 default and
        // never throws.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-06-01 12:00:45.000000',
        ]);

        $this->expectException(TemporalDomainException::class);
        $product->prices()->correct(['amount' => 1200], validFrom: '2026-02-01');
    }

    // --- deadlockRetryAttempts max(1, ...) floor (DecrementInteger to max(0, ...)) ---

    public function test_retry_attempts_floor_keeps_the_write_running(): void
    {
        config(['bitemporal.writes.deadlock_retry_attempts' => 0]);

        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1000], validFrom: '2026-01-01');

        // max(1, 0) guarantees the transaction runs once; max(0, 0) would skip it.
        $this->assertSame(1, $product->prices()->currentKnowledge()->count());
    }

    // --- forwardWindow nearest-future boundary (LogicalOrSingleSubExprNegation) ---

    public function test_forward_change_caps_at_the_nearest_future_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-08-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-08-01', 'valid_to' => '2026-10-01']);
        $this->insertPrice($product, ['amount' => 3000, 'valid_from' => '2026-10-01', 'valid_to' => null]);

        $product->prices()->changeEffectiveFrom(['amount' => 1500], '2026-06-01');

        // The change must stop at the *nearest* future boundary (Aug 1), leaving
        // the Aug-Oct segment intact. Picking the farthest boundary would have
        // overwritten it with 1500.
        $this->assertSame(1500, $product->prices()->validAt('2026-07-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(2000, $product->prices()->validAt('2026-09-01')->currentKnowledge()->sole()->amount);
    }

    // --- loadCurrentKnown usort (FunctionCallRemoval, InstanceOf_ false, ArrayItemRemoval) ---

    public function test_close_targets_the_row_matching_sorted_segment_order(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        // Insert OUT of valid_from order: the open Jun row gets the smaller id.
        $junRow = $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-06-01', 'valid_to' => null]);
        $janRow = $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);

        // Correcting the open Jun segment must close exactly the Jun row. Without
        // the canonical sort, currentModels[index] points at the wrong physical
        // row (closing the Jan row, or producing an overlap).
        $product->prices()->correct(['amount' => 2500], validFrom: '2026-06-01');

        $junKey = $junRow->getKey();
        $janKey = $janRow->getKey();
        $this->assertIsInt($junKey);
        $this->assertIsInt($janKey);
        $jun = ProductPrice::query()->findOrFail($junKey);
        $jan = ProductPrice::query()->findOrFail($janKey);

        $this->assertNotNull($jun->recorded_to, 'the Jun row should have been closed');
        $this->assertNull($jan->recorded_to, 'the Jan row should remain open');
    }

    // --- assertExpectedCurrent segment selection (InstanceOf_ / Ternary) ---

    public function test_expected_current_uses_the_window_from_segment(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        // The expectation must be checked against the segment at the window start
        // (2000 at Jul), not the head segment (1000). Using head() would throw.
        $committed = $product->prices()->correct(
            ['amount' => 2500],
            validFrom: '2026-07-01',
            expectedCurrentAttributes: ['amount' => 2000],
        );

        $this->assertGreaterThan(0, $committed->insertedCount());
        $this->assertSame(2500, $product->prices()->validAt('2026-08-15')->currentKnowledge()->sole()->amount);
    }

    public function test_expected_current_uses_head_when_window_from_is_null(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();

        // Empty timeline + open-from window => head() is consulted (null), so the
        // expectation fails with a conflict. The mutant calls at(null) and throws
        // a TypeError instead.
        $this->expectException(TemporalWriteConflictException::class);
        $product->prices()->correct(
            ['amount' => 1500],
            expectedCurrentAttributes: ['amount' => 1000],
        );
    }

    public function test_expected_current_missing_segment_raises_conflict_not_error(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();

        // Empty timeline, dated window => at() returns null => actual is [] and the
        // expectation fails with a conflict. The mutant reads ->attributes on null.
        $this->expectException(TemporalWriteConflictException::class);
        $product->prices()->correct(
            ['amount' => 500],
            validFrom: '2026-03-01',
            expectedCurrentAttributes: ['amount' => 999],
        );
    }

    // --- newQuery null-dimension scoping (MethodCallRemoval whereNull) ---

    public function test_null_dimension_write_is_scoped_to_null_rows(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->seedDimensioned($product, ['amount' => 500, 'currency' => null, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->seedDimensioned($product, ['amount' => 1000, 'currency' => 'GBP', 'valid_from' => '2026-01-01', 'valid_to' => null]);

        // Writing the null-currency timeline must load only the null-currency row.
        // Dropping whereNull() pulls the GBP row in too and overlaps.
        $product->dimensionedPrices()
            ->forDimensions(['currency' => null])
            ->correct(['amount' => 600], validFrom: '2026-03-01');

        $this->assertSame(
            600,
            $product->dimensionedPrices()->forDimensions(['currency' => null])->validAt('2026-04-01')->currentKnowledge()->sole()->amount,
        );
        // The GBP timeline is untouched.
        $this->assertCount(1, $product->dimensionedPrices()->forDimensions(['currency' => 'GBP'])->currentKnowledge()->get());
        $this->assertSame(
            1000,
            $product->dimensionedPrices()->forDimensions(['currency' => 'GBP'])->validAt('2026-04-01')->currentKnowledge()->sole()->amount,
        );
    }
}
