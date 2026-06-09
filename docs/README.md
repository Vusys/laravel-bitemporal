# Documentation

User guide for `vusys/laravel-bitemporal` — effective-dated and bitemporal Eloquent models for Laravel.

Start with the concepts, then install, then work through the read and write APIs.

1. [Concepts](01-concepts.md) — valid time, recorded time, spells, anti-rows
2. [Installation](02-installation.md) — requirements, install, publishing config
3. [Defining models](03-defining-models.md) — the `Bitemporal` trait, relations, migrations, the generator
4. [Reading](04-reading.md) — point-in-time reads, spell predicates, entity scoping, eager loading
5. [Writing](05-writing.md) — change, correct, retract, end, supersede, backfill, optimistic concurrency
6. [Dimensions](06-dimensions.md) — modelling more than one timeline per entity
7. [The as-of lens](07-as-of-lens.md) — ambient point-in-time reads with `TemporalLens`
8. [Events](08-events.md) — the write lifecycle and the committed-event result objects
9. [Configuration](09-configuration.md) — every config key explained
10. [Testing](10-testing.md) — how to test code that uses the package

For where the package is in its build and what is still planned, see the [implementation status and roadmap](../README.md#status).
