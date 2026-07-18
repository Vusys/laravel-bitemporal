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

    /**
     * Pins the documented read contract (issue #43): the point-in-time
     * predicates include anti-rows by default, so a retracted window reads back
     * as "present but null" until the caller opts out with excludeRetractions().
     * Flipping this default would break diffs/timelines/writer, which all query
     * through the same predicates and must see anti-rows.
     */
    public function test_retractions_are_visible_to_default_reads_until_excluded(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $product->prices()->retract('2026-04-01', '2026-07-01');

        // Default validAt/currentKnowledge: the window resolves to the anti-row,
        // which is the "present but null" footgun the contract warns about.
        $default = $product->prices()->validAt('2026-05-01')->currentKnowledge()->get();
        $this->assertCount(1, $default);
        $defaultRow = $default->first();
        $this->assertNotNull($defaultRow);
        $this->assertTrue($defaultRow->is_retraction);
        $this->assertNull($defaultRow->amount);

        // knownAt (the recorded axis) sees the anti-row too.
        $known = $product->prices()->validAt('2026-05-01')->knownAt('2026-08-01')->get();
        $this->assertCount(1, $known);
        $knownRow = $known->first();
        $this->assertNotNull($knownRow);
        $this->assertTrue($knownRow->is_retraction);

        // excludeRetractions() is the opt-out: the window now reads as empty.
        $this->assertCount(0, $product->prices()->validAt('2026-05-01')->currentKnowledge()->excludeRetractions()->get());
        $this->assertCount(0, $product->prices()->validAt('2026-05-01')->knownAt('2026-08-01')->excludeRetractions()->get());
    }
}
