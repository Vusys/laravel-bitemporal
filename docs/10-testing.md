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

## On the roadmap

Dedicated testing ergonomics — an `InteractsWithTimelines` trait with assertions like `assertTemporalTimeline()` and `assertNoTemporalOverlaps()`, Pest expectations, and a `BitemporalFactory` with temporal states — are planned but not yet shipped. Until they land, the public API plus standard PHPUnit/Pest assertions cover testing fully, as shown above.
