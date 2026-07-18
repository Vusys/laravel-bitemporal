<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Tests\Fixtures\Models\EffectivePrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Effective-dated-only models ($tracksRecordedTime = false) track valid time
 * only. Their table has no recorded spell, so superseded rows are overwritten
 * physically rather than closed via recorded_to.
 */
final class EffectiveDatedOnlyWriteTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_change_on_empty_timeline_inserts_one_row(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $product = $this->makeProduct();

        $product->bitemporalMany(EffectivePrice::class)->changeEffectiveFrom(['amount' => 500], '2026-01-01');

        $rows = EffectivePrice::query()->where('product_id', $product->getKey())->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertNotNull($row);
        $this->assertSame(500, $row->amount);
        $this->assertNull($row->valid_to);
    }

    public function test_forward_change_overwrites_the_old_row_in_place(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        EffectivePrice::query()->create([
            'product_id' => $product->getKey(),
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);

        $result = $product->bitemporalMany(EffectivePrice::class)->changeEffectiveFrom(['amount' => 1200], '2026-06-01');

        // No recorded axis: the old open-ended row is physically removed, not
        // preserved. The timeline is left with exactly the two new segments.
        $this->assertSame(2, EffectivePrice::query()->where('product_id', $product->getKey())->count());
        $this->assertSame(1, $result->closedCount());
        $this->assertSame(2, $result->insertedCount());

        $this->assertSame(1000, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-03-01')->sole()->amount);
        $this->assertSame(1200, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-09-01')->sole()->amount);
    }

    public function test_correct_rewrites_a_past_window_without_leaving_overlaps(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        EffectivePrice::query()->create([
            'product_id' => $product->getKey(),
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);

        $product->bitemporalMany(EffectivePrice::class)->correct(['amount' => 900], validFrom: '2026-02-01', validTo: '2026-03-01');

        $this->assertSame(900, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-02-15')->sole()->amount);
        $this->assertSame(1000, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-01-15')->sole()->amount);
        $this->assertSame(1000, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-04-01')->sole()->amount);

        // Every instant resolves to exactly one row — no overlapping live rows.
        $this->assertCount(1, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-02-15')->get());
    }

    public function test_retract_removes_a_window_from_the_timeline(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $product = $this->makeProduct();
        EffectivePrice::query()->create([
            'product_id' => $product->getKey(),
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);

        $product->bitemporalMany(EffectivePrice::class)->retract(validFrom: '2026-02-01', validTo: '2026-03-01');

        $this->assertSame(1000, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-01-15')->sole()->amount);
        $this->assertSame(1000, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-04-01')->sole()->amount);
        $this->assertCount(0, $product->bitemporalMany(EffectivePrice::class)->validAt('2026-02-15')->where('is_retraction', false)->get());
    }
}
