<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot;

use Illuminate\Support\Collection;

/**
 * Return value of TemporalLens::warmGuards(). Collects guard failures and
 * raised lints across every model passed in, without throwing.
 */
final readonly class BootDiagnosticsReport
{
    /**
     * @param  Collection<class-string, string>  $failedGuards  model => failure message
     * @param  Collection<class-string, array<class-string, string>>  $raisedLints  model => [lint => message]
     */
    public function __construct(
        public Collection $failedGuards,
        public Collection $raisedLints,
    ) {}

    public function summary(): string
    {
        $lintCount = $this->raisedLints->reduce(
            static fn (int $carry, array $lints): int => $carry + count($lints),
            0,
        );

        return sprintf(
            '%d model(s) failed guards, %d lint(s) raised.',
            $this->failedGuards->count(),
            $lintCount,
        );
    }
}
