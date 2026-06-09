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

A first-party audit-log subscriber that persists every committed write to a table is on the roadmap; until then these events are the integration point for your own audit logging.

> By default the package does **not** fire Eloquent model events (`saved`, `created`, …) for the rows it writes — temporal rows are managed by the writer, not by ordinary model saves. Enable them with `writes.fire_eloquent_events = true` if you rely on model observers.

Next: [Configuration](09-configuration.md).
