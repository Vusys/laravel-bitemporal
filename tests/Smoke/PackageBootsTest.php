<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Smoke;

use Vusys\Bitemporal\BitemporalServiceProvider;
use Vusys\Bitemporal\Tests\TestCase;

final class PackageBootsTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $app = $this->app;
        $this->assertNotNull($app);
        $this->assertTrue($app->providerIsLoaded(BitemporalServiceProvider::class));
    }

    public function test_config_is_merged(): void
    {
        $this->assertSame('UTC', config('bitemporal.spells.timezone'));
        $this->assertFalse(config('bitemporal.spells.allow_zero_length'));
    }
}
