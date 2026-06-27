<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Database\Mutation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins the exact columns, precision, nullability, defaults, index names and
 * foreign-key behaviour emitted by the temporal Blueprint macros by building a
 * real Blueprint and inspecting its queued columns/commands directly (no DB
 * round-trip, so micro-second precision and index names survive intact).
 */
final class TemporalBlueprintMacrosMutationTest extends IntegrationTestCase
{
    private function blueprint(string $table = 'macro_prices'): Blueprint
    {
        return new Blueprint(DB::connection(), $table);
    }

    private function hasColumn(Blueprint $b, string $name): bool
    {
        foreach ($b->getColumns() as $col) {
            if ($col->get('name') === $name) {
                return true;
            }
        }

        return false;
    }

    private function column(Blueprint $b, string $name): ColumnDefinition
    {
        foreach ($b->getColumns() as $col) {
            if ($col->get('name') === $name) {
                return $col;
            }
        }

        $this->fail("column {$name} not emitted");
    }

    /**
     * @return array<int, Fluent<string, mixed>>
     */
    private function commandsNamed(Blueprint $b, string $name): array
    {
        return array_values(array_filter(
            $b->getCommands(),
            static fn (Fluent $cmd): bool => $cmd->get('name') === $name,
        ));
    }

    private function onlyIndex(Blueprint $b): Fluent
    {
        $indexes = $this->commandsNamed($b, 'index');
        $this->assertCount(1, $indexes, 'expected exactly one index command');

        return $indexes[0];
    }

    private function assertDateTime(ColumnDefinition $col, bool $nullable): void
    {
        $this->assertSame('dateTime', $col->get('type'));
        $this->assertSame(6, $col->get('precision'));
        $this->assertSame($nullable, $col->get('nullable'));
    }

    // --- column shape: precision, nullability, default ------------------------

    public function test_valid_period_emits_precise_columns_with_defaults(): void
    {
        $b = $this->blueprint();
        $b->validPeriod();

        // valid_from: datetime(6), NOT NULL by default.
        $this->assertDateTime($this->column($b, 'valid_from'), nullable: false);
        // valid_to: datetime(6), always nullable.
        $this->assertDateTime($this->column($b, 'valid_to'), nullable: true);

        $isRetraction = $this->column($b, 'is_retraction');
        $this->assertSame('boolean', $isRetraction->get('type'));
        $this->assertFalse($isRetraction->get('default'));
        $this->assertSame(false, $isRetraction->get('default'));

        $this->assertFalse($this->hasColumn($b, 'recorded_from'));
    }

    public function test_temporal_period_matches_valid_period(): void
    {
        $b = $this->blueprint();
        $b->temporalPeriod();

        $this->assertDateTime($this->column($b, 'valid_from'), nullable: false);
        $this->assertDateTime($this->column($b, 'valid_to'), nullable: true);
        $this->assertSame(false, $this->column($b, 'is_retraction')->get('default'));
        $this->assertFalse($this->hasColumn($b, 'recorded_from'));
    }

    public function test_recorded_period_emits_precise_columns(): void
    {
        $b = $this->blueprint();
        $b->recordedPeriod();

        $this->assertDateTime($this->column($b, 'recorded_from'), nullable: false);
        $this->assertDateTime($this->column($b, 'recorded_to'), nullable: true);
        $this->assertFalse($this->hasColumn($b, 'valid_from'));
        $this->assertFalse($this->hasColumn($b, 'is_retraction'));
    }

    public function test_bitemporal_periods_emits_all_four_period_columns(): void
    {
        $b = $this->blueprint();
        $b->bitemporalPeriods();

        $this->assertDateTime($this->column($b, 'valid_from'), nullable: false);
        $this->assertDateTime($this->column($b, 'valid_to'), nullable: true);
        $this->assertDateTime($this->column($b, 'recorded_from'), nullable: false);
        $this->assertDateTime($this->column($b, 'recorded_to'), nullable: true);
        $this->assertSame(false, $this->column($b, 'is_retraction')->get('default'));
    }

