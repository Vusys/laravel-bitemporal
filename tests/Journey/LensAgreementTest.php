<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Journey\Journeys\LensAgreementJourney;

#[Group('journey')]
final class LensAgreementTest extends JourneyTestCase
{
    public function test_ambient_lens_reads_track_explicit_predicates_under_shuffling(): void
    {
        $this->journey(LensAgreementJourney::class)->shuffles($this->shuffleCount())->run();
    }
}
