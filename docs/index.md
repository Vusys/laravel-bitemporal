# Laravel Bitemporal

Safe effective-dated and bitemporal relations for Laravel.

`vusys/laravel-bitemporal` provides first-class support for **effective-dated** and **bitemporal** Eloquent data — modelling facts that change over time, where the application needs to answer:

- What was true on this business date?
- What did the system believe was true on this business date at the time?
- When did this value become effective, and when was a correction recorded?

The value is not a handful of query scopes. It is a custom temporal relation with **safe write operations**, range splitting, correction handling, point-in-time eager loading, and overlap prevention — so you can correct the past without destroying the previous state of knowledge or creating overlapping historical facts.

```php
$product->prices()->correct(attributes: ['amount' => 12.00], validFrom: '2026-02-01');
```

## Install

```bash
composer require vusys/laravel-bitemporal
```

PHP 8.4+, Laravel 11 / 12 / 13, and PostgreSQL, MySQL/MariaDB, or SQLite. The service provider is auto-discovered.

## Start here

New to the package? Work through it in order:

1. [Concepts](01-concepts.md) — valid time, recorded time, spells, anti-rows
2. [Installation](02-installation.md) — requirements, install, publishing config
3. [Defining models](03-defining-models.md) — the `Bitemporal` trait, relations, migrations, the generators
4. [Reading](04-reading.md) — point-in-time reads, spell predicates, entity scoping, eager loading
5. [Writing](05-writing.md) — change, correct, retract, end, supersede, backfill, concurrency, idempotency

Then reach for the rest as you need it:

- [Dimensions](06-dimensions.md) · [The as-of lens](07-as-of-lens.md) · [Events](08-events.md)
- [Configuration](09-configuration.md) · [Exception catalogue](09a-exception-catalogue.md) · [Testing](10-testing.md)
- [Temporal pivots](11-pivots.md) · [Diffs and timelines](12-diffs-and-timelines.md) · [Boot guards and lints](13-boot-guards-and-lints.md) · [Commands](14-commands.md)

## See it in a real domain

Prefer to learn from a full worked example? Each of these follows one domain end to end — schema, writes, reads, and history — and leans on a different corner of the API:

- [Insurance & claims](15-example-insurance.md) — recorded time and `knownAt`, retroactive endorsements, retractions, and knowledge diffs
- [Salary history](16-example-salary.md) — `changeEffectiveFrom` vs `correct`, reproducible payroll, optimistic concurrency, and backfilling
- [SaaS subscriptions](17-example-subscriptions.md) — dimensions, temporal pivots, webhook-safe idempotent writes, and the as-of lens
- [Tax & regulatory rates](18-example-tax.md) — back-dated legislation, full restatements, and importing historical beliefs
