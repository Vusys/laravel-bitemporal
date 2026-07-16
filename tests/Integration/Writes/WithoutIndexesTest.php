<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Vusys\Bitemporal\Database\Grammar\IndexRegistry;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalOnlineDdlException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\IndexedPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\IndexedPriceTwo;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\RangeIndexedPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Covers TemporalLens::withoutIndexes(): it drops exactly the package overlap
 * indexes for the duration of the callback, leaves custom indexes and the
 * PostgreSQL EXCLUDE constraint intact, recreates faithfully on exit, is
 * reentrant, and refuses to run inside a transaction.
 */
final class WithoutIndexesTest extends IntegrationTestCase
{
    private const string CUSTOM_INDEX = 'indexed_prices_custom_currency_idx';

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitemporal.backfill.suppress_sqlite_warning' => true]);

        $this->buildIndexedTable('indexed_prices');
        $this->buildIndexedTable('indexed_prices_two');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('indexed_prices');
        Schema::dropIfExists('indexed_prices_two');
        Schema::dropIfExists('range_indexed_prices');

        parent::tearDown();
    }

    private function buildIndexedTable(string $table): void
    {
        Schema::dropIfExists($table);
        Schema::create($table, function ($t) use ($table): void {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->integer('amount')->nullable();
            $t->string('currency', 3)->nullable();
            $t->validPeriod();
            $t->recordedPeriod();
            $t->preventTemporalOverlaps(['product_id']);
            $t->preventBitemporalOverlaps(['product_id']);
            $t->index(['currency'], $table.'_custom_currency_idx');
        });
    }

    /**
     * @return array<int, string>
     */
    private function indexNames(string $table): array
    {
        return array_map(
            static fn (array $index): string => (string) $index['name'],
            Schema::getIndexes($table),
        );
    }

    private function packageIndexesPresent(string $table): bool
    {
        $names = $this->indexNames($table);

        return array_all(IndexRegistry::candidateNames($table), fn (string $candidate): bool => in_array($candidate, $names, true));
    }

    public function test_drops_only_package_indexes_and_recreates_them(): void
    {
        $this->assertTrue($this->packageIndexesPresent('indexed_prices'));
        $before = IndexRegistry::candidateNames('indexed_prices');

        $insideNames = [];
        TemporalLens::withoutIndexes(IndexedPrice::class, function () use (&$insideNames): void {
            $insideNames = $this->indexNames('indexed_prices');
        });

        // Inside the callback: package indexes gone, custom index survives.
        foreach ($before as $packageIndex) {
            $this->assertNotContains($packageIndex, $insideNames);
        }
        $this->assertContains(self::CUSTOM_INDEX, $insideNames);

        // After: package indexes restored, custom index still present.
        $this->assertTrue($this->packageIndexesPresent('indexed_prices'));
        $this->assertContains(self::CUSTOM_INDEX, $this->indexNames('indexed_prices'));
    }

    public function test_recreated_indexes_keep_their_exact_columns(): void
    {
        $columnsBefore = $this->packageIndexColumns('indexed_prices');

        TemporalLens::withoutIndexes(IndexedPrice::class, fn (): null => null);

        $this->assertSame($columnsBefore, $this->packageIndexColumns('indexed_prices'));
        $this->assertNotEmpty($columnsBefore);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function packageIndexColumns(string $table): array
    {
        $candidates = IndexRegistry::candidateNames($table);
        $columns = [];
        foreach (Schema::getIndexes($table) as $index) {
            if (in_array($index['name'], $candidates, true)) {
                $columns[$index['name']] = array_map(strval(...), $index['columns']);
            }
        }
        ksort($columns);

        return $columns;
    }

    public function test_reentrant_same_model_drops_and_recreates_once(): void
    {
        $ddl = $this->captureDdl(function (): void {
            TemporalLens::withoutIndexes(IndexedPrice::class, function (): void {
                // Nested same-model call is a pass-through: no extra DDL.
                TemporalLens::withoutIndexes(IndexedPrice::class, function (): void {
                    $this->assertFalse($this->packageIndexesPresent('indexed_prices'));
                });
                $this->assertFalse($this->packageIndexesPresent('indexed_prices'));
            });
        });

        // Two package indexes → 2 drops + 2 creates, not doubled by the nesting.
        $this->assertSame(2, $this->countDrops($ddl));
        $this->assertSame(2, $this->countCreates($ddl));
        $this->assertTrue($this->packageIndexesPresent('indexed_prices'));
    }

    public function test_different_models_compose(): void
    {
        TemporalLens::withoutIndexes(IndexedPrice::class, function (): void {
            TemporalLens::withoutIndexes(IndexedPriceTwo::class, function (): void {
                $this->assertFalse($this->packageIndexesPresent('indexed_prices'));
                $this->assertFalse($this->packageIndexesPresent('indexed_prices_two'));
            });

            // Inner model restored, outer still dropped.
            $this->assertTrue($this->packageIndexesPresent('indexed_prices_two'));
            $this->assertFalse($this->packageIndexesPresent('indexed_prices'));
        });

        $this->assertTrue($this->packageIndexesPresent('indexed_prices'));
        $this->assertTrue($this->packageIndexesPresent('indexed_prices_two'));
    }

    public function test_throws_when_called_inside_a_transaction(): void
    {
        $this->expectException(TemporalOnlineDdlException::class);

        DB::transaction(function (): void {
            TemporalLens::withoutIndexes(IndexedPrice::class, fn (): null => null);
        });
    }

    public function test_rejects_a_non_temporal_model(): void
    {
        $this->expectException(TemporalInvalidSpellException::class);

        TemporalLens::withoutIndexes(Product::class, fn (): null => null);
    }

    public function test_sqlite_recreation_emits_a_full_lock_warning(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The full-table-lock warning is SQLite-specific.');
        }

        config(['bitemporal.backfill.suppress_sqlite_warning' => false]);
        $spy = Log::spy();

        TemporalLens::withoutIndexes(IndexedPrice::class, fn (): null => null);

        $spy->shouldHaveReceived('warning');
    }

    public function test_sqlite_introspection_skips_non_created_indexes(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite auto-index origins are engine-specific.');
        }

        // The inline UNIQUE constraint makes SQLite emit an auto-index whose
        // origin is not 'c' — introspection must skip it, not treat it as ours.
        DB::statement('create table origin_probe (id integer primary key, sku text unique)');

        $found = resolve(IndexRegistry::class)->existing(DB::connection(), 'origin_probe');

        $this->assertSame([], $found);

        Schema::drop('origin_probe');
    }

    public function test_recreation_runs_even_when_callback_throws(): void
    {
        try {
            TemporalLens::withoutIndexes(IndexedPrice::class, function (): never {
                throw new RuntimeException('boom');
            });
            $this->fail('the callback exception should propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertTrue($this->packageIndexesPresent('indexed_prices'));
    }

    public function test_recreate_failure_names_the_index(): void
    {
        $this->expectException(TemporalOnlineDdlException::class);
        $this->expectExceptionMessageMatches('/indexed_prices_(bi)?temporal_overlap/');

        // Dropping the table inside the callback makes the recreate DDL fail.
        TemporalLens::withoutIndexes(IndexedPrice::class, function (): void {
            Schema::drop('indexed_prices');
        });
    }

    public function test_table_without_package_indexes_is_a_clean_noop(): void
    {
        $ran = false;
        $ddl = $this->captureDdl(function () use (&$ran): void {
            // product_price_versions (the plain fixture table) has no package indexes.
            TemporalLens::withoutIndexes(ProductPrice::class, function () use (&$ran): void {
                $ran = true;
            });
        });

        $this->assertTrue($ran);
        $this->assertSame(0, $this->countDrops($ddl));
        $this->assertSame(0, $this->countCreates($ddl));
    }

    public function test_exclude_constraint_survives_and_stays_enforced(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('EXCLUDE USING gist is a PostgreSQL feature.');
        }

        Schema::dropIfExists('range_indexed_prices');
        Schema::create('range_indexed_prices', function ($t): void {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->bitemporalPeriods([], false, true);
            $t->preventBitemporalOverlaps(['product_id'], [], true);
        });

        $insert = static function (string $from, string $to): void {
            DB::insert(
                'INSERT INTO range_indexed_prices (product_id, valid_period, recorded_period) '
                ."VALUES (1, tstzrange(?, ?, '[)'), tstzrange(?, NULL, '[)'))",
                [$from, $to, '2024-01-01'],
            );
        };

        // The range table has only the EXCLUDE constraint (no plain package
        // index), so withoutIndexes() drops nothing and the constraint still
        // rejects an overlapping row inserted mid-callback. Boot guards are
        // suppressed because a range-mode table has no valid_from/valid_to.
        $this->expectException(QueryException::class);

        TemporalLens::withoutBootGuards(function () use ($insert): void {
            TemporalLens::withoutIndexes(RangeIndexedPrice::class, function () use ($insert): void {
                $insert('2024-01-01', '2024-06-01');
                $insert('2024-03-01', '2024-09-01'); // overlaps → EXCLUDE rejects
            });
        });
    }

    /**
     * Capture the SQL statements executed while $callback runs.
     *
     * @return array<int, string>
     */
    private function captureDdl(callable $callback): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower((string) $query->sql);
        });

        $callback();

        return $statements;
    }

    /**
     * @param  array<int, string>  $statements
     */
    private function countDrops(array $statements): int
    {
        return count(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'drop index'),
        ));
    }

    /**
     * @param  array<int, string>  $statements
     */
    private function countCreates(array $statements): int
    {
        return count(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'create index') || str_contains($sql, 'add index'),
        ));
    }
}
