<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey\Journeys;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Journey\Concerns\ExploresTimelines;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * A journey that pins the bulk-import path (`backfill()->timeline()`) against the
 * incremental write path (`changeEffectiveFrom` / `correct`).
 *
 * One product's timeline is built incrementally through shuffled forward changes
 * and retroactive corrections. Periodically its current segments are lifted out
 * and backfilled into a brand-new product; the rebuilt timeline must be
 * indistinguishable from the original. Two entirely different code paths must
 * land on the same observable current knowledge.
 */
final class BackfillEquivalenceJourney extends Journey
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

            // Lift the current segments out and rebuild them from scratch in a
            // fresh product via the bulk-import path. The rebuild must be a
            // perfect copy of the original's current knowledge.
            Step::make('rebuild via backfill')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $source = $this->liveSegments($product);

                    $ctx->remember('source signature', array_map($this->signature(...), $source));

                    $rows = array_map(fn (ProductPrice $p): array => [
                        'amount' => $p->amount,
                        'valid_from' => $p->valid_from->format('Y-m-d H:i:s.u'),
                        'valid_to' => $p->valid_to?->format('Y-m-d H:i:s.u'),
                    ], $source);

                    if ($rows === []) {
                        $ctx->remember('rebuilt signature', []);

                        return;
                    }

                    $shadow = Product::query()->create(['name' => 'shadow']);
                    $shadow->prices()->backfill()->timeline($rows);

                    $ctx->remember('rebuilt signature', array_map($this->signature(...), $this->liveSegments($shadow)));
                })
                ->assert(fn (Context $ctx) => Assert::assertSame(
                    $ctx->get('source signature'),
                    $ctx->get('rebuilt signature'),
                    'A backfill of the current segments must reproduce the current timeline exactly.',
                )),
        ];
    }

    #[\Override]
    public function invariants(): array
    {
        return [
            Invariant::make('current knowledge holds no overlapping valid spans', function (Context $ctx): void {
                $previous = null;
                foreach ($this->liveSegments($ctx->instance('product', Product::class)) as $row) {
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
                $product = $ctx->instance('product', Product::class);
                $count = ProductPrice::query()->where('product_id', $product->id)->count();
                $high = $ctx->has('row high water') ? $ctx->integer('row high water') : 0;

                Assert::assertGreaterThanOrEqual($high, $count, 'Row count dropped — history was mutated in place.');
                $ctx->remember('row high water', $count);
            }),
        ];
    }

    /**
     * The product's live current-knowledge segments, ordered by valid_from.
     *
     * @return array<int, ProductPrice>
     */
    private function liveSegments(Product $product): array
    {
        return $product->prices()
            ->currentKnowledge()
            ->excludeRetractions()
            ->get()
            ->sortBy(fn (ProductPrice $p): int => $p->valid_from->getTimestamp())
            ->values()
            ->all();
    }

    private function signature(ProductPrice $price): string
    {
        return implode('|', [
            $price->valid_from->format('Y-m-d H:i:s.u'),
            $price->valid_to?->format('Y-m-d H:i:s.u') ?? '∞',
            (string) ($price->amount ?? 'null'),
        ]);
    }
}
