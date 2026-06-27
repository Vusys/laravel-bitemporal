<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\BootGuards\Mutation;

use Illuminate\Support\Facades\Event;
use Vusys\Bitemporal\Events\TemporalBootLintRaised;
use Vusys\Bitemporal\Tests\Fixtures\Models\LintProbePrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins the surviving mutants in build/mutants/src__Bitemporal.txt that affect
 * initializeBitemporal(). The two TrueValue mutants on the cache assignment are
 * equivalent: the early-return is gated by isset(), which is true regardless of
 * whether the cached value is true or false.
 */
final class BitemporalInitializeMutationTest extends IntegrationTestCase
{
    public function test_initialize_bitemporal_is_publicly_callable(): void
    {
        $model = new ProductPrice;

        // PublicVisibility flips the method to `protected`; this external call then
        // fatals before the assertion runs.
        $model->initializeBitemporal();

        $this->assertInstanceOf(ProductPrice::class, $model);
    }

    public function test_initialize_bitemporal_runs_the_boot_lints(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', ['amount']);

        Event::fake([TemporalBootLintRaised::class]);

        // First construction of this dedicated model boots it: guards pass, then the
        // lints run and the compaction lint fires for the 'amount' domain column.
        // The MethodCallRemoval mutant drops the BootLints::runAgainst() call, so no
        // lint event is dispatched.
        new LintProbePrice;

        Event::assertDispatched(TemporalBootLintRaised::class);
    }
}
