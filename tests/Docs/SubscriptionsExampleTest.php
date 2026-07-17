<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Exceptions\TemporalWriteConflictException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Docs\Models\Account;
use Vusys\Bitemporal\Tests\Docs\Models\Feature;
use Vusys\Bitemporal\Tests\Docs\Models\Plan;
use Vusys\Bitemporal\Tests\Docs\Models\Subscription;

/**
 * Worked example: SaaS subscriptions & entitlements (docs/17-example-subscriptions.md).
 *
 * The subscription, the per-region price, and the plan's feature grants are
 * three independent timelines: dimensions partition the regions, a temporal
 * pivot models the many-to-many grants without losing their history, and the
 * as-of lens reassembles a coherent picture at any past instant. Idempotency
 * keys make the whole thing safe to drive from at-least-once billing webhooks.
 */
final class SubscriptionsExampleTest extends DocsTestCase
{
    private function makeAccount(): Account
    {
        return Account::query()->create(['name' => 'Acme']);
    }

    public function test_an_upgrade_mid_cycle_is_scoped_to_its_region(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        $account = $this->makeAccount();

        $account->subscription()
            ->forDimensions(['region' => 'EU'])
            ->changeEffectiveFrom(
                attributes: ['plan' => 'pro', 'seats' => 25],
                validFrom: '2026-06-01',
            );

        $euNow = $account->subscription()
            ->forDimensions(['region' => 'EU'])
            ->validAt('2026-06-15')
            ->currentKnowledge()
            ->sole();

        $this->assertSame('pro', $euNow->plan);
        $this->assertSame(25, $euNow->seats);

        // The US price list is a separate timeline; this write left it untouched.
        $this->assertCount(0, $account->subscription()->forDimensions(['region' => 'US'])->currentKnowledge()->get());
    }

    public function test_webhook_replay_with_the_same_key_and_params_is_a_no_op(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        $account = $this->makeAccount();

        $apply = fn () => $account->subscription()
            ->forDimensions(['region' => 'EU'])
            ->changeEffectiveFrom(
                attributes: ['plan' => 'pro', 'seats' => 25],
                validFrom: '2026-06-01',
                idempotencyKey: 'stripe:evt_1P',
            );

        $apply();
        $countAfterFirst = Subscription::query()->where('account_id', $account->getKey())->count();

        // Stripe redelivers the same event — replaying must not write new rows.
        $apply();

        $this->assertSame($countAfterFirst, Subscription::query()->where('account_id', $account->getKey())->count());
    }

    public function test_webhook_replay_with_the_same_key_but_different_params_throws(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        $account = $this->makeAccount();

        $account->subscription()
            ->forDimensions(['region' => 'EU'])
            ->changeEffectiveFrom(['plan' => 'pro', 'seats' => 25], validFrom: '2026-06-01', idempotencyKey: 'stripe:evt_1P');

        // Same key, different parameters — a genuine mistake, surfaced loudly.
        $this->expectException(TemporalWriteConflictException::class);

        $account->subscription()
            ->forDimensions(['region' => 'EU'])
            ->changeEffectiveFrom(['plan' => 'pro', 'seats' => 50], validFrom: '2026-06-01', idempotencyKey: 'stripe:evt_1P');
    }

    public function test_region_timelines_do_not_bleed_into_each_other(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 00:00:00');
        $account = $this->makeAccount();

        $account->subscription()->forDimensions(['region' => 'EU'])->changeEffectiveFrom(['plan' => 'pro', 'seats' => 25], '2026-06-01');
        $account->subscription()->forDimensions(['region' => 'US'])->changeEffectiveFrom(['plan' => 'starter', 'seats' => 5], '2026-06-01');

        $this->assertSame('pro', $account->subscription()->forDimensions(['region' => 'EU'])->currentKnowledge()->sole()->plan);
        $this->assertSame('starter', $account->subscription()->forDimensions(['region' => 'US'])->currentKnowledge()->sole()->plan);
    }

    public function test_temporal_pivot_grants_and_ends_features_over_time(): void
    {
        CarbonImmutable::setTestNow('2026-03-01 00:00:00');
        $pro = Plan::query()->create(['tier' => 'pro']);
        $auditLog = Feature::query()->create(['name' => 'audit-log']);
        $prioritySupport = Feature::query()->create(['name' => 'priority-support']);

        // Pro has priority support from the start of the year...
        $pro->features()->attachFor(related: $prioritySupport, validFrom: '2026-01-01');
        // ...and gains the audit-log feature from 1 March.
        $pro->features()->attachFor(related: $auditLog, validFrom: '2026-03-01');

        // Priority support is dropped from Pro at the end of June.
        CarbonImmutable::setTestNow('2026-06-15 00:00:00');
        $pro->features()->detachAt(related: $prioritySupport, validTo: '2026-07-01');

        // In April both features are granted.
        $inApril = $pro->features()->validAt('2026-04-01')->currentKnowledge()->get();
        $this->assertCount(2, $inApril);

        // In August priority support is gone; audit-log remains.
        $inAugust = $pro->features()->validAt('2026-08-01')->currentKnowledge()->get();
        $this->assertCount(1, $inAugust);
        $this->assertSame($auditLog->getKey(), $inAugust->first()?->getAttribute('feature_id'));
    }

    public function test_correct_assignment_fixes_a_grant_window(): void
    {
        CarbonImmutable::setTestNow('2026-03-01 00:00:00');
        $pro = Plan::query()->create(['tier' => 'pro']);
        $auditLog = Feature::query()->create(['name' => 'audit-log']);

        $pro->features()->attachFor(related: $auditLog, validFrom: '2026-03-01');

        // It was actually included a month earlier.
        $pro->features()->correctAssignment(related: $auditLog, validFrom: '2026-02-01');

        $this->assertCount(1, $pro->features()->validAt('2026-02-15')->currentKnowledge()->get());
    }

    public function test_as_of_lens_reconstructs_entitlements_at_a_past_instant(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $account = $this->makeAccount();
        // changeEffectiveFrom requires a forward-dated validFrom, so seed the
        // subscription while the clock is at inception.
        $account->subscription()->forDimensions(['region' => 'EU'])->changeEffectiveFrom(['plan' => 'pro', 'seats' => 25], '2026-01-01');

        CarbonImmutable::setTestNow('2026-03-01 00:00:00');
        $pro = Plan::query()->create(['tier' => 'pro']);
        $auditLog = Feature::query()->create(['name' => 'audit-log']);
        $pro->features()->attachFor(related: $auditLog, validFrom: '2026-03-01');

        // On 3 June, open a lens once and let every temporal read inherit it.
        /** @var Collection<int, Model> $couldAccess */
        $couldAccess = TemporalLens::validAt('2026-06-03', function () use ($account) {
            $subscription = $account->subscription()
                ->forDimensions(['region' => 'EU'])
                ->sole();               // implicitly validAt('2026-06-03')

            return Plan::where('tier', $subscription->plan)
                ->firstOrFail()
                ->features()
                ->currentKnowledge()
                ->get();                // the grants in force that day
        });

        $this->assertCount(1, $couldAccess);
        $this->assertSame($auditLog->getKey(), $couldAccess->first()?->getAttribute('feature_id'));
    }
}
