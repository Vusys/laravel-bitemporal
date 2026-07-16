<?php

declare(strict_types=1);

return [
    'columns' => [
        'valid_from' => 'valid_from',
        'valid_to' => 'valid_to',
        'recorded_from' => 'recorded_from',
        'recorded_to' => 'recorded_to',
        'is_retraction' => 'is_retraction',
    ],

    'spells' => [
        'bounds' => '[)',
        'null_end_means_infinity' => true,
        'timezone' => 'UTC',
        'allow_zero_length' => false,
    ],

    'guards' => [
        'enabled' => true,
    ],

    'database' => [
        // Opt in to PostgreSQL native range columns (tstzrange) + a
        // database-enforced EXCLUDE USING gist overlap constraint. PG only;
        // the composite-index path stays the default everywhere else.
        'prefer_native_ranges' => false,

        // Whether the EnableBitemporalExtensions migration may run
        // CREATE EXTENSION IF NOT EXISTS btree_gist. Set false on locked-down
        // hosts and install the extension out of band.
        'create_postgres_extensions' => true,
    ],

    'audit_log' => [
        'enabled' => false,
        'table' => 'temporal_audit_log',
        'connection' => null,
    ],

    'writes' => [
        'compact_adjacent_segments' => true,
        'compaction_excluded_columns' => ['created_at', 'updated_at'],
        'future_validity_tolerance_ms' => 1000,
        'fire_eloquent_events' => false,
        'lock_strategy' => 'parent_row',
        'parent_lock_timeout_ms' => 5000,
        'advisory_lock_timeout_ms' => 5000,
        'deadlock_retry_attempts' => 1,
        'clock_skew_tolerance_ms' => 60000,
        'idempotency_window' => '7 days',
        'idempotency_auto_prune' => true,
    ],
];
