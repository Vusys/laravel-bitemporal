<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Lens;

use Illuminate\Support\Facades\Event;
use Vusys\Bitemporal\Boot\BootDiagnosticsReport;
use Vusys\Bitemporal\Events\TemporalBootLintRaised;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Lens\AsOfJobListener;
use Vusys\Bitemporal\Lens\AsOfMiddleware;
use Vusys\Bitemporal\Lens\LensStack;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\SoftDeletingPrice;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Mutation coverage for {@see LensStack} (plus the asOf lifecycle hooks). All
 * cases here are DB-free.
 */
final class LensStackMutationTest extends TestCase
{
    // Kills UnwrapFinally: the suppression flag must be restored after the
    // callback returns, not left stuck at true.
    public function test_without_boot_guards_restores_the_flag(): void
    {
        $stack = new LensStack;

        $before = $stack->bootGuardsSuppressed();
        $this->assertFalse($before);

        $insideValue = $stack->withoutBootGuards(fn (): bool => $stack->bootGuardsSuppressed());

        $this->assertTrue($insideValue);

        $after = $stack->bootGuardsSuppressed();
        $this->assertFalse($after);
    }

    // Kills the parse() Ternary: a configured non-UTC timezone must be honoured
    // (the mutant would always fall back to UTC).
    public function test_parse_honours_the_configured_timezone(): void
    {
        config(['bitemporal.spells.timezone' => 'Europe/London']);

        $stack = new LensStack;

        $stack->asOf('2026-06-01 12:00:00', null, function () use ($stack): void {
            $frame = $stack->current();
            $this->assertNotNull($frame);
            $this->assertSame('Europe/London', $frame->validAt?->timezoneName);
        });
    }

    // Kills the assertEmpty() Concat / ConcatOperandRemoval mutants: both halves
    // of the message must be present and in order.
    public function test_assert_empty_message_contains_both_clauses(): void
    {
        $stack = new LensStack;

        $stack->validAt('2026-01-01', function () use ($stack): void {
            try {
                $stack->assertEmpty();
                $this->fail('Expected a TemporalConfigurationException.');
            } catch (TemporalConfigurationException $exception) {
                $this->assertStringStartsWith(
                    'a TemporalLens::asOf() frame was left open at the end of the request or job; ',
                    $exception->getMessage(),
                );
                $this->assertStringContainsString('asOf() must always pop its frame', $exception->getMessage());
            }
        });
    }

    // Kills the AsOfJobListener MethodCallRemoval: handleProcessed must assert
    // the stack is empty (a leaked frame must blow up).
    public function test_job_listener_asserts_on_a_leaked_frame(): void
    {
        $stack = new LensStack;

        $stack->validAt('2026-01-01', function () use ($stack): void {
            $listener = new AsOfJobListener($stack);
            $this->expectException(TemporalConfigurationException::class);
            $listener->handleProcessed();
        });
    }

    // Kills the AsOfMiddleware MethodCallRemoval: terminate must assert empty.
    public function test_middleware_terminate_asserts_on_a_leaked_frame(): void
    {
        $stack = new LensStack;

        $stack->validAt('2026-01-01', function () use ($stack): void {
            $middleware = new AsOfMiddleware($stack);
            $this->expectException(TemporalConfigurationException::class);
            $middleware->terminate();
        });
    }

    // Kills warmGuards Foreach_ + the runAgainst / failedGuards->put
    // MethodCallRemoval mutants: a guard failure must be collected for the model.
    public function test_warm_guards_collects_a_failing_model(): void
    {
        $report = TemporalLens::withoutBootGuards(
            fn (): BootDiagnosticsReport => TemporalLens::warmGuards([SoftDeletingPrice::class]),
        );
        $this->assertInstanceOf(BootDiagnosticsReport::class, $report);

        $this->assertTrue($report->failedGuards->has(SoftDeletingPrice::class));
        $this->assertStringContainsString('SoftDeletes', (string) $report->failedGuards->get(SoftDeletingPrice::class));
    }

    // Kills warmGuards NotIdentical (`$raised !== []`) + the raisedLints->put
    // MethodCallRemoval: raised lints must be recorded for the model.
    public function test_warm_guards_records_raised_lints(): void
    {
        config()->set('bitemporal.writes.compaction_excluded_columns', ['amount']);

        $report = TemporalLens::warmGuards([ProductPrice::class]);

        $this->assertTrue($report->raisedLints->has(ProductPrice::class));
    }

    // Kills warmGuards FalseValue (`dispatch: false` -> `true`): warming must NOT
    // dispatch lint events.
    public function test_warm_guards_does_not_dispatch_lint_events(): void
    {
        // Ensure ProductPrice's construction-time guards are already cached so
        // instantiation inside warmGuards does not itself dispatch.
        new ProductPrice;

        config()->set('bitemporal.writes.compaction_excluded_columns', ['amount']);

        Event::fake([TemporalBootLintRaised::class]);

        TemporalLens::warmGuards([ProductPrice::class]);

        Event::assertNotDispatched(TemporalBootLintRaised::class);
    }

    // Kills warmGuardsOrFail IfNegation (clean direction) + PublicVisibility:
    // a clean model must return a report (and the method must be callable
    // through the facade).
    public function test_warm_guards_or_fail_returns_a_report_for_clean_models(): void
    {
        $report = TemporalLens::warmGuardsOrFail([ProductPrice::class]);

        $this->assertInstanceOf(BootDiagnosticsReport::class, $report);
        $this->assertTrue($report->failedGuards->isEmpty());
    }

    // Kills warmGuardsOrFail IfNegation (failing direction) + Throw_ + the
    // message Concat / ConcatOperandRemoval mutants.
    public function test_warm_guards_or_fail_throws_for_a_failing_model(): void
    {
        try {
            TemporalLens::withoutBootGuards(
                fn (): BootDiagnosticsReport => TemporalLens::warmGuardsOrFail([SoftDeletingPrice::class]),
            );
            $this->fail('Expected a TemporalConfigurationException.');
        } catch (TemporalConfigurationException $exception) {
            $this->assertStringStartsWith('Boot guards failed: ', $exception->getMessage());
            $this->assertStringContainsString('model(s) failed guards', $exception->getMessage());
        }
    }
}
