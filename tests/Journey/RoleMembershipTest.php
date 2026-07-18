<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Journey\Journeys\RoleMembershipJourney;

#[Group('journey')]
final class RoleMembershipTest extends JourneyTestCase
{
    public function test_role_membership_cardinality_holds_under_shuffling(): void
    {
        $this->journey(RoleMembershipJourney::class)->shuffles($this->shuffleCount())->run();
    }
}
