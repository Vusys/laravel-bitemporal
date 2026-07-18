---
name: audit-fix-prs-72-77
description: Third audit wave (issues #72-#77); PRs #93-#98 open against master
metadata:
  type: project
---

Third correctness-audit wave, all six low-severity issues fixed on 2026-07-18, one PR each (branches `fix/<n>-...`), all open against master:

- #72 → PR #93 — lens job turns snapshot/restore instead of blind `reset()`, preserving an outer `asOf()` frame across sync `dispatchSync()` (added `LensStack::beginJobTurn`/`endJobTurn`).
- #73 → PR #94 — new `Idempotency\IdempotencyWindow` parses `writes.idempotency_window` once via `CarbonInterval::make()`, falls back to 7-day default; `find()`/prune take a `CarbonInterval`.
- #74 → PR #95 — `BitemporalOne` adds a `valid_to` NULLS-first tie-break (`(valid_to IS NULL) DESC, valid_to DESC`) via an `Expression` (needs a `@phpstan-ignore argument.type` — larastan demands literal-string for raw SQL).
- #75 → PR #96 — `BitemporalBelongsToMany::currentAssignmentQuery()` now `->forDimensions(pivotDimensionTuple())` so detach/correct guards scope like the writer.
- #77 → PR #97 — `DiffEngine::keyByMatch()` asserts unique `(valid_from, dimensions)` keys, throwing `TemporalDomainException::invariant()` instead of silently overwriting.
- #76 → PR #98 — **design fork, user chose dedicated bucket**: new `TemporalDiff::$retracted` + `TemporalRetraction` DTO (`?from`, `to`). A window that became an anti-row classifies as `retracted` not `changed`/`added`. DTO carries both sides so the diff stays a complete reconciliation (the `DiffRoundTripJourney` property law depends on this — a plain `Collection<Model>` would be lossy).

Wave follows [[audit-fix-prs-65-71]] (v0.6.0). Not yet merged/released. phpstan runs on `tests/` at level 9. See [[design-decision-style]].
