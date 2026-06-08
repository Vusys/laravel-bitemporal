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

    public function test_job_listener_resets_then_asserts(): void
    {
        $stack = new LensStack;
        $listener = new AsOfJobListener($stack);

        // A frame leaked from a previous job is cleared before the next runs.
        $stack->validAt('2026-01-01', function () use ($listener, $stack): void {
            $listener->handleProcessing();
            $this->assertSame(0, $stack->depth());
        });

        $listener->handleProcessed();
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
