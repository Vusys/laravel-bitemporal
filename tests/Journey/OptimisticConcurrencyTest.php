<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Bitemporal\Tests\Journey\Journeys\OptimisticConcurrencyJourney;
use Vusys\Runabout\RunsJourneys;

#[Group('journey')]
final class OptimisticConcurrencyTest extends IntegrationTestCase
{
    use RunsJourneys;

    public function test_guarded_and_keyed_writes_stay_consistent_under_shuffling(): void
    {
        $this->journey(OptimisticConcurrencyJourney::class)->shuffles(25)->run();
    }
}
