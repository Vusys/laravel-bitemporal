# Temporal pivots

A bitemporal pivot models a **many-to-many assignment that is itself a timeline**: which roles a user held, and *when* — both in valid time ("from June this user was an admin") and in recorded time ("we corrected that on the 10th"). The pivot row is not a flag you flip; it is a temporal model scoped to the `(parent, related)` pair, with the same correction-preserving guarantees as any other timeline.

`BitemporalBelongsToMany` is the relation that exposes it.

## The pivot model

The pivot extends Laravel's `Pivot` and adds the `Bitemporal` trait. It does **not** declare a `$temporalEntity` — its entity is the injected composite `(parent, related)` tuple, which the relation supplies at resolution time. (The relation-type boot guard exempts `Pivot` models for exactly this reason.)

```php
use Illuminate\Database\Eloquent\Relations\Pivot;
use Vusys\Bitemporal\Bitemporal;

class UserRoleAssignment extends Pivot
{
    use Bitemporal;

    public $incrementing = true;          // it's a timeline, so rows have their own id
    protected $table = 'user_role_assignments';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
```

Set `public $incrementing = true` — unlike an ordinary pivot, each version is a distinct row with its own primary key. Any non-key columns (here a `scope`) are ordinary temporal attributes you can correct over time.

## The relation

Declare the relation on the parent with `bitemporalBelongsToMany()`, telling it which pivot model carries the timeline:

```php
use Vusys\Bitemporal\Relations\BitemporalBelongsToMany;

class User extends Model
{
    use HasBitemporalRelations;

    public function roles(): BitemporalBelongsToMany
    {
        return $this->bitemporalBelongsToMany(Role::class)
            ->using(UserRoleAssignment::class);
    }
}
```

```php
public function bitemporalBelongsToMany(
    string $related,
    ?string $using = null,
    ?string $foreignPivotKey = null,
    ?string $relatedPivotKey = null,
    ?string $parentKey = null,
): BitemporalBelongsToMany
```

The pivot class can be passed as the `$using` argument or chained with `->using(...)` (as above). The key names default to Laravel's conventions (`user_id`, `role_id`); override them with the remaining arguments if your columns differ. **`->using()` is required** — a read or write before it is set throws `TemporalConfigurationException`.

## The migration

The pivot table carries both foreign keys, any attribute columns, and the full bitemporal period set:

```php
Schema::create('user_role_assignments', function (Blueprint $table) {
    $table->id();
    $table->bitemporalForeignFor(User::class);   // user_id, FK, restrictOnDelete
    $table->bitemporalForeignFor(Role::class);   // role_id, FK, restrictOnDelete

    $table->string('scope')->nullable();         // a temporal pivot attribute

    $table->bitemporalPeriods();                 // valid_* + recorded_* (µs) + is_retraction
    $table->timestamps();

    $table->preventBitemporalOverlaps(['user_id', 'role_id']);
});
```

The overlap-prevention index is keyed on **both** foreign keys, because the related key is folded into the assignment's dimension tuple — each `(user, role)` pair is its own independent timeline.

## Writing assignments

The standard `attach`/`detach`/`sync` methods are **disabled** — they would destroy history. Each throws `TemporalConfigurationException` pointing at the temporal replacement:

| Disabled | Use instead |
| --- | --- |
| `attach()` | `attachFor()` |
| `detach()` | `detachAt()` |
| `sync()` | `attachFor()` / `detachAt()` |

The temporal write methods mirror the change/correct/end vocabulary from [Writing](05-writing.md), specialised to a `related` model:

```php
// Assign a role from a date (open-ended).
$user->roles()->attachFor(related: $admin, validFrom: '2026-06-01');

// Assign over a closed window.
$user->roles()->attachFor(
    related: $admin,
    validFrom: '2026-06-01',
    validTo: '2026-08-01',
    attributes: ['scope' => 'eu'],
);

// End an open-ended assignment at a date.
$user->roles()->detachAt(related: $admin, validTo: '2026-09-01');

// Retroactively correct an existing assignment (value and/or window).
$user->roles()->correctAssignment(
    related: $admin,
    validFrom: '2026-06-01',
    validTo: '2026-08-01',
    attributes: ['scope' => 'global'],
);
```

```php
public function attachFor(
    Model $related,
    CarbonInterface|string $validFrom,
    CarbonInterface|string|null $validTo = null,
    array $attributes = [],
): TemporalWriteCommitted

public function detachAt(
    Model $related,
    CarbonInterface|string $validTo,
): TemporalWriteCommitted

public function correctAssignment(
    Model $related,
    CarbonInterface|string|null $validFrom = null,
    CarbonInterface|string|null $validTo = null,
    array $attributes = [],
): TemporalWriteCommitted
```

Each returns the same committed-event object as any other write (see [Events](08-events.md)). `detachAt()` throws `TemporalCardinalityException` if there is no open-ended current assignment to end; `correctAssignment()` throws the same if the tuple has no existing assignment — use `attachFor()` to create one first.

## Reading assignments

Reads use the ordinary point-in-time predicates from [Reading](04-reading.md), evaluated over the pivot timeline. The relation yields **pivot models**, so the related key and any pivot attributes are read off the assignment itself:

```php
// Roles currently assigned, as we believe today.
$user->roles()->currentKnowledge()->get();

// Was this assignment in force mid-July?
$user->roles()->validAt('2026-07-01')->currentKnowledge()->get();   // 1 row
$user->roles()->validAt('2026-09-01')->currentKnowledge()->get();   // 0 rows

// The single assignment valid at a date, with its attribute.
$user->roles()
    ->validAt('2026-07-01')
    ->currentKnowledge()
    ->sole()
    ->getAttribute('scope');                                        // 'eu'
```

To resolve the actual `Role` models, join or eager-load from the related keys as you would any pivot.

Next: [Diffs and timelines](12-diffs-and-timelines.md).
