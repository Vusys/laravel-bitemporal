<?php

declare(strict_types=1);

namespace Bitemporal\Tests;

use Bitemporal\BitemporalServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BitemporalServiceProvider::class,
        ];
    }
}
