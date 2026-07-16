# Changelog

All notable changes to `vusys/laravel-bitemporal` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Worked-example correctness — fixes surfaced by end-to-end tests of the four worked examples.

### Fixed
- `backfill()->timeline()` / `importHistoricalKnowledge()` now accept value columns supplied flat on each row (as `supersedeTimeline()` already does), not only nested under an `attributes` key.
- `backfill()->timeline()` stamps the recorded axis as "now" for rows that omit `recorded_from`, instead of rejecting them — matching the documented behaviour for seeding a clean value history.
- `make:bitemporal-model` scaffolds `temporalEntity()` with an explicit foreign key (`<entity>_id`) so it matches the column `bitemporalForeignFor()` emits; the previous stub let Eloquent guess `temporal_entity_id`, which the metadata resolver could not find.

### Documentation
- Corrected the worked examples (insurance, salary, subscriptions, tax) and the model/writing/dimensions guides: pinned the `temporalEntity()` foreign key, made value columns nullable where `retract()` is used, noted that a write replaces the whole value tuple, fixed a zero-length backfill spell, and pinned the `Compensation` table name.

### Tested
- Added a `Tests\Docs` suite that recreates all four worked examples end to end.

## [0.5.0] - 2026-07-16

Bulk-load ergonomics.

### Added
- `withoutIndexes()`: drops and recreates package indexes around bulk loads for faster imports.

### Documentation
- Documented streaming backfill and `withoutIndexes()` in the writing guide.

### Tested
- Closed coverage gaps in `withoutIndexes()`.

## [0.4.0] - 2026-07-16

Engine hardening.

### Added
- PostgreSQL premium path: `tstzrange` + `EXCLUDE USING gist`.
- Boot guards: introspection guards, app guards, and an advisory lint channel.
- `TemporalMetrics` observability interface.
- Streaming backfill (chunked import + scoped post-audit).
- Advisory-lock verification.

### Changed
- Full exception-catalogue parity scan.
- Typed class constants and `resolve()` helper (Rector).

### Tested
- Two-connection concurrency tests on real engines.
- Octane / FrankenPHP / Swoole lens-lifecycle coverage.

## [0.3.0] - 2026-07-15

Documentation site and project infrastructure.

### Added
- MkDocs Material documentation site with GitHub Pages deploy.
- Domain-driven worked-example documentation pages.
- README badges, Codecov coverage upload, and OpenSSF Scorecard.

### Changed
- CI provisions only the matrixed database per cell.
- Removed roadmap references; package presented as feature-complete.
- Dependabot dependency updates (actions/checkout, upload/download-artifact, dev dependencies).

## [0.2.0] - 2026-06-27

Mutation-test hardening.

### Added
- Comprehensive mutation-testing suite across writes, reads, relations, boot guards, console commands, and unit value objects (~91% MSI).

### Changed
- Ratcheted the mutation floor as a union MSI gate.
- Brought tests and fixtures to PHPStan level 9 compliance.

### Fixed
- Blueprint-macros test made Laravel 11 + PHPStan compatible.

## [0.1.0] - 2026-06-23

Core bitemporal engine.

### Added
- Bitemporal trait, temporal query builder, and full read API with period / effective-date queries.
- Value objects: Spell (period), Timeline, TimelineSegment, PeriodBounds.
- Write API: `changeEffectiveFrom`, `correct`, `retract`, `endAt`, `supersedeTimeline`, `forceDeleteHistory`.
- Temporal dimensions (`$temporalDimensions` + `forDimensions`) and the `TemporalLens::asOf()` ambient point-in-time lens.
- Relations: temporal has-many / belongs-to, polymorphic MorphTo, and `BitemporalBelongsToMany` pivots.
- Backfill API for historical-knowledge import.
- Optimistic concurrency and locking strategies.
- Boot guards (config-validation framework + key guards).
- Idempotency keys and an audit-log subscriber.
- Diff helpers and timeline materialisers.
- Temporal migration macros (data-model API).
- Generators and console commands: `make:*` scaffolding, `make:bitemporal-migration`, and `bitemporal:*` audit/diff commands.
- Testing helpers and factories.
- Exception catalogue with `TemporalDomainException`.
- User documentation.

