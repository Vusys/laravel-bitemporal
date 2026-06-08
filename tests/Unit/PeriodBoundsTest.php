<?php

declare(strict_types=1);

namespace Bitemporal\Tests\Unit;

use Bitemporal\PeriodBounds;
use Bitemporal\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PeriodBoundsTest extends TestCase
{
    public function test_backing_values(): void
    {
        $this->assertSame('[)', PeriodBounds::ClosedOpen->value);
        $this->assertSame('(]', PeriodBounds::OpenClosed->value);
        $this->assertSame('[]', PeriodBounds::Closed->value);
        $this->assertSame('()', PeriodBounds::Open->value);
    }

    /**
     * @return array<string, array{PeriodBounds, bool, bool}>
     */
    public static function boundsProvider(): array
    {
        return [
            'closed-open' => [PeriodBounds::ClosedOpen, true, false],
            'open-closed' => [PeriodBounds::OpenClosed, false, true],
            'closed' => [PeriodBounds::Closed, true, true],
            'open' => [PeriodBounds::Open, false, false],
        ];
    }

    #[DataProvider('boundsProvider')]
    public function test_inclusivity(PeriodBounds $bounds, bool $lower, bool $upper): void
    {
        $this->assertSame($lower, $bounds->includesLower());
        $this->assertSame($upper, $bounds->includesUpper());
    }
}
