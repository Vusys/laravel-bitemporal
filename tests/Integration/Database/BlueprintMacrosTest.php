<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Database;

use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class BlueprintMacrosTest extends IntegrationTestCase
{
    public function test_macros_create_the_temporal_columns(): void
    {
        Schema::create('macro_prices', function ($table): void {
            $table->id();
            $table->bitemporalForeignFor(Product::class);
            $table->integer('amount')->nullable();
            $table->bitemporalPeriods();
            $table->preventBitemporalOverlaps(['product_id']);
            $table->timestamps();
        });

        $this->assertTrue(Schema::hasColumns('macro_prices', [
            'product_id',
            'valid_from',
            'valid_to',
            'recorded_from',
            'recorded_to',
            'is_retraction',
        ]));

        Schema::dropIfExists('macro_prices');
    }

    public function test_overlap_macros_use_identifier_safe_index_names(): void
    {
        Schema::create('macro_prices', function ($table): void {
            $table->id();
            $table->bitemporalForeignFor(Product::class);
            $table->string('currency', 3);
            $table->bitemporalPeriods();
            $table->preventBitemporalOverlaps(['product_id'], ['currency']);
        });

        foreach (Schema::getIndexes('macro_prices') as $index) {
            $name = $index['name'] ?? '';
            $this->assertLessThanOrEqual(
                64,
                strlen(is_string($name) ? $name : ''),
                "index name '{$name}' exceeds the 64-character identifier limit",
            );
        }

        Schema::dropIfExists('macro_prices');
    }

    public function test_temporal_period_macro_creates_valid_only_columns(): void
    {
        Schema::create('macro_temporal', function ($table): void {
            $table->id();
            $table->bitemporalForeignFor(Product::class);
            $table->temporalPeriod();
            $table->preventTemporalOverlaps(['product_id']);
        });

        $this->assertTrue(Schema::hasColumns('macro_temporal', ['valid_from', 'valid_to', 'is_retraction']));
        $this->assertFalse(Schema::hasColumn('macro_temporal', 'recorded_from'));

        Schema::dropIfExists('macro_temporal');
    }
}
