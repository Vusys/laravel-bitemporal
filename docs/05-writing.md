# Writing

This is the heart of the package. Every write runs the bitemporal correction algorithm inside a transaction: it locks the entity's timeline, captures a single "recorded at" instant, loads the current-knowledge timeline, applies the change, splits and compacts as needed, then closes old recorded spells and inserts new rows. You never `update()` or `delete()` a temporal row directly.

All write methods are called on a `bitemporalMany()` (or `bitemporalMorphMany()`) relation, and each returns a *committed event* object describing exactly what happened (see [Events](08-events.md)).

## Change vs correct

The distinction matters:

- **`changeEffectiveFrom`** — a forward-only change. "From today, the price is £12." It closes the open-ended current segment and starts a new one. Use this for genuine changes in the world.
- **`correct`** — rewrites the value over an arbitrary window, including the past. "Actually, the price was £12 for all of February." This can split an existing segment and inserts a corrected row while preserving the superseded row in recorded history. Use this to fix mistakes.

```php
// Forward-only change effective from a date.
$product->prices()->changeEffectiveFrom(
    attributes: ['amount' => 12.00],
    validFrom: '2026-06-01',
);

// Correct a historical window (both bounds optional; null = open).
$product->prices()->correct(
    attributes: ['amount' => 12.00],
    validFrom: '2026-02-01',
    validTo: '2026-03-01',
);
```

`validFrom` / `validTo` / `validAt` accept a `CarbonInterface` or a parseable date string.

