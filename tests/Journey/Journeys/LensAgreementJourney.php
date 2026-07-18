<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey\Journeys;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Journey\Concerns\ExploresTimelines;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * A journey that stresses the point-in-time *lens*: reading through an ambient
 * `TemporalLens::asOf($validAt, $knownAt, ...)` frame must return exactly what an
 * explicit `->validAt()->knownAt()` query returns.
 *
 * Forward changes and retroactive corrections are interleaved with clock
 * advances so record time accumulates layers of belief. After every step, a grid
 * of (valid, known) probes is read both ways and the two must agree — the lens
 * is only ever sugar for the predicates, never a different answer.
 */
final class LensAgreementJourney extends Journey
{
    use ExploresTimelines;

    private const string START = '2026-01-01 00:00:00';

    public function steps(): array
    {
        return [
            Step::make('create product')
                ->act(function (Context $ctx): void {
                    $ctx->travelTo(self::START);
                    $ctx->remember('product', Product::query()->create(['name' => 'Widget']));
                })
                ->assert(fn (Context $ctx) => Assert::assertTrue(
                    $ctx->instance('product', Product::class)->exists,
                )),

            Step::make('seed opening price')
                ->after('create product')
                ->act(function (Context $ctx): void {
                    $ctx->instance('product', Product::class)->prices()
                        ->changeEffectiveFrom(['amount' => $ctx->randomInt(1, 5) * 100], self::START);
                    $ctx->travel('+1 second');
                })
                ->assert(fn (Context $ctx) => Assert::assertSame(
                    1,
                    $ctx->instance('product', Product::class)->prices()->currentKnowledge()->count(),
                )),

            Step::make('advance the clock')
                ->after('seed opening price')
                ->repeatable()
                ->act(fn (Context $ctx) => $ctx->travel('+'.$ctx->randomInt(1, 30).' days')),

            Step::make('change effective from')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $validFrom = CarbonImmutable::now()->addDays($ctx->randomInt(0, 60));

                    $this->attempt(fn () => $product->prices()
                        ->changeEffectiveFrom(['amount' => $ctx->randomInt(1, 9) * 100], $validFrom));
                }),

            Step::make('correct a window')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $from = CarbonImmutable::parse(self::START)->addDays($ctx->randomInt(0, 400));
                    $to = $from->addDays($ctx->randomInt(1, 120));

                    $this->attempt(fn () => $product->prices()
                        ->correct(['amount' => $ctx->randomInt(1, 9) * 100], $from, $to));
                }),
        ];
    }

    public function invariants(): array
    {
        return [
            Invariant::make('current knowledge holds no overlapping valid spans', function (Context $ctx): void {
                $rows = $ctx->instance('product', Product::class)->prices()
                    ->currentKnowledge()
                    ->excludeRetractions()
                    ->get()
                    ->sortBy(fn (ProductPrice $p) => $p->valid_from->getTimestamp())
                    ->values();

                $previous = null;
                foreach ($rows as $row) {
                    if ($previous !== null) {
                        Assert::assertNotNull($previous->valid_to, 'An open-ended span cannot be followed by a later span.');
                        Assert::assertTrue(
                            $row->valid_from->greaterThanOrEqualTo($previous->valid_to),
                            'Two live value spans overlap in valid time.',
                        );
                    }
                    $previous = $row;
                }
            }),

            Invariant::make('physical history only ever grows', function (Context $ctx): void {
                $count = ProductPrice::query()->count();
                $high = $ctx->has('row high water') ? $ctx->integer('row high water') : 0;

                Assert::assertGreaterThanOrEqual($high, $count, 'Row count dropped — history was mutated in place.');
                $ctx->remember('row high water', $count);
            }),

            // The lens must be pure sugar: for every (valid, known) probe, reading
            // inside an ambient asOf() frame equals reading with explicit
            // predicates. Both bounds are pinned so at most one live row matches.
            Invariant::make('ambient lens reads agree with explicit predicates', function (Context $ctx): void {
                if (! $ctx->has('product')) {
                    return;
                }

                $product = $ctx->instance('product', Product::class);
                $anchor = CarbonImmutable::parse(self::START);

                foreach ([30, 400] as $vDays) {
                    foreach ([$anchor->addDay(), CarbonImmutable::now()] as $known) {
                        $valid = $anchor->addDays($vDays);

                        $viaLens = TemporalLens::asOf(
                            $valid,
                            $known,
                            fn (): ?int => ProductPrice::query()->whereTemporalEntity($product)->excludeRetractions()->first()?->amount,
                        );

                        $explicit = ProductPrice::query()
                            ->whereTemporalEntity($product)
                            ->validAt($valid)
                            ->knownAt($known)
                            ->excludeRetractions()
                            ->first()?->amount;

                        Assert::assertSame(
                            $explicit,
                            $viaLens,
                            "Lens and explicit predicates disagree at valid={$valid->toDateString()} known={$known->toDateString()}.",
                        );
                    }
                }
            }),
        ];
    }
}
