<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Bitemporal\Tests\Journey\Journeys\RoleMembershipJourney;
use Vusys\Runabout\RunsJourneys;

#[Group('journey')]
final class RoleMembershipTest extends IntegrationTestCase
{
    use RunsJourneys;

    public function test_role_membership_cardinality_holds_under_shuffling(): void
    {
        $this->journey(RoleMembershipJourney::class)->shuffles(25)->run();
    }
}
