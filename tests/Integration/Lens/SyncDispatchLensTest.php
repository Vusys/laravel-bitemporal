<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Lens;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Jobs\CapturesLensStateJob;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end guard for issue #72: a job dispatched synchronously inside an
 * asOf() callback fires JobProcessing while the caller's outer frame is still
 * open. The listener must snapshot-and-restore that frame rather than blindly
 * reset the stack, so the rest of the callback keeps reading through the pinned
 * instant instead of silently falling back to "now".
 */
final class SyncDispatchLensTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CapturesLensStateJob::$depthDuringHandle = null;
    }

    protected function tearDown(): void
    {
        CapturesLensStateJob::$depthDuringHandle = null;
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function seedCorrectedTimeline(): Product
    {
        $product = $this->makeProduct();

        // Original belief, superseded by a correction recorded 2026-03-01.
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null,
            'recorded_from' => '2026-01-01', 'recorded_to' => '2026-03-01',
        ]);
        $this->insertPrice($product, [
            'amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01',
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);
        $this->insertPrice($product, [
            'amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null,
            'recorded_from' => '2026-03-01', 'recorded_to' => null,
        ]);

        return $product;
    }

    public function test_sync_dispatched_job_preserves_the_outer_asof_frame(): void
    {
        $product = $this->seedCorrectedTimeline();

        $pinnedAfterDispatch = TemporalLens::asOf('2026-09-01', '2026-02-01', function () use ($product): ?int {
            // The sync queue fires JobProcessing/JobProcessed around handle().
            dispatch_sync(new CapturesLensStateJob);

            // With the frame preserved the read still resolves to the belief held
            // in February (1000), not the current-knowledge 1200.
            return ProductPrice::query()->whereTemporalEntity($product)->sole()->amount;
        });

        // Inside the job the outer frame was still open (depth 1); a blind reset
        // would have recorded 0 here.
        $this->assertSame(1, CapturesLensStateJob::$depthDuringHandle);

        // And the frame survived the whole dispatch so the trailing read is pinned.
        $this->assertSame(1000, $pinnedAfterDispatch);

        // The frame is popped cleanly once the callback returns.
        $this->assertSame(0, TemporalLens::depth());
    }
}
