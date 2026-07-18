<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency\Mutation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Locking\AdvisoryLocker;
use Vusys\Bitemporal\Locking\ReleasableLock;
use Vusys\Bitemporal\Locking\TransactionLockHandle;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins the engine-branch survivors in
 * build/mutants/src__Locking__AdvisoryLocker.txt that only execute against a
 * real database: the GET_LOCK timeout-seconds math, the GET_LOCK/RELEASE_LOCK
 * bindings, the pg_advisory_xact_lock statement and the sqlite fallback.
 *
 * Each test skips unless the active connection matches, so the file is green
 * (or skipped) on all four engines.
 */
final class AdvisoryLockerEngineTest extends IntegrationTestCase
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $captured = [];

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    private function requireDriver(string ...$drivers): void
    {
        if (! in_array($this->driver(), $drivers, true)) {
            $this->markTestSkipped('Requires one of: '.implode(', ', $drivers).' (active: '.$this->driver().').');
        }
    }

    private function startCapturing(): void
    {
        $this->captured = [];
        DB::connection()->listen(function ($query): void {
            $this->captured[] = [$query->sql, $query->bindings];
        });
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    private function firstStatementContaining(string $needle): ?array
    {
        foreach ($this->captured as $entry) {
            if (str_contains($entry[0], $needle)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $dimensions
     */
    private function keyFor(Model $entity, array $dimensions): string
    {
        $method = new ReflectionMethod(AdvisoryLocker::class, 'key');
        $result = $method->invoke(new AdvisoryLocker, $entity, $dimensions);

        $this->assertIsString($result);

        return $result;
    }

    public function test_mysql_get_lock_acquires_and_releases_the_advisory_key(): void
    {
        $this->requireDriver('mysql', 'mariadb');

        $product = $this->makeProduct();
        $dimensions = ['region' => 'eu'];
        $key = $this->keyFor($product, $dimensions);

        $this->startCapturing();

        $handle = (new AdvisoryLocker)->lockFor($product, $dimensions, 5000);

        $this->assertInstanceOf(ReleasableLock::class, $handle);

        // The GET_LOCK statement ran with [key, seconds] — proves the SQL was
        // executed (MethodCallRemoval) and both bindings present (ArrayItemRemoval).
        $getLock = $this->firstStatementContaining('GET_LOCK');
        $this->assertNotNull($getLock);
        $this->assertSame('SELECT GET_LOCK(?, ?) AS acquired', $getLock[0]);
        $this->assertCount(2, $getLock[1]);
        $this->assertSame($key, $getLock[1][0]);

        $handle->release();

        // RELEASE_LOCK ran with exactly the key binding. A MethodCallRemoval on
        // the releaser drops the statement entirely (assertNotNull fails) and an
        // ArrayItemRemoval empties the bindings (assertSame([$key]) fails). We
        // assert on the captured SQL rather than probing IS_FREE_LOCK live,
        // because the advisory lock is connection-scoped and Testbench may
        // reconnect the underlying PDO between acquire and release, leaving the
        // live free/held state nondeterministic.
        $release = $this->firstStatementContaining('RELEASE_LOCK');
        $this->assertNotNull($release);
        $this->assertSame('SELECT RELEASE_LOCK(?)', $release[0]);
        $this->assertSame([$key], $release[1]);
    }

    /**
     * Drives the max(1, (int) ceil($timeoutMs / 1000)) conversion through
     * values chosen to separate every arithmetic mutant.
     *
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function timeoutCases(): iterable
    {
        // 0ms       -> max(1, 0) = 1     kills DecrementInteger (max 0 -> 0) and
        //                                 IncrementInteger (max 2 -> 2).
        yield 'floor of max keeps at least one second' => [0, 1];
        // 2100ms    -> ceil(2.1) = 3     kills RoundingFamily floor(2)/round(2),
        //                                 Division (*1000) and CastInt (3.0 != 3).
        yield 'ceil rounds 2.1s up to 3' => [2100, 3];
        // 1000ms    -> ceil(1.0) = 1     with /999 it would be ceil(1.001) = 2.
        yield 'exactly one second stays one' => [1000, 1];
        // 1001ms    -> ceil(1.001) = 2   with /1001 it would be ceil(1.0) = 1.
        yield 'just over one second rounds to two' => [1001, 2];
    }

    #[DataProvider('timeoutCases')]
    public function test_mysql_timeout_is_converted_to_ceil_seconds(int $timeoutMs, int $expectedSeconds): void
    {
        $this->requireDriver('mysql', 'mariadb');

        $product = $this->makeProduct();
        // Distinct dimensions per case so keys never collide across runs.
        $dimensions = ['t' => $timeoutMs];

        $this->startCapturing();

        $handle = (new AdvisoryLocker)->lockFor($product, $dimensions, $timeoutMs);

        $getLock = $this->firstStatementContaining('GET_LOCK');
        $this->assertNotNull($getLock);
        // assertSame is strict: a float (CastInt removal) fails against an int.
        $this->assertSame($expectedSeconds, $getLock[1][1]);

        $handle->release();
    }

    public function test_mysql_default_timeout_is_five_seconds(): void
    {
        $this->requireDriver('mysql', 'mariadb');

        $product = $this->makeProduct();

        $this->startCapturing();

        // No timeout argument -> the 5000 default -> ceil(5.0) = 5. Kills the
        // IncrementInteger default (5001 -> 6 seconds).
        $handle = (new AdvisoryLocker)->lockFor($product, ['region' => 'default']);

        $getLock = $this->firstStatementContaining('GET_LOCK');
        $this->assertNotNull($getLock);
        $this->assertSame(5, $getLock[1][1]);

        $handle->release();
    }

    public function test_pgsql_runs_transaction_scoped_advisory_lock(): void
    {
        $this->requireDriver('pgsql');

        $product = $this->makeProduct();
        $dimensions = ['region' => 'eu'];
        $key = $this->keyFor($product, $dimensions);

        $this->startCapturing();

        // pg_advisory_xact_lock is only meaningful inside a transaction; the
        // locker now refuses to run outside one (issue #67), so acquire within
        // an explicit transaction that mirrors the writer's real usage.
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $handle = (new AdvisoryLocker)->lockFor($product, $dimensions, 5000, $connection);

            $this->assertInstanceOf(TransactionLockHandle::class, $handle);

            // The pg_advisory_xact_lock(hashtextextended(?, 0)) statement ran with
            // the key binding. Kills MethodCallRemoval (no statement) and
            // ArrayItemRemoval (bindings -> []).
            $statement = $this->firstStatementContaining('pg_advisory_xact_lock');
            $this->assertNotNull($statement);
            $this->assertStringContainsString('hashtextextended(?, 0)', $statement[0]);
            $this->assertSame([$key], $statement[1]);
        } finally {
            $connection->rollBack();
        }
    }

    public function test_pgsql_refuses_to_lock_outside_a_transaction(): void
    {
        $this->requireDriver('pgsql');

        $product = $this->makeProduct();

        // Outside a transaction pg_advisory_xact_lock releases at statement end
        // and SET LOCAL is a no-op — the "lock" would give zero mutual
        // exclusion. The locker must refuse rather than lock nothing (#67).
        $this->expectException(TemporalWriteConflictException::class);
        $this->expectExceptionMessageMatches('/outside a transaction/');

        (new AdvisoryLocker)->lockFor($product, ['region' => 'eu'], 5000);
    }

    public function test_sqlite_falls_back_to_a_parent_row_lock(): void
    {
        $this->requireDriver('sqlite');

        $product = $this->makeProduct();

        $handle = (new AdvisoryLocker)->lockFor($product, ['region' => 'eu'], 5000);

        // The else branch returns a ParentRowLocker handle, tagged 'parent_row'.
        $this->assertInstanceOf(TransactionLockHandle::class, $handle);
        $this->assertSame('parent_row', $handle->strategy());
    }
}
