# Boot guards and lints

The first time a temporal model is used, the package validates its configuration. That validation has two channels:

- **Guards** are hard checks. A guard failure means the model is mis-wired in a way that would corrupt data, so it throws `TemporalConfigurationException` and stops boot.
- **Lints** are advisory. A lint means "this is probably a mistake, but the package will still work" — it logs a warning and fires an event, and boot continues.

Both run once per model class, then cache. They are controlled by `guards.enabled` (see [Configuration](09-configuration.md)).

## Guards

Guards enforce the invariants the writer relies on. The shipped set:

| Guard | Rejects |
| --- | --- |
| `BootGuardSoftDeletes` | combining `Bitemporal` with `SoftDeletes` — use `retract()` / `forceDeleteHistory()` instead |
| `BootGuardRelationType` | a `temporalEntity()` that isn't `BelongsTo` or `MorphTo` (`Pivot` models are exempt) |
| `BootGuardNewEloquentBuilder` | a model whose `newEloquentBuilder()` isn't a `BitemporalBuilder` |
| `BootGuardNewCollection` | a model whose `newCollection()` isn't a `BitemporalCollection` |
| `BootGuardDimensions` | a `temporalDimensions()` that isn't an array of column-name strings |
| `BootGuardPrimaryKey` | a primary key that collides with a temporal column or a declared dimension |

A guard failure is not recoverable at runtime — it always indicates a configuration bug to fix before shipping.

## Lints

Lints catch the subtler mistakes that compile and run but quietly do the wrong thing:

| Lint | Warns when |
| --- | --- |
| `BootLintCompactionExcludesDomainColumn` | `writes.compaction_excluded_columns` lists a **domain** column — compaction would silently merge segments that differ only on that column, erasing real history |
| `BootLintMutableDatetimeCast` | a temporal column is declared with a **mutable** `datetime`/`date` cast — the trait applies `immutable_datetime` automatically, and a mutable cast is usually a copy-paste error |

Each raised lint is logged at warning level and dispatched as a `TemporalBootLintRaised` event:

```php
use Vusys\Bitemporal\Events\TemporalBootLintRaised;

Event::listen(function (TemporalBootLintRaised $e) {
    // $e->model    — class-string of the temporal model
    // $e->lint     — class-string of the lint that fired
    // $e->message  — the advisory text
});
```

### Suppressing a lint

If a lint is a false positive for a given model — you genuinely do want a domain column excluded from compaction — silence that specific lint with the `$suppressedBootLints` property. List the lint **class strings**:

```php
use Vusys\Bitemporal\Boot\Lints\BootLintCompactionExcludesDomainColumn;

class ProductPrice extends Model
{
    use Bitemporal;

    protected array $suppressedBootLints = [
        BootLintCompactionExcludesDomainColumn::class,
    ];
}
```

Suppression is per-model and per-lint; everything not listed still runs. There is no equivalent for guards — a guard failure must be fixed, not suppressed.

## Warming guards ahead of time

Guards and lints run lazily, on first model use. To surface problems at deploy time instead of on the first request that happens to touch each model, warm them explicitly.

From the command line — pass one or more model classes (see [`bitemporal:warm-guards`](14-commands.md#bitemporalwarm-guards)):

```bash
php artisan bitemporal:warm-guards "App\Models\ProductPrice" "App\Models\UserRoleAssignment"
```

Or programmatically, via the `TemporalLens` facade, when you want the diagnostics as an object rather than an exit code:

```php
use Vusys\Bitemporal\Facades\TemporalLens;

$report = TemporalLens::warmGuards([ProductPrice::class]);

$report->failedGuards;   // Collection: model => failure message
$report->raisedLints;    // Collection: model => [lint => message]
$report->summary();      // "0 model(s) failed guards, 0 lint(s) raised."
```

`warmGuards()` never throws — it collects everything into a `BootDiagnosticsReport` for you to inspect. Use `warmGuardsOrFail()` instead when you want a hard failure on any guard breach (lints still never throw):

```php
TemporalLens::warmGuardsOrFail([ProductPrice::class, UserRoleAssignment::class]);
```

That is the call to put in a deploy smoke-test or a health check.

> `TemporalLens::withoutBootGuards(fn () => …)` exists to disable guards and lints inside a closure. It is a testing affordance — there is no production use case for skipping the guards.

Next: [Commands](14-commands.md).
