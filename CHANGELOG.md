# Changelog

All notable changes to `vusys/laravel-bitemporal` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

