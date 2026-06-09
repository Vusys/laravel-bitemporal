# Laravel Bitemporal Models

Safe effective-dated and bitemporal relations for Laravel.

`vusys/laravel-bitemporal` provides first-class support for **effective-dated** and **bitemporal** Eloquent data — modelling facts that change over time, where the application needs to answer:

- What was true on this business date?
- What did the system believe was true on this business date at the time?
- When did this value become effective, and when was a correction recorded?

The value is not a handful of query scopes. It is a custom temporal relation with **safe write operations**, range splitting, correction handling, point-in-time eager loading, and overlap prevention — so you can correct the past without destroying the previous state of knowledge or creating overlapping historical facts.

```php
$product->prices()->correct(attributes: ['amount' => 12.00], validFrom: '2026-02-01');
```

## Installation

```bash
composer require vusys/laravel-bitemporal
```

PHP 8.4+, Laravel 11 / 12 / 13, and PostgreSQL, MySQL/MariaDB, or SQLite. The service provider is auto-discovered. See [Installation](docs/02-installation.md) for the optional config publish.

## Quick example

A product has many price versions. The entity model exposes the timeline; the temporal model carries the period columns.

```php
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

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

class ProductPrice extends Model
{
    use Bitemporal;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

Period-column casts are applied automatically — you don't declare them. The migration uses the package's Blueprint macros:

```php
Schema::create('product_prices', function (Blueprint $table) {
    $table->id();
    $table->bitemporalForeignFor(Product::class);
    $table->decimal('amount', 10, 2);
    $table->bitemporalPeriods();
    $table->timestamps();
    $table->preventBitemporalOverlaps(['product_id']);
});
```

Read at a point in time:

```php
$price = $product->prices()
    ->validAt($invoice->issued_at)     // true on this business date
    ->knownAt($invoice->created_at)    // as we believed it then
    ->sole();
```

Write safely:

```php
$product->prices()->changeEffectiveFrom(['amount' => 12.00], validFrom: '2026-06-01');
$product->prices()->correct(['amount' => 12.00], validFrom: '2026-02-01', validTo: '2026-03-01');
$product->prices()->retract(validFrom: '2026-02-01', validTo: '2026-03-01');
```

## Documentation

Full user guide in [`docs/`](docs/README.md):

- [Concepts](docs/01-concepts.md) · [Installation](docs/02-installation.md) · [Defining models](docs/03-defining-models.md)
- [Reading](docs/04-reading.md) · [Writing](docs/05-writing.md) · [Dimensions](docs/06-dimensions.md)
- [As-of lens](docs/07-as-of-lens.md) · [Events](docs/08-events.md) · [Configuration](docs/09-configuration.md) · [Testing](docs/10-testing.md)

## Requirements

- PHP 8.4+
- Laravel 11 / 12 / 13
- PostgreSQL, MySQL/MariaDB, or SQLite

## Status

The package is pre-1.0 and built from a detailed internal specification. The read side, the core write side (change / correct / retract / end / supersede / hard-delete), dimensions, the as-of lens, polymorphic entities, backfill, optimistic concurrency, lock strategies, the migration macros, and the first generator are implemented and green on SQLite.

Still in progress: temporal pivots (`BitemporalBelongsToMany`), idempotency keys, a first-party audit-log subscriber, the PostgreSQL `EXCLUDE` / MySQL sentinel database grammars (verified against real engines in CI), dedicated testing helpers and factories, diff helpers, and the remaining generators.

## Development

```bash
composer install
composer ci          # phpstan (L9, no baseline) + pint + rector + phpunit
composer test
composer infection   # mutation testing
```

## License

MIT. See [LICENSE](LICENSE).
