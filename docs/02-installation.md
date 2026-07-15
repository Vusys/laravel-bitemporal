# Installation

## Requirements

- PHP 8.4+
- Laravel 11, 12, or 13
- One of: PostgreSQL, MySQL/MariaDB, or SQLite

All period columns are stored at **microsecond precision** (`datetime(6)` / `timestamptz`). The package mandates this; the migration macros emit it for you.

## Install

```bash
composer require vusys/laravel-bitemporal
```

The service provider is auto-discovered. It registers the migration Blueprint macros, the `make:bitemporal-model` and `bitemporal:warm-guards` console commands, the `TemporalLens` facade's backing singleton, the queue listeners that reset the as-of lens between jobs, and the write-locker binding implied by your `lock_strategy` config.

## Publish the config (optional)

The package works with sane defaults out of the box. Publish the config only if you want to change column names, the lock strategy, or compaction behaviour:

```bash
php artisan vendor:publish --tag=bitemporal-config
```

This writes `config/bitemporal.php`. Every key is documented in [Configuration](09-configuration.md).

## No package migrations to run

The package ships no tables of its own for the core feature set — your temporal data lives in *your* tables, declared with the Blueprint macros (see [Defining models](03-defining-models.md)). The optional companion tables (idempotency keys, the audit log) ship as publishable migrations you can opt into when you enable those features.

Next: [Defining models](03-defining-models.md).
