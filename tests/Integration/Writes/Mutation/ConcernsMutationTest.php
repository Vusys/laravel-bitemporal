<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\Mutation;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins surviving mutants in:
 *   src__Concerns__HasTemporalEntity.txt   (PublicVisibility accessors + Ternary)
 *   src__Concerns__HasTemporalDimensions.txt (Continue_, TrueValue)
 *
 * HasTemporalWrites' killable dimension-tuple mutants are pinned by
 * BackfillMutationTest::test_backfill_stamps_dimension_columns_on_each_row (an
 * empty tuple leaves the stamped column null).
 */
final class ConcernsMutationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // --- HasTemporalEntity: PublicVisibility on the column accessors ---

    public function test_temporal_column_accessors_are_public(): void
    {
        $model = new ProductPrice;

        // Each accessor is `protected`-mutated; an external call must keep working.
        $this->assertTrue($model->tracksRecordedTime());
        $this->assertSame('valid_from', $model->validFromColumn());
        $this->assertSame('valid_to', $model->validToColumn());
        $this->assertSame('recorded_from', $model->recordedFromColumn());
        $this->assertSame('recorded_to', $model->recordedToColumn());
        $this->assertSame('is_retraction', $model->isRetractionColumn());
    }

    // --- HasTemporalEntity: Ternary in temporalColumn() ---

    public function test_temporal_column_uses_the_configured_string_name(): void
    {
        config(['bitemporal.columns.valid_from' => 'custom_valid_from']);

        // Real: is_string($value) is true -> return the config value. The Ternary
        // swap returns the config KEY ('valid_from') from the true branch instead.
        $this->assertSame('custom_valid_from', (new ProductPrice)->validFromColumn());
    }

    // --- HasTemporalDimensions: Continue_ in forDimensions() ---

    public function test_for_dimensions_applies_every_clause_after_a_null_value(): void
    {
        $builder = ProductPriceWithDimensions::query()->forDimensions([
            'currency' => null,   // null -> whereNull(); the real code `continue`s
            'amount' => 1000,     // the `break` mutant never reaches this clause
        ]);

        $this->assertCount(2, $builder->getQuery()->wheres);
    }

    // --- HasTemporalDimensions: TrueValue in hasWheresOutside() ---

    public function test_write_rejects_a_pending_non_string_where_clause(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        // A nested closure where has no string 'column'. hasWheresOutside() must
        // return true on it, which makes the writer reject the pending where. The
        // TrueValue mutant returns false and lets the write through.
        $this->expectException(TemporalMissingDimensionException::class);

        $product->prices()
            ->where(fn ($query) => $query->whereNull('amount'))
            ->correct(['amount' => 1000], validFrom: '2026-01-01');
    }
}
