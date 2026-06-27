<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console\Mutation;

use Illuminate\Support\Facades\Artisan;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\Supplier;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AuditOverlapsCommandMutationTest extends IntegrationTestCase
{
    public function test_clean_timeline_reports_no_overlaps(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-06-01', 'valid_to' => null]);

        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => ProductPrice::class]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No overlaps detected for '.ProductPrice::class.'.', Artisan::output());
    }

    public function test_overlap_is_detected_and_described(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $this->insertPrice($product, ['amount' => 1200, 'valid_from' => '2026-03-01', 'valid_to' => null]);

        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => ProductPrice::class]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('1 overlap(s) detected for '.ProductPrice::class.'.', $output);

        $rows = ProductPrice::query()->where('product_id', $product->getKey())->orderBy('id')->get();
        $expectedTuple = 'product_id='.var_export($rows->first()?->getAttribute('product_id'), true);

        // Tuple key + row labels are reported exactly (kills the var_export/concat
        // mutants and the keyLabel ternary that would print "int" instead of the id).
        $this->assertStringContainsString('overlap in tuple ['.$expectedTuple.']', $output);
        $this->assertStringContainsString('#'.$rows[0]->getKey().' and #'.$rows[1]->getKey(), $output);
    }

    public function test_rows_on_different_entities_do_not_overlap(): void
    {
        $a = $this->makeProduct('A');
        $b = $this->makeProduct('B');

        // Identical, overlapping valid periods but on DIFFERENT entities.
        $this->insertPrice($a, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $this->insertPrice($b, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => ProductPrice::class]);

        // The entity column must keep these in separate tuples -> no overlap.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No overlaps detected', Artisan::output());
    }

    public function test_morph_owners_of_the_same_type_stay_isolated_by_id(): void
    {
        $one = Customer::query()->create(['name' => 'Acme']);
        $two = Customer::query()->create(['name' => 'Globex']);

        $this->seedAddress($one, '2026-01-01', null);
        $this->seedAddress($two, '2026-01-01', null);

        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => Address::class]);

        // owner_id keeps same-type owners apart (kills SpreadOneItem on the tuple).
        $this->assertSame(0, $exit);
    }

    public function test_morph_owners_of_different_types_stay_isolated_by_type(): void
    {
        $customer = Customer::query()->create(['name' => 'Acme']);
        $supplier = Supplier::query()->create(['name' => 'Globex']);

        // Same numeric key (1) in separate tables, identical overlapping periods.
        $this->assertSame($customer->getKey(), $supplier->getKey());
        $this->seedAddressFor($customer->getMorphClass(), $customer->getKey());
        $this->seedAddressFor($supplier->getMorphClass(), $supplier->getKey());

        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => Address::class]);

        // owner_type keeps the two apart (kills morph entityColumns ArrayItemRemoval).
        $this->assertSame(0, $exit);
    }

    public function test_invalid_model_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => 'Not\\A\\Real\\Class']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Provide a valid --model FQCN.', Artisan::output());
    }

    public function test_existing_non_model_class_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => Spell::class]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Provide a valid --model FQCN.', Artisan::output());
    }

    public function test_non_temporal_model_is_rejected(): void
    {
        $exit = Artisan::call('bitemporal:audit-overlaps', ['--model' => Product::class]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a temporal model.', Artisan::output());
    }

    private function seedAddress(Customer $owner, string $validFrom, ?string $validTo): void
    {
        $this->seedAddressFor($owner->getMorphClass(), $owner->getKey(), $validFrom, $validTo);
    }

    private function seedAddressFor(string $ownerType, int|string $ownerId, string $validFrom = '2026-01-01', ?string $validTo = null): void
    {
        Address::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'label' => 'addr',
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'recorded_from' => '2026-01-01',
            'recorded_to' => null,
            'is_retraction' => false,
        ]);
    }
}
