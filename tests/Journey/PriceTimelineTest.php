<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Journey\Journeys\PriceTimelineJourney;

#[Group('journey')]
final class PriceTimelineTest extends JourneyTestCase
{
    public function test_price_timeline_holds_its_invariants_under_shuffling(): void
    {
        $this->journey(PriceTimelineJourney::class)->shuffles($this->shuffleCount())->run();
    }
}
