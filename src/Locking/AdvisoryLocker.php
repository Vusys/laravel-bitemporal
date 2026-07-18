<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;

/**
 * Locks on an (entity, dimensions) advisory key rather than the parent row, so
 * different dimension tuples for the same entity do not contend. Uses
 * GET_LOCK on MySQL/MariaDB and a transaction-scoped advisory lock on
 * PostgreSQL. SQLite has no advisory locks, so it falls back to a parent-row
 * lock.
 *
 * The lock timeout is honoured on every engine: GET_LOCK takes the wait as its
 * second argument, and PostgreSQL's SET LOCAL lock_timeout bounds the advisory
 * wait (verified against PG 16 — lock_timeout applies to advisory-lock waits).
 * A wait that exceeds the budget surfaces as TemporalWriteConflictException so
 * the caller can retry or reconcile uniformly across engines.
 */
final class AdvisoryLocker implements WriteLocker
{
    /**
     * PostgreSQL SQLSTATE raised when a statement is cancelled because it waited
     * longer than lock_timeout.
     */
    private const string PG_LOCK_TIMEOUT = '55P03';

    public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000): WriteLockHandle
    {
        $connection = $entity->getConnection();
        $driver = $connection->getDriverName();
        $key = $this->key($entity, $dimensions);
        $label = $this->label($entity);

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $this->lockMysql($connection, $key, $label, $timeoutMs);
        }

        if ($driver === 'pgsql') {
            return $this->lockPostgres($connection, $key, $label, $timeoutMs);
        }

        // SQLite (and anything else without advisory locks): parent-row lock.
        return (new ParentRowLocker)->lockFor($entity, $dimensions, $timeoutMs);
    }

    private function lockMysql(Connection $connection, string $key, string $label, int $timeoutMs): WriteLockHandle
    {
        $seconds = max(1, (int) ceil($timeoutMs / 1000));
        $result = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$key, $seconds]);

        // GET_LOCK returns 1 when acquired, 0 on wait-timeout, NULL on error/kill.
        // The pre-existing code ignored the result and proceeded as if locked;
        // treating anything but 1 as a conflict is the correctness fix.
        $acquired = is_object($result) && property_exists($result, 'acquired') ? $result->acquired : null;

        if ((int) $acquired !== 1) {
            throw TemporalWriteConflictException::lockTimeout($label, $timeoutMs);
        }

        // Advisory locks are connection-scoped. Capture the PDO we locked on so
        // release() can refuse to run RELEASE_LOCK against a swapped-out session.
        $acquiredPdo = $connection->getPdo();

        return new ReleasableLock('advisory', static function () use ($connection, $key, $label, $acquiredPdo): void {
            if ($connection->getPdo() !== $acquiredPdo) {
                throw TemporalWriteConflictException::connectionChanged($label);
            }

            $connection->select('SELECT RELEASE_LOCK(?)', [$key]);
        });
    }

    private function lockPostgres(Connection $connection, string $key, string $label, int $timeoutMs): WriteLockHandle
    {
        // SET LOCAL keeps the timeout scoped to the surrounding transaction; the
        // value is an integer count of milliseconds, so it is safe to inline.
        $connection->statement("SET LOCAL lock_timeout = '".max(1, $timeoutMs)."ms'");

        try {
            $connection->statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        } catch (QueryException $exception) {
            if ($this->isPgLockTimeout($exception)) {
                throw TemporalWriteConflictException::lockTimeout($label, $timeoutMs);
            }

            throw $exception;
        }

        return new TransactionLockHandle('advisory');
    }

    private function isPgLockTimeout(QueryException $exception): bool
    {
        if ((string) $exception->getCode() === self::PG_LOCK_TIMEOUT) {
            return true;
        }

        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === self::PG_LOCK_TIMEOUT;
    }

    private function label(Model $entity): string
    {
        $id = $entity->getKey();
        $idString = is_int($id) || is_string($id) ? (string) $id : get_debug_type($id);

        return $entity->getMorphClass().'#'.$idString;
    }

    /**
     * Build a bounded advisory-lock key. Every discriminating component — table,
     * morph class, entity id, and the sorted dimensions — is folded into a
     * single fixed-length sha1 digest, so the total length is always 49 chars
     * (`temporal:` + 40). The previous form concatenated the raw components and
     * then substr()'d to 64, which — with a long FQCN or table name — could
     * shear the id/hash off the end, collapsing distinct tuples onto the same
     * MySQL GET_LOCK key (false contention, or worse, a real loss of mutual
     * exclusion between different entities). Hashing the whole composite makes
     * every component contribute regardless of length (issue #49).
     *
     * @param  array<string, mixed>  $dimensions
     */
    private function key(Model $entity, array $dimensions): string
    {
        $id = $entity->getKey();
        $idString = is_int($id) || is_string($id) ? (string) $id : '';

        $composite = implode('|', [
            $entity->getTable(),
            $entity->getMorphClass(),
            $idString,
            (string) json_encode($this->sortedKeys($dimensions)),
        ]);

        return 'temporal:'.substr(sha1($composite), 0, 40);
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
