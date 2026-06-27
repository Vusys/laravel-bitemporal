<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Support\Mutation;

use Vusys\Bitemporal\Support\TemporalEntityMetadata;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Mutation coverage for {@see TemporalEntityMetadata::columnMap()}: the two
 * ArrayItem mutants turn a `recorded_*` key/value pair into a comparison
 * (re-indexing the entry), so pinning the exact map kills them.
 */
final class TemporalEntityMetadataMutationTest extends TestCase
{
    public function test_column_map_pins_every_logical_column_to_its_physical_name(): void
    {
        $meta = new TemporalEntityMetadata(
            validFrom: 'vf',
            validTo: 'vt',
            recordedFrom: 'rf',
            recordedTo: 'rt',
            isRetraction: 'ir',
            tracksRecordedTime: true,
            dimensions: ['currency'],
        );

        $this->assertSame([
            'valid_from' => 'vf',
            'valid_to' => 'vt',
            'recorded_from' => 'rf',
            'recorded_to' => 'rt',
            'is_retraction' => 'ir',
        ], $meta->columnMap());
    }
}
