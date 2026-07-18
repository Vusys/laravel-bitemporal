<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\Mutation;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Idempotency\IdempotencyStore;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Exercises IdempotencyStore::hash()/store()/find() directly so the snapshot
 * decoding, key-list filtering, and canonical-hash mutants are pinned.
 */
final class IdempotencyStoreMutationTest extends IntegrationTestCase
{
    private function store(): IdempotencyStore
    {
        return new IdempotencyStore;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{recorded_at: string, closed_ids: array<int, int|string>, inserted_ids: array<int, int|string>, compacted: bool}|null
     */
    private function roundTrip(string $entityId, string $key, array $snapshot): ?array
    {
        $connection = DB::connection();
        $store = $this->store();

        // The DBs are persistent across runs, so a fixed (model, entity, key)
        // tuple would collide with leftover rows (find() then throws on the
        // differing parameters hash). Derive a unique entity id per call so the
        // round-trip can only ever see the row we just stored.
        $entityId = uniqid($entityId.'-', true);

        // parameters_hash is CHAR(64); PostgreSQL blank-pads short values, so a
        // 1-char hash would not round-trip ('h' !== 'h<63 spaces>') and find()
        // would raise a spurious conflict. Use a full-width 64-char hash.
        $hash = str_repeat('h', 64);

        // @phpstan-ignore-next-line — intentionally storing a loose snapshot.
        $store->store($connection, 'M', null, $entityId, $key, 'correct', $hash, $snapshot);

        return $store->find($connection, 'M', null, $entityId, $key, $hash, CarbonInterval::day());
    }

    public function test_canonical_hash_is_order_independent(): void
    {
        // Kills canonicalise LogicalNot, IfNegation (array_is_list), and the
        // ksort FunctionCallRemoval: payloads differing only in key order at any
        // depth must hash identically.
        $a = IdempotencyStore::hash(['b' => 2, 'a' => 1, 'nested' => ['z' => 1, 'a' => 2]]);
        $b = IdempotencyStore::hash(['a' => 1, 'b' => 2, 'nested' => ['a' => 2, 'z' => 1]]);

        $this->assertSame($a, $b);

        // Genuinely different params must still produce a different hash.
        $this->assertNotSame(
            IdempotencyStore::hash(['a' => 1]),
            IdempotencyStore::hash(['a' => 2]),
        );
    }

    public function test_key_lists_keep_only_scalars_and_stay_reindexed(): void
    {
        // Kills the keyList mutants (LogicalNot, LogicalOr/AND/negations,
        // UnwrapArrayFilter, UnwrapArrayValues) and the closed_ids/inserted_ids
        // Coalesce mutants. Non-scalar entries (2.5, null) sit in the middle so
        // dropping array_values leaves a key gap.
        $found = $this->roundTrip('1', 'k', [
            'recorded_at' => '2026-01-01 00:00:00.000000',
            'closed_ids' => [1, 2.5, 'abc', null],
            'inserted_ids' => [10],
            'compacted' => true,
        ]);

        $this->assertNotNull($found);
        $this->assertSame([1, 'abc'], $found['closed_ids']);
        $this->assertSame([10], $found['inserted_ids']);
        $this->assertSame(true, $found['compacted']);
        $this->assertSame('2026-01-01 00:00:00.000000', $found['recorded_at']);
    }

    public function test_missing_compacted_defaults_to_false(): void
    {
        // Kills the FalseValue mutant on `$decoded['compacted'] ?? false`.
        $found = $this->roundTrip('2', 'k', [
            'recorded_at' => '2026-01-01 00:00:00.000000',
            'closed_ids' => [],
            'inserted_ids' => [],
        ]);

        $this->assertNotNull($found);
        $this->assertSame(false, $found['compacted']);
    }

    public function test_compacted_is_cast_to_bool(): void
    {
        // Kills the CastBool mutant and the `false ?? ...` Coalesce mutant: a
        // truthy non-bool (1) must come back as a strict boolean true.
        $found = $this->roundTrip('3', 'k', [
            'recorded_at' => '2026-01-01 00:00:00.000000',
            'closed_ids' => [],
            'inserted_ids' => [],
            'compacted' => 1,
        ]);

        $this->assertNotNull($found);
        $this->assertSame(true, $found['compacted']);
    }

    public function test_unreadable_snapshot_fails_loudly_instead_of_replaying_as_a_miss(): void
    {
        // Issue #50: a claimed key whose stored result does not decode to the
        // expected object shape is NOT a miss. Returning null would re-run a write
        // that already committed once, double-applying it. find() must distinguish
        // "unreadable row" from "no row" and throw.
        //
        // result_snapshot is a JSON column (json_valid CHECK on MySQL/MariaDB/PG),
        // so a literally-invalid string can't be stored there. Use valid JSON that
        // decodes to a non-array (a JSON scalar) — the case the ! is_array() guard
        // actually defends against.
        $connection = DB::connection();
        $entityId = uniqid('corrupt-', true);
        $hash = str_repeat('h', 64);

        $connection->table('temporal_idempotency_keys')->insert([
            'key' => 'k',
            'model' => 'M',
            'entity_type' => null,
            'entity_id' => $entityId,
            'operation' => 'correct',
            'parameters_hash' => $hash,
            'result_snapshot' => '"corrupt"',
            'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
        ]);

        $this->expectException(TemporalWriteConflictException::class);

        $this->store()->find($connection, 'M', null, $entityId, 'k', $hash, CarbonInterval::day());
    }
}
