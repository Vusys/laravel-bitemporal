<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console;

use Illuminate\Testing\PendingCommand;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\SoftDeletingPrice;
use Vusys\Bitemporal\Tests\TestCase;

final class WarmGuardsCommandTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    private function warm(array $arguments): PendingCommand
    {
        $command = $this->artisan('bitemporal:warm-guards', $arguments);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    public function test_it_passes_for_a_well_configured_model(): void
    {
        $this->warm(['models' => [ProductPrice::class]])->assertSuccessful();
    }

    public function test_it_fails_for_a_misconfigured_model(): void
    {
        $this->warm(['models' => [SoftDeletingPrice::class]])->assertFailed();
    }

    public function test_it_fails_for_a_non_model_class(): void
    {
        $this->warm(['models' => [self::class]])->assertFailed();
    }
}
