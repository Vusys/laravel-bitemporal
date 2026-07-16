<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Exceptions\TemporalUnsupportedDatabaseException;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * The native-range macros are PostgreSQL-only. On any other engine they refuse
 * to emit tstzrange columns / an EXCLUDE constraint rather than producing broken
 * DDL.
 */
final class RangeMacroGuardTest extends IntegrationTestCase
{
    public function test_range_periods_require_postgres(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('This guard is for the non-PostgreSQL engines.');
        }

        $this->expectException(TemporalUnsupportedDatabaseException::class);

        Schema::create('range_guard_probe', function ($table): void {
            $table->id();
            $table->bitemporalPeriods([], false, true);
        });
    }

    public function test_range_exclusion_requires_postgres(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('This guard is for the non-PostgreSQL engines.');
        }

        $this->expectException(TemporalUnsupportedDatabaseException::class);

        Schema::create('range_guard_probe', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->preventBitemporalOverlaps(['product_id'], [], true);
        });
    }
}
