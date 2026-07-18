<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Journey\Journeys\DimensionedPriceJourney;

#[Group('journey')]
final class DimensionedPriceTest extends JourneyTestCase
{
    public function test_dimensioned_timelines_stay_isolated_under_shuffling(): void
    {
        $this->journey(DimensionedPriceJourney::class)->shuffles($this->shuffleCount())->run();
    }
}
