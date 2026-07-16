<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Base case for the worked-example tests under docs/15..18.
 *
 * Each test mirrors a section of a worked example as closely as the fixtures
 * allow, so the published snippets are exercised end to end and stay honest as
 * the engine changes. The models and migrations under Docs/Models and
 * Docs/Migrations are dedicated to these examples so the code reads like the
 * documentation (Policy/Compensation/Subscription/TaxRate rather than the
 * generic Product fixtures used elsewhere).
 */
abstract class DocsTestCase extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Migrations');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
