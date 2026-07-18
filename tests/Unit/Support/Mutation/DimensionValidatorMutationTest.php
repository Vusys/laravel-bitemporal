<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Support\Mutation;

use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Support\DimensionValidator;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Mutation coverage for {@see DimensionValidator}: the unknown-dimension loop
 * and throw, and the reconcile loop / continue.
 */
final class DimensionValidatorMutationTest extends TestCase
{
    public function test_assert_complete_passes_for_an_exact_tuple(): void
    {
        DimensionValidator::assertComplete(['currency'], ['currency' => 'GBP']);

        $this->expectNotToPerformAssertions();
    }

    // Kills the second Foreach_ ([] would skip the unknown-dimension scan) and
    // the unknownDimension Throw_ (removing the throw lets the unknown key slip
    // through).
    public function test_assert_complete_rejects_an_undeclared_dimension(): void
    {
        $this->expectException(TemporalMissingDimensionException::class);
        $this->expectExceptionMessage("'extra' is not a declared temporal dimension");

        DimensionValidator::assertComplete(['currency'], ['currency' => 'GBP', 'extra' => 1]);
    }

    // Kills the reconcile Foreach_ ([] would leave the dimension key in the
    // payload).
    public function test_reconcile_strips_matching_dimension_keys(): void
    {
        $result = DimensionValidator::reconcileAttributes(
            ['currency'],
            ['currency' => 'GBP'],
            ['currency' => 'GBP', 'amount' => 100],
        );

        $this->assertSame(['amount' => 100], $result);
    }

    // Kills the Continue_ -> break mutant: a leading declared column missing
    // from the payload must be skipped (continue), not abort the loop (break)
    // before the later column is stripped.
    public function test_reconcile_continues_past_absent_dimensions(): void
    {
        $result = DimensionValidator::reconcileAttributes(
            ['region', 'currency'],
            ['region' => 'EU', 'currency' => 'GBP'],
            ['currency' => 'GBP'],
        );

        $this->assertSame([], $result);
    }

    public function test_reconcile_throws_on_a_conflicting_value(): void
    {
        $this->expectException(TemporalMissingDimensionException::class);

        DimensionValidator::reconcileAttributes(
            ['currency'],
            ['currency' => 'GBP'],
            ['currency' => 'USD'],
        );
    }

    // Issue #48: a dimension present in attributes but absent from the tuple is
    // an incomplete-dimension case (assertComplete's job). reconcileAttributes
    // must NOT throw a misleading conflict against a null tuple value; it leaves
    // the key in place so assertComplete can raise the right error.
    public function test_reconcile_defers_missing_tuple_dimension_to_assert_complete(): void
    {
        $result = DimensionValidator::reconcileAttributes(
            ['currency'],
            [], // currency omitted from the tuple
            ['currency' => 'GBP', 'amount' => 100],
        );

        $this->assertSame(['currency' => 'GBP', 'amount' => 100], $result);
    }
}
