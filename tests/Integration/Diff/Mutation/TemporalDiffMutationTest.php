<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Diff\Mutation;

use Vusys\Bitemporal\Diff\DiffEngine;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class TemporalDiffMutationTest extends IntegrationTestCase
{
    public function test_is_empty_is_false_when_only_rows_were_added(): void
    {
        // Kills the LogicalAnd mutant in TemporalDiff::isEmpty(): an added-only
        // diff must not be reported as empty. (added || removed) && changed
        // would wrongly return true here.
        $to = [(new ProductPrice)->forceFill([
            'id' => 1, 'product_id' => 1, 'amount' => 1000, 'currency' => 'GBP',
            'valid_from' => '2026-02-01 00:00:00', 'valid_to' => null,
            'recorded_from' => '2026-01-01 00:00:00', 'recorded_to' => null,
            'is_retraction' => false,
        ])];

        $diff = DiffEngine::compare([], $to, (new ProductPrice)->temporalMetadata());

        $this->assertCount(1, $diff->added);
        $this->assertTrue($diff->removed->isEmpty());
        $this->assertTrue($diff->changed->isEmpty());
        $this->assertFalse($diff->isEmpty());
    }
}
