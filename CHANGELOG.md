# Changelog

All notable changes to `vusys/laravel-bitemporal` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.0] - 2026-07-18

A simpler entity declaration, plus an audit-driven wave of correctness and concurrency hardening across the writer, backfill, locking, and boundary-precision paths — and a property-based journey-testing harness that exercises the invariants against the real database matrix.

### Changed
- **BREAKING:** a temporal model now declares the entity it versions with a `$temporalEntity` class-string instead of a `temporalEntity()` relation method:

  ```php
  // before
  public function temporalEntity(): BelongsTo
  {
      return $this->belongsTo(Product::class, 'product_id');
  }

  // after
  protected string $temporalEntity = Product::class;
  ```

  The library builds the `BelongsTo` and derives the foreign key from the entity's natural key (`<entity>_id`) — the same column `bitemporalForeignFor()` emits — so the two can no longer drift, and the foreign key no longer has to be pinned by hand. Models with a polymorphic parent or a non-conventional foreign key override `temporalEntityRelation()` (returning a `BelongsTo` or `MorphTo`) instead of declaring the property. To migrate, replace each `temporalEntity()` method with the property, or rename it to `temporalEntityRelation()` where it returns a `MorphTo` or pins a custom key.
- The `Bitemporal` trait now defaults `$dateFormat` to `Y-m-d H:i:s.u`, so temporal models no longer need to declare it by hand — the trait already owned the in-memory precision (the immutable casts); this closes the storage half, since Eloquent would otherwise serialise the writer's microsecond instants with the connection default (`Y-m-d H:i:s`) and silently truncate them. An explicit `$dateFormat` still wins; `make:bitemporal-model` no longer scaffolds the line.

### Added
- `BootLintTruncatedDateFormat`: warns when a model declares a `$dateFormat` without sub-second precision, which would truncate temporal spells on save.
- `TemporalConfigurationException::nativeRangesUnsupported()`: the never-read `database.prefer_native_ranges` flag now fails fast at boot instead of producing tables whose reads throw a cryptic "column valid_from does not exist" (native `tstzrange` reads are not yet implemented; the composite-index layout remains the supported mode).
- `idempotencyStore` now throws `idempotencySnapshotUnreadable` for a claimed key whose stored result is undecodable, instead of returning a "miss" and silently re-executing a committed write.

