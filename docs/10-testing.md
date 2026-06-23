# Testing

You test code that uses this package the way you test any Eloquent code — against a real (test) database, usually SQLite in-memory — exercising the read and write APIs and asserting on the resulting rows.

## Setup

SQLite is fully supported and is what the package's own suite runs on by default. Point your test connection at `:memory:`, run your migrations (which use the Blueprint macros), and use the package normally. No special test harness is required.

### Running the suite against MySQL, MariaDB, and PostgreSQL

The package's own test suite is engine-agnostic and passes on SQLite, MySQL 8.4, MariaDB, and PostgreSQL 16. `tooling/db-compose.yml` brings those engines up locally on non-conflicting ports, and `tooling/test-all-backends.sh` runs the whole suite against each:

```bash
docker compose -f tooling/db-compose.yml up -d --wait
tooling/test-all-backends.sh
docker compose -f tooling/db-compose.yml down -v
```

You need `pdo_mysql` and `pdo_pgsql` in your CLI PHP. To target a single engine, set the `DB_*` env vars from the compose file's header comment in front of `vendor/bin/phpunit`.

```php
public function test_a_correction_preserves_history(): void
{
    $product = Product::create(['name' => 'Widget']);

    $product->prices()->changeEffectiveFrom(['amount' => 10.00], '2026-01-01');
    $product->prices()->correct(['amount' => 12.00], validFrom: '2026-01-01');

    // Current knowledge sees the correction.
    $this->assertSame(
        12.00,
        (float) $product->prices()->validAt('2026-06-01')->currentKnowledge()->sole()->amount,
    );

    // The superseded belief is still there under knownAt().
    $this->assertSame(2, $product->prices()->withoutLens()->count());
}
```

## Assert on the committed event

Because each write returns its committed event, you can assert on exactly what the write did without re-querying — closed/inserted counts, the recorded instant, the affected rows:

```php
$result = $product->prices()->correct(['amount' => 12.00], validFrom: '2026-01-01');

$this->assertSame(1, $result->closedCount());
$this->assertSame(1, $result->insertedCount());
$this->assertFalse($result->compacted);
```

## Assert events fired

The committed events are ordinary Laravel events, so `Event::fake()` works:

```php
use Vusys\Bitemporal\Events\TemporalCorrectionCommitted;

Event::fake([TemporalCorrectionCommitted::class]);

$product->prices()->correct(['amount' => 12.00], validFrom: '2026-01-01');

Event::assertDispatched(TemporalCorrectionCommitted::class);
```

Note these fire on `afterCommit`, so wrap-in-transaction test traits that never commit will suppress them; prefer real (committed) writes in tests that assert on these events.

## Time control

Use Laravel's `travelTo()` to pin "now" so the recorded-time axis and any open-ended `validFrom` defaults are deterministic:

```php
$this->travelTo('2026-06-01 12:00:00', function () use ($product) {
    $product->prices()->changeEffectiveFrom(['amount' => 12.00], now());
});
```

## Timeline assertions

The `InteractsWithTimelines` trait adds assertions that read a timeline the way you reason about it, instead of hand-querying rows. Use it in any PHPUnit test case:

```php
use Vusys\Bitemporal\Testing\InteractsWithTimelines;

class PriceTest extends TestCase
{
    use InteractsWithTimelines;
}
```

| Assertion | Checks |
| --- | --- |
| `assertTemporalAttributes($relation, $validAt, $knownAt = null, $attributes)` | the single row valid at `$validAt` (optionally known at `$knownAt`) has these attributes |
| `assertTemporalTimeline($relation, $expected, $includeSuperseded = false)` | the current-known timeline matches `$expected` positionally, ordered by `valid_from` |
| `assertTemporalTimelineUnordered($relation, $expected, …)` | the same, as a set (order-independent) |
| `assertNoTemporalOverlaps($modelClass)` | no two current-known rows in any tuple overlap on the valid axis |
| `assertNoBitemporalOverlaps($modelClass)` | no two physical rows (current or superseded) overlap on **both** axes |
| `assertExactlyOneOpenEndedCurrentKnownPerDimensionTuple($entity)` | each tuple under `$entity` has at most one open-ended current row |
| `expectTemporalException($class, $messageSubstring = null)` | a temporal exception of that type is thrown |
| `expectGuardFailure($guardClass, $callback)` | `$callback` raises a `TemporalConfigurationException` listing that boot guard |

```php
$product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-01-01');
$product->prices()->changeEffectiveFrom(['amount' => 1200], '2026-06-01');

$this->assertTemporalTimeline($product->prices(), [
    ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
    ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null],
]);

$this->assertNoTemporalOverlaps(ProductPrice::class);
```

Pass a relation (`$product->prices()`) or a `BitemporalBuilder` to the relation-based assertions; pass the temporal model class to the overlap auditors.

## Pest expectations

For Pest, the package registers chainable expectations on the temporal builder. They are wired up automatically while the test suite is running — no manual setup, and a no-op when Pest isn't installed:

```php
expect($product->prices())
    ->validAt('2026-02-15')->knownAt('2026-03-10')
    ->toHaveTemporalAttributes(['amount' => 1200]);

expect(fn () => $product->prices()->changeEffectiveFrom(['amount' => 1], '2020-01-01'))
    ->toThrowTemporalException(TemporalInvalidSpellException::class);
```

## Factories

`BitemporalFactory` is a base factory with chainable temporal states, so you can seed history directly without going through the write API. Extend it and set `$model`:

```php
use Vusys\Bitemporal\Factories\BitemporalFactory;

class ProductPriceFactory extends BitemporalFactory
{
    protected $model = ProductPrice::class;

    public function definition(): array
    {
        return ['amount' => 1000, 'currency' => 'GBP'];
        // valid_*/recorded_* are supplied by the states below.
    }
}
```

The states compose:

| State | Effect |
| --- | --- |
| `validFrom($date)` / `validTo($date\|null)` | set the valid spell (`null` = open-ended) |
| `recordedFrom($date)` / `recordedTo($date\|null)` | set the recorded spell (`null` = current knowledge) |
| `currentKnowledge()` | `recorded_to = null` |
| `openEnded()` | `valid_to = null` |
| `superseded($at)` | `recorded_to = $at` — a belief held until `$at` |
| `retracted()` | mark an anti-row: `is_retraction = true`, value columns nulled |

```php
ProductPrice::factory()->for($product)
    ->validFrom('2026-01-01')->validTo('2026-06-01')
    ->currentKnowledge()
    ->create();
```

Scaffold one with `php artisan make:bitemporal-factory ProductPriceFactory --model=ProductPrice` (see [Commands](14-commands.md)).
