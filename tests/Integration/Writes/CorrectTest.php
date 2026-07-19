<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class CorrectTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_retroactive_correction_splits_the_timeline(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->prices()->correct(['amount' => 1200], validFrom: '2026-04-01', validTo: '2026-07-01');

        $current = $product->prices()->currentKnowledge()->get();
        $this->assertCount(3, $current);
        $this->assertSame(1000, $product->prices()->validAt('2026-03-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(1200, $product->prices()->validAt('2026-05-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(1000, $product->prices()->validAt('2026-09-01')->currentKnowledge()->sole()->amount);

        // The old belief is still readable as it was known before today.
        $asKnownBefore = $product->prices()->validAt('2026-05-01')->knownAt('2026-01-15')->sole();
        $this->assertSame(1000, $asKnownBefore->amount);
    }

    public function test_open_ended_correction_overwrites_to_infinity(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->prices()->correct(['amount' => 1200], validFrom: '2026-04-01');

        $current = $product->prices()->currentKnowledge()->get();
        $this->assertCount(2, $current);
        $this->assertSame(1200, $product->prices()->validAt('2030-01-01')->currentKnowledge()->sole()->amount);
    }

    public function test_correction_compacts_adjacent_equivalent_segments(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        // Correct the later segment back down to 1000 so it merges with the first.
        $product->prices()->correct(['amount' => 1000], validFrom: '2026-06-01');

        $current = $product->prices()->currentKnowledge()->get();
        $this->assertCount(1, $current);

        $row = $current->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->valid_from->equalTo(CarbonImmutable::parse('2026-01-01')));
        $this->assertNull($row->valid_to);
    }

    public function test_writer_managed_columns_are_rejected(): void
    {
        $product = $this->makeProduct();

        $this->expectException(TemporalMissingDimensionException::class);

        $product->prices()->correct(['amount' => 1200, 'is_retraction' => true], validFrom: '2026-01-01');
    }

    public function test_string_correction_is_not_folded_to_a_no_op(): void
    {
        // Issue #66/#79: a zero-padded code correction ("007" -> "7") is a real
        // change. Folding the two through float would treat the write as a no-op
        // and silently drop it, leaving the timeline on the stale value.
        CarbonImmutable::setTestNow('2026-03-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, [
            'amount' => 1000, 'currency' => '007', 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01',
        ]);

        // amount is unchanged; only the string column moves, so the write must
        // hinge entirely on the "007" vs "7" comparison not collapsing.
        $product->prices()->correct(['amount' => 1000, 'currency' => '7'], validFrom: '2026-01-01');

        // A new current-known row carries the corrected code.
        $current = $product->prices()->currentKnowledge()->get();
        $this->assertCount(1, $current);
        $this->assertSame('7', $current->sole()->currency);

        // The prior belief was closed off, not overwritten in place: it is still
        // readable as it was known before the correction.
        $priorRows = ProductPrice::query()->where('product_id', $product->getKey())->count();
        $this->assertSame(2, $priorRows);
        $this->assertSame('007', $product->prices()->validAt('2026-01-15')->knownAt('2026-02-01')->sole()->currency);
    }

    public function test_correction_on_empty_timeline_inserts_one_row(): void
    {
        CarbonImmutable::setTestNow('2026-02-01 00:00:00');

        $product = $this->makeProduct();

        $product->prices()->correct(['amount' => 500], validFrom: '2026-01-01');

        $this->assertSame(1, ProductPrice::query()->count());
        $this->assertSame(500, $product->prices()->currentKnowledge()->sole()->amount);
    }
}
