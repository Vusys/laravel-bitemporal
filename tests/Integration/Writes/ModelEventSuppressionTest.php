<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class ModelEventSuppressionTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_eloquent_model_events_are_suppressed_by_default(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $created = 0;
        Event::listen('eloquent.created: '.ProductPrice::class, function () use (&$created): void {
            $created++;
        });

        $product = $this->makeProduct();
        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01');

        $this->assertSame(0, $created);
    }

    public function test_eloquent_model_events_fire_when_enabled(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        config(['bitemporal.writes.fire_eloquent_events' => true]);

        $created = 0;
        Event::listen('eloquent.created: '.ProductPrice::class, function () use (&$created): void {
            $created++;
        });

        $product = $this->makeProduct();
        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01');

        $this->assertSame(1, $created);
    }
}
