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
 * A proof-of-concept journey over a single product's bitemporal price timeline.
 *
 * The shuffler interleaves forward edits, retroactive corrections, retractions,
 * timeline ends, whole-timeline supersessions and clock advances in orders we
 * never hand-write. Because "advance the clock" is an ordinary step, record time
 * (when we learnt something) and valid time (when it is true) drift apart
 * differently on every trail — which is exactly the surface where bitemporal
 * write bugs hide. Each mutating step also draws a compaction preference, so
 * segment-coalescing runs (or is suppressed) in unplanned combinations too.
 *
 * The invariants below encode the properties that must survive any ordering:
 *  - current knowledge never holds two overlapping valid spans for one entity;
 *  - physical history only ever grows (writes never mutate a row in place);
 *  - what we believed as-of an early instant (t0) is frozen for good — a later
 *    retroactive edit must not rewrite the past belief;
 *  - the current value at a fixed historical instant only ever moves when a step
 *    actually edits a window covering it — clock advances and compaction never
 *    disturb it.
 */
final class PriceTimelineJourney extends Journey
{
    use ExploresTimelines;

    /**
     * The instant the timeline is anchored to. The clock only ever moves forward
     * from here, so `knownAt(START)` observations stay reproducible.
     */
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
                    $product = $ctx->instance('product', Product::class);

                    $product->prices()->changeEffectiveFrom(
                        ['amount' => $ctx->randomInt(1, 5) * 100],
                        self::START,
                    );
                    $this->recordEdit($ctx, CarbonImmutable::parse(self::START), null);

