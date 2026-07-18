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

The **Committed** events fire *after commit*, so their listeners only run when the write actually landed. The **Starting** events fire *inside the write transaction*, before the rows are written.

> **`*Starting` listeners must be side-effect-free.** A `*Starting` event is dispatched inside the open write transaction, so if the write subsequently fails and rolls back, any side effect your listener performed (a queued job, a cache write, an outbound HTTP call, a row in another table) is **not** rolled back with it. Use `*Starting` only for in-transaction bookkeeping; put anything with an external side effect on the matching `*Committed` event, which fires only after the write durably lands.

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

A failed audit insert is **never fully silent**: the subscriber logs it at `error` level via the default logger regardless of whether anything listens to `TemporalAuditLogWriteFailed`. But the audit trail is still *best-effort* — the temporal write commits whether or not its audit row lands. If you need an audit record that is guaranteed to exist exactly when the write does (e.g. for compliance), do not rely on the best-effort subscriber: listen to the committed events directly and persist within your own transaction, or reconcile against them. The committed events carry the same information.

If you want different audit behaviour, leave the subscriber off and listen to the committed events directly — they carry the same information.

## The boot-lint event

`TemporalBootLintRaised` fires when a model trips an advisory configuration lint at boot. It is the hook for surfacing those warnings in your own logging or CI. See [Boot guards and lints](13-boot-guards-and-lints.md).

> By default the package does **not** fire Eloquent model events (`saved`, `created`, …) for the rows it writes — temporal rows are managed by the writer, not by ordinary model saves. Enable them with `writes.fire_eloquent_events = true` if you rely on model observers.

Next: [Configuration](09-configuration.md).
