# Events

Every temporal write fires a paired `*Starting` / `*Committed` event, and the `*Committed` event **doubles as the return value** of the write method. So you can either react to writes globally with a listener, or inspect the result inline — same object.

## The committed event

All committed events extend `TemporalWriteCommitted`:

```php
$result = $product->prices()->correct(['amount' => 12.00], validFrom: '2026-02-01');

$result->model;        // class-string of the temporal model
$result->entity;       // the parent Model
$result->dimensions;   // array<string, mixed> dimension tuple in force
$result->recordedAt;   // CarbonImmutable — the single instant stamped on this write
$result->rowsClosed;   // array<int, Model> — rows whose recorded spell was closed
$result->rowsInserted; // array<int, Model> — rows inserted
$result->compacted;    // bool — whether compaction merged segments
$result->closedCount();
$result->insertedCount();
```

This is the audit trail of a single write in object form: what was superseded, what replaced it, and the exact instant it was recorded.

## The event catalogue

Fired *after commit* (via `DB::afterCommit`), so listeners only run when the write actually landed:

| Write method | Starting event | Committed event |
| --- | --- | --- |
| `changeEffectiveFrom`, `endAt` | `TemporalChangeStarting` | `TemporalChangeCommitted` |
| `correct` | `TemporalCorrectionStarting` | `TemporalCorrectionCommitted` |
| `retract` | `TemporalRetractionStarting` | `TemporalRetractionCommitted` |
| `supersedeTimeline` | `TemporalTimelineSupersedingStarting` | `TemporalTimelineSuperseded` |
| `forceDeleteHistory` | `TemporalHardDeleteStarting` | `TemporalHardDeleteCommitted` |
| `backfill()->*` | `TemporalBackfillStarting` | `TemporalBackfillCommitted` |

Diagnostic events fired during processing:

- `TemporalCompactionPerformed` — adjacent segments were merged.
- `TemporalOverlapPrevented` — the writer detected and refused an overlap.
- `TemporalFutureRowEncountered` — a write touched a row whose validity is in the future (beyond `writes.future_validity_tolerance_ms`).

## Listening

The committed events are ordinary Laravel events:

```php
use Vusys\Bitemporal\Events\TemporalCorrectionCommitted;

Event::listen(function (TemporalCorrectionCommitted $e) {
    Log::info('price corrected', [
        'entity' => $e->entity->getKey(),
        'closed' => $e->closedCount(),
        'inserted' => $e->insertedCount(),
        'at' => $e->recordedAt,
    ]);
});
```

## The audit log

The package ships a first-party subscriber that persists every committed write to a table, so you get a durable audit trail without writing your own listener. It is **opt-in** — enable it under `audit_log` in the config (see [Configuration](09-configuration.md)):

```php
'audit_log' => [
    'enabled' => true,
    'table' => 'temporal_audit_log',
    'connection' => null,
],
```

Publish and run the package migrations to create `temporal_audit_log`, then every committed write — change, correction, retraction, supersede, backfill, and hard-delete — lands one row:

| Column | Holds |
| --- | --- |
| `event_class` | the committed event's class basename (e.g. `TemporalCorrectionCommitted`) |
| `model`, `entity_type`, `entity_id` | the temporal model and the entity it was about |
| `dimensions` | the dimension tuple in force (JSON) |
| `payload` | operation detail: `closed_ids` / `inserted_ids` / `deleted_ids` and `compacted` (JSON) |
| `recorded_at` | the write's recorded instant |
| `observed_at` | wall-clock time the audit row was inserted |

The subscriber writes **after** the temporal transaction commits, in a separate transaction. If that audit insert fails, the temporal write is **not** rolled back — it already committed — and a `TemporalAuditLogWriteFailed` event fires so you can alert on it:

```php
use Vusys\Bitemporal\Events\TemporalAuditLogWriteFailed;

Event::listen(function (TemporalAuditLogWriteFailed $e) {
    Log::error('temporal audit write failed', ['event' => $e]);
});
```

If you want different audit behaviour, leave the subscriber off and listen to the committed events directly — they carry the same information.

## The boot-lint event

`TemporalBootLintRaised` fires when a model trips an advisory configuration lint at boot. It is the hook for surfacing those warnings in your own logging or CI. See [Boot guards and lints](13-boot-guards-and-lints.md).

> By default the package does **not** fire Eloquent model events (`saved`, `created`, …) for the rows it writes — temporal rows are managed by the writer, not by ordinary model saves. Enable them with `writes.fire_eloquent_events = true` if you rely on model observers.

Next: [Configuration](09-configuration.md).
