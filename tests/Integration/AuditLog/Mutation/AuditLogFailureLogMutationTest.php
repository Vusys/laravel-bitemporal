<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\AuditLog\Mutation;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Pins the audit failure path: an error is logged with full context, and the
 * temporal write still commits.
 */
final class AuditLogFailureLogMutationTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('bitemporal.audit_log.enabled', true);
    }

    public function test_failure_logs_an_error_with_event_and_exception_context(): void
    {
        // Kills the Log::error MethodCallRemoval and the log-context
        // ArrayItemRemoval ('event') / ArrayItem ('exception') mutants.
        Schema::dropIfExists('temporal_audit_log');

        /** @var array<int, array<string, mixed>> $captured */
        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$captured): void {
            if ($event->message === 'TemporalAuditLogSubscriber failed') {
                $captured[] = $event->context;
            }
        });

        $product = $this->makeProduct();
        $product->prices()->correct(['amount' => 1500], '2026-01-01');

        $this->assertCount(1, $captured);

        $context = $captured[0];
        $this->assertArrayHasKey('event', $context);
        $this->assertArrayHasKey('model', $context);
        $this->assertArrayHasKey('exception', $context);

        // The temporal write itself committed despite the audit failure.
        $this->assertSame(1500, $product->prices()->validAt('2026-06-01')->sole()->amount);
    }
}
