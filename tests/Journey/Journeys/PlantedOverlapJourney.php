<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey\Journeys;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * A deliberately-broken journey used as a teeth regression guard: it plants a
 * bitemporal overlap directly in the table — bypassing the writer's guards, the
 * way a real writer bug would — and carries the same no-overlap invariant the
 * real journeys rely on.
 *
 * Running it MUST fail. PlantedBugTest asserts exactly that; if a future change
 * blunts the no-overlap invariant, this journey stops failing and the guard
 * turns red, flagging that the harness has lost its teeth.
 */
final class PlantedOverlapJourney extends Journey
{
    private const string START = '2026-01-01 00:00:00';

    public function steps(): array
    {
        return [
            Step::make('create product')
                ->act(function (Context $ctx): void {
                    $ctx->travelTo(self::START);
                    $ctx->remember('product', Product::query()->create(['name' => 'Widget']));
                }),

            Step::make('seed opening price')
                ->after('create product')
                ->act(fn (Context $ctx) => $ctx->instance('product', Product::class)->prices()
                    ->changeEffectiveFrom(['amount' => 500], self::START)),

            // The planted bug: a second live current-knowledge row whose valid
            // span overlaps the open-ended seed. A correct writer can never
            // produce this; inserting it raw simulates one that regressed.
            Step::make('plant an overlap')
                ->after('seed opening price')
                ->act(function (Context $ctx): void {
                    $product = $ctx->instance('product', Product::class);

                    ProductPrice::query()->create([
                        'product_id' => $product->id,
                        'amount' => 900,
                        'valid_from' => CarbonImmutable::parse(self::START)->addDays(10),
                        'valid_to' => CarbonImmutable::parse(self::START)->addDays(50),
                        'recorded_from' => CarbonImmutable::now(),
                        'recorded_to' => null,
                        'is_retraction' => false,
                    ]);
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
        ];
    }
}
