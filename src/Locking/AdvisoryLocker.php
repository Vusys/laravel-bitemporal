<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

use Illuminate\Database\Eloquent\Model;

/**
 * Locks on an (entity, dimensions) advisory key rather than the parent row, so
 * different dimension tuples for the same entity do not contend. Uses
 * GET_LOCK on MySQL/MariaDB and a transaction-scoped advisory lock on
 * PostgreSQL. SQLite has no advisory locks, so it falls back to a parent-row
 * lock.
 */
final class AdvisoryLocker implements WriteLocker
{
    public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000): WriteLockHandle
    {
        $connection = $entity->getConnection();
        $driver = $connection->getDriverName();
        $key = $this->key($entity, $dimensions);

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $connection->select('SELECT GET_LOCK(?, ?) AS acquired', [$key, max(1, (int) ceil($timeoutMs / 1000))]);

            return new ReleasableLock('advisory', static function () use ($connection, $key): void {
                $connection->select('SELECT RELEASE_LOCK(?)', [$key]);
            });
        }

        if ($driver === 'pgsql') {
            $connection->statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);

            return new TransactionLockHandle('advisory');
        }

        // SQLite (and anything else without advisory locks): parent-row lock.
        return new ParentRowLocker()->lockFor($entity, $dimensions, $timeoutMs);
    }

    /**
     * @param  array<string, mixed>  $dimensions
     */
    private function key(Model $entity, array $dimensions): string
    {
        $hash = substr(sha1((string) json_encode($this->sortedKeys($dimensions))), 0, 24);

        $id = $entity->getKey();
        $idString = is_int($id) || is_string($id) ? (string) $id : '';

        $key = 'temporal:'.$entity->getTable().':'.$entity->getMorphClass().':'.$idString.':'.$hash;

        return substr($key, 0, 64);
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @return array<string, mixed>
     */
    private function sortedKeys(array $dimensions): array
    {
        ksort($dimensions);

        return $dimensions;
    }
}
