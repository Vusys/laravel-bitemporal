<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Lens\Mutation;

use Illuminate\Support\Collection;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Concerns\InteractsWithLens;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Lens\LensStack;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Mutation coverage for {@see InteractsWithLens}: the
 * ambient-lens application, the explicit-pin/withoutLens/captureLens opt-outs,
 * and the null-safe lens resolution.
 */
final class InteractsWithLensMutationTest extends IntegrationTestCase
{
    private function seedTwoSegments(): Product
    {
        $product = $this->makeProduct();

        // Single knowledge (recorded_to null), two valid segments.
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        return $product;
    }

    // Kills withoutLens TrueValue (lensDisabled left false) and applyAmbientLens
    // LogicalOr (`||` -> `&&`): a get() through getModels() must ignore the
    // ambient frame and return every segment.
    public function test_without_lens_returns_every_segment(): void
    {
        $product = $this->seedTwoSegments();

        $rows = TemporalLens::asOf('2026-03-01', '2026-02-01', fn () => ProductPrice::query()
            ->whereTemporalEntity($product)
            ->withoutLens()
            ->get());

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(2, $rows);
    }

    // Kills markValidAtPinned TrueValue (explicitValidAt left false) and
    // applyAmbientLens LogicalAnd (`&&` -> `||`): an explicit validAt() must win
    // over the ambient frame's validAt.
    public function test_explicit_valid_at_overrides_the_ambient_frame(): void
    {
        $product = $this->seedTwoSegments();

        $amount = TemporalLens::asOf('2026-09-01', '2026-02-01', fn () => ProductPrice::query()
            ->whereTemporalEntity($product)
            ->validAt('2026-03-01')
            ->sole()
            ->amount);

        $this->assertSame(1000, $amount);
    }

    // Kills the validAt InstanceOf_ (`$frame->validAt instanceof ...` -> true):
    // a knownAt-only frame has a null validAt, which must NOT be applied.
    public function test_known_at_only_frame_does_not_pin_valid_at(): void
    {
        $product = $this->seedTwoSegments();

        $rows = TemporalLens::knownAt('2026-02-01', fn () => ProductPrice::query()
            ->whereTemporalEntity($product)
            ->get());

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(2, $rows);
    }

    // Kills the knownAt InstanceOf_ (`$frame->knownAt instanceof ...` -> true):
    // a validAt-only frame has a null knownAt, which must NOT be applied.
    public function test_valid_at_only_frame_does_not_pin_known_at(): void
    {
        $product = $this->seedTwoSegments();

        $rows = TemporalLens::validAt('2026-03-01', fn () => ProductPrice::query()
            ->whereTemporalEntity($product)
            ->get());

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(1, $rows);

        $first = $rows->first();
        $this->assertInstanceOf(ProductPrice::class, $first);
        $this->assertSame(1000, $first->amount);
    }

    // Kills captureLens TrueValue (hasCapturedFrame left false): a captured
    // frame must still drive the read after the stack frame has been popped.
    public function test_captured_frame_survives_leaving_the_stack(): void
    {
        $product = $this->seedTwoSegments();

        $builder = TemporalLens::asOf('2026-03-01', '2026-02-01', fn () => ProductPrice::query()
            ->whereTemporalEntity($product)
            ->captureLens());

        // The asOf frame is now popped; the captured frame must persist.
        $this->assertInstanceOf(BitemporalBuilder::class, $builder);
        $rows = $builder->get();

        $this->assertCount(1, $rows);

        $first = $rows->first();
        $this->assertInstanceOf(ProductPrice::class, $first);
        $this->assertSame(1000, $first->amount);
    }

    // Kills the getModels ArrayItemRemoval (`['*']` -> `[]`): getModels() with no
    // arguments must hydrate full rows.
    public function test_get_models_selects_all_columns_by_default(): void
    {
        $product = $this->seedTwoSegments();

        $models = ProductPrice::query()->whereTemporalEntity($product)->getModels();

        $this->assertNotSame([], $models);

        $first = $models[0];
        $this->assertInstanceOf(ProductPrice::class, $first);
        $this->assertSame(1000, $first->amount);
    }

    // Kills the PublicVisibility mutants on captureLens / markValidAtPinned /
    // markKnownAtPinned: each must remain callable from outside the class.
    public function test_pin_and_capture_helpers_are_public(): void
    {
        $this->assertInstanceOf(BitemporalBuilder::class, ProductPrice::query()->captureLens());

        ProductPrice::query()->markValidAtPinned();
        ProductPrice::query()->markKnownAtPinned();

        $this->addToAssertionCount(1);
    }

    // Kills the captureLens NullSafeMethodCall (`?->current()` -> `->current()`):
    // with no LensStack bound, resolution returns null and must not be
    // dereferenced.
    public function test_capture_lens_tolerates_an_unbound_stack(): void
    {
        unset($this->app[LensStack::class]);

        $this->assertInstanceOf(BitemporalBuilder::class, ProductPrice::query()->captureLens());
    }

    // Kills the applyAmbientLens NullSafeMethodCall: a read with no LensStack
    // bound must still resolve to "no frame" rather than dereferencing null.
    public function test_get_models_tolerates_an_unbound_stack(): void
    {
        $product = $this->seedTwoSegments();

        unset($this->app[LensStack::class]);

        $rows = ProductPrice::query()->whereTemporalEntity($product)->get();

        $this->assertCount(2, $rows);
    }
}
