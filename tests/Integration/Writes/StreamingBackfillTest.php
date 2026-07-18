<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Vusys\Bitemporal\Events\TemporalBackfillCommitted;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class StreamingBackfillTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function nonOverlappingMonths(int $count): \Generator
    {
        // Adjacent, non-overlapping monthly valid periods — never contend.
        for ($i = 0; $i < $count; $i++) {
            $from = CarbonImmutable::parse('2020-01-01')->addMonths($i);
            yield [
                'attributes' => ['amount' => 1000 + $i],
                'valid_from' => $from->format('Y-m-d'),
                'valid_to' => $from->addMonth()->format('Y-m-d'),
                'recorded_from' => '2020-01-01',
                'recorded_to' => null,
            ];
        }
    }

    public function test_streaming_import_inserts_everything_in_chunks(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $product = $this->makeProduct();

        Event::fake([TemporalBackfillCommitted::class]);

        $committed = $product->prices()->backfill()
            ->stream(chunkSize: 25)
            ->timeline($this->nonOverlappingMonths(120));

        $this->assertSame(120, ProductPrice::query()->count());
        // The returned event is the final aggregate (chunkIndex null).
        $this->assertNull($committed->chunkIndex);
        $this->assertSame(120, $committed->insertedCount());

        // 120 / 25 = 5 chunk events (indexes 0..4) + 1 aggregate (null).
        Event::assertDispatchedTimes(TemporalBackfillCommitted::class, 6);
        Event::assertDispatched(
            TemporalBackfillCommitted::class,
            fn (TemporalBackfillCommitted $e): bool => $e->chunkIndex === 4,
        );
    }

    public function test_the_post_audit_catches_a_planted_cross_chunk_overlap(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $product = $this->makeProduct();

        // Two chunks that are each internally clean but overlap across the
        // chunk boundary (both cover Jan 2020, current-known).
        $rows = [
            ['attributes' => ['amount' => 1], 'valid_from' => '2020-01-01', 'valid_to' => '2020-02-01', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
            ['attributes' => ['amount' => 2], 'valid_from' => '2020-01-15', 'valid_to' => '2020-03-01', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
        ];

        try {
            $product->prices()->backfill()->stream(chunkSize: 1)->timeline($rows);
            $this->fail('the post-audit should have detected the cross-chunk overlap');
        } catch (TemporalOverlapException $exception) {
            // Both rows were inserted (each in its own chunk) before the audit ran.
            $this->assertCount(2, $exception->getInsertedIds());
            $this->assertSame(2, ProductPrice::query()->count());
        }
    }

    public function test_post_audit_catches_a_cross_chunk_overlap_among_closed_rows(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $product = $this->makeProduct();

        // Two closed-recorded rows (recorded_to set), one per chunk, that collide
        // on BOTH axes: overlapping valid periods AND overlapping recorded
        // periods. The old audit only loaded whereNull(recorded_to) rows on the
        // valid axis, so it never saw these — issue #47.
        $rows = [
            ['attributes' => ['amount' => 1], 'valid_from' => '2020-01-01', 'valid_to' => '2020-02-01', 'recorded_from' => '2019-01-01', 'recorded_to' => '2020-06-01'],
            ['attributes' => ['amount' => 2], 'valid_from' => '2020-01-15', 'valid_to' => '2020-03-01', 'recorded_from' => '2019-01-01', 'recorded_to' => '2020-06-01'],
        ];

        try {
            $product->prices()->backfill()->stream(chunkSize: 1)->importHistoricalKnowledge($rows);
            $this->fail('the post-audit should have detected the closed-row bitemporal overlap');
        } catch (TemporalOverlapException $exception) {
            $this->assertCount(2, $exception->getInsertedIds());
            $this->assertSame(2, ProductPrice::query()->count());
        }
    }

    public function test_post_audit_allows_superseded_beliefs_on_the_same_valid_window(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $product = $this->makeProduct();

        // Same valid window but disjoint recorded periods: a superseded belief
        // followed by the current one. This is legitimate bitemporal history and
        // must NOT be flagged — the audit tests both axes, not valid alone.
        $rows = [
            ['attributes' => ['amount' => 1], 'valid_from' => '2020-01-01', 'valid_to' => '2020-02-01', 'recorded_from' => '2019-01-01', 'recorded_to' => '2020-01-01'],
            ['attributes' => ['amount' => 2], 'valid_from' => '2020-01-01', 'valid_to' => '2020-02-01', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
        ];

        $committed = $product->prices()->backfill()->stream(chunkSize: 1)->importHistoricalKnowledge($rows);

        $this->assertSame(2, $committed->insertedCount());
        $this->assertSame(2, ProductPrice::query()->count());
    }

    public function test_post_audit_can_be_disabled(): void
    {
        config(['bitemporal.backfill.post_audit_check' => false]);
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $product = $this->makeProduct();

        $rows = [
            ['attributes' => ['amount' => 1], 'valid_from' => '2020-01-01', 'valid_to' => '2020-02-01', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
            ['attributes' => ['amount' => 2], 'valid_from' => '2020-01-15', 'valid_to' => '2020-03-01', 'recorded_from' => '2020-01-01', 'recorded_to' => null],
        ];

        // With the audit off the overlapping rows import without complaint.
        $committed = $product->prices()->backfill()->stream(chunkSize: 1)->timeline($rows);

        $this->assertSame(2, $committed->insertedCount());
        $this->assertSame(2, ProductPrice::query()->count());
    }

    public function test_non_streaming_timeline_is_unchanged(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $product = $this->makeProduct();

        $committed = $product->prices()->backfill()->timeline($this->nonOverlappingMonths(3));

        $this->assertNull($committed->chunkIndex);
        $this->assertSame(3, ProductPrice::query()->count());
    }
}
