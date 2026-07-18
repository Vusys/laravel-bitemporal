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

final class DiffTimelinesCommandMutationTest extends IntegrationTestCase
{
    public function test_reports_counts_and_changed_attributes_for_the_scoped_entity(): void
    {
        $product = $this->makeProduct();
        $other = $this->makeProduct('Other');

        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => '2026-03-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-03-01', 'recorded_to' => null]);

        // A stable row on another entity: if scoping is dropped it leaks into counts.
        $this->insertPrice($other, ['amount' => 999, 'valid_from' => '2026-01-01', 'valid_to' => null, 'recorded_from' => '2026-02-01', 'recorded_to' => null]);

        $exit = Artisan::call('bitemporal:diff-timelines', [
            '--model' => ProductPrice::class,
            '--entity-id' => $product->getKey(),
            '--from-known-at' => '2026-02-20',
            '--to-known-at' => '2026-03-10',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        // Exact summary line; the foreign-entity row must not appear in any bucket.
        $this->assertStringContainsString('added: 0, removed: 0, changed: 1, retracted: 0, unchanged: 0', $output);
        // The changed attribute list is printed exactly.
        $this->assertStringContainsString('  changed [amount]', $output);
    }

    public function test_morph_diff_scopes_on_the_morph_foreign_key(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);

        $this->seedAddress($customer, 'home', '2026-02-01', '2026-03-01');
        $this->seedAddress($customer, 'office', '2026-03-01', null);

        $exit = Artisan::call('bitemporal:diff-timelines', [
            '--model' => Address::class,
            '--entity-id' => $customer->getKey(),
            '--from-known-at' => '2026-02-20',
            '--to-known-at' => '2026-03-10',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        // Scoped via owner_id -> the label change is detected.
        $this->assertStringContainsString('changed: 1', $output);
        $this->assertStringContainsString('  changed [label]', $output);
    }

    public function test_invalid_model_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:diff-timelines', [
            '--model' => 'Not\\A\\Real\\Class',
            '--entity-id' => 1,
            '--from-known-at' => '2026-01-01',
            '--to-known-at' => '2026-02-01',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Provide a valid --model FQCN.', Artisan::output());
    }

    public function test_existing_non_model_class_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:diff-timelines', [
            '--model' => Spell::class,
            '--entity-id' => 1,
            '--from-known-at' => '2026-01-01',
            '--to-known-at' => '2026-02-01',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Provide a valid --model FQCN.', Artisan::output());
    }

    public function test_non_temporal_model_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:diff-timelines', [
            '--model' => Product::class,
            '--entity-id' => 1,
            '--from-known-at' => '2026-01-01',
            '--to-known-at' => '2026-02-01',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a temporal model.', Artisan::output());
    }

    public function test_missing_recorded_date_option_is_rejected(): void
    {
        // Provide entity-id and to-known-at but omit from-known-at.
        $exit = Artisan::call('bitemporal:diff-timelines', [
            '--model' => ProductPrice::class,
            '--entity-id' => 1,
            '--to-known-at' => '2026-02-01',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--entity-id, --from-known-at and --to-known-at are required.', Artisan::output());
    }

    private function seedAddress(Customer $owner, string $label, string $recordedFrom, ?string $recordedTo): void
    {
        Address::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'label' => $label,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => $recordedFrom,
            'recorded_to' => $recordedTo,
            'is_retraction' => false,
        ]);
    }
}
