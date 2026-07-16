<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Proves the PostgreSQL native-range path end to end: bitemporalPeriods(useRanges:
 * true) emits tstzrange columns and preventBitemporalOverlaps(useRanges: true)
 * emits a live EXCLUDE USING gist constraint that the database enforces. Gated to
 * PostgreSQL; skipped-with-reason elsewhere.
 */
final class PostgresRangeExclusionTest extends IntegrationTestCase
{
    private const string TABLE = 'range_price_versions';

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
            $table->bitemporalPeriods([], false, true);
            $table->preventBitemporalOverlaps(['product_id'], [], true);
        });
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::dropIfExists(self::TABLE);
        }

        parent::tearDown();
    }

    public function test_the_btree_gist_extension_is_installed_by_the_migration(): void
    {
        $present = DB::selectOne("SELECT 1 AS present FROM pg_extension WHERE extname = 'btree_gist'");

        $this->assertNotNull($present, 'EnableBitemporalExtensions should have created btree_gist');
    }

    public function test_the_range_columns_are_tstzrange(): void
    {
        foreach (['valid_period', 'recorded_period'] as $column) {
            $udt = DB::scalar(
                'SELECT udt_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                [self::TABLE, $column],
            );

            $this->assertSame('tstzrange', $udt, "{$column} should be a tstzrange column");
        }
    }

    public function test_the_exclusion_constraint_rejects_a_genuinely_overlapping_row(): void
    {
        $this->insertRange(1, '2024-01-01', '2024-06-01');

        // Same product, same (open) recorded period, overlapping valid period.
        $this->expectException(QueryException::class);
        $this->insertRange(1, '2024-03-01', '2024-09-01');
    }

    public function test_the_constraint_allows_non_overlapping_and_other_entities(): void
    {
        $this->insertRange(1, '2024-01-01', '2024-06-01');

        // Adjacent (half-open, so touching is not overlapping) — allowed.
        $this->insertRange(1, '2024-06-01', '2024-09-01');

        // A different product never contends.
        $this->insertRange(2, '2024-01-01', '2024-12-01');

        $this->assertSame(3, DB::table(self::TABLE)->count());
    }

    private function insertRange(int $productId, string $from, string $to): void
    {
        DB::insert(
            'INSERT INTO '.self::TABLE.' (product_id, valid_period, recorded_period) '
            ."VALUES (?, tstzrange(?, ?, '[)'), tstzrange(?, NULL, '[)'))",
            [$productId, $from, $to, '2024-01-01'],
        );
    }
}
