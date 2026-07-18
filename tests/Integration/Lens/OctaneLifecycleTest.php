<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Lens;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use ReflectionProperty;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Lens\LensFrame;
use Vusys\Bitemporal\Lens\LensStack;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Long-lived-worker lifecycle coverage: the asOf() lens stack must reset at
 * request/job boundaries so no frame bleeds across requests served by the same
 * Octane/FrankenPHP/Swoole worker. The lifecycle events are driven through the
 * real container dispatcher (the same registration the service provider wires),
 * so this also proves the Octane listeners register safely without the
 * laravel/octane package installed.
 */
final class OctaneLifecycleTest extends TestCase
{
    private const string REQUEST_RECEIVED = 'Laravel\Octane\Events\RequestReceived';

    private const string REQUEST_TERMINATED = 'Laravel\Octane\Events\RequestTerminated';

    private const string WORKER_STARTING = 'Laravel\Octane\Events\WorkerStarting';

    private function stack(): LensStack
    {
        return resolve(LensStack::class);
    }

    private function dispatcher(): Dispatcher
    {
        return resolve(Dispatcher::class);
    }

    /** Simulate a worker killed mid-callback: a frame left on the stack. */
    private function leakFrame(LensStack $stack): void
    {
        $property = new ReflectionProperty(LensStack::class, 'frames');
        $property->setValue($stack, [new LensFrame(CarbonImmutable::parse('2026-01-01'), null)]);

        $this->assertSame(1, $stack->depth());
    }

    public function test_octane_listeners_register_without_the_octane_package(): void
    {
        $this->assertFalse(class_exists(self::REQUEST_RECEIVED), 'laravel/octane must not be installed for this guarantee to mean anything');

        foreach ([self::REQUEST_RECEIVED, self::REQUEST_TERMINATED, self::WORKER_STARTING] as $event) {
            $this->assertTrue($this->dispatcher()->hasListeners($event), "no listener registered for {$event}");
        }
    }

    public function test_a_lens_does_not_leak_across_successive_requests_on_one_worker(): void
    {
        $stack = $this->stack();
        $seen = [];

        // Request N: open and correctly close a lens; the boundary sees it clean.
        $this->dispatcher()->dispatch(self::REQUEST_RECEIVED);
        $stack->validAt('2026-01-01', function () use ($stack, &$seen): void {
            $seen[] = $stack->depth();
        });
        $this->dispatcher()->dispatch(self::REQUEST_TERMINATED);

        // Request N+1: the stack starts empty — no bleed from request N.
        $this->dispatcher()->dispatch(self::REQUEST_RECEIVED);
        $this->assertSame(0, $stack->depth());
        $this->dispatcher()->dispatch(self::REQUEST_TERMINATED);

        $this->assertSame([1], $seen);
    }

    public function test_a_leaked_frame_is_caught_at_termination_and_reset_before_the_next_request(): void
    {
        $stack = $this->stack();
        $this->leakFrame($stack);

        // The terminating boundary surfaces the leak loudly.
        try {
            $this->dispatcher()->dispatch(self::REQUEST_TERMINATED);
            $this->fail('RequestTerminated should have thrown on a leaked frame');
        } catch (TemporalConfigurationException) {
            // expected
        }

        // The next request resets first, so the leak does not cascade.
        $this->dispatcher()->dispatch(self::REQUEST_RECEIVED);
        $this->assertSame(0, $stack->depth());
        $this->dispatcher()->dispatch(self::REQUEST_TERMINATED);
    }

    public function test_worker_starting_resets_a_leaked_frame(): void
    {
        $stack = $this->stack();
        $this->leakFrame($stack);

        $this->dispatcher()->dispatch(self::WORKER_STARTING);

        $this->assertSame(0, $stack->depth());
    }

    public function test_job_boundary_trims_leaks_asserts_and_survives_a_failed_job(): void
    {
        $stack = $this->stack();

        // A frame leaked within a job turn is trimmed at the JobProcessed
        // boundary and surfaced, so it cannot cascade into the next job.
        $this->dispatcher()->dispatch(new JobProcessing('sync', $this->fakeJob()));
        $this->leakFrame($stack);
        try {
            $this->dispatcher()->dispatch(new JobProcessed('sync', $this->fakeJob()));
            $this->fail('JobProcessed should have thrown on a leaked frame');
        } catch (TemporalConfigurationException) {
            // expected
        }
        $this->assertSame(0, $stack->depth());

        // A clean job passes the post-boundary assertion.
        $this->dispatcher()->dispatch(new JobProcessing('sync', $this->fakeJob()));
        $this->dispatcher()->dispatch(new JobProcessed('sync', $this->fakeJob()));
        $this->assertSame(0, $stack->depth());

        // A failed job with a leaked frame is caught at the JobFailed boundary
        // and trimmed, so the next job starts clean.
        $this->dispatcher()->dispatch(new JobProcessing('sync', $this->fakeJob()));
        $this->leakFrame($stack);
        try {
            $this->dispatcher()->dispatch(new JobFailed('sync', $this->fakeJob(), new \RuntimeException('boom')));
            $this->fail('JobFailed should have thrown on a leaked frame');
        } catch (TemporalConfigurationException) {
            // expected
        }
        $this->assertSame(0, $stack->depth());
    }

    private function fakeJob(): Job
    {
        // The lens listeners ignore the job payload entirely; a stub satisfies
        // the queue-event constructors without pinning method signatures.
        return $this->createStub(Job::class);
    }
}
