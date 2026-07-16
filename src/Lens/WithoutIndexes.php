<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Database\Grammar\IndexDescriptor;
use Vusys\Bitemporal\Database\Grammar\IndexRegistry;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalOnlineDdlException;

/**
 * Drops a temporal model's package-managed overlap indexes for the duration of a
 * callback (a bulk historical load), then recreates them engine-appropriately on
 * exit. Custom application indexes are untouched; the PostgreSQL EXCLUDE USING
 * gist constraint stays enforced throughout (it is a constraint, not one of the
 * plain indexes this drops).
 *
 * Reentrant per (connection, table): nested calls for the same table are a no-op
 * that just run the callback; different tables compose. Resolved as a singleton
 * so the reentrancy state is shared across a request/worker.
 *
 * WARNING: while a table's overlap index is dropped, the writer's current-known
 * lookups (recorded_to IS NULL) degrade to full scans, so concurrent routine
 * writes to that table are slow (correctness still holds via the advisory lock +
 * post-audit). Quiesce concurrent writers if that matters — see the streaming
 * backfill's quiesceEntities option.
 */
final class WithoutIndexes
{
    /**
     * @var array<string, array{depth: int, dropped: array<int, IndexDescriptor>, connection: ConnectionInterface, table: string}>
     */
    private array $active = [];

    public function __construct(private readonly IndexRegistry $registry) {}

    /**
     * @template TReturn
     *
     * @param  class-string<Model>  $model
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $model, Closure $callback): mixed
    {
        $instance = new $model;

        if (! method_exists($instance, 'temporalMetadata')) {
            throw new TemporalInvalidSpellException($model.' is not a temporal model');
        }

        $connection = $instance->getConnection();
        $table = $instance->getTable();
        $key = ($connection->getName() ?? 'default').':'.$table;

        if ($connection->transactionLevel() !== 0) {
            throw TemporalOnlineDdlException::insideTransaction($connection->transactionLevel());
        }

        // Nested call for the same table: just run through, no DDL.
        if (isset($this->active[$key])) {
            $this->active[$key]['depth']++;

            try {
                return $callback();
            } finally {
                $this->active[$key]['depth']--;
            }
        }

        $dropped = $this->registry->existing($connection, $table);
        foreach ($dropped as $index) {
            $this->registry->drop($connection, $table, $index);
        }

        $this->active[$key] = [
            'depth' => 1,
            'dropped' => $dropped,
            'connection' => $connection,
            'table' => $table,
        ];

        try {
            return $callback();
        } finally {
            // Clear the frame before recreating so a recreate failure cannot
            // wedge the key for the rest of the request.
            unset($this->active[$key]);

            foreach ($dropped as $index) {
                $this->registry->recreate($connection, $table, $index);
            }
        }
    }
}
