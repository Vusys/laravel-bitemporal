<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use Vusys\Bitemporal\Diff\TemporalDiffPair;
use Vusys\Bitemporal\Diff\TemporalRetraction;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Journey\Concerns\ExploresTimelines;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * A journey that pins down `diffTimelines()`: the diff between what was believed
 * at an early record time (kA, just after seeding) and what is believed now must
 * exactly reconcile the two beliefs.
 *
 * Forward changes, retroactive corrections and retractions accumulate belief in
 * record time. After every step the diff is taken and, independently, the belief
 * at both kA and now is read straight from the store. The reconciliation law
 * must hold on every trail:
 *  - the belief now  == unchanged ∪ added   ∪ changed.to   ∪ retracted.to;
 *  - the belief at kA == unchanged ∪ removed ∪ changed.from ∪ retracted.from.
 *
 * A diff that drops, duplicates or misclassifies a believed row breaks it.
 */
final class DiffRoundTripJourney extends Journey
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

                    // Freeze the early record time, then step off it so every later
                    // write is believed strictly after kA.
                    $ctx->remember('kA', CarbonImmutable::now());
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

            Step::make('retract a window')
                ->after('seed opening price')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);
                    $from = CarbonImmutable::parse(self::START)->addDays($ctx->randomInt(0, 400));
                    $to = $from->addDays($ctx->randomInt(1, 120));

                    $this->attempt(fn () => $product->prices()->retract($from, $to));
                }),
        ];
    }

    #[\Override]
    public function invariants(): array
    {
        return [
            Invariant::make('physical history only ever grows', function (Context $ctx): void {
                $count = ProductPrice::query()->count();
                $high = $ctx->has('row high water') ? $ctx->integer('row high water') : 0;

                Assert::assertGreaterThanOrEqual($high, $count, 'Row count dropped — history was mutated in place.');
                $ctx->remember('row high water', $count);
            }),

            // The diff between kA and now must reconcile the two beliefs exactly.
            Invariant::make('diffTimelines reconciles the belief at kA with the belief now', function (Context $ctx): void {
                if (! $ctx->has('kA')) {
                    return;
                }

                $product = $ctx->instance('product', Product::class);
                $kA = $ctx->instance('kA', CarbonImmutable::class);
                $now = CarbonImmutable::now();

                $diff = $product->prices()->diffTimelines(fromKnownAt: $kA, toKnownAt: $now);

                $unchanged = $diff->unchanged->map(fn (Model $row): string => $this->canonical($row))->all();

                $reconstructedNow = [
                    ...$unchanged,
                    ...$diff->added->map(fn (Model $row): string => $this->canonical($row))->all(),
                    ...$diff->changed->map(fn (TemporalDiffPair $pair): string => $this->canonical($pair->to))->all(),
                    // A withdrawn window is believed now as its anti-row.
                    ...$diff->retracted->map(fn (TemporalRetraction $r): string => $this->canonical($r->to))->all(),
                ];

                $reconstructedThen = [
                    ...$unchanged,
                    ...$diff->removed->map(fn (Model $row): string => $this->canonical($row))->all(),
                    ...$diff->changed->map(fn (TemporalDiffPair $pair): string => $this->canonical($pair->from))->all(),
                    // The pre-retraction value row was believed at kA (absent when
                    // the window was both created and retracted after kA).
                    ...$diff->retracted
                        ->map(fn (TemporalRetraction $r): ?string => $r->from !== null ? $this->canonical($r->from) : null)
                        ->filter(fn (?string $canonical): bool => $canonical !== null)
                        ->all(),
                ];

                Assert::assertSame(
                    $this->beliefAt($product, $now),
                    $this->sorted($reconstructedNow),
                    'The diff does not reconstruct the belief held now.',
                );

                Assert::assertSame(
                    $this->beliefAt($product, $kA),
                    $this->sorted($reconstructedThen),
                    'The diff does not reconstruct the belief held at kA.',
                );
            }),
        ];
    }

    /**
     * The believed timeline at a record instant, as a sorted list of canonical
     * row signatures read straight from the store — the independent yardstick the
     * diff must reproduce.
     *
     * @return list<string>
     */
    private function beliefAt(Product $product, CarbonImmutable $known): array
    {
        return $this->sorted(
            $product->prices()->knownAt($known)->get()
                ->map(fn (ProductPrice $row): string => $this->canonical($row))
                ->all(),
        );
    }

    /**
     * A stable signature of a believed row: its valid window, amount and
     * retraction flag — exactly the attributes the diff engine compares. Record
     * time is deliberately excluded; it differs between kA and now by design.
     */
    private function canonical(Model $row): string
    {
        return implode('|', [
            (string) $this->instant($row->getAttribute('valid_from')),
            (string) $this->instant($row->getAttribute('valid_to')),
            (string) ($this->scalar($row->getAttribute('amount')) ?? 'null'),
            $row->getAttribute('is_retraction') ? 'R' : '-',
        ]);
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
