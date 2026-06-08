<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\EdgeCases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Vusys\Bitemporal\Events\TemporalFutureRowEncountered;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class FutureRowTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_forward_change_is_capped_by_a_future_row(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        Event::fake([TemporalFutureRowEncountered::class]);

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-09-01']);
        $this->insertPrice($product, ['amount' => 2000, 'valid_from' => '2026-09-01', 'valid_to' => null]);

        $product->prices()->changeEffectiveFrom(['amount' => 1200], '2026-06-01');

        // The future-dated 2000 row is preserved; the change stops at its boundary.
        $this->assertSame(1200, $product->prices()->validAt('2026-07-01')->currentKnowledge()->sole()->amount);
        $this->assertSame(2000, $product->prices()->validAt('2026-10-01')->currentKnowledge()->sole()->amount);

        Event::assertDispatched(TemporalFutureRowEncountered::class, fn (TemporalFutureRowEncountered $event): bool => $event->boundary->equalTo(CarbonImmutable::parse('2026-09-01')));
    }
}
