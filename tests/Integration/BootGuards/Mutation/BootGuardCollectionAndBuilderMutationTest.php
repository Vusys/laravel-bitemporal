<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\BootGuards\Mutation;

use Vusys\Bitemporal\Boot\Guards\BootGuardNewCollection;
use Vusys\Bitemporal\Boot\Guards\BootGuardNewEloquentBuilder;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\PlainBuilderPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\PlainCollectionPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Kills the InstanceOf_ mutants (`!$x instanceof Y` -> `!true`) in
 * BootGuardNewCollection and BootGuardNewEloquentBuilder. The mutant always
 * skips the failure branch, so the guard must actually fire for a model that
 * returns the wrong collection / builder type.
 */
final class BootGuardCollectionAndBuilderMutationTest extends IntegrationTestCase
{
    public function test_rejects_a_non_bitemporal_collection(): void
    {
        /** @var PlainCollectionPrice $model */
        $model = TemporalLens::withoutBootGuards(static fn (): PlainCollectionPrice => new PlainCollectionPrice);

        $this->assertSame(
            'newCollection() must return a Vusys\Bitemporal\Collections\BitemporalCollection',
            new BootGuardNewCollection()->check($model),
        );
    }

    public function test_accepts_a_bitemporal_collection(): void
    {
        $this->assertNull(new BootGuardNewCollection()->check(new ProductPrice));
    }

    public function test_rejects_a_non_bitemporal_builder(): void
    {
        /** @var PlainBuilderPrice $model */
        $model = TemporalLens::withoutBootGuards(static fn (): PlainBuilderPrice => new PlainBuilderPrice);

        $this->assertSame(
            'newEloquentBuilder() must return a Vusys\Bitemporal\BitemporalBuilder',
            new BootGuardNewEloquentBuilder()->check($model),
        );
    }

    public function test_accepts_a_bitemporal_builder(): void
    {
        $this->assertNull(new BootGuardNewEloquentBuilder()->check(new ProductPrice));
    }
}
