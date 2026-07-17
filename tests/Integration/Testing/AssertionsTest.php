<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Testing;

use PHPUnit\Framework\AssertionFailedError;
use Vusys\Bitemporal\Boot\Guards\BootGuardRelationType;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Testing\InteractsWithTimelines;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AssertionsTest extends IntegrationTestCase
{
    use InteractsWithTimelines;

    public function test_assert_temporal_attributes_passes_for_the_valid_row(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertTemporalAttributes($product->prices(), validAt: '2026-03-01', attributes: ['amount' => 1000]);
        $this->assertTemporalAttributes($product->prices(), validAt: '2026-09-01', attributes: ['amount' => 1200]);
    }

    public function test_assert_temporal_timeline_matches_positionally(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertTemporalTimeline($product->prices(), [
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
            ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null],
        ]);
    }

    public function test_assert_temporal_timeline_handles_retraction_rows(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-03-01']);
        $this->insertPrice($product, ['amount' => null, 'currency' => null, 'valid_from' => '2026-03-01', 'valid_to' => '2026-07-01', 'is_retraction' => true]);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-07-01', 'valid_to' => null]);

        $this->assertTemporalTimeline($product->prices(), [
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-03-01'],
            ['is_retraction' => true, 'valid_from' => '2026-03-01', 'valid_to' => '2026-07-01'],
            ['amount' => 1200, 'valid_from' => '2026-07-01', 'valid_to' => null],
        ]);
    }

    public function test_assert_temporal_timeline_unordered(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertTemporalTimelineUnordered($product->prices(), [
            ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null],
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
        ]);
    }

    public function test_assert_no_temporal_overlaps_passes_for_clean_timeline(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertNoTemporalOverlaps(ProductPrice::class);
    }

    public function test_assert_no_temporal_overlaps_detects_an_overlap(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-03-01', 'valid_to' => null]);

        $this->expectException(AssertionFailedError::class);
        $this->assertNoTemporalOverlaps(ProductPrice::class);
    }

    public function test_assert_no_bitemporal_overlaps_allows_superseded_rows(): void
    {
        $product = $this->makeProduct();
        // Same valid window, but the first row is superseded (recorded_to set),
        // so they do not overlap bitemporally.
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-01-01', 'recorded_to' => '2026-02-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => null]);

        $this->assertNoBitemporalOverlaps(ProductPrice::class);
    }

    public function test_assert_exactly_one_open_ended_current_known(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $this->assertExactlyOneOpenEndedCurrentKnownPerDimensionTuple($product);
    }

    public function test_expect_temporal_exception_helper(): void
    {
        $this->expectTemporalException(TemporalInvalidSpellException::class);

        throw new TemporalInvalidSpellException('boom');
    }

    public function test_expect_guard_failure(): void
    {
        $this->expectGuardFailure(
            BootGuardRelationType::class,
            function (): never {
                throw TemporalConfigurationException::guardFailures(
                    'X',
                    ['BootGuardRelationType' => 'temporalEntityRelation() must return a BelongsTo or MorphTo relation'],
                );
            },
        );
    }
}
