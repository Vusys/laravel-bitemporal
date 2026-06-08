<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class SupersedeTimelineTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_supersede_replaces_the_whole_timeline(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 999, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->prices()->supersedeTimeline([
            ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01'],
            ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null],
        ]);

        $current = $product->prices()->currentKnowledge()->get();
        $this->assertCount(2, $current);
        $this->assertSame(1000, $product->prices()->validAt('2026-03-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(1200, $product->prices()->validAt('2026-09-01')->currentKnowledge()->sole()->amount);

        // The old 999 belief is closed, not deleted.
        $this->assertSame(999, $product->prices()->validAt('2026-03-01')->knownAt('2026-01-15')->sole()->amount);
    }
}
