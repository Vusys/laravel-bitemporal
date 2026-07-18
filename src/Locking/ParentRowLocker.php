<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;

/**
 * Default locker: takes a row lock on the parent (entity) row with
 * SELECT ... FOR UPDATE. All dimension tuples for the entity serialise on this
 * one lock. Released automatically at transaction end.
 */
final class ParentRowLocker implements WriteLocker
{
    public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000, ?ConnectionInterface $connection = null): WriteLockHandle
    {
        // Take the row lock on the connection the write transaction runs on so
        // the FOR UPDATE actually participates in that transaction (issue #67);
        // fall back to the entity's connection when the caller did not pass one.
        $connection ??= $entity->getConnection();

        $locked = $connection->table($entity->getTable())
            ->where($entity->getKeyName(), '=', $entity->getKey())
            ->lockForUpdate()
            ->first([$entity->getKeyName()]);

        if ($locked === null) {
            throw TemporalWriteConflictException::entityMissing($entity::class, $entity->getKey());
        }

        return new TransactionLockHandle('parent_row');
    }
}
