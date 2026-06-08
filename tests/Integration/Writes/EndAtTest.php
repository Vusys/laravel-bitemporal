<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class EndAtTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_end_at_closes_the_open_ended_fact(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->prices()->endAt('2026-12-31');

        $row = $product->prices()->currentKnowledge()->sole();
        $this->assertTrue($row->valid_to?->equalTo(CarbonImmutable::parse('2026-12-31')));

        // After the end date the fact no longer holds.
        $this->assertCount(0, $product->prices()->validAt('2027-01-01')->currentKnowledge()->get());
        $this->assertSame(1000, $product->prices()->validAt('2026-06-01')->currentKnowledge()->sole()->amount);
    }
}
