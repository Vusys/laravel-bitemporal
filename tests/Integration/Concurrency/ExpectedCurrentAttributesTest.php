<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Concurrency;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class ExpectedCurrentAttributesTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_correction_proceeds_when_the_expectation_matches(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->prices()->correct(
            attributes: ['amount' => 1200],
            validFrom: '2026-04-01',
            expectedCurrentAttributes: ['amount' => 1000],
        );

        $this->assertSame(1200, $product->prices()->validAt('2026-09-01')->currentKnowledge()->sole()->amount);
    }

    public function test_correction_aborts_when_the_expectation_is_stale(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');

        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->expectException(TemporalWriteConflictException::class);

        $product->prices()->correct(
            attributes: ['amount' => 1200],
            validFrom: '2026-04-01',
            expectedCurrentAttributes: ['amount' => 999],
        );
    }
}
