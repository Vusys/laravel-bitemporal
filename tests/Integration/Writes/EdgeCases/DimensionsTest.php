<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\EdgeCases;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class DimensionsTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedRow(Product $product, array $attributes): void
    {
        ProductPriceWithDimensions::query()->create([
            'product_id' => $product->getKey(),
            'recorded_from' => '2026-01-01 00:00:00',
            'recorded_to' => null,
            'is_retraction' => false,
            ...$attributes,
        ]);
    }

    public function test_for_dimensions_scopes_reads(): void
    {
        $product = $this->makeProduct();
        $this->seedRow($product, ['amount' => 1000, 'currency' => 'GBP', 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->seedRow($product, ['amount' => 2000, 'currency' => 'USD', 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $gbp = $product->dimensionedPrices()->forDimensions(['currency' => 'GBP'])->validAt('2026-03-01')->currentKnowledge()->sole();
        $this->assertSame(1000, $gbp->amount);
    }

    public function test_for_dimensions_scopes_writes(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        $this->seedRow($product, ['amount' => 1000, 'currency' => 'GBP', 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->seedRow($product, ['amount' => 2000, 'currency' => 'USD', 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->dimensionedPrices()->forDimensions(['currency' => 'GBP'])->changeEffectiveFrom(['amount' => 1200], '2026-06-01');

        $this->assertSame(1200, $product->dimensionedPrices()->forDimensions(['currency' => 'GBP'])->validAt('2026-09-01')->currentKnowledge()->sole()->amount);
        // The USD timeline is untouched.
        $this->assertCount(1, $product->dimensionedPrices()->forDimensions(['currency' => 'USD'])->currentKnowledge()->get());
        $this->assertSame(2000, $product->dimensionedPrices()->forDimensions(['currency' => 'USD'])->validAt('2026-09-01')->currentKnowledge()->sole()->amount);
    }

    public function test_missing_dimension_is_rejected(): void
    {
        $product = $this->makeProduct();

        $this->expectException(TemporalMissingDimensionException::class);

        $product->dimensionedPrices()->correct(['amount' => 1200], validFrom: '2026-01-01');
    }

    public function test_dimension_omitted_from_tuple_but_present_in_attributes_reports_incomplete(): void
    {
        // Issue #48: currency is a declared dimension, supplied in attributes but
        // NOT pinned via forDimensions(). The user's real mistake is an
        // incomplete dimension tuple; they must get that diagnostic, not the
        // misleading "conflict" (value-vs-null) error.
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $this->expectException(TemporalMissingDimensionException::class);
        $this->expectExceptionMessage("missing the required dimension 'currency'");

        $product->dimensionedPrices()->changeEffectiveFrom(['amount' => 1200, 'currency' => 'GBP'], '2026-06-01');
    }

    public function test_dimension_conflict_is_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $this->expectException(TemporalMissingDimensionException::class);

        $product->dimensionedPrices()
            ->forDimensions(['currency' => 'GBP'])
            ->changeEffectiveFrom(['amount' => 1200, 'currency' => 'USD'], '2026-06-01');
    }

    public function test_null_dimension_is_a_distinct_value(): void
    {
        $product = $this->makeProduct();
        $this->seedRow($product, ['amount' => 500, 'currency' => null, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->seedRow($product, ['amount' => 1000, 'currency' => 'GBP', 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $nullRows = $product->dimensionedPrices()->forDimensions(['currency' => null])->currentKnowledge()->get();
        $this->assertCount(1, $nullRows);
        $this->assertSame(500, $nullRows->first()?->amount);

        $gbpRows = $product->dimensionedPrices()->forDimensions(['currency' => 'GBP'])->currentKnowledge()->get();
        $this->assertCount(1, $gbpRows);
        $this->assertSame(1000, $gbpRows->first()?->amount);
    }

    public function test_pending_where_before_write_is_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $this->expectException(TemporalMissingDimensionException::class);

        $product->dimensionedPrices()
            ->forDimensions(['currency' => 'GBP'])
            ->where('amount', 1000)
            ->changeEffectiveFrom(['amount' => 1200], '2026-06-01');
    }
}
