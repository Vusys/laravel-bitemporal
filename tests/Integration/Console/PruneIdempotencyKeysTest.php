<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class PruneIdempotencyKeysTest extends IntegrationTestCase
{
    public function test_it_prunes_rows_older_than_the_window(): void
    {
        DB::table('temporal_idempotency_keys')->insert([
            [
                'key' => 'old', 'model' => 'X', 'entity_type' => null, 'entity_id' => '1',
                'operation' => 'correct', 'parameters_hash' => str_repeat('a', 64),
                'result_snapshot' => '{}', 'created_at' => '2000-01-01 00:00:00.000000',
            ],
            [
                'key' => 'fresh', 'model' => 'X', 'entity_type' => null, 'entity_id' => '2',
                'operation' => 'correct', 'parameters_hash' => str_repeat('b', 64),
                'result_snapshot' => '{}', 'created_at' => now()->format('Y-m-d H:i:s.u'),
            ],
        ]);

        $exit = Artisan::call('bitemporal:prune-idempotency-keys');

        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('temporal_idempotency_keys')->where('key', 'old')->count());
        $this->assertSame(1, DB::table('temporal_idempotency_keys')->where('key', 'fresh')->count());
    }
}
