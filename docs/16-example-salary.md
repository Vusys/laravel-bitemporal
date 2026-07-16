# Worked example: salary & compensation history

Payroll is where the difference between *changing* a value and *correcting* it stops being academic. A raise that starts next month and a raise that should have started three months ago are entirely different operations, even though both end with the same number in the same column. Get them confused and you either lose the audit trail of what someone was actually paid, or you rewrite history the tax authority has already seen. This page models an employee's compensation as a timeline and uses it to draw the line sharply.

The features it leans on: [`changeEffectiveFrom` vs `correct`](05-writing.md), the [as-of lens](07-as-of-lens.md) for reproducible payroll runs, [`expectedCurrentAttributes`](05-writing.md) for optimistic concurrency, [`asTimeline()` / `fullHistory()`](12-diffs-and-timelines.md), a single [dimension](06-dimensions.md), and [`backfill()`](05-writing.md) for importing legacy records.

## The models

An `Employee` is the entity; `Compensation` is the versioned fact. Pay is split into independently-corrected streams — `base` and `bonus` — so we declare `component` as a *dimension*. Each component is its own timeline: correcting a bonus never disturbs base pay.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

class Compensation extends Model
{
    use Bitemporal;

    protected array $temporalDimensions = ['component'];

    // "compensation" is uncountable, so Eloquent would infer the table as the
    // singular `compensation`; pin it to match the migration.
    protected $table = 'compensations';

    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
```

```php
class Employee extends Model
{
    use HasBitemporalRelations;

    public function compensation(): BitemporalMany
    {
        return $this->bitemporalMany(Compensation::class);
    }
}
```

The `component` column must be listed in the overlap guard so each stream partitions independently:

```php
Schema::create('compensations', function (Blueprint $table) {
    $table->id();
    $table->bitemporalForeignFor(Employee::class);   // employee_id

    $table->string('component');                     // 'base' | 'bonus' — the dimension
    $table->decimal('annual_amount', 12, 2);

    $table->bitemporalPeriods();
    $table->timestamps();

    $table->preventBitemporalOverlaps(['employee_id'], ['component']);
});
```

## A raise that starts next month

The annual review lands: base pay rises to £62,000 from 1 August. This is a real change in the world going forward, so it is a `changeEffectiveFrom` — it closes the current open-ended base segment on 31 July and opens a new one. Because the model has a dimension, every write pins it with `forDimensions()`.

```php
$employee->compensation()
    ->forDimensions(['component' => 'base'])
    ->changeEffectiveFrom(
        attributes: ['annual_amount' => 62_000],
        validFrom: '2026-08-01',
    );
```

## A raise that should have started months ago

Now the other kind. HR discovers the promotion paperwork was mislaid: the £62,000 should have taken effect on 1 May, not 1 August. The three months already paid at the old rate were *wrong*. This is a `correct()` — it rewrites the value over a historical window and preserves the superseded rows in recorded history.

```php
$employee->compensation()
    ->forDimensions(['component' => 'base'])
    ->correct(
        attributes: ['annual_amount' => 62_000],
        validFrom: '2026-05-01',
        validTo: '2026-08-01',
    );
```

!!! note
    The distinction in one line: **`changeEffectiveFrom` says the world changed; `correct` says our record was wrong.** After the correction the current timeline shows £62,000 from May, but the rows describing what we *believed* (and paid) between May and the correction date are still there on the recorded axis — which is exactly what makes the back-pay defensible.

## Reproducing a payroll run

The May payroll was already run and filed against the old £58,000 base. When finance re-opens May to compute the back-pay, they must first reproduce the run *exactly as it was known then* — not as it looks after the correction. The [as-of lens](07-as-of-lens.md) sets both axes for a whole block of code, so every read inside inherits them.

```php
use Vusys\Bitemporal\Facades\TemporalLens;

$mayAsFiled = TemporalLens::asOf(
    validAt: '2026-05-31',      // pay effective at month-end
    knownAt: '2026-06-05',      // as the system believed it on the original run date
    callback: fn () => $employee->compensation()
        ->forDimensions(['component' => 'base'])
        ->sole()
        ->annual_amount,        // 58000 — what was actually filed
);
```

Comparing that against the current belief (£62,000) yields the back-pay owed, and both figures are provable from the store.

## Guarding against a lost update

Two HR admins open the same record. One adjusts the bonus while the other is still deciding. To make the second write fail loudly rather than silently clobbering the first, pass `expectedCurrentAttributes` — the write throws `TemporalWriteConflictException` if the current segment no longer matches what the admin read.

```php
try {
    $employee->compensation()
        ->forDimensions(['component' => 'bonus'])
        ->correct(
            attributes: ['annual_amount' => 8_000],
            validFrom: '2026-01-01',
            expectedCurrentAttributes: ['annual_amount' => 5_000],  // fail unless still 5,000
        );
} catch (TemporalWriteConflictException $e) {
    // Someone changed the bonus underneath us — reload and let the admin decide again.
}
```

## The full history for a review

A compensation review wants the complete base-pay story, current beliefs only, as an ordered, non-overlapping value object. `asTimeline()` returns a `Timeline` of `TimelineSegment`s rather than a flat row set.

```php
$timeline = $employee->compensation()
    ->forDimensions(['component' => 'base'])
    ->currentKnowledge()
    ->asTimeline();

foreach ($timeline as $segment) {
    $segment->validSpell->from;        // when this rate took effect
    $segment->attributes['annual_amount'];
}
```

When you instead want *every physical row* including superseded beliefs — to show the reviewer that a correction happened, not just its result — reach for `fullHistory()`:

```php
$everyBelief = $employee->compensation()
    ->forDimensions(['component' => 'base'])
    ->fullHistory()
    ->get();   // ordered by valid_from then recorded_from
```

## Importing from the legacy system

When you migrate an employee in from a previous HR platform, you are seeding known history, not making changes today. Route it through `backfill()` so the rows land as a clean current-knowledge timeline rather than a stack of corrections:

```php
$employee->compensation()
    ->forDimensions(['component' => 'base'])
    ->backfill()
    ->timeline([
        ['annual_amount' => 50_000, 'valid_from' => '2023-01-01', 'valid_to' => '2024-01-01'],
        ['annual_amount' => 54_000, 'valid_from' => '2024-01-01', 'valid_to' => '2025-01-01'],
        ['annual_amount' => 58_000, 'valid_from' => '2025-01-01', 'valid_to' => null],
    ]);
```

## What to take away

The whole domain turns on one question at each write: *did the world change, or was our record wrong?* Change opens a new truth going forward and leaves the past intact; correct rewrites a window while keeping the superseded belief on the recorded axis so payroll stays reproducible and back-pay stays provable. Dimensions keep base and bonus from colliding, and `backfill()` gets legacy history in without pretending it is a correction.

Next: [Worked example: SaaS subscriptions & entitlements](17-example-subscriptions.md).
