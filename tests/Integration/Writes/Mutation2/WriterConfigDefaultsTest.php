<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Writes\Mutation2;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Vusys\Bitemporal\Exceptions\TemporalDomainException;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Removes specific keys from `bitemporal.writes` so the `?? DEFAULT` / second
 * argument to config()/intConfig() is exercised, then pins behaviour to the
 * exact default literal. This kills the Increment/Decrement/FalseValue mutants
 * on the default arguments in BitemporalWriter, which the first pass wrongly
 * treated as equivalent because the config key was always present.
 */
final class WriterConfigDefaultsTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function forgetWriteKeys(array $keys): void
    {
        config()->set('bitemporal.writes', Arr::except(config('bitemporal.writes'), $keys));
    }

    // --- clock_skew_tolerance_ms default (60000) ---

    public function test_clock_skew_default_tolerates_drift_at_exactly_60000ms(): void
    {
        $this->forgetWriteKeys(['clock_skew_tolerance_ms']);

        CarbonImmutable::setTestNow('2026-06-01 12:00:00.000000');

        $product = $this->makeProduct();
        // recorded_from is exactly 60_000 ms ahead of now => drift == default tolerance.
        // With default 60000: 60000 > 60000 is false => tolerated (write proceeds).
        // DecrementInteger default 59999: 60000 > 59999 is true => would throw.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-06-01 12:01:00.000000',
        ]);

        $committed = $product->prices()->correct(['amount' => 1200], validFrom: '2026-02-01');

        $this->assertGreaterThan(0, $committed->insertedCount());
    }

    public function test_clock_skew_default_rejects_drift_just_over_60000ms(): void
    {
        $this->forgetWriteKeys(['clock_skew_tolerance_ms']);

        CarbonImmutable::setTestNow('2026-06-01 12:00:00.000000');

        $product = $this->makeProduct();
        // Drift of 60_001 ms. With default 60000: 60001 > 60000 => throws.
        // IncrementInteger default 60001: 60001 > 60001 is false => would tolerate.
        $this->insertPrice($product, [
            'amount' => 1000,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'recorded_from' => '2026-06-01 12:01:00.001000',
        ]);

        $this->expectException(TemporalDomainException::class);
        $product->prices()->correct(['amount' => 1200], validFrom: '2026-02-01');
    }

    // --- future_validity_tolerance_ms default (1000) ---

    public function test_future_validity_default_allows_backdate_at_exactly_1000ms(): void
    {
        $this->forgetWriteKeys(['future_validity_tolerance_ms']);

        CarbonImmutable::setTestNow('2026-06-01 12:00:00.000000');

        $product = $this->makeProduct();

        // validFrom is exactly 1000 ms before now. threshold = now - 1000ms.
        // With default 1000: from == threshold => not less than => allowed.
        // DecrementInteger default 999: threshold = now-999, from < threshold => rejects.
        $committed = $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01 11:59:59.000000');

        $this->assertGreaterThan(0, $committed->insertedCount());
    }

    public function test_future_validity_default_rejects_backdate_just_beyond_1000ms(): void
    {
        $this->forgetWriteKeys(['future_validity_tolerance_ms']);

        CarbonImmutable::setTestNow('2026-06-01 12:00:00.000000');

        $product = $this->makeProduct();

        // validFrom is 1001 ms before now. threshold = now - 1000ms.
        // With default 1000: from < threshold => rejects.
        // IncrementInteger default 1001: threshold = now-1001, from == threshold => would allow.
        $this->expectException(TemporalInvalidSpellException::class);
        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01 11:59:58.999000');
    }

    // --- fire_eloquent_events default (false) ---

    public function test_fire_eloquent_events_default_off_persists_quietly(): void
    {
        $this->forgetWriteKeys(['fire_eloquent_events']);

        CarbonImmutable::setTestNow('2026-06-01 00:00:00');

        $fired = false;
        ProductPrice::saved(function () use (&$fired): void {
            $fired = true;
        });

        $product = $this->makeProduct();
        $product->prices()->changeEffectiveFrom(['amount' => 1000], '2026-06-01');

        // Default false => persist() uses saveQuietly() => no Eloquent model events.
        // FalseValue mutant flips the default to true => save() fires "saved".
        $this->assertFalse($fired, 'temporal writes must not fire Eloquent model events by default');
    }

    // --- idempotency_window default/Ternary (config string is honoured) ---

    public function test_idempotency_window_uses_configured_string_for_replay(): void
    {
        config(['bitemporal.writes.idempotency_window' => '30 days']);

        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $product = $this->makeProduct();

        $first = $product->prices()->correct(['amount' => 1000], validFrom: '2026-01-01', idempotencyKey: 'window-key');
        $this->assertSame(1, $first->insertedCount());

        // 15 days later: inside the configured 30-day window, so the prior result
        // is replayed and reconstructed (insertedCount stays 1 from the stored ids).
        // The Ternary mutant returns the literal '7 days' instead of the configured
        // value: 15 days > 7 days => the record is out of window => a fresh write
        // runs and (re-applying the same segment) inserts nothing.
        CarbonImmutable::setTestNow('2026-01-16 00:00:00');

        $second = $product->prices()->correct(['amount' => 1000], validFrom: '2026-01-01', idempotencyKey: 'window-key');

        $this->assertSame(1, $second->insertedCount());
    }
}
