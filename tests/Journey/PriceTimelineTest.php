<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Bitemporal\Tests\Journey\Journeys\PriceTimelineJourney;
use Vusys\Runabout\RunsJourneys;

#[Group('journey')]
final class PriceTimelineTest extends IntegrationTestCase
{
    use RunsJourneys;

    public function test_price_timeline_holds_its_invariants_under_shuffling(): void
    {
        $this->journey(PriceTimelineJourney::class)->shuffles(25)->run();
    }
}
