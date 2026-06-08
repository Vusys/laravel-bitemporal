<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Vusys\Bitemporal\SpellBounds;
use Vusys\Bitemporal\Tests\TestCase;

final class SpellBoundsTest extends TestCase
{
    public function test_backing_values(): void
    {
        $this->assertSame('[)', SpellBounds::ClosedOpen->value);
        $this->assertSame('(]', SpellBounds::OpenClosed->value);
        $this->assertSame('[]', SpellBounds::Closed->value);
        $this->assertSame('()', SpellBounds::Open->value);
    }

    /**
     * @return array<string, array{SpellBounds, bool, bool}>
     */
    public static function boundsProvider(): array
    {
        return [
            'closed-open' => [SpellBounds::ClosedOpen, true, false],
            'open-closed' => [SpellBounds::OpenClosed, false, true],
            'closed' => [SpellBounds::Closed, true, true],
            'open' => [SpellBounds::Open, false, false],
        ];
    }

    #[DataProvider('boundsProvider')]
    public function test_inclusivity(SpellBounds $bounds, bool $lower, bool $upper): void
    {
        $this->assertSame($lower, $bounds->includesLower());
        $this->assertSame($upper, $bounds->includesUpper());
    }
}
