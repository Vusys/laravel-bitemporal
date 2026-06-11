<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\EdgeCases;

use Vusys\Bitemporal\Exceptions\TemporalDomainException;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class ClockSkewTest extends IntegrationTestCase
{
    public function test_write_refuses_when_recorded_from_is_far_in_the_future(): void
    {
        $product = $this->makeProduct();
        // An existing row whose recorded_from is years ahead of now() — a
        // regressed host clock. The writer must refuse rather than bump that far.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2099-01-01 00:00:00',
        ]);

        $this->expectException(TemporalDomainException::class);
        $this->expectExceptionMessageMatches('/Clock skew/');

        $product->prices()->correct(['amount' => 1200], '2026-02-01');
    }

    public function test_small_recorded_from_lead_is_tolerated_and_bumped(): void
    {
        $product = $this->makeProduct();
        // Within tolerance: a recorded_from a few ms ahead is bumped, not refused.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => now()->addMilliseconds(10)->format('Y-m-d H:i:s.u'),
        ]);

        $committed = $product->prices()->correct(['amount' => 1200], '2026-02-01');

        $this->assertGreaterThan(0, $committed->insertedCount());
    }
}
