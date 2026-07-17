<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Characterization probes written to *learn* how the package behaves at corners
 * the journeys don't yet cover. Each test documents a hypothesis in its name and
 * asserts the behaviour actually observed, so a future change that alters it is
 * surfaced deliberately.
 */
#[Group('journey')]
final class BehaviourProbesTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function priceCount(int $productId): int
    {
        return ProductPrice::query()->where('product_id', $productId)->count();
    }

    /**
     * Idempotency is keyed on (key, params), not on wall-clock time: replaying
     * the same keyed write a day later must still be a no-op and hand back the
     * ORIGINAL recorded_at, not a fresh one.
     */
    public function test_idempotent_replay_survives_a_clock_advance(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $product = $this->makeProduct();

        $first = $product->prices()->correct(['amount' => 1500], '2026-01-01', null, idempotencyKey: 'job-x');
        $countAfterFirst = $this->priceCount($product->id);

        CarbonImmutable::setTestNow('2026-01-02 00:00:00');
        $second = $product->prices()->correct(['amount' => 1500], '2026-01-01', null, idempotencyKey: 'job-x');

        $this->assertSame($countAfterFirst, $this->priceCount($product->id), 'a replay a day later must write nothing');
        $this->assertSame(
            $first->recordedAt->format('Y-m-d H:i:s.u'),
            $second->recordedAt->format('Y-m-d H:i:s.u'),
            'a replay must return the original recorded_at, not the new now',
        );
    }

    /**
     * A retraction opens a gap in current knowledge; correcting the same window
     * afterwards must make a value queryable there again — retraction is not a
     * permanent tombstone against future writes.
     */
    public function test_retract_then_correct_restores_a_queryable_value(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $product->prices()->retract('2026-02-01', '2026-04-01');

        $inGap = $product->prices()->validAt('2026-03-01')->currentKnowledge()->excludeRetractions()->get();
        $this->assertCount(0, $inGap, 'the retracted window is empty in current knowledge');

        $product->prices()->correct(['amount' => 1000], '2026-02-01', '2026-04-01');

        $restored = $product->prices()->validAt('2026-03-01')->currentKnowledge()->excludeRetractions()->sole();
        $this->assertSame(1000, $restored->amount, 'correcting the retracted window makes it queryable again');
    }

    /**
     * Record time remembers what we used to believe: after retracting a window,
     * a `knownAt()` read positioned *before* the retraction was recorded must
     * still see the original value across that window.
     */
    public function test_known_at_before_a_retraction_still_sees_the_old_belief(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $beforeRetraction = CarbonImmutable::now();

        CarbonImmutable::setTestNow('2026-06-02 00:00:00');
        $product->prices()->retract('2026-02-01', '2026-04-01');

        $asWeBelievedBefore = $product->prices()
            ->knownAt($beforeRetraction)
            ->validAt('2026-03-01')
            ->excludeRetractions()
            ->sole();

        $this->assertSame(1000, $asWeBelievedBefore->amount, 'the pre-retraction belief is preserved in record time');
    }
}
