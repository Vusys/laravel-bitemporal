<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Boot\Mutation;

use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\MutableDatetimeCastPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Kills the surviving boolean mutants in HasTemporalCasts::initializeHasTemporalCasts
 * by asserting that the opt-out flag actually gates the auto-applied casts.
 *
 * Equivalent mutant (not targeted):
 *  - PublicVisibility (`public` -> `protected`). Eloquent only ever calls the
 *    `initialize{Trait}` hook internally via `$this->{$method}()`, so protected
 *    visibility is just as reachable — no observable difference.
 */
final class HasTemporalCastsMutationTest extends IntegrationTestCase
{
    public function test_a_model_with_auto_casts_disabled_keeps_its_declared_cast(): void
    {
        /** @var MutableDatetimeCastPrice $model */
        $model = TemporalLens::withoutBootGuards(static fn (): MutableDatetimeCastPrice => new MutableDatetimeCastPrice);

        // autoApplyTemporalCasts === false => the trait must return early and
        // leave the declared mutable cast untouched. Both boolean mutants on the
        // guard cause the casts to be applied anyway (immutable_datetime).
        $this->assertSame('datetime', $model->getCasts()['valid_from']);
    }

    public function test_a_default_model_gets_immutable_datetime_casts(): void
    {
        $casts = new ProductPrice()->getCasts();

        $this->assertSame('immutable_datetime', $casts['valid_from']);
        $this->assertSame('immutable_datetime', $casts['valid_to']);
        $this->assertSame('immutable_datetime', $casts['recorded_from']);
        $this->assertSame('immutable_datetime', $casts['recorded_to']);
        $this->assertSame('boolean', $casts['is_retraction']);
    }
}
