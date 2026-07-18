<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Bitemporal\Tests\Journey\Journeys\BackfillEquivalenceJourney;
use Vusys\Runabout\RunsJourneys;

#[Group('journey')]
final class BackfillEquivalenceTest extends IntegrationTestCase
{
    use RunsJourneys;

    public function test_backfill_reproduces_the_incremental_timeline_under_shuffling(): void
    {
        $this->journey(BackfillEquivalenceJourney::class)->shuffles(25)->run();
    }
}
