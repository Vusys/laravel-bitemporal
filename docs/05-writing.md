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

## Ending and retracting

```php
// Close the current open-ended segment at a date (it stops being valid; no new value).
$product->prices()->endAt('2026-12-31');

// Record that a window was never true — inserts an anti-row.
$product->prices()->retract(validFrom: '2026-02-01', validTo: '2026-03-01');
```

`retract()` with no `validTo` retracts open-endedly from `validFrom`.

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

## A note on scoping writes

A write is scoped to the relation's entity (and its dimensions, if any). Adding a raw `where()` to the query before a write is rejected with `TemporalMissingDimensionException` — the writer cannot reason about an arbitrary predicate. To scope to an independent timeline, declare it as a dimension and use `forDimensions()` (see [Dimensions](06-dimensions.md)).

## Backfilling history

When importing existing historical data — migrating from a legacy system, or seeding known-past knowledge — go through `backfill()` rather than the change/correct API, so you can stamp the recorded axis explicitly instead of "now":

```php
$product->prices()->backfill()->timeline([
    ['amount' => 10.00, 'valid_from' => '2025-01-01', 'valid_to' => '2025-06-01'],
    ['amount' => 11.00, 'valid_from' => '2025-06-01', 'valid_to' => null],
]);
```

- `timeline($rows)` — import a clean current-knowledge timeline.
- `importHistoricalKnowledge($rows)` — import rows that already carry recorded spells, reconstructing past *beliefs* (not just past values).
- `retraction($row)` — backfill a single anti-row.

Each returns a `TemporalBackfillCommitted` event.

Next: [Dimensions](06-dimensions.md).
