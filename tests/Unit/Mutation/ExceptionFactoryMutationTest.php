<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Mutation;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Events\TemporalBackfillCommitted;
use Vusys\Bitemporal\Events\TemporalChangeCommitted;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Exceptions\TemporalMissingDimensionException;
use Vusys\Bitemporal\Exceptions\TemporalUnsupportedDatabaseException;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Pins surviving mutants in the exception static factories and event accessors.
 * Each factory is invoked from this (external) scope, which kills the
 * PublicVisibility mutants (a `protected static` factory cannot be called here),
 * and the asserted message substrings kill the Concat / Ternary message mutants.
 *
 * Reports covered:
 *   src__Exceptions__TemporalWriteConflictException.txt
 *   src__Exceptions__TemporalConfigurationException.txt
 *   src__Exceptions__TemporalUnsupportedDatabaseException.txt
 *   src__Exceptions__TemporalMissingDimensionException.txt
 *   src__Events__TemporalWriteCommitted.txt
 *   src__Events__TemporalBackfillCommitted.txt
 */
final class ExceptionFactoryMutationTest extends TestCase
{
    public function test_entity_missing_embeds_scalar_key_via_string_cast(): void
    {
        // Ternary swap would yield "#int" (get_debug_type) instead of "#5".
        // PublicVisibility: a protected static factory is uncallable from here.
        $exception = TemporalWriteConflictException::entityMissing(ProductPrice::class, 5);

        $this->assertStringContainsString(ProductPrice::class.'#5', $exception->getMessage());
        $this->assertStringContainsString('no longer exists', $exception->getMessage());
    }

    public function test_clock_regressed_names_the_tuple(): void
    {
        $exception = TemporalWriteConflictException::clockRegressed('product#7');

        $this->assertStringContainsString('product#7', $exception->getMessage());
        $this->assertStringContainsString('regressed', $exception->getMessage());
    }

    public function test_guard_failures_prefixes_the_model_header(): void
    {
        // ConcatOperandRemoval drops the "temporal model ... failed boot validation"
        // prefix; Concat appends it after the lines instead of before.
        $exception = TemporalConfigurationException::guardFailures('Acme\\Price', ['some_guard' => 'broke']);

        $this->assertStringStartsWith('temporal model Acme\\Price failed boot validation:', $exception->getMessage());
        $this->assertStringContainsString('[some_guard] broke', $exception->getMessage());
    }

    public function test_missing_temporal_entity_message(): void
    {
        $exception = TemporalConfigurationException::missingTemporalEntity('Acme\\Price');

        $this->assertStringContainsString('Acme\\Price', $exception->getMessage());
        $this->assertStringContainsString('temporalEntityRelation() method', $exception->getMessage());
    }

    public function test_btree_gist_missing_message(): void
    {
        $exception = TemporalUnsupportedDatabaseException::btreeGistMissing();

        $this->assertStringContainsString('btree_gist extension not available', $exception->getMessage());
    }

    public function test_advisory_locks_unsupported_names_the_engine(): void
    {
        $exception = TemporalUnsupportedDatabaseException::advisoryLocksUnsupported('sqlite');

        $this->assertStringContainsString('sqlite does not support advisory locks', $exception->getMessage());
    }

    public function test_engine_version_below_minimum_interpolates_all_operands(): void
    {
        $exception = TemporalUnsupportedDatabaseException::engineVersionBelowMinimum('mysql', '5.7', '8.0');

        $this->assertStringContainsString('mysql 5.7 below required 8.0', $exception->getMessage());
    }

    public function test_unknown_dimension_names_the_column(): void
    {
        $exception = TemporalMissingDimensionException::unknownDimension('flavour');

        $this->assertStringContainsString("'flavour' is not a declared temporal dimension", $exception->getMessage());
    }

    public function test_write_committed_defaults_compacted_to_false(): void
    {
        // FalseValue flips the default to true.
        $event = new TemporalChangeCommitted(
            ProductPrice::class,
            new ProductPrice,
            [],
            CarbonImmutable::parse('2026-01-01'),
            [],
            [],
        );

        $this->assertFalse($event->compacted);
    }

    public function test_backfill_committed_inserted_count_is_public(): void
    {
        // PublicVisibility: insertedCount() must remain callable from outside.
        $event = new TemporalBackfillCommitted(ProductPrice::class, new ProductPrice, [], [new ProductPrice, new ProductPrice]);

        $this->assertSame(2, $event->insertedCount());
    }
}
