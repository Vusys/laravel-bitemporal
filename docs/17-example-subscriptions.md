# Worked example: SaaS subscriptions & entitlements

A subscription looks simple until you have to answer a support ticket six weeks after the fact: "the customer says they could export data on 3 June — could they?" The plan changed mid-cycle, the feature set that plan grants changed independently, prices vary by region, and half the writes arrive from billing webhooks that fire more than once. This page models an account's subscription and a plan's feature entitlements as separate timelines, and shows how the API keeps each honest.

The features it leans on: [dimensions](06-dimensions.md) for per-region pricing, [temporal pivots](11-pivots.md) for plan↔feature grants, [`idempotencyKey`](05-writing.md) for webhook-safe writes, and the [as-of lens](07-as-of-lens.md) for rendering a whole request "as of" a date.

## The models

An `Account` is the entity; `Subscription` is the versioned fact — the plan tier and seat count in force over a stretch of valid time, varying by billing `region`.

```php
class Subscription extends Model
{
    use Bitemporal;

    protected array $temporalDimensions = ['region'];

    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
```

```php
class Account extends Model
{
    use HasBitemporalRelations;

    public function subscription(): BitemporalMany
    {
        return $this->bitemporalMany(Subscription::class);
    }
}
```

## An upgrade mid-cycle

The account upgrades from Starter to Pro, effective immediately, on the EU price list. That is a forward change, scoped to the `region` dimension — `forDimensions()` both filters the write and stamps the region onto the new row.

```php
$account->subscription()
    ->forDimensions(['region' => 'EU'])
    ->changeEffectiveFrom(
        attributes: ['plan' => 'pro', 'seats' => 25],
        validFrom: '2026-06-01',
    );
```

The US price list is a separate timeline; this write leaves it untouched. Reading back a single region's state is the same `forDimensions()` tuple plus a point-in-time predicate:

```php
$euNow = $account->subscription()
    ->forDimensions(['region' => 'EU'])
    ->validAt('2026-06-15')
    ->currentKnowledge()
    ->sole();       // plan => 'pro'
```

## Webhook writes that fire twice

The upgrade is driven by a Stripe `customer.subscription.updated` event. Stripe guarantees *at-least-once* delivery, so the same event can arrive two or three times. Passing the event id as an `idempotencyKey` makes the write safe to replay: the first call applies it; a later call with the **same key and the same parameters** is a no-op that returns the original committed event; a later call with the same key but **different parameters** throws `TemporalWriteConflictException`, surfacing a genuine mistake instead of silently double-applying.

```php
public function handleStripeWebhook(array $event): void
{
    $account->subscription()
        ->forDimensions(['region' => 'EU'])
        ->changeEffectiveFrom(
            attributes: ['plan' => 'pro', 'seats' => 25],
            validFrom: $event['effective_at'],
            idempotencyKey: "stripe:{$event['id']}",   // e.g. stripe:evt_1P...
        );
}
```

!!! note
    Keys are namespaced per `(model, entity)` and retained for `writes.idempotency_window` (7 days by default) — long enough to cover a redelivery storm without the webhook handler needing its own dedupe table. See [Writing](05-writing.md#idempotent-writes).

## Which features does a plan grant?

The set of features a plan includes is itself a timeline — "Pro gained the audit-log feature in March, and lost priority-support in July" — and one plan grants many features, so it is a [temporal pivot](11-pivots.md). The pivot model carries the bitemporal periods; the relation is `bitemporalBelongsToMany`.

```php
use Illuminate\Database\Eloquent\Relations\Pivot;

class PlanFeature extends Pivot
{
    use Bitemporal;

    public $incrementing = true;         // each version is its own row
    protected $table = 'plan_feature';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
```

```php
class Plan extends Model
{
    use HasBitemporalRelations;

    public function features(): BitemporalBelongsToMany
    {
        return $this->bitemporalBelongsToMany(Feature::class)
            ->using(PlanFeature::class);
    }
}
```

The ordinary `attach`/`detach`/`sync` are disabled because they would destroy history; the temporal verbs mirror the change/correct/end vocabulary, specialised to a related model:

```php
// Pro gains the audit-log feature from 1 March.
$pro->features()->attachFor(related: $auditLog, validFrom: '2026-03-01');

// Priority support is dropped from Pro at the end of June.
$pro->features()->detachAt(related: $prioritySupport, validTo: '2026-07-01');

// Correct a grant window that was entered wrong.
$pro->features()->correctAssignment(
    related: $auditLog,
    validFrom: '2026-02-01',    // it was actually included a month earlier
);
```

## "What could they access on 3 June?"

Back to the support ticket. The answer needs the plan the account was on that day *and* the features that plan granted that day — two timelines read at the same instant. Rather than thread `validAt('2026-06-03')` through every query, open an [as-of lens](07-as-of-lens.md) once and let every temporal read inside inherit it.

```php
use Vusys\Bitemporal\Facades\TemporalLens;

$couldAccess = TemporalLens::validAt('2026-06-03', function () use ($account) {
    $plan = $account->subscription()
        ->forDimensions(['region' => 'EU'])
        ->sole();               // implicitly validAt('2026-06-03')

    return Plan::where('tier', $plan->plan)
        ->firstOrFail()
        ->features()
        ->currentKnowledge()
        ->get();                // the grants in force that day
});
```

Every query in the callback resolves as of 3 June, so the reconstructed entitlement set is exactly what the customer saw — not what the plan grants today.

## What to take away

The subscription, the per-region price, and the plan's feature grants are three independent timelines, and the API keeps them from bleeding into each other: dimensions partition the regions, a temporal pivot models the many-to-many grants without losing their history, and the as-of lens reassembles a coherent picture at any past instant. Idempotency keys make the whole thing safe to drive from at-least-once billing webhooks.

Next: [Worked example: tax & regulatory rates](18-example-tax.md).