    // --- nullable flag plumbed through ---------------------------------------

    public function test_nullable_argument_makes_from_columns_nullable(): void
    {
        $b = $this->blueprint();
        $b->bitemporalPeriods(nullable: true);

        $this->assertTrue($this->column($b, 'valid_from')->get('nullable'));
        $this->assertTrue($this->column($b, 'recorded_from')->get('nullable'));
        // to-columns are nullable regardless.
        $this->assertTrue($this->column($b, 'valid_to')->get('nullable'));
        $this->assertTrue($this->column($b, 'recorded_to')->get('nullable'));
    }

    // --- custom option column names (array_merge survives) -------------------

    public function test_valid_period_options_override_and_keep_defaults(): void
    {
        $b = $this->blueprint();
        $b->validPeriod(['valid_from' => 'vf']);

        // override applied (kills "$columns() only" UnwrapArrayMerge)
        $this->assertTrue($this->hasColumn($b, 'vf'));
        $this->assertFalse($this->hasColumn($b, 'valid_from'));
        // non-overridden defaults kept (kills "$options only" UnwrapArrayMerge)
        $this->assertTrue($this->hasColumn($b, 'valid_to'));
        $this->assertTrue($this->hasColumn($b, 'is_retraction'));
    }

    public function test_temporal_period_options_override_and_keep_defaults(): void
    {
        $b = $this->blueprint();
        $b->temporalPeriod(['valid_from' => 'vf']);

        $this->assertTrue($this->hasColumn($b, 'vf'));
        $this->assertTrue($this->hasColumn($b, 'valid_to'));
        $this->assertTrue($this->hasColumn($b, 'is_retraction'));
    }

    public function test_recorded_period_options_override_and_keep_defaults(): void
    {
        $b = $this->blueprint();
        $b->recordedPeriod(['recorded_from' => 'rf']);

        $this->assertTrue($this->hasColumn($b, 'rf'));
        $this->assertTrue($this->hasColumn($b, 'recorded_to'));
    }

    public function test_bitemporal_periods_options_override_and_keep_defaults(): void
    {
        $b = $this->blueprint();
        $b->bitemporalPeriods(['valid_from' => 'vf', 'recorded_from' => 'rf']);

        $this->assertTrue($this->hasColumn($b, 'vf'));
        $this->assertTrue($this->hasColumn($b, 'rf'));
        $this->assertTrue($this->hasColumn($b, 'valid_to'));
        $this->assertTrue($this->hasColumn($b, 'recorded_to'));
        $this->assertTrue($this->hasColumn($b, 'is_retraction'));
    }

    // --- foreign key / morphs -------------------------------------------------

    public function test_bitemporal_foreign_for_restricts_on_delete(): void
    {
        $b = $this->blueprint();
        $b->bitemporalForeignFor(Product::class);

        $this->assertTrue($this->hasColumn($b, 'product_id'));

        $foreign = $this->commandsNamed($b, 'foreign');
        $this->assertCount(1, $foreign);
        $this->assertSame(['product_id'], $foreign[0]->get('columns'));
        $this->assertSame('products', $foreign[0]->get('on'));
        $this->assertSame('restrict', $foreign[0]->get('onDelete'));
    }

    public function test_bitemporal_morphs_for_emits_morph_columns(): void
    {
        $b = $this->blueprint();
        $b->bitemporalMorphsFor('owner');

        $this->assertTrue($this->hasColumn($b, 'owner_type'));
        $this->assertTrue($this->hasColumn($b, 'owner_id'));
        $this->assertSame('string', $this->column($b, 'owner_type')->get('type'));
    }

    // --- overlap index columns -----------------------------------------------

    public function test_prevent_temporal_overlaps_index_columns(): void
    {
        $b = $this->blueprint();
        $b->preventTemporalOverlaps(['product_id', 'tenant_id'], ['currency']);

        $index = $this->onlyIndex($b);
        $this->assertSame(
            ['product_id', 'tenant_id', 'currency', 'valid_from', 'valid_to'],
            $index->get('columns'),
        );
    }

