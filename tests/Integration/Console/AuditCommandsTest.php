<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console;

use Illuminate\Support\Facades\Artisan;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AuditCommandsTest extends IntegrationTestCase
{
    public function test_audit_overlaps_passes_on_a_clean_timeline(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => ProductPrice::class]);

        $this->assertSame(0, $exit);
    }

    public function test_audit_overlaps_fails_when_rows_overlap(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-03-01', 'valid_to' => null]);

        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => ProductPrice::class]);

        $this->assertSame(1, $exit);
    }

    public function test_audit_table_renders_rows(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $exit = Artisan::call('bitemporal:audit-table', [
            '--model' => ProductPrice::class,
            '--entity-id' => $product->getKey(),
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1 row(s)', Artisan::output());
    }

    public function test_diff_timelines_reports_a_change(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => '2026-03-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-03-01', 'recorded_to' => null]);

        $exit = Artisan::call('bitemporal:diff-timelines', [
            '--model' => ProductPrice::class,
            '--entity-id' => $product->getKey(),
            '--from-known-at' => '2026-02-20',
            '--to-known-at' => '2026-03-10',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('changed: 1', Artisan::output());
    }
}