!!! warning "A write replaces the whole value tuple"
    `changeEffectiveFrom` and `correct` set every value column from the
    `attributes` you pass — they do not merge onto the existing row. Any value
    column you omit is written as `NULL`, not carried over. On a model with more
    than one value column, pass **all** of them each time (e.g. correcting a
    policy's `limit` also needs `deductible` and `premium`), or you will silently
    blank the others.

## Ending and retracting

```php
// Close the current open-ended segment at a date (it stops being valid; no new value).
$product->prices()->endAt('2026-12-31');

// Record that a window was never true — inserts an anti-row.
$product->prices()->retract(validFrom: '2026-02-01', validTo: '2026-03-01');
```

`retract()` with no `validTo` retracts open-endedly from `validFrom`. The anti-row it inserts carries `NULL` in every value column, so those columns must be nullable — see [Defining models](03-defining-models.md#migrations).

## Replacing a whole timeline

`supersedeTimeline()` takes a complete set of desired rows and makes the timeline match them in one transaction — closing everything currently recorded and inserting the new picture. Useful when an upstream system sends you a full restatement rather than a delta.

```php
$product->prices()->supersedeTimeline([
    ['amount' => 10.00, 'valid_from' => '2026-01-01', 'valid_to' => '2026-02-01'],
    ['amount' => 12.00, 'valid_from' => '2026-02-01', 'valid_to' => null],
]);
```

## Hard delete

`forceDeleteHistory()` physically removes every row for the entity's timeline (scoped to the relation, and to dimensions if set). This is the one destructive operation — it exists for GDPR-style erasure and test teardown, not for corrections.

```php
$product->prices()->forceDeleteHistory();
```

## Compaction

After a write, adjacent segments that carry identical attributes are merged into one (so a correction that happens to match its neighbour doesn't leave a redundant boundary). This is on by default and configurable globally via `writes.compact_adjacent_segments` and `writes.compaction_excluded_columns`. Override per call with the `$compact` argument on any write method:

```php
$product->prices()->changeEffectiveFrom(['amount' => 12.00], '2026-06-01', compact: false);
```

## Optimistic concurrency

To guard against a lost update — you read a value, decide a change, and want to fail if someone else changed it underneath you — pass `expectedCurrentAttributes`. The write throws `TemporalWriteConflictException` if the current segment no longer matches:

```php
$product->prices()->correct(
    attributes: ['amount' => 12.00],
    validFrom: '2026-02-01',
    expectedCurrentAttributes: ['amount' => 10.00],   // fail unless still 10.00
);
```

## Idempotent writes

When a write may be retried — a queued job that can run twice, a webhook delivered more than once — pass an `idempotencyKey`. The first call with a given key runs normally; a later call with the **same key and the same parameters** is a no-op that returns the original committed event (same `recordedAt`, no new rows). A later call with the same key but **different parameters** throws `TemporalWriteConflictException`, surfacing the mistake instead of silently double-applying.

```php
$product->prices()->correct(
    attributes: ['amount' => 12.00],
    validFrom: '2026-02-01',
    idempotencyKey: 'price-sync:job-42',
);
```

`idempotencyKey` is accepted by `changeEffectiveFrom()` and `correct()`. Keys are namespaced per `(model, entity)`, stored in `temporal_idempotency_keys`, and retained for `writes.idempotency_window` (default 7 days). With `writes.idempotency_auto_prune` on (the default) the package prunes expired keys daily; see [`bitemporal:prune-idempotency-keys`](14-commands.md#bitemporalprune-idempotency-keys) and [Configuration](09-configuration.md).

## A note on scoping writes

A write is scoped to the relation's entity (and its dimensions, if any). Adding a raw `where()` to the query before a write is rejected with `TemporalMissingDimensionException` — the writer cannot reason about an arbitrary predicate. To scope to an independent timeline, declare it as a dimension and use `forDimensions()` (see [Dimensions](06-dimensions.md)).

## Backfilling history

When importing existing historical data — migrating from a legacy system, or seeding known-past knowledge — go through `backfill()` rather than the change/correct API. It skips the correction algorithm and writes your rows directly, so a whole value history lands in one pass:

```php
$product->prices()->backfill()->timeline([
    ['amount' => 10.00, 'valid_from' => '2025-01-01', 'valid_to' => '2025-06-01'],
    ['amount' => 11.00, 'valid_from' => '2025-06-01', 'valid_to' => null],
]);
```

Value columns may be given flat (as above) or nested under an `attributes` key. Every row needs `valid_from`/`valid_to`; `recorded_from`/`recorded_to` are optional and control which method you are effectively using:

- `timeline($rows)` — import a clean current-knowledge timeline. Rows that omit `recorded_from` have the recorded axis stamped as "now".
- `importHistoricalKnowledge($rows)` — import rows that already carry explicit recorded spells, reconstructing past *beliefs* (not just past values).
- `retraction($row)` — backfill a single anti-row.

Each returns a `TemporalBackfillCommitted` event.

### Streaming large imports

For large historical loads, `stream()` keeps memory bounded by validating and inserting a chunk at a time, each in its own transaction, so the whole set is never materialised at once:

```php
$product->prices()->backfill()
    ->stream(chunkSize: 1000)
    ->timeline($rowsIterable);
```

Chunks are not cross-validated during the stream — that would defeat streaming. Instead a scoped overlap audit runs after the final chunk and throws `TemporalOverlapException` if any cross-chunk overlap slipped through; the exception carries `getInsertedIds()` so you can recover with `forceDeleteHistory()`. A `TemporalBackfillCommitted` event fires once per chunk (with its `chunkIndex`) and once at the end (`chunkIndex` is `null`). Tune the defaults with `backfill.default_chunk_size` and `backfill.post_audit_check`.

### Dropping indexes for a bulk load

Very large loads pay a per-row cost maintaining the overlap indexes as rows are inserted. `TemporalLens::withoutIndexes()` drops the package-managed overlap indexes for the duration of a callback and rebuilds them once, in a single pass, on exit:

```php
use Vusys\Bitemporal\Facades\TemporalLens;

TemporalLens::withoutIndexes(ProductPrice::class, function () use ($product, $rows) {
    $product->prices()->backfill()->stream(chunkSize: 1000)->timeline($rows);
});
```

- Only the indexes emitted by `preventBitemporalOverlaps()` / `preventTemporalOverlaps()` are dropped. Custom application indexes are left untouched, and on PostgreSQL the `EXCLUDE USING gist` constraint stays enforced throughout the load.
- Recreation uses the engine's online path — `CREATE INDEX CONCURRENTLY` (PostgreSQL), `ALGORITHM=INPLACE, LOCK=NONE` (MySQL/MariaDB), a plain rebuild on SQLite. Set `backfill.online_ddl = false` to force a blocking rebuild on engine versions that reject the online path.
- It is reentrant per table: nested calls for the same model are a no-op, and different models compose.
- It **must not be called inside a transaction** — `CREATE INDEX CONCURRENTLY` forbids a transaction block — and throws [`TemporalOnlineDdlException`](09a-exception-catalogue.md#temporalonlineddlexception) if it is. The callback may still open its own transactions (the backfill does).

While a table's overlap index is dropped, the writer's current-known lookups fall back to full scans, so concurrent routine writes to that table are slow (they stay correct — the advisory lock and the post-import audit still hold). It pays off only for genuinely large, one-off loads; for modest ETL the saved index maintenance is negligible. Quiesce concurrent writers for the duration if that matters.

Next: [Dimensions](06-dimensions.md).
