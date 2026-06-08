<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\BootGuards;

use Vusys\Bitemporal\Boot\BootGuards;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Tests\Fixtures\Models\MisrelatedPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\SoftDeletingPrice;
use Vusys\Bitemporal\Tests\TestCase;

final class BootGuardsTest extends TestCase
{
    public function test_a_well_configured_model_passes(): void
    {
        BootGuards::default()->runAgainst(new ProductPrice);

        $this->expectNotToPerformAssertions();
    }

    public function test_soft_deletes_is_rejected(): void
    {
        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/SoftDeletes/');

        BootGuards::default()->runAgainst(new SoftDeletingPrice);
    }

    public function test_a_non_belongs_to_temporal_entity_is_rejected(): void
    {
        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/BelongsTo or MorphTo/');

        BootGuards::default()->runAgainst(new MisrelatedPrice);
    }

    public function test_guards_run_when_a_temporal_model_is_first_used(): void
    {
        config(['bitemporal.guards.enabled' => true]);

        $this->expectException(TemporalConfigurationException::class);

        // First instantiation triggers initializeBitemporal -> guards.
        new SoftDeletingPrice;
    }
}
