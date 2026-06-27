<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Boot\Mutation;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Vusys\Bitemporal\Boot\BootLints;
use Vusys\Bitemporal\Boot\Lints\BootLintCompactionExcludesDomainColumn;
use Vusys\Bitemporal\Boot\Lints\BootLintMutableDatetimeCast;
use Vusys\Bitemporal\Events\TemporalBootLintRaised;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Fixtures\Models\MutableCastSuppressingCompactionPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\MutableDatetimeCastPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Kills the surviving mutants in BootLints::runAgainst / default() / suppressedLints.
 *
 * Equivalent mutants (not targeted):
 *  - suppressedLints() UnwrapArrayFilter (`array_values(array_filter($value,
 *    is_string(...)))` -> `array_values($value)`) and UnwrapArrayValues
 *    (`-> array_filter(...)`). The result feeds only into
 *    `in_array($class, $suppressed, true)`; a class-string can never strictly
 *    equal a non-string element, and in_array ignores keys, so neither the
 *    is_string filter nor the array_values reindex changes any outcome.
 */
final class BootLintsMutationTest extends IntegrationTestCase
{
    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function clean(string $class): object
    {
        return TemporalLens::withoutBootGuards(static fn () => new $class);
    }

    public function test_default_runs_both_lints_and_returns_every_raised_one(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', ['amount']);

        $raised = BootLints::default()->runAgainst($this->clean(MutableDatetimeCastPrice::class), dispatch: false);

        // ArrayItemRemoval on default() drops the compaction lint; ArrayOneItem
        // on the return truncates to a single entry. Both raised lints must survive.
        $this->assertCount(2, $raised);
        $this->assertArrayHasKey(BootLintCompactionExcludesDomainColumn::class, $raised);
        $this->assertArrayHasKey(BootLintMutableDatetimeCast::class, $raised);
    }

    public function test_a_suppressed_lint_does_not_halt_later_lints(): void
    {
        // The compaction lint *would* fire, but the model suppresses it. The
        // mutable-datetime lint (checked afterwards) must still be raised, which
        // only holds if the suppressed lint is `continue`d past rather than
        // `break`ing the loop.
        config()->set('bitemporal.writes.compaction_excluded_columns', ['amount']);

        $raised = BootLints::default()->runAgainst($this->clean(MutableCastSuppressingCompactionPrice::class), dispatch: false);

        $this->assertArrayNotHasKey(BootLintCompactionExcludesDomainColumn::class, $raised);
        $this->assertArrayHasKey(BootLintMutableDatetimeCast::class, $raised);
    }

    public function test_a_non_raising_lint_does_not_halt_later_lints(): void
    {
        // Default config => the compaction lint returns null (no domain column).
        // The mutable-datetime lint that follows must still be raised, which only
        // holds if a null result is `continue`d past rather than `break`ing.
        config()->set('bitemporal.writes.compaction_excluded_columns', ['created_at', 'updated_at']);

        $raised = BootLints::default()->runAgainst($this->clean(MutableDatetimeCastPrice::class), dispatch: false);

        $this->assertArrayNotHasKey(BootLintCompactionExcludesDomainColumn::class, $raised);
        $this->assertArrayHasKey(BootLintMutableDatetimeCast::class, $raised);
    }

    public function test_dispatch_defaults_to_true_and_logs_and_emits_an_event(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', ['created_at', 'updated_at']);
        $model = $this->clean(MutableDatetimeCastPrice::class);

        Event::fake([TemporalBootLintRaised::class]);
        $log = Log::spy();

        // No `dispatch:` argument -> the default of true must apply.
        $raised = BootLints::default()->runAgainst($model);

        $this->assertArrayHasKey(BootLintMutableDatetimeCast::class, $raised);

        Event::assertDispatched(
            TemporalBootLintRaised::class,
            static fn (TemporalBootLintRaised $event): bool => $event->model === MutableDatetimeCastPrice::class
                && $event->lint === BootLintMutableDatetimeCast::class
                && str_contains($event->message, 'valid_from'),
        );

        $log->shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'BootLintMutableDatetimeCast')
                && ($context['model'] ?? null) === MutableDatetimeCastPrice::class,
        );
    }

    public function test_dispatch_false_neither_logs_nor_emits(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', ['created_at', 'updated_at']);
        $model = $this->clean(MutableDatetimeCastPrice::class);

        Event::fake([TemporalBootLintRaised::class]);
        $log = Log::spy();

        BootLints::default()->runAgainst($model, dispatch: false);

        Event::assertNotDispatched(TemporalBootLintRaised::class);
        $log->shouldNotHaveReceived('warning');
    }
}
