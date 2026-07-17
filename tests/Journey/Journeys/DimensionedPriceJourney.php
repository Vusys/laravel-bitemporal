<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * A journey over a *dimensioned* timeline: one product carries an independent
 * price timeline per currency. Writes are scoped with `forDimensions()`, and
 * the shuffler is free to edit the currencies in any interleaving.
 *
 * The point of interest is dimension isolation: an edit to GBP must never
 * disturb USD or EUR. The invariants police that from two angles — each
 * currency's live timeline stays internally consistent, and the whole baseline
 * belief (currency included) is frozen against every later write.
 */
final class DimensionedPriceJourney extends Journey
{
    private const string START = '2026-01-01 00:00:00';

    /** @var list<string> */
    private const array CURRENCIES = ['GBP', 'USD', 'EUR'];

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

            Step::make('seed one price per currency')
                ->after('create product')
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);

                    foreach (self::CURRENCIES as $currency) {
                        $product->dimensionedPrices()
                            ->forDimensions(['currency' => $currency])
                            ->changeEffectiveFrom(['amount' => $ctx->randomInt(1, 5) * 100], self::START);
                    }

                    $t0 = CarbonImmutable::now();
                    $ctx->remember('t0', $t0);
                    $ctx->remember('known at t0', $this->snapshot(
                        $product->dimensionedPrices()->knownAt($t0)->excludeRetractions()->get(),
                    ));
                    $ctx->travel('+1 second');
                })
                ->assert(fn (Context $ctx) => Assert::assertCount(
                    3,
                    $ctx->instance('product', Product::class)->dimensionedPrices()->currentKnowledge()->get(),
                )),

            Step::make('advance the clock')
                ->after('seed one price per currency')
                ->repeatable()
                ->act(fn (Context $ctx) => $ctx->travel('+'.$ctx->randomInt(1, 30).' days')),

            Step::make('change one currency forward')
                ->after('seed one price per currency')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $currency = $ctx->pick(self::CURRENCIES);
                    $validFrom = CarbonImmutable::now()->addDays($ctx->randomInt(0, 60));
                    $amount = $ctx->randomInt(1, 9) * 100;

                    $ctx->remember('edit currency', $currency);
                    $ctx->remember('edit valid from', $validFrom);
                    $ctx->remember('edit amount', $amount);
                    $ctx->remember('edit rejected', $this->attempt(fn () => $product->dimensionedPrices()
                        ->forDimensions(['currency' => $currency])
                        ->changeEffectiveFrom(['amount' => $amount], $validFrom)));
                })
                ->assertWhen(
                    fn (Context $ctx): bool => $ctx->get('edit rejected') === false,
                    function (Context $ctx): void {
                        $product = $ctx->instance('product', Product::class);
                        $rows = $product->dimensionedPrices()
                            ->forDimensions(['currency' => $ctx->string('edit currency')])
                            ->validAt($ctx->instance('edit valid from', CarbonImmutable::class))
                            ->currentKnowledge()
                            ->excludeRetractions()
                            ->get();

                        Assert::assertCount(1, $rows, 'A forward edit must leave exactly one live value for that currency.');
                        Assert::assertSame($ctx->integer('edit amount'), $rows->firstOrFail()->amount);
                    },
                ),

            Step::make('correct one currency retroactively')
                ->after('seed one price per currency')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $currency = $ctx->pick(self::CURRENCIES);
                    $from = CarbonImmutable::parse(self::START)->addDays($ctx->randomInt(0, 400));
                    $to = $from->addDays($ctx->randomInt(1, 120));

                    $this->attempt(fn () => $product->dimensionedPrices()
                        ->forDimensions(['currency' => $currency])
                        ->correct(['amount' => $ctx->randomInt(1, 9) * 100], $from, $to));
                }),
        ];
    }

    public function invariants(): array
    {
        return [
            Invariant::make('each currency timeline has no overlapping valid spans', function (Context $ctx): void {
                $product = $ctx->instance('product', Product::class);

                foreach (self::CURRENCIES as $currency) {
                    $rows = $product->dimensionedPrices()
                        ->forDimensions(['currency' => $currency])
                        ->currentKnowledge()
                        ->excludeRetractions()
                        ->get()
                        ->sortBy(fn (ProductPriceWithDimensions $p) => $p->valid_from->getTimestamp())
                        ->values();

                    $previous = null;
                    foreach ($rows as $row) {
                        if ($previous !== null) {
                            Assert::assertNotNull($previous->valid_to, "An open-ended {$currency} span cannot precede another.");
                            Assert::assertTrue(
                                $row->valid_from->greaterThanOrEqualTo($previous->valid_to),
                                "Two live {$currency} spans overlap in valid time.",
                            );
                        }
                        $previous = $row;
                    }
                }
            }),

            Invariant::make('physical history only ever grows', function (Context $ctx): void {
                $count = ProductPriceWithDimensions::query()->count();
                $high = $ctx->has('row high water') ? $ctx->integer('row high water') : 0;

                Assert::assertGreaterThanOrEqual($high, $count, 'Row count dropped — history was mutated in place.');
                $ctx->remember('row high water', $count);
            }),

            Invariant::make('the baseline belief across every currency is immutable', function (Context $ctx): void {
                if (! $ctx->has('known at t0')) {
                    return;
                }

                $current = $this->snapshot(
                    $ctx->instance('product', Product::class)->dimensionedPrices()
                        ->knownAt($ctx->instance('t0', CarbonImmutable::class))
                        ->excludeRetractions()
                        ->get(),
                );

                Assert::assertSame(
                    $ctx->get('known at t0'),
                    $current,
                    'A later write rewrote the baseline belief of some currency as of t0.',
                );
            }),
        ];
    }

    private function attempt(callable $write): bool
    {
        try {
            $write();

            return false;
        } catch (TemporalInvalidSpellException) {
            return true;
        }
    }

    /**
     * @param  Collection<int, ProductPriceWithDimensions>  $rows
     * @return list<array{currency: string|null, valid_from: string, valid_to: string|null, amount: int|null}>
     */
    private function snapshot(Collection $rows): array
    {
        $view = [];
        foreach ($rows as $p) {
            $view[] = [
                'currency' => $p->currency,
                'valid_from' => (string) $p->valid_from,
                'valid_to' => $p->valid_to === null ? null : (string) $p->valid_to,
                'amount' => $p->amount,
            ];
        }

        usort($view, fn (array $a, array $b): int => [$a['currency'], $a['valid_from']] <=> [$b['currency'], $b['valid_from']]);

        return $view;
    }
}
