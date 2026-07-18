<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\BootGuards;

use Vusys\Bitemporal\Boot\BootLints;
use Vusys\Bitemporal\Boot\Guards\BootGuardPrimaryKey;
use Vusys\Bitemporal\Boot\Lints\BootLintCompactionExcludesDomainColumn;
use Vusys\Bitemporal\Boot\Lints\BootLintTruncatedDateFormat;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\CollidingKeyModel;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\TruncatedDateFormatPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class BootLintsTest extends IntegrationTestCase
{
    public function test_primary_key_guard_flags_a_colliding_key(): void
    {
        /** @var CollidingKeyModel $model */
        $model = TemporalLens::withoutBootGuards(fn (): CollidingKeyModel => new CollidingKeyModel);

        $this->assertNotNull(new BootGuardPrimaryKey()->check($model));
    }

    public function test_primary_key_guard_passes_for_a_normal_model(): void
    {
        $this->assertNull(new BootGuardPrimaryKey()->check(new ProductPrice));
    }

    public function test_compaction_lint_fires_for_a_domain_column(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', ['amount']);

        $raised = new BootLints([new BootLintCompactionExcludesDomainColumn])
            ->runAgainst(new ProductPrice, dispatch: false);

        $this->assertArrayHasKey(BootLintCompactionExcludesDomainColumn::class, $raised);
    }

    public function test_lint_is_suppressed_per_model(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', ['amount']);

        /** @var CollidingKeyModel $model */
        $model = TemporalLens::withoutBootGuards(fn (): CollidingKeyModel => new CollidingKeyModel);

        $raised = new BootLints([new BootLintCompactionExcludesDomainColumn])
            ->runAgainst($model, dispatch: false);

        $this->assertArrayNotHasKey(BootLintCompactionExcludesDomainColumn::class, $raised);
    }

    public function test_truncated_date_format_lint_fires_for_a_sub_second_less_format(): void
    {
        /** @var TruncatedDateFormatPrice $model */
        $model = TemporalLens::withoutBootGuards(fn (): TruncatedDateFormatPrice => new TruncatedDateFormatPrice);

        $this->assertNotNull(new BootLintTruncatedDateFormat()->check($model));
    }

    public function test_truncated_date_format_lint_passes_for_the_trait_default(): void
    {
        // ProductPrice declares no $dateFormat, so the trait supplies microsecond
        // precision and the lint must stay silent.
        $this->assertNull(new BootLintTruncatedDateFormat()->check(new ProductPrice));
    }

    public function test_warm_guards_reports_without_throwing(): void
    {
        $report = TemporalLens::warmGuards([ProductPrice::class]);

        $this->assertTrue($report->failedGuards->isEmpty());
        $this->assertStringContainsString('0 model(s) failed', $report->summary());
    }
}
