<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Vusys\Bitemporal\Boot\BootGuard;

/**
 * Every temporal column the model resolves — at its configured (possibly
 * overridden) name — must exist on the table. This catches a fixture or
 * migration that renamed a column via a per-model override without adding it to
 * the schema.
 *
 * Introspection degrades gracefully: if the table is not migrated yet, or the
 * driver cannot be introspected, the guard passes rather than blocking boot
 * (BootLintForeignKeyIntrospectionUnavailable-style visibility is a separate
 * concern). recorded_* columns are skipped when the model opts out of
 * recorded-time tracking.
 */
final class BootGuardColumnsExist implements BootGuard
{
    public function check(Model $model): ?string
    {
        if (! method_exists($model, 'temporalColumnMap') || ! method_exists($model, 'tracksRecordedTime')) {
            return null;
        }

        $table = $model->getTable();

        try {
            $schema = Schema::connection($model->getConnectionName());

            if (! $schema->hasTable($table)) {
                return null; // not migrated (e.g. resolved before migrations) — cannot introspect
            }

            $tracksRecordedTime = $model->tracksRecordedTime();
            $missing = [];

            foreach ($model->temporalColumnMap() as $logical => $column) {
                if (! $tracksRecordedTime && ($logical === 'recorded_from' || $logical === 'recorded_to')) {
                    continue;
                }

                if (! $schema->hasColumn($table, $column)) {
                    $missing[] = $column;
                }
            }
        } catch (Throwable) {
            return null; // introspection unavailable on this engine — do not block boot
        }

        if ($missing === []) {
            return null;
        }

        return "temporal column(s) missing from table '{$table}': ".implode(', ', $missing)
            .'. Check the migration and any per-model column overrides.';
    }
}
