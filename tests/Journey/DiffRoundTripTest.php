<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Journey\Journeys\DiffRoundTripJourney;

#[Group('journey')]
final class DiffRoundTripTest extends JourneyTestCase
{
    public function test_difftimelines_reconciles_beliefs_under_shuffling(): void
    {
        $this->journey(DiffRoundTripJourney::class)->shuffles($this->shuffleCount())->run();
    }
}
