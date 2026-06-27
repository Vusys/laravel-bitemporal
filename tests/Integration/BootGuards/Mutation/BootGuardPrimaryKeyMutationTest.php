<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\BootGuards\Mutation;

use Vusys\Bitemporal\Boot\Guards\BootGuardPrimaryKey;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\CollidingDimensionKeyModel;
use Vusys\Bitemporal\Tests\Fixtures\Models\CollidingValidToKeyModel;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Kills the surviving spread mutants in BootGuardPrimaryKey::check.
 *
 * Equivalent mutants (not targeted):
 *  - LogicalOr (`!method_exists(temporalColumnMap) || !method_exists(temporalDimensions)`
 *    -> `&&`). Both methods are supplied together by the Bitemporal trait, so
 *    they are always either both present or both absent; for every real model
 *    `false || false` and `false && false` agree.
 *  - UnwrapArrayValues (`[...array_values($map), ...]` -> `[...$map, ...]`).
 *    The reserved set is consulted only with `in_array($key, ..., true)`, which
 *    compares values; spreading the string-keyed map yields the same values.
 */
final class BootGuardPrimaryKeyMutationTest extends IntegrationTestCase
{
    public function test_flags_a_key_colliding_with_a_non_first_temporal_column(): void
    {
        /** @var CollidingValidToKeyModel $model */
        $model = TemporalLens::withoutBootGuards(static fn (): CollidingValidToKeyModel => new CollidingValidToKeyModel);

        // The key is valid_to (index 1 in the column map). SpreadOneItem keeps
        // only the first column (valid_from), so it would miss this collision.
        $this->assertSame(
            "primary key 'valid_to' collides with a temporal column or dimension",
            new BootGuardPrimaryKey()->check($model),
        );
    }

    public function test_flags_a_key_colliding_with_a_dimension(): void
    {
        /** @var CollidingDimensionKeyModel $model */
        $model = TemporalLens::withoutBootGuards(static fn (): CollidingDimensionKeyModel => new CollidingDimensionKeyModel);

        // The key is the dimension 'currency'. SpreadRemoval nests the dimensions
        // as a single array element, so the collision would no longer be found.
        $this->assertSame(
            "primary key 'currency' collides with a temporal column or dimension",
            new BootGuardPrimaryKey()->check($model),
        );
    }

    public function test_passes_for_a_well_configured_model(): void
    {
        $this->assertNull(new BootGuardPrimaryKey()->check(new ProductPrice));
    }
}
