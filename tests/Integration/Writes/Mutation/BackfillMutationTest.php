<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;
use Vusys\Bitemporal\Backfill\BackfillValidator;
use Vusys\Bitemporal\Backfill\BitemporalBackfill;
use Vusys\Bitemporal\Events\TemporalBackfillCommitted;
use Vusys\Bitemporal\Events\TemporalBackfillStarting;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Locking\ParentRowLocker;
use Vusys\Bitemporal\Locking\TransactionLockHandle;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Locking\WriteLockHandle;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Bitemporal\TimelineSegment;

/**
 * Pins surviving mutants in build/mutants/src__Backfill__BitemporalBackfill.txt
 * and src__Backfill__BackfillValidator.txt.
 */
final class BackfillMutationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ===================== BitemporalBackfill =====================

    public function test_backfill_takes_the_write_lock(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $spy = new class implements WriteLocker
        {
            public int $calls = 0;

            public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000, ?ConnectionInterface $connection = null): WriteLockHandle
            {
                $this->calls++;

                return new TransactionLockHandle('spy');
            }
        };
        app()->instance(WriteLocker::class, $spy);

        $product = $this->makeProduct();
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);

        // MethodCallRemoval: dropping lockFor() leaves calls at 0.
        $this->assertSame(1, $spy->calls);
    }

    public function test_backfill_dispatches_starting_and_committed_events(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        Event::fake([TemporalBackfillStarting::class, TemporalBackfillCommitted::class]);

        $product = $this->makeProduct();
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);

        // Two separate MethodCallRemoval mutants drop each dispatch.
        Event::assertDispatched(TemporalBackfillStarting::class);
        Event::assertDispatched(TemporalBackfillCommitted::class);
    }

    public function test_backfill_casts_retraction_flag_to_bool(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // is_retraction supplied as int 1. Real code (bool)-casts it before the
        // strictly-typed TimelineSegment bool param; the CastBool mutant passes the
        // raw int and TypeErrors. attributes empty so the anti-row validates.
        $product->prices()->backfill()->timeline([
            ['attributes' => [], 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01', 'recorded_from' => '2026-01-01', 'recorded_to' => null, 'is_retraction' => 1],
        ]);

        $row = ProductPrice::query()->sole();
        $this->assertTrue((bool) $row->is_retraction);
    }

    public function test_backfill_stamps_dimension_columns_on_each_row(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $product->dimensionedPrices()->forDimensions(['currency' => 'GBP'])->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);

        // Foreach_ over [] and the inner MethodCallRemoval both leave currency null.
        $row = ProductPriceWithDimensions::query()->sole();
        $this->assertSame('GBP', $row->currency);
    }

    public function test_backfill_persists_the_valid_to_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => '2026-03-01', 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);

        // MethodCallRemoval on setAttribute(validTo) leaves valid_to null.
        $row = ProductPrice::query()->sole();
        $this->assertNotNull($row->valid_to);
        $this->assertTrue($row->valid_to->equalTo(CarbonImmutable::parse('2026-03-01')));
    }

    public function test_backfill_accepts_a_carbon_instant_date_value(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // date() must keep its CarbonInterface branch. The InstanceOf_ mutant turns
        // it into `false`, falls through past is_string, and throws on the Carbon
        // value instead of accepting it.
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => CarbonImmutable::parse('2026-01-01'), 'valid_to' => null, 'recorded_from' => CarbonImmutable::parse('2026-01-01'), 'recorded_to' => null],
        ]);

        $row = ProductPrice::query()->sole();
        $this->assertTrue($row->valid_from->equalTo(CarbonImmutable::parse('2026-01-01')));
    }

    public function test_backfill_rejects_an_uninterpretable_date_value(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // date() must throw on a non-null, non-Carbon, non-string value. The
        // uncovered Throw_ mutant drops the throw and returns null instead.
        $this->expectException(TemporalInvalidSpellException::class);
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => 12345, 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);
    }

    public function test_backfill_applies_the_configured_timezone_to_string_dates(): void
    {
        config(['bitemporal.spells.timezone' => 'America/New_York']);
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-06-01 00:00:00', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);

        // timezone() must return the configured string, not 'UTC'. The Ternary swap
        // returns 'UTC', so the stored wall-clock string would not be shifted.
        $raw = DB::table('product_price_versions')->value('valid_from');

        if (! is_string($raw)) {
            $this->fail('expected a string valid_from value');
        }

        // PostgreSQL drops the trailing .000000, so compare engine-agnostically:
        // parsing still proves the America/New_York wall-clock shift (killing the
        // Ternary mutant that would leave the stored value at UTC midnight).
        $this->assertSame(
            '2026-05-31 20:00:00',
            CarbonImmutable::parse($raw)->format('Y-m-d H:i:s'),
        );
    }

    public function test_import_historical_knowledge_is_public(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // PublicVisibility: calling importHistoricalKnowledge() from outside the
        // class must work; the protected mutant fatals.
        $committed = $product->prices()->backfill()->importHistoricalKnowledge([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);

        $this->assertSame(1, $committed->insertedCount());
    }

    public function test_constructor_rejects_a_non_temporal_related_model(): void
    {
        // Product has no temporalMetadata(). The constructor must throw with a
        // "<Class> is not a temporal model" message. Throw_ drops the throw (then
        // fatals on temporalMetadata()); the Concat mutants reorder/drop operands.
        $message = null;
        try {
            new BitemporalBackfill(new Product, new Product, [], new ParentRowLocker, resolve(Dispatcher::class));
            $this->fail('expected a TemporalInvalidSpellException');
        } catch (TemporalInvalidSpellException $e) {
            $message = $e->getMessage();
        } catch (Throwable) {
            $this->fail('expected a TemporalInvalidSpellException, got a different throwable');
        }

        $this->assertStringStartsWith(Product::class, $message);
        $this->assertStringContainsString('is not a temporal model', $message);
    }

    // ===================== BackfillValidator =====================

    public function test_validator_rejects_a_missing_recorded_from(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // recorded_from null => Spell(null, ...). validateRow must throw. Kills the
        // two InstanceOf_/LogicalOr mutants (which deref null) and the Throw_ mutant.
        $this->expectException(TemporalInvalidSpellException::class);
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => null, 'recorded_to' => '2026-03-01'],
        ]);
    }

    public function test_validator_rejects_a_future_recorded_to(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // recorded_to in the future => throw. Kills the InstanceOf_ (`false &&`) and
        // the uncovered Throw_ on the future-recorded_to branch.
        $this->expectException(TemporalInvalidSpellException::class);
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => '2026-12-01'],
        ]);
    }

    public function test_validator_rejects_an_anti_row_carrying_attributes(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // is_retraction=true with non-empty attributes must throw. Kills the
        // LogicalAndAllSubExprNegation and the uncovered Throw_ on that branch.
        $this->expectException(TemporalInvalidSpellException::class);
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01', 'recorded_from' => '2026-01-01', 'recorded_to' => null, 'is_retraction' => true],
        ]);
    }

    public function test_validator_rejects_a_segment_without_a_recorded_spell(): void
    {
        // Direct call with recordedSpell = null (unreachable through the row API but
        // valid input). Kills the `!recorded instanceof Spell` -> `!true` mutant,
        // which then dereferences null.
        $segment = new TimelineSegment(Spell::between('2026-01-01', null), null, ['amount' => 1000]);

        $this->expectException(TemporalInvalidSpellException::class);
        (new BackfillValidator)->validate([$segment], CarbonImmutable::parse('2026-06-01'));
    }

    public function test_validator_accepts_a_well_formed_batch(): void
    {
        // Sanity: a valid two-row batch must pass validation (guards the negation
        // mutants from trivially "passing" by always throwing).
        $now = CarbonImmutable::parse('2026-06-01');
        $segments = [
            new TimelineSegment(Spell::between('2026-01-01', '2026-03-01'), Spell::between('2026-01-01', null), ['amount' => 1000]),
            new TimelineSegment(Spell::between('2026-03-01', null), Spell::between('2026-03-01', null), ['amount' => 1200]),
        ];

        (new BackfillValidator)->validate($segments, $now);

        $this->expectNotToPerformAssertions();
    }
}
