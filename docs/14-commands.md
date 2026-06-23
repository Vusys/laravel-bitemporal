# Commands

The package registers a set of Artisan commands — generators that scaffold code, and operational commands that audit and maintain a live timeline. All are auto-registered by the service provider.

## Generators

### `make:bitemporal-model`

Scaffolds a temporal model: the `Bitemporal` trait, the `$dateFormat`, and a `BelongsTo` `temporalEntity()`.

```bash
php artisan make:bitemporal-model ProductPrice --entity=Product
```

### `make:bitemporal-migration`

Generates a migration for a temporal table, pre-filled with the Blueprint macros (`bitemporalForeignFor`, `bitemporalPeriods`, `preventBitemporalOverlaps`). Pass `--model` to infer the table name, entity foreign key, and dimensions from an existing temporal model.

```bash
php artisan make:bitemporal-migration create_product_prices_table --model=ProductPrice
```

| Argument / option | Purpose |
| --- | --- |
| `name` | the migration name (e.g. `create_product_prices_table`) |
| `--model=` | a temporal model (FQCN or basename, auto-prefixed `App\Models\`) to infer table/entity/dimensions from |
| `--temporal-only` | emit `temporalPeriod()` instead of `bitemporalPeriods()` for an effective-dated-only table |

You still fill in the domain columns. See [Defining models](03-defining-models.md) for the macro reference.

### `make:bitemporal-factory`

Scaffolds a factory extending `BitemporalFactory`, wired to a model. If `--model` is omitted it is inferred by dropping the `Factory` suffix from the name.

```bash
php artisan make:bitemporal-factory ProductPriceFactory --model=ProductPrice
```

See [Testing](10-testing.md#factories) for the factory states.

## Operational commands

### `bitemporal:audit-overlaps`

Scans the current-knowledge timeline of a model for overlapping valid periods within any `(entity, dimensions)` tuple — the invariant the writer guarantees, verified against what is actually on disk. Exit code `0` when clean, `1` when overlaps are found (so it fits a CI or cron gate).

```bash
php artisan bitemporal:audit-overlaps --model="App\Models\ProductPrice"
```

Each overlap is reported as `overlap in tuple [key] between #id1 and #id2`.

### `bitemporal:audit-table`

Renders one entity's timeline as a human-readable table — the valid and recorded bounds and the retraction flag for each row. Add `--full` to include superseded beliefs (the full physical history), not just current knowledge.

```bash
php artisan bitemporal:audit-table --model="App\Models\ProductPrice" --entity-id=123
php artisan bitemporal:audit-table --model="App\Models\ProductPrice" --entity-id=123 --full
```

### `bitemporal:diff-timelines`

Compares what was believed about an entity at two recorded dates — the command-line face of [`diffTimelines()`](12-diffs-and-timelines.md). It prints the added / removed / changed / unchanged counts and the changed attribute names.

```bash
php artisan bitemporal:diff-timelines \
    --model="App\Models\ProductPrice" \
    --entity-id=123 \
    --from-known-at="2026-02-20" \
    --to-known-at="2026-03-10"
```

```
added: 1, removed: 0, changed: 1, unchanged: 5
  changed [amount]
```

### `bitemporal:warm-guards`

Runs the [boot guards and lints](13-boot-guards-and-lints.md) against the named models now, so misconfiguration fails at deploy time rather than on first request. Accepts one or more model class names. Non-zero exit on a guard failure.

```bash
php artisan bitemporal:warm-guards "App\Models\ProductPrice" "App\Models\UserRoleAssignment"
```

### `bitemporal:prune-idempotency-keys`

Deletes idempotency keys older than `writes.idempotency_window` (see [Configuration](09-configuration.md)). When `writes.idempotency_auto_prune` is `true` (the default) the service provider schedules this `daily()` for you; run it by hand for a one-off sweep or when you manage the schedule yourself.

```bash
php artisan bitemporal:prune-idempotency-keys
php artisan bitemporal:prune-idempotency-keys --connection=tenant
```

| Option | Purpose |
| --- | --- |
| `--connection=` | the database connection to prune (defaults to the configured one) |

Next: [Exception catalogue](09a-exception-catalogue.md).
