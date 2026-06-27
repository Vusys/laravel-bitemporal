<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Boot\Mutation;

use Vusys\Bitemporal\Boot\Lints\BootLintMutableDatetimeCast;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\MutableDatetimeCastPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ReportSoftDeletingPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Kills the surviving mutants in BootDiagnosticsReport::summary (the lint-count
 * reducer: Plus -> minus, and the 0 initial -> 1 / -1) by building a real report
 * over a guard-failing model, a lint-raising model, and a clean model.
 */
final class BootDiagnosticsReportMutationTest extends IntegrationTestCase
{
    public function test_report_partitions_models_and_summarises_counts(): void
    {
        // Disable boot guards so the *misconfigured* models can be instantiated
        // (warmGuards itself re-runs the guards explicitly to populate the report).
        config()->set('bitemporal.guards.enabled', false);
        config()->set('bitemporal.writes.compaction_excluded_columns', ['created_at', 'updated_at']);

        $report = TemporalLens::warmGuards([
            ReportSoftDeletingPrice::class,
            MutableDatetimeCastPrice::class,
            ProductPrice::class,
        ]);

        // Partition contents.
        $this->assertTrue($report->failedGuards->has(ReportSoftDeletingPrice::class));
        $this->assertFalse($report->failedGuards->has(MutableDatetimeCastPrice::class));
        $this->assertFalse($report->failedGuards->has(ProductPrice::class));
        $this->assertSame(1, $report->failedGuards->count());

        $this->assertTrue($report->raisedLints->has(MutableDatetimeCastPrice::class));
        $this->assertFalse($report->raisedLints->has(ProductPrice::class));
        $this->assertArrayHasKey(
            BootLintMutableDatetimeCast::class,
            $report->raisedLints->get(MutableDatetimeCastPrice::class),
        );

        // Exactly one guard failure and one raised lint => the summary string
        // must read precisely this; any off-by-one or sign flip in the reducer
        // changes the lint count.
        $this->assertSame('1 model(s) failed guards, 1 lint(s) raised.', $report->summary());
    }
}
