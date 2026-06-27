<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console\Mutation;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPriceWithDimensions;
use Vusys\Bitemporal\Tests\Fixtures\Models\UserRoleAssignment;
use Vusys\Bitemporal\Tests\TestCase;

require_once __DIR__.'/Fixtures/MigrationProbe.php';

final class MakeBitemporalMigrationMutationTest extends TestCase
{
    /** @var array<int, string> */
    private array $generated = [];

    protected function tearDown(): void
    {
        foreach ($this->generated as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{exit: int, output: string, path: string, contents: string}
     */
    private function generate(string $name, array $options = []): array
    {
        $exit = Artisan::call('make:bitemporal-migration', ['name' => $name] + $options);
        $output = Artisan::output();

        $dir = $this->app?->databasePath('migrations') ?? '';
        $matches = File::glob($dir.'/*_'.$name.'*');
        $path = $matches[0] ?? '';

        if ($path !== '') {
            $this->generated[] = $path;
        }

        return [
            'exit' => $exit,
            'output' => $output,
            'path' => $path,
            'contents' => $path !== '' ? (string) file_get_contents($path) : '',
        ];
    }

    public function test_default_shape_when_no_model_is_given(): void
    {
        $result = $this->generate('create_default_table');

        $this->assertSame(0, $result['exit']);

        $contents = $result['contents'];
        // Placeholders must all be substituted (kills UnwrapStrReplace + each str_replace ArrayItemRemoval).
        $this->assertStringNotContainsString('{{', $contents);
        $this->assertStringContainsString("Schema::create('temporal_table', function", $contents);
        $this->assertStringContainsString('bitemporalForeignFor(Model::class)', $contents);
        $this->assertStringContainsString("entityColumns: ['entity_id'],", $contents);
        $this->assertStringContainsString('dimensions: [],', $contents);
        $this->assertStringContainsString('$table->bitemporalPeriods();', $contents);

        // Path keeps the timestamp prefix and the .php suffix.
        $base = basename($result['path']);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_default_table\.php$/', $base);
        $this->assertStringEndsWith('.php', $result['path']);

        // The success line is "Created migration: <path>" in that exact order.
        $this->assertStringContainsString('Created migration: '.$result['path'], $result['output']);
    }

    public function test_temporal_only_flag_swaps_the_period_helper(): void
    {
        $result = $this->generate('create_temporal_only_table', ['--temporal-only' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('$table->temporalPeriod();', $result['contents']);
        $this->assertStringNotContainsString('$table->bitemporalPeriods();', $result['contents']);
    }

    public function test_belongs_to_model_shape_is_inferred(): void
    {
        $result = $this->generate('create_bt_table', ['--model' => ProductPrice::class]);

        $this->assertSame(0, $result['exit']);
        $contents = $result['contents'];
        $this->assertStringContainsString("Schema::create('product_price_versions', function", $contents);
        $this->assertStringContainsString('bitemporalForeignFor(Product::class)', $contents);
        $this->assertStringContainsString("entityColumns: ['product_id'],", $contents);
        $this->assertStringContainsString('dimensions: [],', $contents);
    }

    public function test_dimensions_are_quoted_into_the_constraint(): void
    {
        $result = $this->generate('create_dim_table', ['--model' => ProductPriceWithDimensions::class]);

        $this->assertSame(0, $result['exit']);
        $contents = $result['contents'];
        $this->assertStringContainsString("Schema::create('dimensioned_prices', function", $contents);
        $this->assertStringContainsString("entityColumns: ['product_id'],", $contents);
        $this->assertStringContainsString("dimensions: ['currency'],", $contents);
    }

    public function test_morph_model_shape_uses_both_morph_columns(): void
    {
        $result = $this->generate('create_morph_table', ['--model' => Address::class]);

        $this->assertSame(0, $result['exit']);
        $contents = $result['contents'];
        $this->assertStringContainsString("Schema::create('addresses', function", $contents);
        $this->assertStringContainsString('bitemporalForeignFor(Model::class)', $contents);
        $this->assertStringContainsString("entityColumns: ['owner_type', 'owner_id'],", $contents);
    }

    public function test_model_without_temporal_entity_keeps_default_entity_column(): void
    {
        $result = $this->generate('create_pivot_table', ['--model' => UserRoleAssignment::class]);

        $this->assertSame(0, $result['exit']);
        $contents = $result['contents'];
        $this->assertStringContainsString("Schema::create('user_role_assignments', function", $contents);
        $this->assertStringContainsString('bitemporalForeignFor(Model::class)', $contents);
        $this->assertStringContainsString("entityColumns: ['entity_id'],", $contents);
        $this->assertStringContainsString('dimensions: [],', $contents);
    }

    public function test_existing_non_model_class_falls_back_to_basename_shape(): void
    {
        $result = $this->generate('create_spell_table', ['--model' => Spell::class]);

        $this->assertSame(0, $result['exit']);
        $contents = $result['contents'];
        // Spell exists but is not an Eloquent model -> snake-plural basename table.
        $this->assertStringContainsString("Schema::create('spells', function", $contents);
        $this->assertStringContainsString('bitemporalForeignFor(Spell::class)', $contents);
        $this->assertStringContainsString("entityColumns: ['entity_id'],", $contents);
    }

    public function test_bare_model_name_is_resolved_under_app_models_namespace(): void
    {
        $result = $this->generate('create_probe_table', ['--model' => 'MigrationProbe']);

        $this->assertSame(0, $result['exit']);
        $contents = $result['contents'];
        // "MigrationProbe" must resolve to App\Models\MigrationProbe.
        $this->assertStringContainsString("Schema::create('app_probe_prices', function", $contents);
        $this->assertStringContainsString('bitemporalForeignFor(Product::class)', $contents);
        $this->assertStringContainsString("entityColumns: ['product_id'],", $contents);
    }

    public function test_empty_name_is_rejected(): void
    {
        $exit = Artisan::call('make:bitemporal-migration', ['name' => '']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('A migration name is required.', Artisan::output());
    }
}
