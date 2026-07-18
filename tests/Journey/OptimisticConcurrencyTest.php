<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Journey\Journeys\OptimisticConcurrencyJourney;

#[Group('journey')]
final class OptimisticConcurrencyTest extends JourneyTestCase
{
    public function test_guarded_and_keyed_writes_stay_consistent_under_shuffling(): void
    {
        $this->journey(OptimisticConcurrencyJourney::class)->shuffles($this->shuffleCount())->run();
    }
}
