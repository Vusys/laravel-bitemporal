<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console;

use Illuminate\Testing\PendingCommand;
use Vusys\Bitemporal\Tests\TestCase;

final class MakeBitemporalModelCommandTest extends TestCase
{
    private string $generated = '';

    protected function tearDown(): void
    {
        if ($this->generated !== '' && file_exists($this->generated)) {
            @unlink($this->generated);
        }

        parent::tearDown();
    }

    public function test_it_generates_a_temporal_model(): void
    {
        $command = $this->artisan('make:bitemporal-model', ['name' => 'WidgetPrice', '--entity' => 'Widget']);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->run());

        $app = $this->app;
        $this->assertNotNull($app);
        $this->generated = $app->path('Models/WidgetPrice.php');

        $this->assertFileExists($this->generated);

        $contents = (string) file_get_contents($this->generated);
        $this->assertStringContainsString('use Vusys\Bitemporal\Bitemporal;', $contents);
        $this->assertStringContainsString('class WidgetPrice extends Model', $contents);
        $this->assertStringContainsString('protected string $temporalEntity = Widget::class;', $contents);
        $this->assertStringContainsString('widget_id', $contents);
    }
}