    public function test_prevent_bitemporal_overlaps_index_columns(): void
    {
        $b = $this->blueprint();
        $b->preventBitemporalOverlaps(['product_id', 'tenant_id'], ['currency']);

        $index = $this->onlyIndex($b);
        $this->assertSame(
            ['product_id', 'tenant_id', 'currency', 'valid_from', 'valid_to', 'recorded_from', 'recorded_to'],
            $index->get('columns'),
        );
    }

    // --- overlap index NAMING (the <= 64 / hash branch) ----------------------

    public function test_overlap_index_uses_plain_name_when_short(): void
    {
        $b = $this->blueprint('prices');
        $b->preventTemporalOverlaps(['product_id']);

        $this->assertSame('prices_temporal_overlap', $this->onlyIndex($b)->get('index'));
    }

    public function test_overlap_index_keeps_plain_name_at_exactly_64_chars(): void
    {
        $table = str_repeat('a', 47);
        $name = "{$table}_temporal_overlap";
        $this->assertSame(64, strlen($name));

        $b = $this->blueprint($table);
        $b->preventTemporalOverlaps(['product_id']);

        // <= 64 keeps the plain name; the "< 64" / "> 64" mutants would hash it.
        $this->assertSame($name, $this->onlyIndex($b)->get('index'));
    }

    public function test_overlap_index_hashes_long_name_with_suffix_prefix(): void
    {
        $table = str_repeat('a', 60);
        $name = "{$table}_temporal_overlap";
        $this->assertGreaterThan(64, strlen($name));

        $b = $this->blueprint($table);
        $b->preventTemporalOverlaps(['product_id']);

        // Exact "{suffix}_" . md5(name) — pins concat order and both operands.
        $this->assertSame('temporal_overlap_'.md5($name), $this->onlyIndex($b)->get('index'));
    }

    public function test_bitemporal_overlap_index_hashes_long_name(): void
    {
        $table = str_repeat('b', 60);
        $name = "{$table}_bitemporal_overlap";
        $this->assertGreaterThan(64, strlen($name));

        $b = $this->blueprint($table);
        $b->preventBitemporalOverlaps(['product_id']);

        $this->assertSame('bitemporal_overlap_'.md5($name), $this->onlyIndex($b)->get('index'));
    }

    // --- columns() config resolution -----------------------------------------

    public function test_custom_configured_column_names_are_applied(): void
    {
        config(['bitemporal.columns' => [
            'valid_from' => 'cfrom',
            'valid_to' => 'cto',
            'recorded_from' => 'crf',
            'recorded_to' => 'crt',
            'is_retraction' => 'cir',
        ]]);

        $b = $this->blueprint();
        $b->bitemporalPeriods();

        foreach (['cfrom', 'cto', 'crf', 'crt', 'cir'] as $name) {
            $this->assertTrue($this->hasColumn($b, $name), "expected configured column {$name}");
        }
        // defaults must NOT leak through (kills LogicalNot/Foreach/Coalesce/Ternary).
        $this->assertFalse($this->hasColumn($b, 'valid_from'));
    }

    public function test_non_array_config_falls_back_to_full_defaults(): void
    {
        config(['bitemporal.columns' => 'not-an-array']);

        $b = $this->blueprint();
        $b->validPeriod();

        // All five defaults present — the ArrayOneItem mutant would keep only valid_from.
        $this->assertTrue($this->hasColumn($b, 'valid_from'));
        $this->assertTrue($this->hasColumn($b, 'valid_to'));
        $this->assertTrue($this->hasColumn($b, 'is_retraction'));
    }

    public function test_non_string_configured_value_falls_back_to_default(): void
    {
        config(['bitemporal.columns' => [
            'valid_from' => ['unexpected'],
        ]]);

        $b = $this->blueprint();
        $b->validPeriod();

        // is_string() guard rejects the array, default name is used.
        $this->assertTrue($this->hasColumn($b, 'valid_from'));
    }
}