### Fixed
- **Idempotency is now concurrency-safe.** The replay check and key-write both run inside the write transaction after the lock is acquired, so two concurrent same-key requests serialize and the second replays instead of double-applying; the replay path no longer re-dispatches the committed event (which double-wrote audit rows and metrics), and `store()` uses `insertOrIgnore` so a colliding claim degrades to a no-op rather than a raw `QueryException`.
- **Effective-dated-only models (`$tracksRecordedTime = false`) can be written again.** Every write path previously called `currentKnowledge()` and stamped a `recorded_to` column that these models do not have; the writer now applies recorded-time logic only when the model tracks it and closes superseded rows physically (delete) for value-time-only data.
- **Composite spell-cast bounds parse in the configured timezone** (`bitemporal.spells.timezone`) instead of the ambient app timezone, so offset-less `DATETIME`/`TEXT` round-trips are instant-stable and boundary comparisons (`containsInstant`, `intersects`, `merge`) are no longer silently corrupted when the app timezone differs from storage.
- **Empty polymorphic entity filter no longer leaks the whole table.** `wherePolymorphicEntityIn()` with an empty set built a where-group Laravel drops, leaving the query unconstrained; it now short-circuits to a false predicate, matching the scalar `whereIn([])` path.
- **The streaming backfill cross-chunk audit checks both axes.** It now loads all rows for a tuple (not just current-known) and tests intersection on both the valid and recorded axes via the now-public `BackfillValidator::overlaps`, reads `withoutLens()`, and falls back to valid-axis intersection for effective-dated-only tuples — so two chunks importing overlapping closed beliefs, or a conflict hidden behind an active as-of/known-at lens, are caught.
- **`TemporalCompactionPerformed` fires post-commit**, not inside the write transaction (which could still roll back), so the metrics subscriber no longer records compaction work that never committed.
- **Dimension validation reports the real error first:** a declared dimension omitted from the tuple now surfaces as "incomplete dimension" rather than a misleading value-vs-null "conflict", and the check is order-independent.
- **Single-result temporal relations are deterministic.** `bitemporalOne` reads force a total order (latest valid period, then latest belief, then key) across the lazy and eager paths, so an unpinned read is at least reproducible; any user `orderBy` still wins. Such relations should still be pinned with `validAt`/`knownAt`/`currentKnowledge` or read inside an `asOf()` frame.
- **`bitemporalBelongsToMany` reads without `->using()` now fail loudly** at execution (`getResults()`, `get()`, `addEagerConstraints()`), matching the write-path guard, instead of querying the far-model stand-in and returning wrong rows or a raw SQL error.
- **Advisory-lock keys can no longer collide.** `AdvisoryLocker::key()` folds every component into a single fixed 40-char digest so a long FQCN/table name cannot shear the discriminating id off MySQL's 64-char `GET_LOCK` budget; lock release in `finally`/retry paths routes through `releaseQuietly()` so a swap-detected release throw no longer masks the in-flight exception or leaks the lock.
- **Numeric attribute equality compares by value** (`'10.00'` vs `10`, `'0'` vs `0`), so decimal/driver type drift no longer produces spurious change diffs and close+reinsert churn. Also: the writer resolves close targets by valid-spell identity rather than positional index, and `PostgresSpellCast` un-doubles interior `""` in quoted range elements.
- **Attribute equality no longer folds distinct values through `float`.** `equals("007", "7")`, `equals("1000", "1e3")`, and integers past 2^53 all wrongly compared equal, so a genuine correction was treated as a no-op and the write silently dropped. The numeric fold is now kept only for its purpose — string/numeric driver drift (`"10.00"` vs `10`) — while two integers compare with `===` (exact past 2^53) and distinct numeric strings stay distinct.
- **The advisory write lock is taken on the write-transaction connection.** When the entity and related models used different connections the lock was acquired on the wrong session — no serialization on MySQL/MariaDB, and on PostgreSQL a `SET LOCAL` / `pg_advisory_xact_lock` outside any transaction meant *zero* mutual exclusion. The lock now threads through the write-transaction connection, and PostgreSQL refuses to lock outside a transaction (`TemporalWriteConflictException::lockOutsideTransaction`) rather than silently locking nothing.
- **The Postgres `EXCLUDE USING gist` constraint is NULL-dimension safe.** A plain `dim WITH =` never rejected two rows sharing a NULL dimension and overlapping periods (`NULL = NULL` is `NULL`, not true), even though the package treats NULL as a first-class dimension value. Nullable dimension columns are now emitted with `coalesce(dim::text, <sentinel>) WITH =` NULL-equal semantics.
- **`Spell` boundaries are anchored to the config timezone on the write side too** (the residual of the read-side fix): `Spell::parse()` and `CompositeSpellCast::set()` now normalise offset-less bounds to `bitemporal.spells.timezone` before persisting (degrading to UTC container-less), so a Spell built from an ambient-zoned value round-trips to the same instant instead of corrupting `equalTo()` and boundary comparisons.
- **PostgreSQL `infinity` / `-infinity` range bounds read as `null`.** `PostgresSpellCast::bound()` previously passed them to `CarbonImmutable::parse()`, which throws — so any `tstzrange` populated by hand-written SQL, a migration, or another application with explicit infinity literals threw on read. They now map to the same unbounded `null` the package uses for open bounds (case-insensitive, quoted or not).
- **The non-streaming batch backfill runs the scoped overlap audit.** Only `insertStreaming()` audited against rows already in scope; the batch `insert()` validated just its in-memory batch, so backfilling into a scope that already held rows could insert bitemporally-overlapping rows undetected. The batch path now runs the same scoped DB audit inside its single transaction, so a detected overlap rolls the whole batch back atomically. Gated by the same `bitemporal.backfill.post_audit_check` config.
- **An ambient `knownAt` lens no longer throws on valid-time-only models.** Reading a uni-temporal model inside a `TemporalLens` frame that carried a `knownAt` bound hit `requireRecordedTime()` and threw; the recorded axis now silently degrades away for models that do not track recorded time, mirroring how an unset axis is skipped. The valid axis still applies.
- `backfill()->timeline()` / `importHistoricalKnowledge()` now accept value columns supplied flat on each row (as `supersedeTimeline()` already does), not only nested under an `attributes` key.
- `backfill()->timeline()` stamps the recorded axis as "now" for rows that omit `recorded_from`, instead of rejecting them — matching the documented behaviour for seeding a clean value history.

### Documentation
- Corrected the worked examples (insurance, salary, subscriptions, tax) and the model/writing/dimensions guides: adopted the `$temporalEntity` declaration, made value columns nullable where `retract()` is used, noted that a write replaces the whole value tuple, fixed a zero-length backfill spell, and pinned the `Compensation` table name.
- Dropped the `$dateFormat` line from every model example now that the trait supplies it, and replaced the "keep this line" note in the model guide with an explanation of why the trait owns microsecond precision.
- Documented the retraction read contract at every read entry point: `validAt`/`knownAt`/`currentKnowledge` intentionally include anti-rows (diffs, timeline materialisation, and the writer's supersession pass depend on seeing retractions), with the `excludeRetractions()` opt-out and the present-but-null footgun called out in `docs/04-reading.md`.
- Documented the `prefer_native_ranges` limitation, the `spell.precedes()` inclusive contract, and the best-effort nature of starting events, the audit log, and partial `correct()` nulling.

### Tested
- Added a `Tests\Docs` suite that recreates all four worked examples end to end.
- Introduced a property-based journey harness (`vusys/laravel-runabout`, `tests/Journey`) that shuffles forward edits, retroactive corrections, retractions, clock advances, compaction, supersession, and endings over generated timelines and asserts the bitemporal invariants after every step — non-overlap of current knowledge, append-only physical history, frozen past beliefs, dimension isolation, lens/predicate agreement, diff reconciliation, and backfill/incremental equivalence, plus optimistic-concurrency and `BitemporalBelongsToMany` cardinality journeys. A toggle-guarded planted-overlap journey proves the harness still has teeth. The journeys run as a per-PR job across the sqlite/mysql/mariadb/pgsql matrix, with an on-demand high-shuffle exploration workflow.

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

