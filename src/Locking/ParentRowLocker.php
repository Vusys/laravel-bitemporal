<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;

/**
 * Default locker: takes a row lock on the parent (entity) row with
 * SELECT ... FOR UPDATE. All dimension tuples for the entity serialise on this
 * one lock. Released automatically at transaction end.
 */
final class ParentRowLocker implements WriteLocker
{
    public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000): WriteLockHandle
    {
        $locked = $entity->newQueryWithoutScopes()
            ->whereKey($entity->getKey())
            ->lockForUpdate()
            ->select($entity->getKeyName())
            ->first();

        if ($locked === null) {
            throw TemporalWriteConflictException::entityMissing($entity::class, $entity->getKey());
        }

        return new TransactionLockHandle('parent_row');
    }
}
