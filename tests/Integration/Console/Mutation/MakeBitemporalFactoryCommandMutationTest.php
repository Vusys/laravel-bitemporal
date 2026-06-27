<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console\Mutation;

use Illuminate\Testing\PendingCommand;
use Vusys\Bitemporal\Tests\TestCase;

final class MakeBitemporalFactoryCommandMutationTest extends TestCase
{
    /** @var array<int, string> */
    private array $generated = [];

    protected function tearDown(): void
    {
        foreach ($this->generated as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }

            $dir = \dirname($path);
            if (is_dir($dir) && basename($dir) !== 'factories') {
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function generate(array $arguments, string $relativePath): string
    {
        $command = $this->artisan('make:bitemporal-factory', $arguments);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->run());

        $app = $this->app;
        $this->assertNotNull($app);
        $path = $app->databasePath('factories/'.$relativePath);
        $this->generated[] = $path;

        return $path;
    }

    public function test_model_option_overrides_the_name_derived_model(): void
    {
        // Name implies "WidgetPrice" but --model forces a different model.
        $path = $this->generate(
            ['name' => 'WidgetPriceFactory', '--model' => 'CustomModel'],
            'WidgetPriceFactory.php',
        );

        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('class WidgetPriceFactory extends BitemporalFactory', $contents);
        // The option wins (kills NotIdentical / Ternary / And-negation mutants).
        $this->assertStringContainsString('protected $model = CustomModel::class;', $contents);
        $this->assertStringContainsString('use App\\Models\\CustomModel;', $contents);
        $this->assertStringNotContainsString('WidgetPrice::class', $contents);
    }

    public function test_model_is_inferred_from_the_name_when_option_absent(): void
    {
        $path = $this->generate(['name' => 'GadgetFactory'], 'GadgetFactory.php');

        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        // No --model: model name comes from the factory name (kills the LogicalAnd
        // mutant that would qualify the empty option string instead).
        $this->assertStringContainsString('protected $model = Gadget::class;', $contents);
        $this->assertStringContainsString('use App\\Models\\Gadget;', $contents);
    }

    public function test_nested_factory_path_uses_forward_slashes(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);

        // Defensively clear any stale literal-backslash artifact (e.g. left by a
        // prior mutated run) and register it for cleanup, so this assertion only
        // reflects what THIS run's generator produced.
        $literal = $app->databasePath('factories/Nested\\GizmoFactory.php');
        if (file_exists($literal)) {
            @unlink($literal);
        }
        $this->generated[] = $literal;

        $path = $this->generate(['name' => 'Nested\\GizmoFactory'], 'Nested/GizmoFactory.php');

        // The backslash in the name must be turned into a directory separator
        // (kills the UnwrapStrReplace on getPath()).
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($literal);
    }
}
