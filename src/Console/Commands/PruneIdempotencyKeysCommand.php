<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes idempotency-key rows older than the configured retention window. Run
 * on a schedule (the service provider registers a daily run when
 * writes.idempotency_auto_prune is enabled).
 */
final class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'bitemporal:prune-idempotency-keys {--connection= : The database connection to prune}';

    protected $description = 'Prune expired temporal idempotency keys';

    public function handle(): int
    {
        $window = config('bitemporal.writes.idempotency_window', '7 days');
        $window = is_string($window) ? $window : '7 days';

        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $cutoff = CarbonImmutable::now()->sub($window)->format('Y-m-d H:i:s.u');

        $deleted = DB::connection($connection)
            ->table('temporal_idempotency_keys')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} expired idempotency key(s).");

        return self::SUCCESS;
    }
}
