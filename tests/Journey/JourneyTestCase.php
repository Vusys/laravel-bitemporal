<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;
use Vusys\Runabout\RunsJourneys;

/**
 * Base for Runabout journey tests. Centralises the trait wiring and the shuffle
 * count so CI can explore far more orderings than a local run.
 *
 * The shuffle count defaults to 25 but is overridable with the JOURNEY_SHUFFLES
 * environment variable — the nightly workflow cranks it into the hundreds.
 * Runabout's own knobs compose on top: RUNABOUT_RANDOMIZE=1 explores fresh
 * seeds each run, RUNABOUT_SEED=<n> / RUNABOUT_TRAIL=<artifact> replay a
 * reported failure, and RUNABOUT_COVERAGE=1 prints a trail-coverage summary.
 */
abstract class JourneyTestCase extends IntegrationTestCase
{
    use RunsJourneys;

    protected function shuffleCount(): int
    {
        $override = getenv('JOURNEY_SHUFFLES');

        return is_string($override) && ctype_digit($override) ? (int) $override : 25;
    }
}
