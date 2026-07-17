# Defining models

A temporal setup has two sides: the **entity** (the thing that persists — a `Product`, a `User`) and the **temporal model** (the versioned facts about it — a `ProductPrice`, a `UserRole`). The entity table is ordinary; the temporal table carries the period columns.

## The temporal model

Add the `Bitemporal` trait and declare the entity it versions with the `$temporalEntity` class-string. That is the only required piece of configuration — the trait builds the `BelongsTo` and discovers the foreign key from it.

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Bitemporal;

class ProductPrice extends Model
{
    use Bitemporal;

    protected string $temporalEntity = Product::class;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
```

The library derives the foreign key from the entity's natural key — `product_id` — which is exactly the column `bitemporalForeignFor()` emits in the migration, so the schema and the relation cannot drift. A polymorphic parent or a non-conventional foreign key cannot be named by a single class-string; those models drop the property and override `temporalEntityRelation()` instead (see [polymorphic entities](#polymorphic-entities)).

You do **not** declare `$casts` for the period columns — the trait applies `immutable_datetime` casts to `valid_from`, `valid_to`, `recorded_from`, `recorded_to` and a boolean cast to `is_retraction` automatically. Disable that with `protected bool $autoApplyTemporalCasts = false;` if you need to manage casts yourself.

The `$dateFormat = 'Y-m-d H:i:s.u'` line preserves microseconds through Eloquent's date serialisation; keep it.

### Per-model overrides

Everything has a config-backed default, overridable per model with a property:

| Property | Purpose | Default |
| --- | --- | --- |
| `protected bool $tracksRecordedTime` | Set `false` for an effective-dated-only model (no recorded spell) | `true` |
| `protected array $temporalDimensions` | Extra columns that scope independent timelines — see [Dimensions](06-dimensions.md) | `[]` |
| `protected bool $autoApplyTemporalCasts` | Auto-cast the period columns | `true` |
| `protected string $validFromColumn` (and `validToColumn`, `recordedFromColumn`, `recordedToColumn`, `isRetractionColumn`) | Rename an individual column for this model | config value |

## The entity (parent) model

Add `HasBitemporalRelations` to the owner and expose the timeline with a relation factory:

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Concerns\HasBitemporalRelations;
use Vusys\Bitemporal\Relations\BitemporalMany;

class Product extends Model
{
    use HasBitemporalRelations;

    public function prices(): BitemporalMany
    {
        return $this->bitemporalMany(ProductPrice::class);
    }
}
```

The relation factories:

- `bitemporalMany($related, $foreignKey = null, $localKey = null)` — the timeline as a one-to-many. This is the relation you call the write API on.
- `bitemporalOne($related, …)` — the single current/as-of row; `sole()` returns `null` when absent.
- `bitemporalOneOrFail($related, …)` — same, but throws `TemporalCardinalityException` when absent.
- `bitemporalMorphMany($related)` — for a temporal model whose `temporalEntityRelation()` is a `MorphTo` (a single timeline table shared across many entity types). See [polymorphic entities](#polymorphic-entities).

## Migrations

The package registers Blueprint macros so a temporal table reads declaratively:

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::create('product_prices', function (Blueprint $table) {
    $table->id();
    $table->bitemporalForeignFor(Product::class);   // product_id, FK, restrictOnDelete

    $table->decimal('amount', 10, 2);

    $table->bitemporalPeriods();                      // valid_* + recorded_* at µs precision + is_retraction
    $table->timestamps();

    $table->preventBitemporalOverlaps(['product_id']);
});
```

Macro reference:

| Macro | Emits |
| --- | --- |
| `validPeriod($options = [], $nullable = false)` | `valid_from`, `valid_to` (µs), `is_retraction` — effective-dated only |
| `temporalPeriod(...)` | alias of `validPeriod` |
| `recordedPeriod(...)` | `recorded_from`, `recorded_to` (µs) |
| `bitemporalPeriods(...)` | both of the above — the usual choice |
| `bitemporalForeignFor($related)` | `foreignIdFor($related)->constrained()->restrictOnDelete()` |
| `bitemporalMorphsFor($name)` | `morphs($name)` for a polymorphic entity column |
| `preventTemporalOverlaps($entityColumns, $dimensions = [])` | a covering index over the entity + dimensions + valid spell |
| `preventBitemporalOverlaps($entityColumns, $dimensions = [])` | the same, including the recorded spell |

`$options` lets you override individual column names (e.g. `bitemporalPeriods(['valid_from' => 'effective_from'])`); `$nullable` makes the *from* columns nullable for backfill scenarios.

> **On overlap prevention.** `preventBitemporalOverlaps()` emits a covering composite index on every driver. The writer's application-level overlap detection is the primary guarantee on every engine, so a correct setup never produces overlaps; the index is defence in depth.

> **Make value columns nullable if you retract.** A [`retract()`](05-writing.md) inserts an *anti-row* — a row that asserts a period never happened — with every domain value column `NULL`. If a value column is declared `NOT NULL`, the retraction fails on the constraint. Any timeline you intend to retract from (or backfill anti-rows into) must have nullable value columns.

### Polymorphic entities

When one timeline table serves many entity types, a single class-string can't name the parent — override `temporalEntityRelation()` to return a `MorphTo`, use `bitemporalMorphsFor()` in the migration, and `bitemporalMorphMany()` on each parent:

```php
// migration
$table->bitemporalMorphsFor('owner');          // owner_type, owner_id

// temporal model — override instead of declaring $temporalEntity
public function temporalEntityRelation(): MorphTo
{
    return $this->morphTo('owner');
}

// parent
public function statuses(): BitemporalMany
{
    return $this->bitemporalMorphMany(Status::class);
}
```

## Many-to-many timelines

A many-to-many assignment that is itself effective-dated — which roles a user held, and when — is modelled with a temporal pivot via `bitemporalBelongsToMany()`. See [Temporal pivots](11-pivots.md).

## Generators

Three commands scaffold the moving parts (full reference in [Commands](14-commands.md)):

```bash
php artisan make:bitemporal-model ProductPrice --entity=Product
php artisan make:bitemporal-migration create_product_prices_table --model=ProductPrice
php artisan make:bitemporal-factory ProductPriceFactory --model=ProductPrice
```

- `make:bitemporal-model` scaffolds the trait, the `$dateFormat`, and the `$temporalEntity` class-string.
- `make:bitemporal-migration` scaffolds the migration pre-filled with the Blueprint macros above (add `--temporal-only` for an effective-dated-only table).
- `make:bitemporal-factory` scaffolds a `BitemporalFactory` for tests (see [Testing](10-testing.md#factories)).

You still fill in the domain columns and add the relation to the parent.

Next: [Reading](04-reading.md).
