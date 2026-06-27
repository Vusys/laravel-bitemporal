<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class PruneIdempotencyKeysMutationTest extends IntegrationTestCase
{
    /**
     * @param  array<int, array{key: string, created_at: string}>  $rows
     */
    private function seedKeys(array $rows, ?string $connection = null): void
    {
        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'key' => $row['key'], 'model' => 'X', 'entity_type' => null, 'entity_id' => '1',
                'operation' => 'correct', 'parameters_hash' => str_repeat('a', 64),
                'result_snapshot' => '{}', 'created_at' => $row['created_at'],
            ];
        }

        DB::connection($connection)->table('temporal_idempotency_keys')->insert($records);
    }

    private function daysAgo(int $days): string
    {
        return CarbonImmutable::now()->subDays($days)->format('Y-m-d H:i:s.u');
    }

    public function test_window_comes_from_config_and_report_counts_deletions(): void
    {
        config(['bitemporal.writes.idempotency_window' => '30 days']);

        $this->seedKeys([
            ['key' => 'ancient', 'created_at' => $this->daysAgo(100)],
            ['key' => 'recent', 'created_at' => $this->daysAgo(10)],
            ['key' => 'fresh', 'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u')],
        ]);

        $exit = Artisan::call('bitemporal:prune-idempotency-keys');

        $this->assertSame(0, $exit);
        // With a 30-day window only the 100-day-old row is expired. If the window
        // ternary collapses to "7 days" the 10-day-old row would go too.
        $this->assertStringContainsString('Pruned 1 expired idempotency key(s).', Artisan::output());
        $this->assertSame(0, DB::table('temporal_idempotency_keys')->where('key', 'ancient')->count());
        $this->assertSame(1, DB::table('temporal_idempotency_keys')->where('key', 'recent')->count());
        $this->assertSame(1, DB::table('temporal_idempotency_keys')->where('key', 'fresh')->count());
    }

    public function test_named_connection_option_targets_that_connection(): void
    {
        config(['database.connections.audit2' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        Schema::connection('audit2')->create('temporal_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->string('model');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('operation');
            $table->string('parameters_hash');
            $table->text('result_snapshot');
            $table->dateTime('created_at', 6)->nullable();
        });

        // Old row on the named connection; the default connection stays empty.
        $this->seedKeys([['key' => 'ancient', 'created_at' => $this->daysAgo(100)]], 'audit2');

        $exit = Artisan::call('bitemporal:prune-idempotency-keys', ['--connection' => 'audit2']);

        $this->assertSame(0, $exit);
        // If the connection resolves to null, the delete hits the (empty) default
        // connection and the audit2 row survives.
        $this->assertSame(0, DB::connection('audit2')->table('temporal_idempotency_keys')->where('key', 'ancient')->count());
    }

    public function test_empty_connection_option_falls_back_to_the_default_connection(): void
    {
        $this->seedKeys([['key' => 'ancient', 'created_at' => $this->daysAgo(100)]]);

        $exit = Artisan::call('bitemporal:prune-idempotency-keys', ['--connection' => '']);

        // An empty string must collapse to null (default connection), not be passed
        // through as a connection name (which would not be configured).
        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('temporal_idempotency_keys')->where('key', 'ancient')->count());
    }
}
