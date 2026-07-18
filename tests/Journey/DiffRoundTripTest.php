<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Bitemporal\Tests\Journey\Journeys\DiffRoundTripJourney;
use Vusys\Runabout\RunsJourneys;

#[Group('journey')]
final class DiffRoundTripTest extends IntegrationTestCase
{
    use RunsJourneys;

    public function test_difftimelines_reconciles_beliefs_under_shuffling(): void
    {
        $this->journey(DiffRoundTripJourney::class)->shuffles(25)->run();
    }
}
