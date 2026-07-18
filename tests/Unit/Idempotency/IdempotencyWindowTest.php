<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Idempotency;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Vusys\Bitemporal\Idempotency\IdempotencyWindow;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Covers {@see IdempotencyWindow}: a free-form config window must resolve to a
 * usable CarbonInterval, and a misconfigured value must fall back to the
 * documented default instead of throwing into the write/prune path (issue #73).
 */
final class IdempotencyWindowTest extends TestCase
{
    public function test_parses_a_valid_human_window(): void
    {
        $anchor = CarbonImmutable::parse('2026-07-18');

        $this->assertSame(
            '2026-06-18',
            $anchor->sub(IdempotencyWindow::parse('30 days'))->toDateString(),
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function badWindows(): array
    {
        return [
            'unknown unit' => ['1 fortnight'],
            'garbage string' => ['garbage'],
            'empty string' => [''],
            'non-string int' => [7],
            'non-string null' => [null],
            'non-string array' => [['7 days']],
        ];
    }

    #[DataProvider('badWindows')]
    public function test_falls_back_to_the_seven_day_default_on_bad_input(mixed $window): void
    {
        $anchor = CarbonImmutable::parse('2026-07-18');

        $this->assertSame(
            '2026-07-11',
            $anchor->sub(IdempotencyWindow::parse($window))->toDateString(),
            'a misconfigured window must fall back to the documented 7-day default',
        );
    }

    public function test_resolve_reads_the_configured_window(): void
    {
        config(['bitemporal.writes.idempotency_window' => '2 weeks']);
        $anchor = CarbonImmutable::parse('2026-07-18');

        $this->assertSame(
            '2026-07-04',
            $anchor->sub(IdempotencyWindow::resolve())->toDateString(),
        );
    }

    public function test_resolve_falls_back_when_the_configured_window_is_invalid(): void
    {
        config(['bitemporal.writes.idempotency_window' => 'not a duration']);
        $anchor = CarbonImmutable::parse('2026-07-18');

        $this->assertSame(
            '2026-07-11',
            $anchor->sub(IdempotencyWindow::resolve())->toDateString(),
        );
    }
}
