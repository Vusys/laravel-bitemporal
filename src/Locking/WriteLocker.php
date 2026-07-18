<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Locking;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

interface WriteLocker
{
    /**
     * Acquire the write lock for a temporal entity and dimension tuple. Blocks
     * until the lock is held or throws TemporalWriteConflictException on
     * timeout / deadlock. The lock is released when the surrounding
     * transaction commits or rolls back.
     *
     * $connection is the connection the write transaction actually runs on.
     * The lock MUST be taken there, not on the entity's own connection: when
     * the temporal-rows model and its parent entity resolve to different
     * connections, a lock on the entity session no longer serializes the
     * writes, and on PostgreSQL a pg_advisory_xact_lock on a connection with no
     * open transaction degrades to no locking at all (issue #67). Defaults to
     * the entity's connection when omitted.
     *
     * @param  array<string, mixed>  $dimensions
     */
    public function lockFor(Model $entity, array $dimensions, int $timeoutMs = 5000, ?ConnectionInterface $connection = null): WriteLockHandle;
}
