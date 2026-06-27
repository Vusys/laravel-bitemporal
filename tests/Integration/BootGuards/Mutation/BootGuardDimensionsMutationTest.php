<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\BootGuards\Mutation;

use Vusys\Bitemporal\Boot\Guards\BootGuardDimensions;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\BadDimensionsPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Kills the surviving mutants in BootGuardDimensions::check (the method_exists
 * LogicalNot and the Foreach_ that empties the loop) by exercising a model whose
 * $temporalDimensions list contains a non-string element after a valid one.
 */
final class BootGuardDimensionsMutationTest extends IntegrationTestCase
{
    public function test_rejects_a_non_string_dimension(): void
    {
        /** @var BadDimensionsPrice $model */
        $model = TemporalLens::withoutBootGuards(static fn (): BadDimensionsPrice => new BadDimensionsPrice);

        $this->assertSame(
            '$temporalDimensions must be an array of column-name strings',
            new BootGuardDimensions()->check($model),
        );
    }

    public function test_passes_for_string_dimensions(): void
    {
        $this->assertNull(new BootGuardDimensions()->check(new ProductPriceWithDimensions));
    }
}
