<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Lens;

use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Lens\AsOfJobListener;
use Vusys\Bitemporal\Lens\AsOfMiddleware;
use Vusys\Bitemporal\Lens\LensStack;
use Vusys\Bitemporal\Tests\TestCase;

final class LensLifecycleTest extends TestCase
{
    public function test_as_of_pops_its_frame(): void
    {
        $stack = new LensStack;

        $stack->asOf('2026-01-01', null, function () use ($stack): void {
            $this->assertSame(1, $stack->depth());
        });

        $this->assertSame(0, $stack->depth());
    }

    public function test_assert_empty_throws_on_a_leaked_frame(): void
    {
        $stack = new LensStack;
        $stack->asOf('2026-01-01', null, fn (): null => null);
        $this->assertSame(0, $stack->depth());

        // Simulate a leaked frame (e.g. a worker killed mid-callback).
        $stack->validAt('2026-01-01', function () use ($stack): void {
            $this->expectException(TemporalConfigurationException::class);
            $stack->assertEmpty();
        });
    }

    public function test_job_listener_preserves_an_open_outer_frame(): void
    {
        $stack = new LensStack;
        $listener = new AsOfJobListener($stack);

        // A job dispatched synchronously inside an asOf() callback must keep the
        // caller's still-open outer frame rather than have it wiped (issue #72).
        $stack->validAt('2026-01-01', function () use ($listener, $stack): void {
            $listener->handleProcessing();
            $this->assertSame(1, $stack->depth(), 'the outer frame must survive the job turn');

            $listener->handleProcessed();
            $this->assertSame(1, $stack->depth(), 'the outer frame must survive after the job finishes');
        });

        $this->assertSame(0, $stack->depth());
    }

    public function test_job_listener_trims_a_frame_leaked_by_the_job(): void
    {
        $stack = new LensStack;
        $listener = new AsOfJobListener($stack);

        // Genuine worker-turn boundary: baseline is zero.
        $listener->handleProcessing();

        // Simulate a frame the job leaked (e.g. worker killed mid-callback).
        $stack->validAt('2026-01-01', function () use ($listener, $stack): void {
            $this->expectException(TemporalConfigurationException::class);

            try {
                $listener->handleProcessed();
            } finally {
                // The leak is trimmed even though the assertion throws.
                $this->assertSame(0, $stack->depth());
            }
        });
    }

    public function test_nested_job_turns_restore_each_baseline(): void
    {
        $stack = new LensStack;
        $listener = new AsOfJobListener($stack);

        $stack->validAt('2026-01-01', function () use ($listener, $stack): void {
            $listener->handleProcessing(); // baseline 1

            $stack->validAt('2026-02-01', function () use ($listener, $stack): void {
                $listener->handleProcessing(); // baseline 2 (nested sync dispatch)
                $listener->handleProcessed();
                $this->assertSame(2, $stack->depth());
            });

            $listener->handleProcessed();
            $this->assertSame(1, $stack->depth());
        });

        $this->assertSame(0, $stack->depth());
    }

    public function test_middleware_terminate_asserts_empty(): void
    {
        $stack = new LensStack;
        $middleware = new AsOfMiddleware($stack);

        $request = new \stdClass;
        $this->assertSame($request, $middleware->handle($request, fn ($r) => $r));
        $middleware->terminate();

        $this->assertSame(0, $stack->depth());
    }
}
