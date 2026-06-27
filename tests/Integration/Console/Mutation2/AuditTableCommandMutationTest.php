<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console\Mutation2;

use Illuminate\Support\Facades\Artisan;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class AuditTableCommandMutationTest extends IntegrationTestCase
{
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

    public function test_non_scalar_cells_are_json_encoded_not_stringified(): void
    {
        // Kills the cell Ternary swap. A cast period column is a CarbonImmutable
        // (non-scalar), so it renders through json_encode -> an ISO-8601 string
        // containing 'T'. The mutant takes the (string) branch instead, which
        // formats Carbon as "2026-01-01 00:00:00" (a space, no 'T').
        $customer = Customer::query()->create(['name' => 'Acme']);
        $this->seedAddress($customer, 'home');

        $exit = Artisan::call('bitemporal:audit-table', [
            '--model' => Address::class,
            '--entity-id' => $customer->getKey(),
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2026-01-01T', $output);
    }
}
