<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey\Journeys;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Journey\Concerns\ExploresTimelines;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * A journey over the two safety valves that guard a timeline against lost
 * updates: `expectedCurrentAttributes` (optimistic concurrency) and
 * `idempotencyKey` (exactly-once replay).
 *
 * The shuffler interleaves guarded corrections whose expectation is sometimes
 * fresh and sometimes deliberately stale, and keyed writes replayed back to
 * back. The properties that must survive every ordering:
 *  - a guarded write commits iff its expectation matched the value it named,
 *    and a rejected one leaves that value exactly as it was;
 *  - replaying a keyed write is a perfect no-op — no new row, same recorded_at;
 *  - current knowledge never overlaps and physical history only grows.
 */
final class OptimisticConcurrencyJourney extends Journey
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

            // A correction guarded by an expectation. When the expectation names
            // the value actually in place, the write must land; when it names a
            // stale value, the write must abort and change nothing.
            Step::make('guarded correction')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $from = CarbonImmutable::parse(self::START)->addDays($ctx->randomInt(0, 300));
                    $to = $from->addDays($ctx->randomInt(1, 120));

                    $observed = $this->currentAmountAt($product, $from);

                    // A stale-null expectation is not cleanly expressible, so only
                    // guard against a value that actually exists at the boundary.
                    if ($observed === null) {
                        $ctx->remember('guard skipped', true);

                        return;
                    }

                    $stale = $ctx->randomInt(0, 1) === 1;
                    $expected = $stale ? $observed + 1 : $observed;
                    $newAmount = $ctx->randomInt(1, 9) * 100;

                    $ctx->remember('guard skipped', false);
                    $ctx->remember('guard stale', $stale);
                    $ctx->remember('guard from', $from);
                    $ctx->remember('guard to', $to);
                    $ctx->remember('guard observed', $observed);
                    $ctx->remember('guard new amount', $newAmount);

                    $conflict = false;
                    try {
                        $product->prices()->correct(
                            ['amount' => $newAmount],
                            $from,
                            $to,
                            expectedCurrentAttributes: ['amount' => $expected],
                        );
                    } catch (TemporalWriteConflictException) {
                        $conflict = true;
                    }

                    $ctx->remember('guard conflict', $conflict);
                })
                ->assertWhen(
                    fn (Context $ctx): bool => $ctx->get('guard skipped') === false,
                    function (Context $ctx): void {
                        $product = $ctx->instance('product', Product::class);
                        $from = $ctx->instance('guard from', CarbonImmutable::class);
                        $to = $ctx->instance('guard to', CarbonImmutable::class);
                        $observed = $ctx->integer('guard observed');

                        if ($ctx->get('guard stale') === true) {
                            Assert::assertTrue($ctx->get('guard conflict') === true, 'A stale expectation must abort the write.');
                            Assert::assertSame($observed, $this->currentAmountAt($product, $from), 'An aborted write must leave the value untouched.');

                            return;
                        }

                        Assert::assertTrue($ctx->get('guard conflict') === false, 'A fresh expectation must let the write land.');
                        $mid = $from->addSeconds((int) ($from->diffInSeconds($to) / 2));
                        Assert::assertSame($ctx->integer('guard new amount'), $this->currentAmountAt($product, $mid), 'The guarded window must hold the new value.');
                    },
                ),

            // The same keyed write, replayed back to back, must be a perfect
            // no-op: no second physical row, and the original recorded_at handed
            // straight back.
            Step::make('idempotent replay')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $from = CarbonImmutable::parse(self::START)->addDays($ctx->randomInt(0, 300));
                    $amount = $ctx->randomInt(1, 9) * 100;
                    $key = 'idem-'.$ctx->timesRan('idempotent replay');

                    $first = $product->prices()->correct(['amount' => $amount], $from, null, idempotencyKey: $key);
                    $ctx->remember('idem rows', ProductPrice::query()->count());
                    $ctx->remember('idem recorded at', $first->recordedAt->format('Y-m-d H:i:s.u'));

                    $second = $product->prices()->correct(['amount' => $amount], $from, null, idempotencyKey: $key);
                    $ctx->remember('idem replay recorded at', $second->recordedAt->format('Y-m-d H:i:s.u'));
                })
                ->assert(function (Context $ctx): void {
                    Assert::assertSame(
                        $ctx->integer('idem rows'),
                        ProductPrice::query()->count(),
                        'A keyed replay must not write a second physical row.',
                    );
                    Assert::assertSame(
                        $ctx->string('idem recorded at'),
                        $ctx->string('idem replay recorded at'),
                        'A keyed replay must hand back the original recorded_at.',
                    );
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
        ];
    }

    private function currentAmountAt(Product $product, CarbonImmutable $at): ?int
    {
        return $product->prices()
            ->validAt($at)
            ->currentKnowledge()
            ->excludeRetractions()
            ->first()?->amount;
    }
}
