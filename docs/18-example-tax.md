# Worked example: tax & regulatory rates

Tax is the domain where the past genuinely does change. A rate set in the budget can be amended by legislation passed months later and back-dated, and yet every return already filed under the old rate must remain reproducible — because that is what the taxpayer actually filed, and what an auditor will check against. You cannot overwrite the rate and you cannot forget the amendment; you need both, on different axes. This page models a jurisdiction's tax rates as a bitemporal timeline.

The features it leans on: retroactive [`correct()`](05-writing.md), a [dimension](06-dimensions.md) for rate category, [`supersedeTimeline()`](05-writing.md) for a full restatement from an authority, and [`backfill()->importHistoricalKnowledge()`](05-writing.md) for seeding past *beliefs*, not just past values.

## The models

A `TaxJurisdiction` is the entity; `TaxRate` is the versioned fact. A jurisdiction has several categories of rate (standard, reduced, zero) that move independently, so `category` is a *dimension*.

```php
class TaxRate extends Model
{
    use Bitemporal;

    protected array $temporalDimensions = ['category'];

    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(TaxJurisdiction::class);
    }
}
```

```php
class TaxJurisdiction extends Model
{
    use HasBitemporalRelations;

    public function rates(): BitemporalMany
    {
        return $this->bitemporalMany(TaxRate::class);
    }
}
```

```php
Schema::create('tax_rates', function (Blueprint $table) {
    $table->id();
    $table->bitemporalForeignFor(TaxJurisdiction::class);

    $table->string('category');              // 'standard' | 'reduced' | 'zero' — the dimension
    $table->decimal('rate', 5, 4);           // e.g. 0.2000 for 20%

    $table->bitemporalPeriods();
    $table->timestamps();

    $table->preventBitemporalOverlaps(['tax_jurisdiction_id'], ['category']);
});
```

## A rate set in the budget

The standard rate rises to 20% from the start of the new tax year. Announced ahead of time, applying going forward — a `changeEffectiveFrom`.

```php
$jurisdiction->rates()
    ->forDimensions(['category' => 'standard'])
    ->changeEffectiveFrom(
        attributes: ['rate' => 0.2000],
        validFrom: '2026-04-06',
    );
```

## Legislation that back-dates a change

In July, a finance act lowers the standard rate to 17.5%, back-dated to 6 April. Returns for the first quarter were already filed at 20%. The rate *was always* 17.5% from April — a `correct()` over the historical window — but the filings made in good faith at 20% must survive on the recorded axis.

```php
$jurisdiction->rates()
    ->forDimensions(['category' => 'standard'])
    ->correct(
        attributes: ['rate' => 0.1750],
        validFrom: '2026-04-06',
    );
```

A Q1 return filed on 30 April is still fully reproducible — its rate is whatever the system *believed* on the filing date, recovered by pinning both axes:

```php
$rateAsFiled = $jurisdiction->rates()
    ->forDimensions(['category' => 'standard'])
    ->validAt('2026-04-30')     // the period the return covered
    ->knownAt('2026-04-30')     // as the law was understood when filed
    ->sole();

$rateAsFiled->rate;             // 0.2000 — the figure on the original return
```

!!! note
    The correction does not falsify the return; it records that our *current* understanding differs from the one in force at filing time. Reconciling the two — issuing the refund for the over-collected 2.5% — is now a defensible calculation with both figures provable from the store.

## A full restatement from the authority

Sometimes the tax authority does not send a delta — it republishes the entire rate schedule for a category. Rather than diff it into a series of changes, `supersedeTimeline()` takes the complete set of desired rows and makes the timeline match them in one transaction.

```php
$jurisdiction->rates()
    ->forDimensions(['category' => 'reduced'])
    ->supersedeTimeline([
        ['rate' => 0.0500, 'valid_from' => '2024-04-06', 'valid_to' => '2026-04-06'],
        ['rate' => 0.0000, 'valid_from' => '2026-04-06', 'valid_to' => null],
    ]);
```

The previously-recorded rows are closed on the recorded axis, not deleted — the old picture is still queryable via `knownAt()`.

## Seeding historical beliefs, not just values

When you stand the system up against decades of prior rates, most of it is a clean value history and belongs in `backfill()->timeline()`. But occasionally you need to reconstruct not just what the rate *was*, but what was *believed at the time* — because an old return was filed on a rate that a later amendment changed, and you want that belief on record. `importHistoricalKnowledge()` accepts rows that already carry their recorded spells.

```php
// Clean value history — no belief reconstruction needed.
$jurisdiction->rates()
    ->forDimensions(['category' => 'standard'])
    ->backfill()
    ->timeline([
        ['rate' => 0.1750, 'valid_from' => '2011-01-04', 'valid_to' => '2011-01-04'],
        ['rate' => 0.2000, 'valid_from' => '2011-01-04', 'valid_to' => '2026-04-06'],
    ]);

// Reconstruct a past belief: from April we believed 20%, until the July amendment
// corrected it to 17.5% — both recorded spells stamped explicitly.
$jurisdiction->rates()
    ->forDimensions(['category' => 'standard'])
    ->backfill()
    ->importHistoricalKnowledge([
        [
            'rate' => 0.2000,
            'valid_from' => '2026-04-06', 'valid_to' => null,
            'recorded_from' => '2026-04-06', 'recorded_to' => '2026-07-15',
        ],
        [
            'rate' => 0.1750,
            'valid_from' => '2026-04-06', 'valid_to' => null,
            'recorded_from' => '2026-07-15', 'recorded_to' => null,
        ],
    ]);
```

The difference is the whole point of importing through `backfill()` rather than replaying corrections: `timeline()` stamps the recorded axis as "now", while `importHistoricalKnowledge()` lets you set it, so a migrated system reproduces the *beliefs* the old one held, not just its final values.

## What to take away

Bitemporal storage is what lets a jurisdiction's past be corrected *and* audited at the same time. A back-dated finance act rewrites the effective rate through `correct()`, while every return already filed stays reproducible via `knownAt()`. A full restatement goes in with `supersedeTimeline()` without erasing the prior picture, and legacy beliefs come across faithfully with `importHistoricalKnowledge()` — so no amount of retroactive legislation ever costs you the record of what you once believed.

Next: back to [Concepts](01-concepts.md), or browse the [full reference](index.md).
