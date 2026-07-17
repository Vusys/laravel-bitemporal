# Worked example: insurance policies & claims

Insurance is the textbook case for two time axes, because the question a claims team has to answer is never simply "what is the cover?" — it is "what cover was in force *on the day of the loss*, and what did we *believe* it was *at the time we paid the claim*?" A back-dated endorsement, a mistaken data entry, or a policy voided for fraud all change the answer to one of those questions without changing the other. This page models a policy's coverage as a bitemporal timeline and walks the API through the events of a claim.

The features it leans on: [`knownAt()`](04-reading.md) and *recorded time*, retroactive [`correct()`](05-writing.md), [`retract()`](05-writing.md) anti-rows, and [`diffKnowledge()`](12-diffs-and-timelines.md).

## The models

A `Policy` is the entity; its `PolicyCoverage` is the versioned fact — the limit, deductible, and premium that apply over a stretch of valid time. Because we care what we believed and when, the coverage model tracks recorded time (the default), so nothing about a past belief is ever overwritten.

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Bitemporal;

class PolicyCoverage extends Model
{
    use Bitemporal;

    protected string $temporalEntity = Policy::class;

    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
```

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Concerns\HasBitemporalRelations;
use Vusys\Bitemporal\Relations\BitemporalMany;

class Policy extends Model
{
    use HasBitemporalRelations;

    public function coverages(): BitemporalMany
    {
        return $this->bitemporalMany(PolicyCoverage::class);
    }
}
```

The migration uses the Blueprint macros from [Defining models](03-defining-models.md):

```php
Schema::create('policy_coverages', function (Blueprint $table) {
    $table->id();
    $table->bitemporalForeignFor(Policy::class);   // policy_id, FK, restrictOnDelete

    // Nullable so retract() can insert an anti-row (all value columns NULL).
    $table->decimal('limit', 12, 2)->nullable();
    $table->decimal('deductible', 10, 2)->nullable();
    $table->decimal('premium', 10, 2)->nullable();

    $table->bitemporalPeriods();                    // valid_* + recorded_* (µs) + is_retraction
    $table->timestamps();

    $table->preventBitemporalOverlaps(['policy_id']);
});
```

## Binding cover

The policy incepts on 1 January with a £250,000 limit. This is a genuine, forward-looking fact about the world, so it is a `changeEffectiveFrom` — it opens the timeline at the inception date and leaves it open-ended.

```php
$policy->coverages()->changeEffectiveFrom(
    attributes: ['limit' => 250_000, 'deductible' => 500, 'premium' => 1_200],
    validFrom: '2026-01-01',
);
```

## Paying a claim on today's knowledge

A fire on 14 March triggers a claim. On 20 March the adjuster settles it against the cover that was in force on the loss date, as the system understands the policy that day. Both axes matter: `validAt()` picks the loss date, `knownAt()` pins the belief to the settlement date.

```php
$coverAtLoss = $policy->coverages()
    ->validAt('2026-03-14')     // cover effective on the day of the fire
    ->knownAt('2026-03-20')     // as we believed it when the claim was paid
    ->sole();

$payout = min($claimAmount, $coverAtLoss->limit) - $coverAtLoss->deductible;
```

Recording *which belief* the payout was based on is the whole point. When the file is reopened months later, the settlement can be reproduced exactly — even if the policy record has since changed underneath it.

## A retroactive endorsement

In April the broker confirms the customer had bought an endorsement raising the limit to £500,000, effective all the way back to inception. This is not a change from today — it is a fix to what was *always* true. `correct()` rewrites the value over a historical window while preserving the superseded row in recorded history.

A write replaces the whole value tuple, so we repeat `deductible` and `premium` — omitting them would blank those columns (see [Writing](05-writing.md#change-vs-correct)):

```php
$policy->coverages()->correct(
    attributes: ['limit' => 500_000, 'deductible' => 500, 'premium' => 1_200],
    validFrom: '2026-01-01',    // the higher limit applied from inception
);
```

!!! note
    The £250,000 row is not deleted. It stays in the timeline with a closed *recorded* spell — it is what the system believed between January and April. The claim paid in March is still fully explainable against the knowledge held that day; only the *current* belief now shows £500,000 from inception.

## Voiding cover for fraud

If underwriting later discovers the application was fraudulent, the policy is void *ab initio* — treated as never having been in force. That is not a correction of the value; it is an assertion that the period never happened. `retract()` inserts an *anti-row* over the window.

```php
// Cover is treated as never valid from inception onward.
$policy->coverages()->retract(validFrom: '2026-01-01');
```

A retraction with no `validTo` retracts open-endedly from the given date. Reads that [`excludeRetractions()`](04-reading.md) will now find no cover in force — while the recorded history still shows every belief the system ever held, including the one the March claim was paid against.

## Auditing what changed

A regulator asks: for the loss on 14 March, how did our understanding of the cover change between the day we paid and today? `diffKnowledge()` compares the belief about a single valid instant across two points on the recorded axis.

```php
$diff = $policy->coverages()->diffKnowledge(
    validAt: '2026-03-14',      // the cover effective on the loss date
    fromKnownAt: '2026-03-20',  // what we believed when we paid
    toKnownAt: '2026-05-01',    // what we believe now
);

foreach ($diff->changed as $pair) {
    // $pair->from->limit === 250000, $pair->to->limit === 500000
    $pair->changedAttributes;   // ['limit']
}
```

The diff makes the story auditable: on the settlement date the limit was believed to be £250,000; today, after the endorsement, we understand it was £500,000 — and the record of both beliefs survives.

## What to take away

Recorded time is what lets you *defend a past decision*. The claim was paid correctly on the knowledge available; a later endorsement corrected the value without falsifying that record; a retraction removed the cover from the current picture without erasing the history. None of the three operations overwrote what came before.

Next: [Worked example: salary & compensation history](16-example-salary.md).
