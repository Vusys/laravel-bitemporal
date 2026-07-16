<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Tests\Docs\Models\TaxJurisdiction;

/**
 * Worked example: tax & regulatory rates (docs/18-example-tax.md).
 *
 * A back-dated finance act rewrites the effective rate through correct(), while
 * every return already filed stays reproducible via knownAt(). A full
 * restatement goes in with supersedeTimeline() without erasing the prior
 * picture, and legacy beliefs come across faithfully with
 * importHistoricalKnowledge().
 */
final class TaxExampleTest extends DocsTestCase
{
    private function makeJurisdiction(): TaxJurisdiction
    {
        return TaxJurisdiction::query()->create(['name' => 'GB']);
    }

    public function test_a_rate_set_in_the_budget_is_a_forward_change(): void
    {
        CarbonImmutable::setTestNow('2026-04-06 00:00:00');
        $jurisdiction = $this->makeJurisdiction();

        $jurisdiction->rates()
            ->forDimensions(['category' => 'standard'])
            ->changeEffectiveFrom(
                attributes: ['rate' => 0.2000],
                validFrom: '2026-04-06',
            );

        $this->assertEquals(0.2000, $jurisdiction->rates()->forDimensions(['category' => 'standard'])->validAt('2026-05-01')->currentKnowledge()->sole()->rate);
    }

    public function test_legislation_that_back_dates_a_change_keeps_the_filed_rate_reproducible(): void
    {
        CarbonImmutable::setTestNow('2026-04-06 00:00:00');
        $jurisdiction = $this->makeJurisdiction();
        $jurisdiction->rates()
            ->forDimensions(['category' => 'standard'])
            ->changeEffectiveFrom(attributes: ['rate' => 0.2000], validFrom: '2026-04-06');

        // July: a finance act lowers the standard rate to 17.5%, back-dated to 6 April.
        CarbonImmutable::setTestNow('2026-07-15 00:00:00');
        $jurisdiction->rates()
            ->forDimensions(['category' => 'standard'])
            ->correct(attributes: ['rate' => 0.1750], validFrom: '2026-04-06');

        // Current understanding is 17.5% from April.
        $this->assertEquals(0.1750, $jurisdiction->rates()->forDimensions(['category' => 'standard'])->validAt('2026-05-01')->currentKnowledge()->sole()->rate);

        // A Q1 return filed on 30 April is still reproducible at the rate believed then.
        $rateAsFiled = $jurisdiction->rates()
            ->forDimensions(['category' => 'standard'])
            ->validAt('2026-04-30')     // the period the return covered
            ->knownAt('2026-04-30')     // as the law was understood when filed
            ->sole();

        $this->assertEquals(0.2000, $rateAsFiled->rate);   // the figure on the original return
    }

    public function test_supersede_timeline_restates_a_category_without_erasing_the_prior_picture(): void
    {
        // A prior reduced rate exists, believed open-ended from April 2024.
        CarbonImmutable::setTestNow('2024-04-06 00:00:00');
        $jurisdiction = $this->makeJurisdiction();
        $jurisdiction->rates()
            ->forDimensions(['category' => 'reduced'])
            ->changeEffectiveFrom(attributes: ['rate' => 0.0500], validFrom: '2024-04-06');

        // The authority republishes the entire reduced schedule.
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        $jurisdiction->rates()
            ->forDimensions(['category' => 'reduced'])
            ->supersedeTimeline([
                ['rate' => 0.0500, 'valid_from' => '2024-04-06', 'valid_to' => '2026-04-06'],
                ['rate' => 0.0000, 'valid_from' => '2026-04-06', 'valid_to' => null],
            ]);

        // Current knowledge matches the restated schedule.
        $this->assertEquals(0.0500, $jurisdiction->rates()->forDimensions(['category' => 'reduced'])->validAt('2025-01-01')->currentKnowledge()->sole()->rate);
        $this->assertEquals(0.0000, $jurisdiction->rates()->forDimensions(['category' => 'reduced'])->validAt('2026-07-01')->currentKnowledge()->sole()->rate);

        // The previously-recorded rows are closed on the recorded axis, not
        // deleted — the old open-ended picture is still queryable via knownAt().
        $asBelievedBefore = $jurisdiction->rates()
            ->forDimensions(['category' => 'reduced'])
            ->validAt('2027-01-01')
            ->knownAt('2024-06-01')
            ->sole();
        $this->assertEquals(0.0500, $asBelievedBefore->rate);
    }

    public function test_backfill_seeds_a_clean_value_history(): void
    {
        CarbonImmutable::setTestNow('2026-04-06 00:00:00');
        $jurisdiction = $this->makeJurisdiction();

        // Flat value columns, no recorded_from — a clean value history stamped
        // as current knowledge.
        $jurisdiction->rates()
            ->forDimensions(['category' => 'standard'])
            ->backfill()
            ->timeline([
                ['rate' => 0.1750, 'valid_from' => '2010-01-01', 'valid_to' => '2011-01-04'],
                ['rate' => 0.2000, 'valid_from' => '2011-01-04', 'valid_to' => '2026-04-06'],
            ]);

        $this->assertEquals(0.2000, $jurisdiction->rates()->forDimensions(['category' => 'standard'])->validAt('2020-01-01')->currentKnowledge()->sole()->rate);
    }

    public function test_import_historical_knowledge_seeds_past_beliefs(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');
        $jurisdiction = $this->makeJurisdiction();

        // Reconstruct a past belief: from April we believed 20%, until the July
        // amendment corrected it to 17.5% — both recorded spells stamped
        // explicitly.
        $jurisdiction->rates()
            ->forDimensions(['category' => 'standard'])
            ->backfill()
            ->importHistoricalKnowledge([
                [
                    'rate' => 0.2000,
                    'valid_from' => '2026-04-06', 'valid_to' => null,
                    'recorded_from' => '2026-04-06', 'recorded_to' => '2026-07-15',
                ],
                [
                    'rate' => 0.1750,
                    'valid_from' => '2026-04-06', 'valid_to' => null,
                    'recorded_from' => '2026-07-15', 'recorded_to' => null,
                ],
            ]);

        // The belief held in May was 20%; today's belief is 17.5%.
        $this->assertEquals(0.2000, $jurisdiction->rates()->forDimensions(['category' => 'standard'])->validAt('2026-05-01')->knownAt('2026-05-01')->sole()->rate);
        $this->assertEquals(0.1750, $jurisdiction->rates()->forDimensions(['category' => 'standard'])->validAt('2026-05-01')->currentKnowledge()->sole()->rate);
    }

    public function test_categories_are_independent_dimensions(): void
    {
        CarbonImmutable::setTestNow('2026-04-06 00:00:00');
        $jurisdiction = $this->makeJurisdiction();

        $jurisdiction->rates()->forDimensions(['category' => 'standard'])->changeEffectiveFrom(['rate' => 0.2000], '2026-04-06');
        $jurisdiction->rates()->forDimensions(['category' => 'reduced'])->changeEffectiveFrom(['rate' => 0.0500], '2026-04-06');

        // Correcting one category leaves the other untouched.
        $jurisdiction->rates()->forDimensions(['category' => 'standard'])->correct(['rate' => 0.1750], validFrom: '2026-04-06');

        $this->assertEquals(0.1750, $jurisdiction->rates()->forDimensions(['category' => 'standard'])->currentKnowledge()->sole()->rate);
        $this->assertEquals(0.0500, $jurisdiction->rates()->forDimensions(['category' => 'reduced'])->currentKnowledge()->sole()->rate);
    }
}
