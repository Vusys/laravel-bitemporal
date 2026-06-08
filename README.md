# Laravel Bitemporal Models

Safe effective-dated and bitemporal relations for Laravel.

`vusys/laravel-bitemporal` provides first-class support for **effective-dated** and **bitemporal** Eloquent data — modelling facts that change over time, where the application needs to answer:

- What was true on this business date?
- What did the system believe was true on this business date at the time?
- When did this value become effective, and when was a correction recorded?

The value is not a handful of query scopes. It is a custom temporal relation with **safe write operations**, database constraints where possible, range splitting, correction handling, point-in-time eager loading, and testing helpers — so you can write:

```php
$product->prices()->correctPeriod(attributes: ['amount' => 1200], validFrom: '2026-02-01');
```

without accidentally destroying the previous state of knowledge or creating overlapping historical facts.

## Quick example

```php
class Product extends Model
{
    use HasBitemporalRelations;

    public function prices(): BitemporalMany
    {
        return $this->bitemporalMany(ProductPrice::class);
    }
}

class ProductPrice extends Model
{
    use Bitemporal;
}

$price = $product->prices()
    ->validAt($invoice->issued_at)
    ->knownAt($invoice->created_at)
    ->sole();
```

## Requirements

- PHP 8.4+
- Laravel 11 / 12 / 13
- PostgreSQL, MySQL/MariaDB, or SQLite

## Documentation

The full specification lives in [`docs/`](docs/README.md):

- [Overview](docs/01-overview.md) · [Data model](docs/02-data-model.md) · [Relations](docs/03-relations.md)
- [Query API](docs/04-query-api.md) · [Write API](docs/05-write-api.md) · [Algorithms](docs/06-algorithms.md)
- [Value objects](docs/06a-value-objects.md) · [Database support](docs/07-database-support.md)
- [Events & exceptions](docs/09-events-exceptions.md) · [Testing](docs/10-testing.md) · [Configuration](docs/11-configuration.md)
- [Architecture](docs/12-architecture.md) · [Implementation phases](docs/16-implementation-phases.md)

## Development

```bash
composer install
composer ci          # phpstan (L9) + pint + rector + phpunit
composer test
composer infection
```

## License

MIT. See [LICENSE](LICENSE).
