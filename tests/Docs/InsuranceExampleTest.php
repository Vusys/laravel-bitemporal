<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Tests\Docs\Models\Policy;

/**
 * Worked example: insurance policies & claims (docs/15-example-insurance.md).
 *
 * Recorded time is what lets you defend a past decision: a claim paid on the
 * knowledge available, an endorsement that corrects the value without
 * falsifying that record, and a retraction that removes cover from the current
 * picture without erasing the history.
 */
final class InsuranceExampleTest extends DocsTestCase
{
    private function makePolicy(): Policy
    {
        return Policy::query()->create(['reference' => 'POL-1']);
    }

    public function test_binding_cover_opens_an_open_ended_timeline(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $policy = $this->makePolicy();

        $policy->coverages()->changeEffectiveFrom(
            attributes: ['limit' => 250_000, 'deductible' => 500, 'premium' => 1_200],
            validFrom: '2026-01-01',
        );

        $cover = $policy->coverages()->validAt('2026-02-01')->currentKnowledge()->sole();

        $this->assertEquals(250_000, $cover->limit);
        $this->assertEquals(500, $cover->deductible);
        $this->assertNull($cover->valid_to);
    }

    public function test_paying_a_claim_reads_cover_in_force_on_the_loss_date(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $policy = $this->makePolicy();
        $policy->coverages()->changeEffectiveFrom(
            attributes: ['limit' => 250_000, 'deductible' => 500, 'premium' => 1_200],
            validFrom: '2026-01-01',
        );

        // 20 March: the adjuster settles the fire claim against the cover in
        // force on the loss date, as the system understood it that day.
        $claimAmount = 300_000;
        $coverAtLoss = $policy->coverages()
            ->validAt('2026-03-14')     // cover effective on the day of the fire
            ->knownAt('2026-03-20')     // as we believed it when the claim was paid
            ->sole();

        $payout = min($claimAmount, (int) $coverAtLoss->limit) - (int) $coverAtLoss->deductible;

        $this->assertSame(249_500, $payout);
    }

    public function test_retroactive_endorsement_corrects_the_limit_and_preserves_prior_belief(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $policy = $this->makePolicy();
        $policy->coverages()->changeEffectiveFrom(
            attributes: ['limit' => 250_000, 'deductible' => 500, 'premium' => 1_200],
            validFrom: '2026-01-01',
        );

        // April: the broker confirms an endorsement raising the limit to
        // £500,000, effective all the way back to inception.
        CarbonImmutable::setTestNow('2026-04-15 00:00:00');
        $policy->coverages()->correct(
            attributes: ['limit' => 500_000, 'deductible' => 500, 'premium' => 1_200],
            validFrom: '2026-01-01',    // the higher limit applied from inception
        );

        // The current belief now shows £500,000 from inception.
        $this->assertEquals(500_000, $policy->coverages()->validAt('2026-02-01')->currentKnowledge()->sole()->limit);

        // The £250,000 row is not deleted — it is still what the system
        // believed between January and April.
        $asKnownInMarch = $policy->coverages()->validAt('2026-03-14')->knownAt('2026-03-20')->sole();
        $this->assertEquals(250_000, $asKnownInMarch->limit);
    }

    public function test_voiding_for_fraud_retracts_cover_but_history_survives(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $policy = $this->makePolicy();
        $policy->coverages()->changeEffectiveFrom(
            attributes: ['limit' => 250_000, 'deductible' => 500, 'premium' => 1_200],
            validFrom: '2026-01-01',
        );

        // Underwriting discovers the application was fraudulent: void ab initio.
        CarbonImmutable::setTestNow('2026-05-02 00:00:00');
        $policy->coverages()->retract(validFrom: '2026-01-01');

        // Reads that exclude retractions now find no cover in force.
        $this->assertCount(
            0,
            $policy->coverages()->validAt('2026-03-14')->currentKnowledge()->excludeRetractions()->get(),
        );

        // The recorded history still shows the belief the March claim was paid against.
        $asKnownInMarch = $policy->coverages()->validAt('2026-03-14')->knownAt('2026-03-20')->sole();
        $this->assertEquals(250_000, $asKnownInMarch->limit);
        $this->assertFalse((bool) $asKnownInMarch->is_retraction);
    }

    public function test_diff_knowledge_audits_what_changed_between_two_beliefs(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $policy = $this->makePolicy();
        $policy->coverages()->changeEffectiveFrom(
            attributes: ['limit' => 250_000, 'deductible' => 500, 'premium' => 1_200],
            validFrom: '2026-01-01',
        );

        CarbonImmutable::setTestNow('2026-04-15 00:00:00');
        $policy->coverages()->correct(
            attributes: ['limit' => 500_000, 'deductible' => 500, 'premium' => 1_200],
            validFrom: '2026-01-01',
        );

        // How did our understanding of the 14 March cover change between the day
        // we paid (20 March) and today (1 May)?
        $diff = $policy->coverages()->diffKnowledge(
            validAt: '2026-03-14',      // the cover effective on the loss date
            fromKnownAt: '2026-03-20',  // what we believed when we paid
            toKnownAt: '2026-05-01',    // what we believe now
        );

        $this->assertCount(1, $diff->changed);

        $pair = $diff->changed->first();
        $this->assertNotNull($pair);
        $this->assertEquals(250_000, $pair->from->getAttribute('limit'));
        $this->assertEquals(500_000, $pair->to->getAttribute('limit'));
        $this->assertContains('limit', $pair->changedAttributes);
    }
}
