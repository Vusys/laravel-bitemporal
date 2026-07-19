<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Proves the EXCLUDE USING gist constraint prevents overlaps even when the
 * dimension value is NULL (issue #68). `NULL = NULL` yields NULL, so a plain
 * `dim WITH =` operator would never conflict two NULL-dimension rows; the
 * package emits `coalesce(dim::text, <sentinel>) WITH =` to give NULL-equal
 * semantics. Gated to PostgreSQL; skipped-with-reason elsewhere.
 */
final class PostgresRangeExclusionNullDimensionTest extends IntegrationTestCase
{
    private const string TABLE = 'range_dim_price_versions';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Native ranges + EXCLUDE USING gist are a PostgreSQL feature.');
        }

        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('currency', 3)->nullable();
            $table->bitemporalPeriods([], false, true);
            $table->preventBitemporalOverlaps(['product_id'], ['currency'], true);
        });
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::dropIfExists(self::TABLE);
        }

        parent::tearDown();
    }

    public function test_overlap_is_rejected_when_the_dimension_is_null(): void
    {
        $this->insertRange(1, null, '2024-01-01', '2024-06-01');

        // Same product, same (NULL) dimension, overlapping valid period: without
        // NULL-equal semantics the two NULL rows would slip past the constraint.
        $this->expectException(QueryException::class);
        $this->insertRange(1, null, '2024-03-01', '2024-09-01');
    }

    public function test_null_dimension_overlap_raises_the_exclusion_constraint_specifically(): void
    {
        $this->insertRange(1, null, '2024-01-01', '2024-06-01');

        // Pin the *reason* for the rejection: SQLSTATE 23P01 is exclusion_violation.
        // A weaker assertion (any QueryException) would false-pass on an incidental
        // error — this proves the NULL-equal coalesce fed the gist EXCLUDE.
        try {
            $this->insertRange(1, null, '2024-03-01', '2024-09-01');
            $this->fail('the overlapping NULL-dimension insert should have been rejected');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0] ?? null);
        }

        // The rejected insert left the timeline with just the original row.
        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_overlap_is_rejected_for_matching_non_null_dimensions(): void
    {
        $this->insertRange(1, 'GBP', '2024-01-01', '2024-06-01');

        $this->expectException(QueryException::class);
        $this->insertRange(1, 'GBP', '2024-03-01', '2024-09-01');
    }

    public function test_adjacent_null_dimension_segments_are_allowed(): void
    {
        // The NULL-equal coalesce must reject overlaps without over-rejecting:
        // two abutting NULL-dimension segments (the shape a normal correction
        // produces) share a boundary but do not overlap, so both must persist.
        $this->insertRange(1, null, '2024-01-01', '2024-06-01');
        $this->insertRange(1, null, '2024-06-01', '2024-12-01');

        $this->assertSame(2, DB::table(self::TABLE)->count());
    }

    public function test_null_dimension_rows_on_disjoint_recorded_periods_are_allowed(): void
    {
        // Same entity, same (NULL) dimension, overlapping valid period — but the
        // recorded periods are disjoint (a superseded belief closed at the moment
        // the new one opens). The EXCLUDE pairs both axes, so this is a legal
        // correction and must not be rejected by the NULL-equal dimension.
        $this->insertClosedRecordedRange(1, null, '2024-01-01', '2024-12-01', '2024-01-01', '2024-03-01');
        $this->insertRange(1, null, '2024-01-01', '2024-12-01', '2024-03-01');

        $this->assertSame(2, DB::table(self::TABLE)->count());
    }

    public function test_distinct_dimensions_never_contend(): void
    {
        $this->insertRange(1, null, '2024-01-01', '2024-12-01');

        // A different (non-null) currency, and a genuinely different currency,
        // overlap in valid time but differ on the dimension — both allowed.
        $this->insertRange(1, 'GBP', '2024-01-01', '2024-12-01');
        $this->insertRange(1, 'USD', '2024-01-01', '2024-12-01');

        $this->assertSame(3, DB::table(self::TABLE)->count());
    }

    private function insertRange(int $productId, ?string $currency, string $from, string $to, string $recordedFrom = '2024-01-01'): void
    {
        DB::insert(
            'INSERT INTO '.self::TABLE.' (product_id, currency, valid_period, recorded_period) '
            ."VALUES (?, ?, tstzrange(?, ?, '[)'), tstzrange(?, NULL, '[)'))",
            [$productId, $currency, $from, $to, $recordedFrom],
        );
    }

    private function insertClosedRecordedRange(int $productId, ?string $currency, string $from, string $to, string $recordedFrom, string $recordedTo): void
    {
        DB::insert(
            'INSERT INTO '.self::TABLE.' (product_id, currency, valid_period, recorded_period) '
            ."VALUES (?, ?, tstzrange(?, ?, '[)'), tstzrange(?, ?, '[)'))",
            [$productId, $currency, $from, $to, $recordedFrom, $recordedTo],
        );
    }
}
