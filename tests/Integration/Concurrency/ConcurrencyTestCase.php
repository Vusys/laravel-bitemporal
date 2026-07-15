<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Base for tests that need genuine cross-session contention: they register a
 * second database connection pointing at the same database so one session can
 * hold a lock while another blocks on it.
 *
 * SQLite cannot express this — a second SQLite connection is a different
 * in-memory database, and lockForUpdate()/advisory locks are no-ops — so these
 * tests skip on SQLite with an explicit reason.
 */
abstract class ConcurrencyTestCase extends IntegrationTestCase
{
    protected const SECOND_CONNECTION = 'temporal_second';

    protected function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    protected function requireDriver(string ...$drivers): void
    {
        if (! in_array($this->driver(), $drivers, true)) {
            $this->markTestSkipped('Requires one of: '.implode(', ', $drivers).' (active: '.$this->driver().').');
        }
    }

    protected function skipUnlessContentionCapable(): void
    {
        if ($this->driver() === 'sqlite') {
            $this->markTestSkipped('Cross-session contention cannot be expressed on SQLite: a second connection is a separate in-memory database and FOR UPDATE / advisory locks are no-ops.');
        }
    }

    /**
     * Register (once) and return a second connection to the same database, with
     * its own PDO/session, so it contends with the default connection.
     */
    protected function secondConnection(): Connection
    {
        $default = DB::getDefaultConnection();

        /** @var array<string, mixed> $config */
        $config = config('database.connections.'.$default);

        config(['database.connections.'.self::SECOND_CONNECTION => $config]);
        DB::purge(self::SECOND_CONNECTION);

        return DB::connection(self::SECOND_CONNECTION);
    }

    protected function tearDown(): void
    {
        if (config('database.connections.'.self::SECOND_CONNECTION) !== null) {
            DB::purge(self::SECOND_CONNECTION);
        }

        parent::tearDown();
    }
}
