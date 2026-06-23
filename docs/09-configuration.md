# Configuration

Publish the config with `php artisan vendor:publish --tag=bitemporal-config` to get `config/bitemporal.php`. Every key has a default, so publishing is optional. Most values can also be overridden per model with a property (see [Defining models](03-defining-models.md)).

```php
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
        'deadlock_retry_attempts' => 1,
        'clock_skew_tolerance_ms' => 60000,
        'idempotency_window' => '7 days',
        'idempotency_auto_prune' => true,
    ],
];
```

## `columns`

The default period column names, applied to every temporal model. Override globally here, or per model with the `validFromColumn` / `validToColumn` / `recordedFromColumn` / `recordedToColumn` / `isRetractionColumn` properties.

## `spells`

- **`bounds`** — the canonical interval form. Spells are half-open `[from, to)`; this is the default and the recommended setting.
- **`null_end_means_infinity`** — a `null` upper bound means "open-ended / +infinity" rather than an unknown value. Keep `true`.
- **`timezone`** — the timezone all period instants are normalised to before comparison. Defaults to `UTC`; storing temporal data in UTC is strongly recommended.
- **`allow_zero_length`** — whether a spell with `from == to` is permitted. Off by default; a zero-length spell is usually a bug.

## `guards`

- **`enabled`** — run the boot guards (and advisory lints) that validate each temporal model's configuration (column presence, relation type, casts, …) the first time it is used. Leave `true` in development. You can warm them ahead of time with `php artisan bitemporal:warm-guards "App\\Models\\ProductPrice"` to fail fast on deploy rather than on first request. See [Boot guards and lints](13-boot-guards-and-lints.md).

## `audit_log`

The first-party audit subscriber, off by default. See [Events](08-events.md#the-audit-log).

- **`enabled`** — when `true`, every committed write is persisted to the audit table. Opt-in.
- **`table`** — the audit table name. Publish and run the package migrations to create it.
- **`connection`** — the database connection to write audit rows on; `null` uses the default connection.

## `writes`

- **`compact_adjacent_segments`** — merge adjacent segments with identical attributes after a write. Override per call with the `$compact` argument.
- **`compaction_excluded_columns`** — columns ignored when deciding whether two segments are "identical" for compaction. Timestamps belong here.
- **`future_validity_tolerance_ms`** — how far past "now" a valid date may be before the write fires `TemporalFutureRowEncountered`. Guards against clock skew while still allowing genuine future-dated changes.
- **`fire_eloquent_events`** — whether the writer fires Eloquent model events (`saved`, etc.) for the rows it writes. Off by default.
- **`lock_strategy`** — how the writer serialises concurrent writers to one timeline:
  - `parent_row` (default) — locks the parent entity row (`SELECT … FOR UPDATE`). Portable across engines.
  - `advisory` — uses a database advisory lock (`pg_advisory_xact_lock` / `GET_LOCK`) keyed by the timeline, leaving the parent row free.
  - `custom` — the package binds nothing; you provide your own `Vusys\Bitemporal\Locking\WriteLocker` implementation in the container.
- **`parent_lock_timeout_ms`** — how long to wait for the lock before failing.
- **`deadlock_retry_attempts`** — how many times to retry the write transaction on a deadlock.
- **`clock_skew_tolerance_ms`** — how far the host clock may regress below the latest recorded instant before a write throws `TemporalDomainException` (clock skew). Guards against a node whose clock has gone backwards corrupting the recorded axis. Default 60 s.
- **`idempotency_window`** — how long an [idempotency key](05-writing.md#idempotent-writes) is retained (a relative-date string, e.g. `'7 days'`). After this, a replayed key no longer matches and the write runs again.
- **`idempotency_auto_prune`** — when `true`, the package schedules `bitemporal:prune-idempotency-keys` to run `daily()`. Turn off if you manage the schedule yourself.

Next: [Testing](10-testing.md).
