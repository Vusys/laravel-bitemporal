<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Vusys\Bitemporal\Boot\BootDiagnosticsReport;
use Vusys\Bitemporal\Boot\BootGuards;
use Vusys\Bitemporal\Boot\BootLints;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;

/**
 * Request-scoped stack of point-in-time lens frames. asOf() pushes a frame for
 * the duration of a callback; nested frames inherit the parent's axes unless
 * overridden. Resolved as a singleton (the TemporalLens facade accessor).
 */
final class LensStack
{
    /**
     * @var array<int, LensFrame>
     */
    private array $frames = [];

    private bool $bootGuardsSuppressed = false;

    /**
     * Run $callback with per-model boot guards disabled. For tests that
     * deliberately violate guard invariants. No production use case.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutBootGuards(Closure $callback): mixed
    {
        $previous = $this->bootGuardsSuppressed;
        $this->bootGuardsSuppressed = true;

        try {
            return $callback();
        } finally {
            $this->bootGuardsSuppressed = $previous;
        }
    }

    public function bootGuardsSuppressed(): bool
    {
        return $this->bootGuardsSuppressed;
    }

    /**
     * Run $callback with the model's package-managed overlap indexes dropped,
     * recreating them on exit — for large bulk backfills that would otherwise
     * pay per-row index maintenance. Custom indexes and the PostgreSQL EXCLUDE
     * constraint are untouched. Reentrant per table. Must not be called inside a
     * transaction.
     *
     * @template TReturn
     *
     * @param  class-string<Model>  $model
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutIndexes(string $model, Closure $callback): mixed
    {
        return resolve(WithoutIndexes::class)->run($model, $callback);
    }

    /**
     * Run guards + lints against each model without throwing, collecting the
     * results into a single report.
     *
     * @param  array<int, class-string<Model>>  $models
     */
    public function warmGuards(array $models): BootDiagnosticsReport
    {
        /** @var Collection<class-string, string> $failed */
        $failed = new Collection;
        /** @var Collection<class-string, array<class-string, string>> $lints */
        $lints = new Collection;

        foreach ($models as $class) {
            $model = new $class;

            try {
                BootGuards::default()->runAgainst($model);
            } catch (TemporalConfigurationException $exception) {
                $failed->put($class, $exception->getMessage());
            }

            $raised = BootLints::default()->runAgainst($model, dispatch: false);
            if ($raised !== []) {
                $lints->put($class, $raised);
            }
        }

        return new BootDiagnosticsReport($failed, $lints);
    }

    /**
     * Like warmGuards(), but throws if any guard failed. Lints never throw.
     *
     * @param  array<int, class-string<Model>>  $models
     */
    public function warmGuardsOrFail(array $models): BootDiagnosticsReport
    {
        $report = $this->warmGuards($models);

        if ($report->failedGuards->isNotEmpty()) {
            throw new TemporalConfigurationException('Boot guards failed: '.$report->summary());
        }

        return $report;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asOf(CarbonInterface|string|null $validAt, CarbonInterface|string|null $knownAt, Closure $callback): mixed
    {
        $this->push($validAt, $knownAt);

        try {
            return $callback();
        } finally {
            array_pop($this->frames);
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function validAt(CarbonInterface|string $validAt, Closure $callback): mixed
    {
        return $this->asOf($validAt, null, $callback);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function knownAt(CarbonInterface|string $knownAt, Closure $callback): mixed
    {
        return $this->asOf(null, $knownAt, $callback);
    }

    public function current(): ?LensFrame
    {
        return $this->frames === [] ? null : $this->frames[count($this->frames) - 1];
    }

    public function depth(): int
    {
        return count($this->frames);
    }

    public function reset(): void
    {
        $this->frames = [];
    }

    public function assertEmpty(): void
    {
        if ($this->frames !== []) {
            throw new TemporalConfigurationException(
                'a TemporalLens::asOf() frame was left open at the end of the request or job; '.
                'asOf() must always pop its frame (it does so automatically unless the worker was killed mid-callback)',
            );
        }
    }

    private function push(CarbonInterface|string|null $validAt, CarbonInterface|string|null $knownAt): void
    {
        $parent = $this->current();

        $this->frames[] = new LensFrame(
            $validAt !== null ? $this->parse($validAt) : $parent?->validAt,
            $knownAt !== null ? $this->parse($knownAt) : $parent?->knownAt,
        );
    }

    private function parse(CarbonInterface|string $value): CarbonImmutable
    {
        $timezone = config('bitemporal.spells.timezone', 'UTC');

        return CarbonImmutable::parse($value)->setTimezone(is_string($timezone) ? $timezone : 'UTC');
    }
}
