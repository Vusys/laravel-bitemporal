# The as-of lens

Threading `validAt()` / `knownAt()` through every query in a request gets tedious when the whole operation should run "as of" one moment — rendering a historical invoice, replaying an audit, generating a month-end report. The `TemporalLens` facade sets an ambient point-in-time view for the duration of a callback, and every temporal query inside it inherits that view automatically.

## Basic use

```php
use Vusys\Bitemporal\Facades\TemporalLens;

$invoiceView = TemporalLens::asOf(
    validAt: $invoice->issued_at,
    knownAt: $invoice->created_at,
    callback: function () use ($invoice) {
        // Every temporal query in here is implicitly ->validAt($issued)->knownAt($created).
        return $invoice->lines->map(fn ($line) => $line->product->prices()->sole());
    },
);
```

Convenience entry points when you only need one axis:

```php
TemporalLens::validAt($date, fn () => /* ... */);
TemporalLens::knownAt($auditMoment, fn () => /* ... */);
```

The lens returns whatever the callback returns.

## Nesting

Frames stack. A nested `asOf()` inherits the parent frame's axes and overrides only what you pass — so an inner block can shift the valid date while keeping the outer knowledge date:

```php
TemporalLens::asOf($validA, $knownA, function () use ($validB) {
    // here: validA / knownA
    TemporalLens::validAt($validB, function () {
        // here: validB / knownA  (knownA inherited)
    });
});
```

## Opting a query out

A single query can ignore the ambient lens with `withoutLens()` — useful for fetching live current state from inside an as-of block:

```php
$liveRow = $product->prices()->withoutLens()->currentKnowledge()->sole();
```

## Inspecting and resetting

```php
TemporalLens::current();   // the active LensFrame, or null
TemporalLens::depth();     // number of stacked frames
TemporalLens::reset();     // clear the stack (defensive)
TemporalLens::assertEmpty(); // throw if a frame leaked
```

## Queue safety

The lens stack is request-scoped. On long-lived workers (Octane, Horizon, queues) a frame left open by one job must not bleed into the next. The package registers `JobProcessing` / `JobProcessed` listeners that reset the stack at each job boundary, so a frame opened inside a job never leaks. `assertEmpty()` is available if you want to assert this invariant yourself in tests.

Next: [Events](08-events.md).
