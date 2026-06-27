<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Console\Mutation;

use Illuminate\Support\Facades\Artisan;
use stdClass;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\TestCase;

final class WarmGuardsCommandMutationTest extends TestCase
{
    public function test_passing_model_reports_passed_with_the_class_name(): void
    {
        $exit = Artisan::call('bitemporal:warm-guards', ['models' => [ProductPrice::class]]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        // "<class> passed" in that exact order (kills info concat mutants + removal).
        $this->assertStringContainsString(ProductPrice::class.' passed', $output);
    }

    public function test_non_model_class_fails_with_class_and_message(): void
    {
        $exit = Artisan::call('bitemporal:warm-guards', ['models' => [stdClass::class]]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        // The RuntimeException must actually be thrown and surfaced as
        // "<class>: not an Eloquent model class" (kills Throw_, the && short-circuit,
        // and every error-line concat/operand/removal mutant).
        $this->assertStringContainsString('stdClass: not an Eloquent model class', $output);
    }
}
