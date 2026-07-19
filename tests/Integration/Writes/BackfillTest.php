<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class BackfillTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_backfill_imports_historical_knowledge_with_explicit_recorded_periods(): void
    {
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

        $this->assertSame(2, ProductPrice::query()->count());
        // The belief held in February was 1000.
        $this->assertSame(1000, $product->prices()->validAt('2026-04-01')->knownAt('2026-02-01')->sole()->amount);
        // Current knowledge is 1200.
        $this->assertSame(1200, $product->prices()->validAt('2026-04-01')->currentKnowledge()->sole()->amount);
    }

    public function test_backfill_retraction_inserts_an_anti_row(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->backfill()->retraction([
            'valid_from' => '2024-04-01', 'valid_to' => '2024-05-01',
            'recorded_from' => '2024-05-15', 'recorded_to' => null,
        ]);

        $row = $product->prices()->validAt('2024-04-15')->currentKnowledge()->sole();
        $this->assertTrue($row->is_retraction);
        $this->assertNull($row->amount);
    }

    public function test_backfill_rejects_a_future_recorded_from(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $this->expectException(TemporalInvalidSpellException::class);

        $product->prices()->backfill()->timeline([
            [
                'attributes' => ['amount' => 1000],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-12-01', 'recorded_to' => null,
            ],
        ]);
    }

    public function test_backfill_rejects_bitemporally_overlapping_rows(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $this->expectException(TemporalOverlapException::class);

        $product->prices()->backfill()->timeline([
            [
                'attributes' => ['amount' => 1000],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-01-01', 'recorded_to' => null,
            ],
            [
                'attributes' => ['amount' => 1200],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-02-01', 'recorded_to' => null,
            ],
        ]);
    }

    public function test_backfill_detects_overlap_against_pre_existing_rows(): void
    {
        // Issue #71: the batch path validated only its own in-memory rows, so a
        // second backfill into a scope that already holds a row could insert a
        // bitemporally-overlapping row undetected.
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);
        $this->assertSame(1, ProductPrice::query()->count());

        try {
            $product->prices()->backfill()->timeline([
                ['attributes' => ['amount' => 1200], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
            ]);
            $this->fail('the scoped audit should have detected the overlap with the existing row');
        } catch (TemporalOverlapException $exception) {
            $this->assertNotEmpty($exception->getInsertedIds());
        }

        // The audit runs inside the batch transaction, so the conflicting insert
        // is rolled back atomically — the scope still holds only the first row.
        $this->assertSame(1, ProductPrice::query()->count());
    }

    public function test_backfill_into_a_scope_with_a_live_row_detects_overlap(): void
    {
        // Issue #71, the ticket's exact framing: a live row written through the
        // ordinary path, then a non-streaming backfill whose valid period matches
        // and whose recorded period overlaps. The scoped audit must reject it.
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // A live row: valid [Jan -> infinity), recorded [2020-01-01 -> infinity).
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2020-01-01', 'valid_to' => null,
            'recorded_from' => '2020-01-01', 'recorded_to' => null,
        ]);

        try {
            $product->prices()->backfill()->timeline([
                ['attributes' => ['amount' => 1200], 'valid_from' => '2020-01-01', 'valid_to' => null, 'recorded_from' => '2020-06-01', 'recorded_to' => null],
            ]);
            $this->fail('the scoped audit should have detected the overlap with the live row');
        } catch (TemporalOverlapException $exception) {
            $this->assertNotEmpty($exception->getInsertedIds());
        }

        // Rolled back atomically: only the original live row survives.
        $this->assertSame(1, ProductPrice::query()->count());
    }

    public function test_backfill_audit_treats_a_shared_microsecond_boundary_as_adjacency(): void
    {
        // A shared boundary is adjacency, not overlap, under half-open [). Two
        // separate backfills abutting at the exact microsecond must both persist
        // — guarding against driver microsecond truncation collapsing the shared
        // boundary into a spurious overlap the #71 audit would then reject.
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2020-01-01 00:00:00.000000', 'valid_to' => '2020-02-01 00:00:00.000000', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
        ]);

        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1200], 'valid_from' => '2020-02-01 00:00:00.000000', 'valid_to' => '2020-03-01 00:00:00.000000', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
        ]);

        $this->assertSame(2, ProductPrice::query()->count());

        // The shared microsecond boundary round-trips exactly on both sides.
        $earlier = ProductPrice::query()->orderBy('valid_from')->first();
        $later = ProductPrice::query()->orderByDesc('valid_from')->first();
        $this->assertNotNull($earlier);
        $this->assertNotNull($later);
        $this->assertSame('2020-02-01 00:00:00.000000', $earlier->valid_to?->format('Y-m-d H:i:s.u'));
        $this->assertSame('2020-02-01 00:00:00.000000', $later->valid_from->format('Y-m-d H:i:s.u'));
    }

    public function test_backfill_audit_flags_a_single_microsecond_overlap(): void
    {
        // The mirror of the adjacency case: a second backfill starting one
        // microsecond *before* the boundary genuinely overlaps. The audit must
        // catch it — proving it compares at microsecond resolution rather than
        // truncating to seconds (which would hide the overlap).
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2020-01-01 00:00:00.000000', 'valid_to' => '2020-02-01 00:00:00.000000', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
        ]);

        try {
            $product->prices()->backfill()->timeline([
                ['attributes' => ['amount' => 1200], 'valid_from' => '2020-01-31 23:59:59.999999', 'valid_to' => '2020-03-01 00:00:00.000000', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
            ]);
            $this->fail('a one-microsecond overlap should have been detected');
        } catch (TemporalOverlapException $exception) {
            $this->assertNotEmpty($exception->getInsertedIds());
        }

        $this->assertSame(1, ProductPrice::query()->count());
    }

    public function test_backfill_scoped_audit_can_be_disabled(): void
    {
        config(['bitemporal.backfill.post_audit_check' => false]);
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1000], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);

        // With the audit off the overlapping row imports without complaint.
        $product->prices()->backfill()->timeline([
            ['attributes' => ['amount' => 1200], 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => null],
        ]);

        $this->assertSame(2, ProductPrice::query()->count());
    }

    public function test_timeline_accepts_flat_value_columns(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // Domain columns supplied flat, as supersedeTimeline() accepts them —
        // no 'attributes' wrapper.
        $product->prices()->backfill()->timeline([
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-03-01', 'recorded_from' => '2026-01-01'],
            ['amount' => 1200, 'valid_from' => '2026-03-01', 'valid_to' => null, 'recorded_from' => '2026-01-01'],
        ]);

        $this->assertSame(1000, $product->prices()->validAt('2026-02-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(1200, $product->prices()->validAt('2026-04-01')->currentKnowledge()->sole()->amount);
    }

    public function test_timeline_stamps_recorded_from_as_now_when_omitted(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // A clean value history: no recorded_from, so the recorded axis is
        // stamped "now" and the rows land as current knowledge.
        $product->prices()->backfill()->timeline([
            ['amount' => 1000, 'valid_from' => '2023-01-01', 'valid_to' => '2024-01-01'],
            ['amount' => 1200, 'valid_from' => '2024-01-01', 'valid_to' => null],
        ]);

        $row = $product->prices()->validAt('2023-06-01')->currentKnowledge()->sole();
        $this->assertSame(1000, $row->amount);
        $this->assertTrue($row->recorded_from->equalTo(CarbonImmutable::parse('2026-06-01')));
        $this->assertSame(1200, $product->prices()->validAt('2026-01-01')->currentKnowledge()->sole()->amount);
    }
}
