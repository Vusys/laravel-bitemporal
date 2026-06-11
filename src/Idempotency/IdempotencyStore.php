<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Idempotency;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;

/**
 * Reads and writes the temporal_idempotency_keys table. Keys are namespaced
 * per (model, entity); the parameters hash detects "same key, different
 * parameters" replays without storing the raw payload.
 */
final class IdempotencyStore
{
    private const string TABLE = 'temporal_idempotency_keys';

    /**
     * Canonicalise the write inputs to a stable sha256 hash. Keys are sorted at
     * every depth so semantically identical payloads hash identically.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function hash(array $inputs): string
    {
        return hash('sha256', (string) json_encode(self::canonicalise($inputs)));
    }

    /**
     * Look up a prior result for this key within the retention window.
     *
     * Returns the stored snapshot on a hit, or null on a miss. Throws when the
     * key exists with a different parameters hash.
     *
     * @return array{recorded_at: string, closed_ids: array<int, int|string>, inserted_ids: array<int, int|string>, compacted: bool}|null
     */
    public function find(ConnectionInterface $connection, string $model, ?string $entityType, string $entityId, string $key, string $hash, string $window): ?array
    {
        $row = $connection->table(self::TABLE)
            ->where('model', $model)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('key', $key)
            ->where('created_at', '>=', CarbonImmutable::now()->sub($window)->format('Y-m-d H:i:s.u'))
            ->first();

        if ($row === null) {
            return null;
        }

        if (! property_exists($row, 'parameters_hash') || $row->parameters_hash !== $hash) {
            throw TemporalWriteConflictException::idempotencyKeyReused($key);
        }

        $decoded = json_decode((string) ($row->result_snapshot ?? '{}'), true);

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'recorded_at' => is_string($decoded['recorded_at'] ?? null) ? $decoded['recorded_at'] : '',
            'closed_ids' => $this->keyList($decoded['closed_ids'] ?? []),
            'inserted_ids' => $this->keyList($decoded['inserted_ids'] ?? []),
            'compacted' => (bool) ($decoded['compacted'] ?? false),
        ];
    }

    /**
     * @param  array{recorded_at: string, closed_ids: array<int, int|string>, inserted_ids: array<int, int|string>, compacted: bool}  $snapshot
     */
    public function store(ConnectionInterface $connection, string $model, ?string $entityType, string $entityId, string $key, string $operation, string $hash, array $snapshot): void
    {
        $connection->table(self::TABLE)->insert([
            'key' => $key,
            'model' => $model,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation,
            'parameters_hash' => $hash,
            'result_snapshot' => (string) json_encode($snapshot),
            'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * @param  array<int, mixed>|mixed  $value
     * @return array<int, int|string>
     */
    private function keyList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $id): bool => is_int($id) || is_string($id)));
    }

    private static function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalise($item);
        }

        if (array_is_list($value)) {
            return $canonical;
        }

        ksort($canonical);

        return $canonical;
    }
}
