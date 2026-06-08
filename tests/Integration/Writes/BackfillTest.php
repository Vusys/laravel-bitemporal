<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class BackfillTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_backfill_imports_historical_knowledge_with_explicit_recorded_periods(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->backfill()->timeline([
            [
                'attributes' => ['amount' => 1000],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
            ],
            [
                'attributes' => ['amount' => 1200],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-03-01', 'recorded_to' => null,
            ],
        ]);

        $this->assertSame(2, ProductPrice::query()->count());
        // The belief held in February was 1000.
        $this->assertSame(1000, $product->prices()->validAt('2026-04-01')->knownAt('2026-02-01')->sole()->amount);
        // Current knowledge is 1200.
        $this->assertSame(1200, $product->prices()->validAt('2026-04-01')->currentKnowledge()->sole()->amount);
    }

    public function test_backfill_retraction_inserts_an_anti_row(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->backfill()->retraction([
            'valid_from' => '2024-04-01', 'valid_to' => '2024-05-01',
            'recorded_from' => '2024-05-15', 'recorded_to' => null,
        ]);

        $row = $product->prices()->validAt('2024-04-15')->currentKnowledge()->sole();
        $this->assertTrue($row->is_retraction);
        $this->assertNull($row->amount);
    }

    public function test_backfill_rejects_a_future_recorded_from(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $this->expectException(TemporalInvalidSpellException::class);

        $product->prices()->backfill()->timeline([
            [
                'attributes' => ['amount' => 1000],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-12-01', 'recorded_to' => null,
            ],
        ]);
    }

    public function test_backfill_rejects_bitemporally_overlapping_rows(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();

        $this->expectException(TemporalOverlapException::class);

        $product->prices()->backfill()->timeline([
            [
                'attributes' => ['amount' => 1000],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-01-01', 'recorded_to' => null,
            ],
            [
                'attributes' => ['amount' => 1200],
                'valid_from' => '2026-01-01', 'valid_to' => null,
                'recorded_from' => '2026-02-01', 'recorded_to' => null,
            ],
        ]);
    }
}
