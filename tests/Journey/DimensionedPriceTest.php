<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Bitemporal\Tests\Journey\Journeys\DimensionedPriceJourney;
use Vusys\Runabout\RunsJourneys;

#[Group('journey')]
final class DimensionedPriceTest extends IntegrationTestCase
{
    use RunsJourneys;

    public function test_dimensioned_timelines_stay_isolated_under_shuffling(): void
    {
        $this->journey(DimensionedPriceJourney::class)->shuffles(25)->run();
    }
}
