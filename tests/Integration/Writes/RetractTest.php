<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class RetractTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_retraction_voids_a_window_with_an_anti_row(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->prices()->retract('2026-04-01', '2026-07-01');

        // The window is now an anti-row: present, flagged, with null attributes.
        $antiRow = $product->prices()->validAt('2026-05-01')->currentKnowledge()->sole();
        $this->assertTrue($antiRow->is_retraction);
        $this->assertNull($antiRow->amount);

        // Excluding retractions, the window reads as empty.
        $this->assertCount(0, $product->prices()->validAt('2026-05-01')->currentKnowledge()->excludeRetractions()->get());

        // Surrounding value is intact.
        $this->assertSame(1000, $product->prices()->validAt('2026-03-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(1000, $product->prices()->validAt('2026-09-01')->currentKnowledge()->sole()->amount);
    }
}
