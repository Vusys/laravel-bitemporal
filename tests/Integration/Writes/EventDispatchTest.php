<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Vusys\Bitemporal\Events\TemporalChangeCommitted;
use Vusys\Bitemporal\Events\TemporalChangeStarting;
use Vusys\Bitemporal\Events\TemporalCorrectionCommitted;
use Vusys\Bitemporal\Events\TemporalCorrectionStarting;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class EventDispatchTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_change_dispatches_starting_and_committed(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        Event::fake([TemporalChangeStarting::class, TemporalChangeCommitted::class]);

        $product = $this->makeProduct();

        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01');

        Event::assertDispatched(TemporalChangeStarting::class);
        Event::assertDispatched(TemporalChangeCommitted::class);
    }

    public function test_correction_dispatches_starting_and_committed(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        Event::fake([TemporalCorrectionStarting::class, TemporalCorrectionCommitted::class]);

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->prices()->correct(['amount' => 1200], validFrom: '2026-02-01');

        Event::assertDispatched(TemporalCorrectionStarting::class);
        Event::assertDispatched(TemporalCorrectionCommitted::class);
    }
}