                    // Freeze the belief as known at the anchor instant, then step
                    // off it by a hair so every later write records strictly after.
                    $t0 = CarbonImmutable::now();
                    $ctx->remember('t0', $t0);
                    $ctx->remember('known at t0', $this->snapshot(
                        $product->prices()->knownAt($t0)->excludeRetractions()->get(),
                    ));
                    $ctx->travel('+1 second');
                })
                ->assert(fn (Context $ctx) => Assert::assertSame(
                    1,
                    $ctx->instance('product', Product::class)->prices()->currentKnowledge()->count(),
                )),

            // Time is a shuffleable event: some trails cross day/record boundaries
            // between edits, others pile edits up within one instant.
            Step::make('advance the clock')
                ->after('seed opening price')
                ->repeatable()
                ->act(fn (Context $ctx) => $ctx->travel('+'.$ctx->randomInt(1, 30).' days')),

            // A forward edit: change the value effective from now-or-later. The
            // value at that instant must become exactly the amount we just wrote.
            Step::make('change effective from')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $validFrom = CarbonImmutable::now()->addDays($ctx->randomInt(0, 60));
                    $amount = $ctx->randomInt(1, 9) * 100;

                    $ctx->remember('fc valid from', $validFrom);
                    $ctx->remember('fc amount', $amount);
                    $ctx->remember('fc rejected', $this->attempt(
                        fn () => $product->prices()->changeEffectiveFrom(['amount' => $amount], $validFrom, $this->drawCompact($ctx)),
                    ));

                    if ($ctx->get('fc rejected') === false) {
                        $this->recordEdit($ctx, $validFrom, null);
                    }
                })
                ->assertWhen(
                    fn (Context $ctx): bool => $ctx->get('fc rejected') === false,
                    function (Context $ctx): void {
                        $product = $ctx->instance('product', Product::class);
                        $rows = $product->prices()
                            ->validAt($ctx->instance('fc valid from', CarbonImmutable::class))
                            ->currentKnowledge()
                            ->excludeRetractions()
                            ->get();

                        Assert::assertCount(1, $rows, 'A forward edit must leave exactly one live value at its start.');
                        Assert::assertSame($ctx->integer('fc amount'), $rows->firstOrFail()->amount);
                    },
                ),

            // A retroactive correction: rewrite a bounded window of valid time.
            // Corrections are allowed to reach into the past — that is the point.
            Step::make('correct a window')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $from = CarbonImmutable::parse(self::START)->addDays($ctx->randomInt(0, 400));
                    $to = $from->addDays($ctx->randomInt(1, 120));
                    $amount = $ctx->randomInt(1, 9) * 100;

                    $ctx->remember('corr from', $from);
                    $ctx->remember('corr to', $to);
                    $ctx->remember('corr amount', $amount);
                    $ctx->remember('corr rejected', $this->attempt(
                        fn () => $product->prices()->correct(['amount' => $amount], $from, $to, $this->drawCompact($ctx)),
                    ));

                    if ($ctx->get('corr rejected') === false) {
                        $this->recordEdit($ctx, $from, $to);
                    }
                })
                ->assertWhen(
                    fn (Context $ctx): bool => $ctx->get('corr rejected') === false,
                    function (Context $ctx): void {
                        $product = $ctx->instance('product', Product::class);
                        $from = $ctx->instance('corr from', CarbonImmutable::class);
                        $to = $ctx->instance('corr to', CarbonImmutable::class);
                        $mid = $from->addSeconds((int) ($from->diffInSeconds($to) / 2));

                        $rows = $product->prices()
                            ->validAt($mid)
                            ->currentKnowledge()
                            ->excludeRetractions()
                            ->get();

                        Assert::assertCount(1, $rows, 'A correction must leave exactly one live value inside its window.');
                        Assert::assertSame($ctx->integer('corr amount'), $rows->firstOrFail()->amount);
                    },
                ),

            // A retraction: withdraw a bounded window of belief. No local claim —
            // the invariants police what it may and may not disturb.
            Step::make('retract a window')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $from = CarbonImmutable::parse(self::START)->addDays($ctx->randomInt(0, 400));
                    $to = $from->addDays($ctx->randomInt(1, 120));

                    $rejected = $this->attempt(fn () => $product->prices()->retract($from, $to));

                    if (! $rejected) {
                        $this->recordEdit($ctx, $from, $to);
                    }
                }),

            // End the open-ended fact: nothing may remain valid past the boundary.
            Step::make('end the timeline')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $validTo = CarbonImmutable::now()->addDays($ctx->randomInt(1, 90));

                    $ctx->remember('end valid to', $validTo);
                    $ctx->remember('end rejected', $this->attempt(
                        fn () => $product->prices()->endAt($validTo, $this->drawCompact($ctx)),
                    ));

                    if ($ctx->get('end rejected') === false) {
                        $this->recordEdit($ctx, $validTo, null);
                    }
                })
                ->assertWhen(
                    fn (Context $ctx): bool => $ctx->get('end rejected') === false,
                    function (Context $ctx): void {
                        $product = $ctx->instance('product', Product::class);
                        $after = $ctx->instance('end valid to', CarbonImmutable::class)->addDay();

                        Assert::assertCount(
                            0,
                            $product->prices()->validAt($after)->currentKnowledge()->excludeRetractions()->get(),
                            'endAt must leave nothing valid after its boundary.',
                        );
                    },
                ),

            // Replace the whole timeline with a fresh set of contiguous segments.
            // The old belief survives in record time; the invariants check that.
            Step::make('supersede the timeline')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $segments = $ctx->randomInt(1, 3);

                    $rows = [];
                    $cursor = CarbonImmutable::parse(self::START);
                    for ($i = 0; $i < $segments; $i++) {
                        $last = $i === $segments - 1;
                        $to = $last ? null : $cursor->addDays($ctx->randomInt(30, 200));
                        $rows[] = [
                            'amount' => $ctx->randomInt(1, 9) * 100,
                            'valid_from' => $cursor->format('Y-m-d H:i:s.u'),
                            'valid_to' => $to?->format('Y-m-d H:i:s.u'),
                        ];
                        if ($to !== null) {
                            $cursor = $to;
                        }
                    }

                    $rejected = $this->attempt(
                        fn () => $product->prices()->supersedeTimeline($rows, $this->drawCompact($ctx)),
                    );

                    if (! $rejected) {
                        // A supersession can move the value at any instant.
                        $this->recordEdit($ctx, null, null);
                    }
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
                        Assert::assertNotNull(
                            $previous->valid_to,
                            'An open-ended span cannot be followed by a later span.',
                        );
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

                Assert::assertGreaterThanOrEqual(
                    $high,
                    $count,
                    'Row count dropped — history was mutated in place instead of appended to.',
                );
                $ctx->remember('row high water', $count);
            }),

            Invariant::make('the belief known at t0 is immutable', function (Context $ctx): void {
                if (! $ctx->has('known at t0')) {
                    return; // before the timeline is seeded there is nothing to freeze
                }

                $current = $this->snapshot(
                    $ctx->instance('product', Product::class)->prices()
                        ->knownAt($ctx->instance('t0', CarbonImmutable::class))
                        ->excludeRetractions()
                        ->get(),
                );

                Assert::assertSame(
                    $ctx->get('known at t0'),
                    $current,
                    'A later write rewrote what was believed as of t0.',
                );
            }),

            // The value read *now* at a fixed historical instant is a pure
            // function of the edits made to that instant's window. A step that
            // does not edit a covering window — a clock advance, a compaction, an
            // edit elsewhere — must leave every probe exactly where it was.
            Invariant::make('historical values stay put unless a step edits them', function (Context $ctx): void {
                if (! $ctx->has('product')) {
                    return;
                }

                $product = $ctx->instance('product', Product::class);
                $edits = $ctx->list('edited windows');
                $ctx->forget('edited windows');

                $baseline = $ctx->get('stability baseline');
                $next = [];

                foreach ($this->probeInstants() as $label => $at) {
                    $current = $product->prices()
                        ->validAt($at)
                        ->currentKnowledge()
                        ->excludeRetractions()
                        ->first()?->amount;

                    $next[$label] = $current;

                    if (is_array($baseline) && array_key_exists($label, $baseline) && ! $this->coveredByAnyEdit($at, $edits)) {
                        Assert::assertSame(
                            $baseline[$label],
                            $current,
                            "The value at START+{$label}d moved without a step editing a window covering it.",
                        );
                    }
                }

                $ctx->remember('stability baseline', $next);
            }),
        ];
    }

    /**
     * Fixed probe instants across the timeline, keyed by their day-offset from
     * START so violation messages read naturally.
     *
     * @return array<int, CarbonImmutable>
     */
    private function probeInstants(): array
    {
        $anchor = CarbonImmutable::parse(self::START);
        $probes = [];

        foreach ([10, 90, 300, 700] as $days) {
            $probes[$days] = $anchor->addDays($days);
        }

        return $probes;
    }

    /**
     * Record that the just-committed write may have changed the value across
     * [$from, $to). A null bound is unbounded on that side (null/null = the whole
     * timeline, as a supersession does).
     */
    private function recordEdit(Context $ctx, ?CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        $ctx->push('edited windows', [
            'from' => $from?->format('Y-m-d H:i:s.u'),
            'to' => $to?->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * @param  array<int, mixed>  $edits
     */
    private function coveredByAnyEdit(CarbonImmutable $at, array $edits): bool
    {
        foreach ($edits as $edit) {
            if (! is_array($edit)) {
                continue;
            }

            $from = is_string($edit['from'] ?? null) ? CarbonImmutable::parse($edit['from']) : null;
            $to = is_string($edit['to'] ?? null) ? CarbonImmutable::parse($edit['to']) : null;

            $afterStart = $from === null || $at->greaterThanOrEqualTo($from);
            $beforeEnd = $to === null || $at->lessThan($to);

            if ($afterStart && $beforeEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * Draw a compaction preference: package default, force-on, or force-off.
     */
    private function drawCompact(Context $ctx): ?bool
    {
        return [null, true, false][$ctx->randomInt(0, 2)];
    }
}
