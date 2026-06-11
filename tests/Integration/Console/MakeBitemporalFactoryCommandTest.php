<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console;

use Illuminate\Testing\PendingCommand;
use Vusys\Bitemporal\Tests\TestCase;

final class MakeBitemporalFactoryCommandTest extends TestCase
{
    private string $generated = '';

    protected function tearDown(): void
    {
        if ($this->generated !== '' && file_exists($this->generated)) {
            @unlink($this->generated);
        }

        parent::tearDown();
    }

    public function test_it_generates_a_temporal_factory(): void
    {
        $command = $this->artisan('make:bitemporal-factory', ['name' => 'WidgetPriceFactory', '--model' => 'WidgetPrice']);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->run());

        $app = $this->app;
        $this->assertNotNull($app);
        $this->generated = $app->databasePath('factories/WidgetPriceFactory.php');

        $this->assertFileExists($this->generated);

        $contents = (string) file_get_contents($this->generated);
        $this->assertStringContainsString('use Vusys\Bitemporal\Factories\BitemporalFactory;', $contents);
        $this->assertStringContainsString('class WidgetPriceFactory extends BitemporalFactory', $contents);
        $this->assertStringContainsString('protected $model = WidgetPrice::class;', $contents);
    }
}
