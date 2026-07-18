<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Lens;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ValidTimeOnlyPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AsOfPropagationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function seedCorrectedTimeline(): Product
    {
        $product = $this->makeProduct();

        // Original belief (since superseded by a correction recorded 2026-03-01).
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
        ]);
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01',
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);
        $this->insertPrice($product, [
            'amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null,
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);

        return $product;
    }

    public function test_reads_inherit_the_ambient_lens(): void
    {
        $product = $this->seedCorrectedTimeline();

        $amount = TemporalLens::asOf('2026-09-01', '2026-02-01', fn () => ProductPrice::query()->whereTemporalEntity($product)->sole()->amount);

        $this->assertSame(1000, $amount);
    }

    public function test_explicit_predicates_override_the_lens(): void
    {
        $product = $this->seedCorrectedTimeline();

        // Pin knownAt explicitly to current; only validAt is inherited.
        $amount = TemporalLens::asOf('2026-09-01', '2026-02-01', fn () => ProductPrice::query()->whereTemporalEntity($product)->currentKnowledge()->sole()->amount);

        $this->assertSame(1200, $amount);
    }

    public function test_nested_frames_merge(): void
    {
        $product = $this->seedCorrectedTimeline();

        $amount = TemporalLens::knownAt('2026-02-01', fn () => TemporalLens::validAt('2026-09-01', fn () => ProductPrice::query()->whereTemporalEntity($product)->sole()->amount));

        $this->assertSame(1000, $amount);
    }

    public function test_eager_loads_inherit_the_lens(): void
    {
        $product = $this->seedCorrectedTimeline();

        $amounts = TemporalLens::asOf('2026-09-01', '2026-02-01', function () use ($product): array {
            $loaded = Product::query()->with('prices')->findOrFail($product->id);

            return $loaded->prices->pluck('amount')->all();
        });

        $this->assertSame([1000], $amounts);
    }

    public function test_without_lens_ignores_the_ambient_frame(): void
    {
        $product = $this->seedCorrectedTimeline();

        $count = TemporalLens::asOf('2026-09-01', '2026-02-01', fn () => ProductPrice::query()->whereTemporalEntity($product)->withoutLens()->count());

        $this->assertSame(3, $count);
    }

    public function test_ambient_known_at_is_skipped_for_valid_time_only_models(): void
    {
        $product = $this->makeProduct();

        // A valid-time-only model reuses the price table but opts out of
        // recorded-time tracking. Two valid segments, no recorded axis in play.
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        // An ambient lens carrying a knownAt must not throw here: the recorded
        // axis simply degrades away, the valid axis still applies.
        $amount = TemporalLens::asOf('2026-03-01', '2026-02-01', fn () => ValidTimeOnlyPrice::query()->whereTemporalEntity($product)->sole()->amount);

        $this->assertSame(1000, $amount);
    }

    public function test_ambient_known_at_only_frame_no_ops_for_valid_time_only_models(): void
    {
        $product = $this->makeProduct();

        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        // Mirror of the asOf case: a knownAt-only frame degrades to a no-op for a
        // valid-time-only model. With no valid axis to apply either, every segment
        // is returned rather than throwing on the recorded-time guard.
        $amounts = TemporalLens::knownAt('2026-02-01', fn () => ValidTimeOnlyPrice::query()
            ->whereTemporalEntity($product)
            ->orderBy('valid_from')
            ->pluck('amount')
            ->all());

        $this->assertSame([1000, 1200], $amounts);
    }

    public function test_ambient_known_at_only_frame_still_applies_to_a_bitemporal_model(): void
    {
        $product = $this->seedCorrectedTimeline();

        // Same frame, a bitemporal model: the recorded axis is real here, so the
        // knownAt is honoured and the read reflects the pre-correction belief
        // (the row recorded before 2026-03-01), not the current one.
        $amount = TemporalLens::knownAt('2026-02-01', fn () => ProductPrice::query()
            ->whereTemporalEntity($product)
            ->validAt('2026-09-01')
            ->sole()
            ->amount);

        $this->assertSame(1000, $amount);
    }

    public function test_writes_ignore_the_ambient_lens(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        // An ambient past lens must not scope the write's view of current state.
        TemporalLens::asOf('2026-02-01', '2026-02-01', function () use ($product): void {
            $product->prices()->correct(['amount' => 1200], validFrom: '2026-04-01');
        });

        $this->assertSame(1200, $product->prices()->validAt('2030-01-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(1000, $product->prices()->validAt('2026-02-01')->currentKnowledge()->sole()->amount);
    }
}
