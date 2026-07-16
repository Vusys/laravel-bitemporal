<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables the PostgreSQL extensions the native-range path relies on. `btree_gist`
 * lets a single GiST index mix scalar `=` operators with range `&&` operators,
 * which the bitemporal EXCLUDE USING gist constraint requires.
 *
 * No-op on every non-PostgreSQL engine, and skippable on locked-down PostgreSQL
 * hosts by setting database.create_postgres_extensions = false (install the
 * extension out of band instead). down() is a deliberate no-op — dropping the
 * extension could break unrelated objects that came to depend on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (config('bitemporal.database.create_postgres_extensions', true) !== true) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        // Intentionally left blank: dropping btree_gist may break other objects.
    }
};
