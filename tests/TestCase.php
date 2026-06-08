<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vusys\Bitemporal\BitemporalServiceProvider;

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
