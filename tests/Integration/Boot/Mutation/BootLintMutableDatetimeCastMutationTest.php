<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Boot\Mutation;

use Vusys\Bitemporal\Boot\Lints\BootLintMutableDatetimeCast;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\MutableDatetimeCastPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Kills the surviving mutants in BootLintMutableDatetimeCast::check by pinning
 * the exact message (which names exactly the offending column) and the null
 * result for a clean model.
 */
final class BootLintMutableDatetimeCastMutationTest extends IntegrationTestCase
{
    public function test_exact_message_names_only_the_offending_column(): void
    {
        /** @var MutableDatetimeCastPrice $model */
        $model = TemporalLens::withoutBootGuards(static fn (): MutableDatetimeCastPrice => new MutableDatetimeCastPrice);

        $message = new BootLintMutableDatetimeCast()->check($model);

        // The exact string kills the foreach/method_exists/identical/concat
        // mutants: only valid_from carries a mutable cast, so every other
        // temporal column must be absent and the wording must match precisely.
        $this->assertSame(
            'temporal column(s) declared with a mutable datetime cast: valid_from. '
            .'Use immutable_datetime (the trait applies it automatically).',
            $message,
        );
    }

    public function test_names_only_the_column_with_a_mutable_cast(): void
    {
        /** @var MutableDatetimeCastPrice $model */
        $model = TemporalLens::withoutBootGuards(static fn (): MutableDatetimeCastPrice => new MutableDatetimeCastPrice);

        $message = (string) new BootLintMutableDatetimeCast()->check($model);

        $this->assertStringContainsString('valid_from', $message);
        $this->assertStringNotContainsString('valid_to', $message);
        $this->assertStringNotContainsString('recorded_from', $message);
        $this->assertStringNotContainsString('is_retraction', $message);
    }

    public function test_returns_null_for_a_model_without_mutable_casts(): void
    {
        // ProductPrice auto-applies immutable_datetime to every period column.
        $this->assertNull(new BootLintMutableDatetimeCast()->check(new ProductPrice));
    }
}
