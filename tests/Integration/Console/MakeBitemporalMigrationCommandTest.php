<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Vusys\Bitemporal\Tests\TestCase;

final class MakeBitemporalMigrationCommandTest extends TestCase
{
    private string $generated = '';

    protected function tearDown(): void
    {
        if ($this->generated !== '' && file_exists($this->generated)) {
            @unlink($this->generated);
        }

        parent::tearDown();
    }

    public function test_it_generates_a_temporal_migration(): void
    {
        $exit = Artisan::call('make:bitemporal-migration', ['name' => 'create_widget_prices_table']);

        $this->assertSame(0, $exit);

        $dir = $this->app?->databasePath('migrations') ?? '';
        $matches = File::glob($dir.'/*_create_widget_prices_table.php');

        $this->assertNotEmpty($matches);
        $this->generated = $matches[0];

        $contents = (string) file_get_contents($this->generated);
        $this->assertStringContainsString('$table->bitemporalPeriods();', $contents);
        $this->assertStringContainsString('$table->preventBitemporalOverlaps(', $contents);
        $this->assertStringContainsString('Schema::create(', $contents);
    }
}
