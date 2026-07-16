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

    'observability' => [
        // Opt in by binding a TemporalMetrics implementation; NullMetrics (a
        // no-op) is bound by default, so nothing is emitted until you do.
        'metrics_enabled' => false,
    ],

    'backfill' => [
        // Rows per chunk for the streaming backfill path (stream()).
        'default_chunk_size' => 1000,
        // Run the scoped overlap audit after a streaming import completes.
        'post_audit_check' => true,

        // withoutIndexes(): recreate package overlap indexes with the engine's
        // online path — CREATE INDEX CONCURRENTLY (PostgreSQL) or
        // ALGORITHM=INPLACE, LOCK=NONE (MySQL/MariaDB). Set false to fall back
        // to a blocking rebuild on engine versions that reject the online path.
        'online_ddl' => true,

        // Silence the withoutIndexes() warning that SQLite rebuilds indexes with
        // a full table lock. Intended for the test suite.
        'suppress_sqlite_warning' => false,
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
