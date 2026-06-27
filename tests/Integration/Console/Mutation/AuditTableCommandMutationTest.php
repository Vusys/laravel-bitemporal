<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console\Mutation;

use Illuminate\Support\Facades\Artisan;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AuditTableCommandMutationTest extends IntegrationTestCase
{
    public function test_renders_headers_cells_and_scoped_count(): void
    {
        $product = $this->makeProduct();
        $other = $this->makeProduct('Other');

        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-02-02',
        ]);
        // A row on a different entity must NOT be counted (where-scope guard).
        $this->insertPrice($other, ['amount' => 50, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $exit = Artisan::call('bitemporal:audit-table', [
            '--model' => ProductPrice::class,
            '--entity-id' => $product->getKey(),
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);

        // Every header column is rendered (kills headers ArrayItemRemoval + table() removal).
        foreach (['valid_from', 'valid_to', 'recorded_from', 'recorded_to', 'is_retraction'] as $header) {
            $this->assertStringContainsString($header, $output);
        }

        // Cell values render in their own columns (kills Foreach_, null-check flip,
        // outer Ternary, and the ArrayOneItem slice that would drop later columns).
        $this->assertStringContainsString('2026-01-01', $output); // valid_from
        $this->assertStringContainsString('2026-02-02', $output); // recorded_from

        // Scoped count line is exactly one row for this entity.
        $this->assertStringContainsString('#'.$product->getKey().': 1 row(s).', $output);
    }

    public function test_full_flag_includes_superseded_rows(): void
    {
        $product = $this->makeProduct();

        // Current-known row.
        $this->insertPrice($product, [
            'amount' => 1200,
            'valid_from' => '2026-06-01',
            'valid_to' => null,
            'recorded_from' => '2026-03-01',
            'recorded_to' => null,
        ]);
        // Superseded row (recorded_to is set), only visible with --full.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-01-01',
            'recorded_to' => '2026-03-01',
        ]);

        Artisan::call('bitemporal:audit-table', [
            '--model' => ProductPrice::class,
            '--entity-id' => $product->getKey(),
        ]);
        $currentOnly = Artisan::output();
        $this->assertStringContainsString(': 1 row(s).', $currentOnly);

        Artisan::call('bitemporal:audit-table', [
            '--model' => ProductPrice::class,
            '--entity-id' => $product->getKey(),
            '--full' => true,
        ]);
        $full = Artisan::output();
        $this->assertStringContainsString(': 2 row(s).', $full);
    }

    public function test_morph_model_scopes_on_the_morph_foreign_key(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);
        $bystander = Customer::query()->create(['name' => 'Other']);

        // Two current addresses for the target owner, one for a bystander.
        $this->seedAddress($customer, 'home');
        $this->seedAddress($customer, 'work');
        $this->seedAddress($bystander, 'elsewhere');

        $exit = Artisan::call('bitemporal:audit-table', [
            '--model' => Address::class,
            '--entity-id' => $customer->getKey(),
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        // Scoped via owner_id (not the row id) -> exactly the two owner rows.
        $this->assertStringContainsString(': 2 row(s).', $output);
    }

    public function test_invalid_model_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:audit-table', [
            '--model' => 'Not\\A\\Real\\Class',
            '--entity-id' => 1,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Provide a valid --model FQCN.', Artisan::output());
    }

    public function test_existing_non_model_class_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:audit-table', [
            '--model' => Spell::class,
            '--entity-id' => 1,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Provide a valid --model FQCN.', Artisan::output());
    }

    public function test_entity_id_is_required(): void
    {
        $exit = Artisan::call('bitemporal:audit-table', [
            '--model' => ProductPrice::class,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--entity-id is required.', Artisan::output());
    }

    public function test_non_temporal_model_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:audit-table', [
            '--model' => Product::class,
            '--entity-id' => 1,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a temporal model.', Artisan::output());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedAddress(Customer $owner, string $label): void
    {
        Address::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'label' => $label,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-01-01',
            'recorded_to' => null,
            'is_retraction' => false,
        ]);
    }
}
