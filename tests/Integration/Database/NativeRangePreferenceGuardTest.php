<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Database;

use Vusys\Bitemporal\BitemporalServiceProvider;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * prefer_native_ranges is wired for DDL and writes but not yet for reads.
 * Enabling it must fail fast with a clear error rather than silently producing
 * a table the read predicates cannot query.
 */
final class NativeRangePreferenceGuardTest extends IntegrationTestCase
{
    public function test_guard_passes_when_native_ranges_are_disabled(): void
    {
        config()->set('bitemporal.database.prefer_native_ranges', false);

        BitemporalServiceProvider::guardNativeRangePreference();

        $this->addToAssertionCount(1);
    }

    public function test_guard_throws_when_native_ranges_are_preferred(): void
    {
        config()->set('bitemporal.database.prefer_native_ranges', true);

        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/prefer_native_ranges/');

        BitemporalServiceProvider::guardNativeRangePreference();
    }
}
