# Diffs and timelines

Two read-side helpers turn a raw query into something you can reason about as a whole:

- **Timeline materialisers** (`asTimeline()`, `fullHistory()`) collapse a query into an ordered, non-overlapping value object.
- **Diff helpers** (`diffTimelines()`, `diffKnowledge()`) answer "what changed between two points on the recorded axis?" — what we *learned* between two dates.

Both are available on any temporal query — through a relation (`$product->prices()`) or on the model directly.

## Materialising a timeline

`asTimeline()` runs the query and returns a `Timeline` — an ordered collection of `TimelineSegment` objects sorted by `valid_from`. It is a terminal call (like `get()`), but it gives you a structured view rather than a flat row collection:

```php
$timeline = $product->prices()->currentKnowledge()->asTimeline();

$timeline->count();                 // number of segments
$timeline->head()?->attributes;     // ['amount' => 1000, …] of the first segment
$timeline->tail();                  // last segment
$timeline->at($instant);            // the segment covering an instant, or null
```

Each `TimelineSegment` exposes its `validSpell`, `recordedSpell`, the domain `attributes` (temporal columns stripped out), and an `isRetraction` flag. Scope the query first — `currentKnowledge()` for the believed-now timeline, or `knownAt($date)` for a past belief — then materialise.

`fullHistory()` is the chainable counterpart for when you want every physical row, including superseded beliefs, in canonical order (`valid_from` then `recorded_from`):

```php
$rows = $product->prices()->fullHistory()->get();   // ordinary Eloquent collection, ordered
```

Use `asTimeline()` when you want a non-overlapping value object to walk; use `fullHistory()` when you want the raw rows including history.

## Diffing what changed

A bitemporal store records not just values but *when you came to believe them*. The diff helpers compare two points on the recorded axis and report what moved.

`diffTimelines($fromKnownAt, $toKnownAt)` compares the whole believed timeline at two recorded dates — "between February and March, how did our picture of this entity's history change?":

```php
$diff = $product->prices()->diffTimelines(
    fromKnownAt: '2026-02-20',     // what we believed on Feb 20
    toKnownAt: '2026-03-10',       // what we believe on Mar 10
);
```

`diffKnowledge($validAt, $fromKnownAt, $toKnownAt)` narrows the comparison to a single valid instant — "for the price *effective on June 1*, did our belief change between those two dates?":

```php
$diff = $product->prices()->diffKnowledge(
    validAt: '2026-06-01',
    fromKnownAt: '2026-02-20',
    toKnownAt: '2026-03-10',
);
```

```php
public function diffTimelines(
    CarbonInterface|string $fromKnownAt,
    CarbonInterface|string $toKnownAt,
): TemporalDiff

public function diffKnowledge(
    CarbonInterface|string $validAt,
    CarbonInterface|string $fromKnownAt,
    CarbonInterface|string $toKnownAt,
): TemporalDiff
```

### Reading the result

Both return a `TemporalDiff`:

```php
$diff->added;       // Collection<Model> — segments present only at `toKnownAt`
$diff->removed;     // Collection<Model> — segments present only at `fromKnownAt`
$diff->changed;     // Collection<TemporalDiffPair> — same span, different value
$diff->retracted;   // Collection<TemporalRetraction> — a span withdrawn between the two dates
$diff->unchanged;   // Collection<Model> — identical in both
$diff->isEmpty();   // true when nothing was added, removed, changed, or retracted
```

A `TemporalDiffPair` describes one segment that changed value across the two dates:

```php
$pair = $diff->changed->first();

$pair->from;                // the earlier-belief Model
$pair->to;                  // the later-belief Model
$pair->changedAttributes;   // ['amount'] — the attribute names that differ
```

So a correction that raised a price from `1000` to `1200` surfaces as one `changed` pair whose `changedAttributes` contains `amount`, with `from->amount === 1000` and `to->amount === 1200`.

A span that became a **retraction** (an [anti-row](01-concepts.md#anti-rows-retractions)) between the two dates is *not* a value change — surfacing it as `changed` would let a consumer misread the withdrawal (`amount → null`, `is_retraction → true`) as a real price change to `NULL`. It lands in its own `retracted` bucket instead:

```php
$retraction = $diff->retracted->first();

$retraction->to;    // the anti-row believed at `toKnownAt` (is_retraction === true)
$retraction->from;  // the value row believed at `fromKnownAt`, or null when the
                    // span was both created and retracted after `fromKnownAt`
```

Both sides are preserved, so `added ∪ changed.to ∪ retracted.to` (plus `unchanged`) reconstructs the belief at `toKnownAt`, and `removed ∪ changed.from ∪ retracted.from` (plus `unchanged`) reconstructs the belief at `fromKnownAt`.

## From the command line

The same comparison is available without writing code, for spot-checks against a running database — see [`bitemporal:diff-timelines`](14-commands.md#bitemporaldiff-timelines):

```bash
php artisan bitemporal:diff-timelines \
    --model="App\Models\ProductPrice" \
    --entity-id=123 \
    --from-known-at="2026-02-20" \
    --to-known-at="2026-03-10"
```

Next: [Boot guards and lints](13-boot-guards-and-lints.md).
