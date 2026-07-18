<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Journey\Journeys\PlantedOverlapJourney;
use Vusys\Runabout\Exceptions\JourneyFailedException;

/**
 * Teeth guard: proves the journey harness still catches a bitemporal defect.
 *
 * PlantedOverlapJourney plants a raw overlap that a correct writer can never
 * produce. If the no-overlap invariant still has teeth, running the journey
 * fails — so this test passes precisely when the harness is working. Should
 * someone weaken that invariant, the planted overlap slips through, the journey
 * stops failing, and this guard goes red.
 */
#[Group('journey')]
final class PlantedBugTest extends JourneyTestCase
{
    public function test_a_planted_overlap_is_caught_by_the_invariants(): void
    {
        // Shrinking would re-run the trail to minimise it; the defect is already
        // minimal, so skip it to keep the guard fast and deterministic.
        putenv('RUNABOUT_SHRINK=0');

        try {
            $this->journey(PlantedOverlapJourney::class)->shuffles(0)->run();
            $this->fail('The planted overlap should have tripped the no-overlap invariant.');
        } catch (JourneyFailedException $failure) {
            $this->assertStringContainsStringIgnoringCase('overlap', $failure->getMessage());
        } finally {
            putenv('RUNABOUT_SHRINK');
        }
    }
}
