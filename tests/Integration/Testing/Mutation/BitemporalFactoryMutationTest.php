<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Testing\Mutation;

use Carbon\CarbonImmutable;
use LogicException;
use Vusys\Bitemporal\Factories\BitemporalFactory;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins each factory state to the exact column/value it persists, plus the
 * non-temporal-model guard.
 */
final class BitemporalFactoryMutationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_recorded_from_sets_recorded_from_column(): void
    {
        // Kills recordedFrom PublicVisibility, ArrayItem ('>'), ArrayItemRemoval.
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->recordedFrom('2026-04-01')
            ->create();

        $this->assertSame('2026-04-01', $price->recorded_from->format('Y-m-d'));
    }

    public function test_recorded_to_sets_recorded_to_column(): void
    {
        // Kills recordedTo PublicVisibility, Identical (=== -> !==), Ternary
        // swap, ArrayItem ('>'), ArrayItemRemoval.
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->recordedTo('2026-05-01')
            ->create();

        $this->assertNotNull($price->recorded_to);
        $this->assertSame('2026-05-01', $price->recorded_to->format('Y-m-d'));
    }

    public function test_current_knowledge_nulls_a_previously_set_recorded_to(): void
    {
        // Kills currentKnowledge ArrayItemRemoval (state([])): superseded sets
        // recorded_to, currentKnowledge must reset it to null.
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->superseded('2026-03-01')
            ->currentKnowledge()
            ->create();

        $this->assertNull($price->recorded_to);
    }

    public function test_open_ended_nulls_a_previously_set_valid_to(): void
    {
        // Kills openEnded ArrayItemRemoval (state([])): validTo sets valid_to,
        // openEnded must reset it to null.
        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->validTo('2026-06-01')
            ->openEnded()
            ->create();

        $this->assertNull($price->valid_to);
    }

    public function test_instant_applies_the_configured_timezone(): void
    {
        // Kills the instant() Ternary mutant (is_string ? 'UTC' : $timezone):
        // a non-UTC configured timezone must actually shift the wall clock.
        config(['bitemporal.spells.timezone' => 'Asia/Tokyo']);

        $product = $this->makeProduct();

        $price = ProductPrice::factory()->for($product)
            ->validFrom('2026-03-01 00:00:00')
            ->create();

        // 2026-03-01 00:00 UTC -> 09:00 in Asia/Tokyo (+09:00).
        $this->assertSame('09:00', $price->valid_from->format('H:i'));
    }

    public function test_non_temporal_model_throws_with_a_descriptive_message(): void
    {
        // Kills meta() Throw_, Concat reorder, and the two ConcatOperandRemoval
        // mutants on the LogicException message.
        $factory = new class extends BitemporalFactory
        {
            /** @var class-string<Product> */
            protected $model = Product::class;

            /**
             * @return array<string, mixed>
             */
            public function definition(): array
            {
                return [];
            }
        };

        try {
            $factory->currentKnowledge();
            $this->fail('Expected a LogicException for a non-temporal model.');
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
            $this->assertStringStartsWith(Product::class, $message);
            $this->assertStringContainsString('is not a temporal model', $message);
        }
    }
}
