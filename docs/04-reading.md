# Reading

Temporal models use a custom Eloquent builder, so the point-in-time predicates are available on any query — through a relation (`$product->prices()`), on the model directly (`ProductPrice::query()`), and inside eager loads.

## Point-in-time reads

```php
// What was the price valid on the invoice date, as we understand it now?
$price = $product->prices()
    ->validAt($invoice->issued_at)
    ->currentKnowledge()
    ->sole();

// What did we believe on the invoice date, as of when the invoice was created?
$price = $product->prices()
    ->validAt($invoice->issued_at)
    ->knownAt($invoice->created_at)
    ->sole();
```

| Method | Meaning |
| --- | --- |
| `validAt($date)` | rows whose valid spell contains the instant: `valid_from <= $date < valid_to` |
| `knownAt($date)` | rows whose recorded spell contains the instant (bitemporal models only) |
| `currentKnowledge()` | rows whose recorded spell is still open (`recorded_to IS NULL`) — what we believe now |

`knownAt()` and `currentKnowledge()` throw `TemporalConfigurationException` on a model that does not track recorded time.

> **Retractions are included by default.** A [retraction](01-concepts.md#anti-rows-retractions) is an anti-row: a real valid period that asserts "nothing was ever true here", with every value column `NULL`. `validAt()`, `knownAt()` and `currentKnowledge()` all match anti-rows, so a retracted window reads back as **present but null**, not absent. Whenever you read domain values and want a retracted window to read as empty, chain [`excludeRetractions()`](#spell-predicates):
>
> ```php
> $price = $product->prices()
>     ->validAt($invoice->issued_at)
>     ->currentKnowledge()
>     ->excludeRetractions()   // a retracted window now yields no row
>     ->first();
> ```
>
> The default is deliberate: diffs, timeline materialisation and the writer's own supersession pass all query through these predicates and must see anti-rows. Excluding them at the source would silently break those paths, so exclusion is opt-in per read.

### `sole()`

`sole()` is the temporal idiom for "there should be exactly one row at this point". It returns that single model, or throws `TemporalCardinalityException` — `expectedOneFoundNone` or `expectedOneFoundMany` — so a broken timeline surfaces loudly instead of silently returning the wrong row. On a `bitemporalOne()` relation, `sole()` returns `null` for "none" unless you used `bitemporalOneOrFail()`.

> **Pin single-result relations.** A `bitemporalOne()` relation only means "one row" once the timeline is narrowed to one — pin it with `validAt()`/`knownAt()`/`currentKnowledge()`, or read it inside an [`asOf()` lens frame](07-as-of-lens.md). Read unpinned (`$product->price` outside any lens), the whole timeline matches; property access then resolves to a **deterministic** row (latest valid period, then latest belief, then key) rather than an arbitrary one, but that is a stability guard, not the value you almost certainly meant. Prefer `sole()`, which raises `expectedOneFoundMany` when the read wasn't narrow enough.

## Spell predicates

Beyond a single instant, you can query whole windows. Each `valid*` method works on the valid spell; each `recorded*` method on the recorded spell (bitemporal only). All bounds are half-open `[from, to)`; a `null` upper bound means open-ended.

| Method | Matches rows whose spell… |
| --- | --- |
| `validIntersects($from, $to = null)` | overlaps `[from, to)` at all |
| `validContains($from, $to = null)` | fully contains `[from, to)` |
| `validContainedBy($from, $to = null)` | falls entirely within `[from, to)` |
| `validStartingFrom($date)` | starts on or after `$date` |
| `validEndingBy($date)` | ends on or before `$date` |
| `recordedIntersects` / `recordedContains` / `recordedContainedBy` / `recordedStartingFrom` / `recordedEndingBy` | the same, on the recorded spell |
| `excludeRetractions()` | drops anti-rows from the result |

```php
// Every price version that was in effect at any point during Q1.
$q1Prices = $product->prices()
    ->validIntersects('2026-01-01', '2026-04-01')
    ->currentKnowledge()
    ->excludeRetractions()
    ->orderBy('valid_from')
    ->get();
```

## Scoping to entities in bulk

When you start from the temporal model rather than a single parent, scope to one or many entities without writing the foreign-key logic yourself. This handles both plain and polymorphic entities.

```php
ProductPrice::query()->whereTemporalEntity($product);          // one entity (Model or MorphContext)
ProductPrice::query()->whereTemporalEntityIn($products);       // many: models or keys
ProductPrice::query()->whereTemporalEntityOf(Product::class, [1, 2, 3]);  // a class + ids
```

## Eager loading

Point-in-time predicates compose with eager loading, so you can load the right version per parent in one query:

```php
$products = Product::query()
    ->with(['prices' => fn ($q) => $q->validAt($date)->currentKnowledge()])
    ->get();
```

## Collection helpers

A query on a temporal model returns a `BitemporalCollection` with helpers for the common "reduce a timeline to a lookup" shapes:

```php
$prices = ProductPrice::query()
    ->whereTemporalEntityIn($products)
    ->validAt($date)
    ->currentKnowledge()
    ->get();

$prices->keyByTemporalEntityId();          // [entity_id => model]
$prices->keyByTemporalEntityReference();   // ["Type:id" => model] — safe across polymorphic types
$prices->groupByTemporalEntity();          // ["Type:id" => Collection<model>]
```

## Materialising a whole timeline

When you want the entity's history as an ordered, non-overlapping value object rather than a flat row set, end the query with `asTimeline()`; for every physical row (including superseded beliefs) in canonical order, use `fullHistory()`. Both — and the diff helpers that compare two points on the recorded axis — are covered in [Diffs and timelines](12-diffs-and-timelines.md).

```php
$timeline = $product->prices()->currentKnowledge()->asTimeline();
$timeline->at($date)?->attributes;   // the segment covering an instant
```

## Bypassing the ambient lens

If you use the [as-of lens](07-as-of-lens.md) to apply a point-in-time view to a whole block of code, a single query can opt out of it with `withoutLens()`:

```php
$liveRow = $product->prices()->withoutLens()->currentKnowledge()->sole();
```

Next: [Writing](05-writing.md).
