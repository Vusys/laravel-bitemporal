<?php

declare(strict_types=1);

namespace Bitemporal\Tests\Smoke;

use Bitemporal\BitemporalServiceProvider;
use Bitemporal\Tests\TestCase;

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
        $this->assertSame('UTC', config('bitemporal.periods.timezone'));
        $this->assertFalse(config('bitemporal.periods.allow_zero_length'));
    }
}
