<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

use Illuminate\Database\Eloquent\Model;

interface WriteLocker
{
    /**
     * Acquire the write lock for a temporal entity and dimension tuple. Blocks
     * until the lock is held or throws TemporalWriteConflictException on
     * timeout / deadlock. The lock is released when the surrounding
     * transaction commits or rolls back.
     *
     * @param  array<string, mixed>  $dimensions
     */
    public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000): WriteLockHandle;
}
