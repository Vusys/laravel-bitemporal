<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Facades;

use Illuminate\Support\Facades\Facade;
use Vusys\Bitemporal\Lens\LensStack;

/**
 * @method static mixed asOf(\Carbon\CarbonInterface|string|null $validAt, \Carbon\CarbonInterface|string|null $knownAt, \Closure $callback)
 * @method static mixed validAt(\Carbon\CarbonInterface|string $validAt, \Closure $callback)
 * @method static mixed knownAt(\Carbon\CarbonInterface|string $knownAt, \Closure $callback)
 * @method static \Vusys\Bitemporal\Lens\LensFrame|null current()
 * @method static int depth()
 * @method static void reset()
 * @method static void assertEmpty()
 *
 * @see LensStack
 */
final class TemporalLens extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LensStack::class;
    }
}
