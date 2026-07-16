# Exception catalogue

Every exception the package throws extends one abstract base, `Vusys\Bitemporal\Exceptions\TemporalException` (itself a `RuntimeException`). Catch that to handle anything temporal; catch a specific subclass to react to one failure mode.

```php
use Vusys\Bitemporal\Exceptions\TemporalException;

try {
    $product->prices()->correct(['amount' => 12.00], validFrom: '2026-02-01');
} catch (TemporalException $e) {
    // any temporal failure
}
```

Message wording lives in `lang/en/messages.php`. The `:placeholder` token set in each message is stable across the 1.x line; publish `lang/vendor/bitemporal/{locale}/messages.php` to translate or reword.

## The hierarchy

| Exception | Thrown when | Caller error? |
| --- | --- | --- |
| `TemporalConfigurationException` | a model is mis-wired — soft-deletes clash, wrong `temporalEntity()` type, a missing relation, a disabled pivot method, a failed boot guard | yes — fix configuration |
| `TemporalInvalidSpellException` | a supplied period is invalid — inverted (`from >= to`), zero-length, or a non-forward-dated `changeEffectiveFrom` | yes — fix the dates |
| `TemporalMissingDimensionException` | a write can't be scoped — a pending `where()`, an incomplete dimension tuple, or a `forDimensions`/attributes conflict | yes — see [Dimensions](06-dimensions.md) |
| `TemporalOverlapException` | a write or a supplied timeline would produce overlapping valid periods | yes — the overlap is in your data |
| `TemporalCardinalityException` | "exactly one" was expected and none / many were found, or a pivot correction/detach has no assignment to act on | usually a data/logic issue |
| `TemporalWriteConflictException` | a concurrent or optimistic conflict — lock timeout, missing entity row, failed `expectedCurrentAttributes`, or an idempotency-key reuse with different parameters | retry or reconcile |
| `TemporalUnsupportedDatabaseException` | the active engine can't support a requested feature — no `btree_gist`, advisory locks on SQLite, or a version below the minimum | yes — environment/config |
| `TemporalOnlineDdlException` | a `TemporalLens::withoutIndexes()` online-DDL operation could not run — it was called inside a transaction, or a package index could not be recreated on exit | yes — call outside a transaction / rebuild the index |
| `TemporalDomainException` | an internal invariant was violated, or the host clock regressed beyond tolerance | **no** — a package bug or environment fault; report it |

### `TemporalConfigurationException`

Raised at boot or relation-resolution. Covers: `Bitemporal` + `SoftDeletes` on the same model; a `temporalEntity()` that is not `BelongsTo`/`MorphTo`; a missing `temporalEntity()`; calling a [disabled pivot method](11-pivots.md#writing-assignments) (`attach`/`detach`/`sync`); and aggregated boot-guard failures. See [Boot guards and lints](13-boot-guards-and-lints.md).

### `TemporalCardinalityException`

Returned by `sole()` as `expectedOneFoundNone` / `expectedOneFoundMany`, so a broken timeline surfaces loudly rather than returning the wrong row. Also thrown by [pivot writes](11-pivots.md) when `correctAssignment()` / `detachAt()` find no assignment to act on. Exposes `wasNoneFound(): bool` to distinguish the two empty/over-full cases.

### `TemporalWriteConflictException`

The concurrency surface. `expectation_failed` is the optimistic-concurrency check (`expectedCurrentAttributes`); `idempotency_conflict` fires when an [idempotency key](05-writing.md#idempotent-writes) is reused with different parameters; `lock_timeout` and `entity_missing` come from the locking strategy (see [Configuration](09-configuration.md)).

### `TemporalOnlineDdlException`

Raised by `TemporalLens::withoutIndexes()` (the bulk-load index helper). `insideTransaction` fires when it is called inside an open transaction (PostgreSQL `CREATE INDEX CONCURRENTLY` forbids a transaction block, and dropping indexes mid-transaction is unsafe) — call it outside any transaction; the callback may open its own. `recreateFailed` fires when a dropped package index cannot be recreated on exit, naming the index and the DDL; on PostgreSQL a failed `CREATE INDEX CONCURRENTLY` may leave an INVALID index to drop manually.

### `TemporalDomainException`

The only exception that is **not** a caller error. It signals a "should never happen" invariant breach inside the algorithm (`invariant(...)`), or a clock that has gone backwards beyond `writes.clock_skew_tolerance_ms` (`clockSkew(...)`). Treat it as a bug report — capture the message and reproduction.

## Factory methods

Every subclass is `final`, has no public constructor, and is built through named static
factory methods — the factory name plus the message encode the scenario (there is no numeric
`code`). The full set, verified in sync with the code by `ExceptionCatalogueParityTest`:

| Exception | Factory methods |
| --- | --- |
| `TemporalConfigurationException` | `missingTemporalEntity`, `unexpectedEntityArgument`, `disabledPivotMethod`, `guardFailures`, `appGuardFailures` |
| `TemporalInvalidSpellException` | `fromAfterTo`, `zeroLength`, `mergeDisjoint`, `antiRowCorrection`, `emptyTimelineSpan`, `unparseableDate` |
| `TemporalMissingDimensionException` | `pendingWhere`, `forbiddenAttribute`, `incomplete`, `unknownDimension`, `conflict` |
| `TemporalOverlapException` | `betweenSegments`, `afterBackfillAudit` |
| `TemporalCardinalityException` | `expectedOneFoundMany`, `expectedOneFoundNone`, `noAssignmentToCorrect`, `noAssignmentToDetach` |
| `TemporalWriteConflictException` | `entityMissing`, `clockRegressed`, `lockTimeout`, `deadlock`, `connectionChanged`, `expectationFailed`, `idempotencyKeyReused` |
| `TemporalUnsupportedDatabaseException` | `btreeGistMissing`, `advisoryLocksUnsupported`, `engineVersionBelowMinimum` |
| `TemporalOnlineDdlException` | `insideTransaction`, `recreateFailed` |
| `TemporalDomainException` | `invariant`, `clockSkew` |

## Asserting on exceptions in tests

The testing trait wraps the common cases so you don't hand-roll `expectException` plus a message match — see [Testing](10-testing.md#timeline-assertions):

```php
$this->expectTemporalException(TemporalInvalidSpellException::class);
```
